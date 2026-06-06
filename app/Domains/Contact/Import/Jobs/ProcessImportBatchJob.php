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

class ProcessImportBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $importJobId;

    public int $offset;

    public array $rows;

    public array $context;

    public function __construct(string $importJobId, int $offset, array $rows, array $context)
    {
        $this->importJobId = $importJobId;
        $this->offset = $offset;
        $this->rows = $rows;
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

        $processedInBatch = 0;
        $failedInBatch = 0;
        $batchErrors = [];

        foreach ($this->rows as $index => $row) {
            $rowNumber = $this->offset + $index + 1;

            if ($processedInBatch > 0 && $processedInBatch % 10 === 0) {
                $importJob->refresh();
                if ($importJob->isCancelled()) {
                    $this->saveBatchProgress($importJob, $processedInBatch, $failedInBatch, $batchErrors);

                    return;
                }
            }

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

        $this->saveBatchProgress($importJob, $processedInBatch, $failedInBatch, $batchErrors);
    }

    private function saveBatchProgress(ImportJob $importJob, int $processedInBatch, int $failedInBatch, array $batchErrors): void
    {
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
