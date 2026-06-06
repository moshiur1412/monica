<?php

namespace App\Domains\Contact\Import\Api\Controllers;

use App\Domains\Contact\Import\Jobs\ProcessImportJob;
use App\Http\Controllers\ApiController;
use App\Http\Resources\ImportJobResource;
use App\Models\ImportJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ImportController extends ApiController
{
    public function __construct()
    {
        $this->middleware('abilities:write')->only(['store', 'cancel']);
        $this->middleware('abilities:read')->only(['index', 'show', 'errors', 'downloadErrors']);
        parent::__construct();
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $page = $request->integer('page', 1);
        $perPage = $this->getLimitPerPage();

        $version = Cache::get("import_list_version:user:{$userId}", 0);
        $cacheKey = "import_list:user:{$userId}:v{$version}:page:{$page}:per_page:{$perPage}";

        return Cache::remember($cacheKey, now()->addMinute(), function () use ($request) {
            $imports = ImportJob::byAccount($request->user()->account_id)
                ->byUser($request->user()->id)
                ->orderBy('created_at', 'desc')
                ->paginate($this->getLimitPerPage());

            return ImportJobResource::collection($imports);
        });
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,vcard,vcf|max:102400',
            'vault_id' => 'required|uuid|exists:vaults,id',
        ]);

        $file = $request->file('file');
        $hash = md5_file($file->getRealPath());
        $filename = $file->getClientOriginalName();
        $path = $file->store('imports');

        $importJob = ImportJob::create([
            'account_id' => $request->user()->account_id,
            'user_id' => $request->user()->id,
            'vault_id' => $request->input('vault_id'),
            'filename' => $filename,
            'original_file_path' => $path,
            'file_hash' => $hash,
            'total_rows' => 0,
            'processed_rows' => 0,
            'failed_rows' => 0,
            'status' => ImportJob::STATUS_PENDING,
            'errors' => null,
            'batch_size' => 50,
        ]);

        ProcessImportJob::dispatch($importJob->id, [
            'account_id' => $request->user()->account_id,
            'vault_id' => $request->input('vault_id'),
            'author_id' => $request->user()->id,
        ]);

        $this->clearImportListCache($request->user()->id);

        return (new ImportJobResource($importJob))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, ImportJob $import)
    {
        if ($import->user_id !== $request->user()->id) {
            abort(404);
        }

        return new ImportJobResource($import);
    }

    public function cancel(Request $request, ImportJob $import)
    {
        if ($import->user_id !== $request->user()->id) {
            abort(404);
        }

        if (! $import->isProcessing()) {
            return $this->setHTTPStatusCode(422)
                ->respondWithError('Import is not currently processing');
        }

        $import->status = ImportJob::STATUS_CANCELLED;
        $import->cancelled_at = now();
        $import->save();

        $this->clearImportListCache($request->user()->id);

        return new ImportJobResource($import);
    }

    public function errors(Request $request, ImportJob $import)
    {
        if ($import->user_id !== $request->user()->id) {
            abort(404);
        }

        $allErrors = $import->errors ?? [];
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 10);
        $total = count($allErrors);
        $items = array_slice($allErrors, ($page - 1) * $perPage, $perPage);
        $lastPage = max((int) ceil($total / $perPage), 1);

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function downloadErrors(Request $request, ImportJob $import)
    {
        if ($import->user_id !== $request->user()->id) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($import->original_file_path)) {
            abort(404, 'Original file not found');
        }

        $errors = $import->errors ?? [];
        $content = Storage::disk('local')->get($import->original_file_path);

        $errorFilename = pathinfo($import->filename, PATHINFO_FILENAME).'_errors.csv';

        if (empty($errors)) {
            $csvContent = "error\nNo errors found.\n";

            return response($csvContent, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$errorFilename.'"',
            ]);
        }

        $errorMap = collect($errors)->groupBy('row')->map(function ($items) {
            return $items->pluck('message')->implode('; ');
        });

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $lines = array_filter($lines, fn ($line) => trim($line) !== '');

        if (empty($lines)) {
            abort(500, 'Cannot read original file');
        }

        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);

        $csv = fopen('php://temp', 'r+');

        if ($csv === false) {
            abort(500, 'Cannot create temporary file');
        }

        fputcsv($csv, array_merge($headers, ['error']));

        $rowNum = 1;
        foreach ($lines as $line) {
            $errorMsg = $errorMap->get($rowNum, '');

            if (empty($errorMsg)) {
                $rowNum++;

                continue;
            }

            $row = str_getcsv($line);
            $data = [];
            foreach ($headers as $index => $header) {
                $data[$header] = isset($row[$index]) ? $row[$index] : '';
            }
            $data['error'] = $errorMsg;
            fputcsv($csv, $data);
            $rowNum++;
        }

        rewind($csv);
        $csvContent = stream_get_contents($csv);
        fclose($csv);

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$errorFilename.'"',
        ]);
    }

    private function clearImportListCache(string $userId): void
    {
        Cache::increment("import_list_version:user:{$userId}");
    }
}
