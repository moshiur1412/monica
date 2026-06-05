<?php

namespace App\Domains\Contact\Import\Jobs;

use App\Domains\Contact\Import\Services\CsvParser;
use App\Domains\Contact\Import\Services\ImportContactFromRow;
use App\Models\ImportJob;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessImportBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $importJobId;

    public int $offset;

    public int $limit;

    public array $context;

    public function __construct(string $importJobId, int $offset, int $limit, array $context)
    {
        $this->importJobId = $importJobId;
        $this->offset = $offset;
        $this->limit = $limit;
        $this->context = $context;
    }

    public function handle(CsvParser $parser, ImportContactFromRow $importer): void
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        $importJob = ImportJob::findOrFail($this->importJobId);

        if ($importJob->isCancelled() || $importJob->isFailed()) {
            return;
        }

        if (! Storage::disk('local')->exists($importJob->original_file_path)) {
            $this->fail(new \RuntimeException("Import file not found: {$importJob->original_file_path}"));

            return;
        }

        $rows = $parser->parse($importJob->original_file_path);
        $batchRows = $rows->slice($this->offset, $this->limit);

        $processedInBatch = 0;
        $failedInBatch = 0;
        $batchErrors = [];

        foreach ($batchRows as $index => $row) {
            $rowNumber = $this->offset + $index + 1;

            try {
                $normalized = $parser->normalizeRow($row);
                $validationErrors = $parser->validateRow($normalized);

                if (! empty($validationErrors)) {
                    foreach ($validationErrors as $error) {
                        $batchErrors[] = [
                            'row' => $rowNumber,
                            'message' => $error,
                        ];
                    }
                    $failedInBatch++;

                    continue;
                }

                $importer->import($normalized, $this->context);
                $processedInBatch++;
            } catch (\Exception $e) {
                $batchErrors[] = [
                    'row' => $rowNumber,
                    'message' => $e->getMessage(),
                ];
                $failedInBatch++;
            }
        }

        DB::transaction(function () use ($importJob, $processedInBatch, $failedInBatch, $batchErrors) {
            $job = ImportJob::lockForUpdate()->findOrFail($importJob->id);
            $job->processed_rows += $processedInBatch;
            $job->failed_rows += $failedInBatch;
            $existingErrors = $job->errors ?? [];
            $job->errors = array_values(array_merge($existingErrors, $batchErrors));
            $job->save();
        });
    }

    public function failed(\Throwable $e): void
    {
        ImportJob::where('id', $this->importJobId)->update([
            'status' => ImportJob::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }
}
