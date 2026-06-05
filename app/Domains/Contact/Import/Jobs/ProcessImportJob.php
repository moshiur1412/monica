<?php

namespace App\Domains\Contact\Import\Jobs;

use App\Domains\Contact\Import\Services\CsvParser;
use App\Models\ImportJob;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $importJobId;

    public array $context;

    public function __construct(string $importJobId, array $context)
    {
        $this->importJobId = $importJobId;
        $this->context = $context;
    }

    public function handle(CsvParser $parser): void
    {
        $importJob = ImportJob::findOrFail($this->importJobId);

        if (! $importJob->isPending()) {
            return;
        }

        if (! Storage::disk('local')->exists($importJob->original_file_path)) {
            $importJob->status = ImportJob::STATUS_FAILED;
            $importJob->completed_at = now();
            $importJob->save();

            return;
        }

        $totalRows = $parser->countRows($importJob->original_file_path);

        $importJob->total_rows = $totalRows;
        $importJob->status = ImportJob::STATUS_PROCESSING;
        $importJob->started_at = now();
        $importJob->save();

        if ($totalRows === 0) {
            $importJob->status = ImportJob::STATUS_COMPLETED;
            $importJob->completed_at = now();
            $importJob->save();

            return;
        }

        $batchSize = $importJob->batch_size;
        $jobs = [];

        for ($offset = 0; $offset < $totalRows; $offset += $batchSize) {
            $limit = min($batchSize, $totalRows - $offset);

            $jobs[] = new ProcessImportBatchJob(
                $this->importJobId,
                $offset,
                $limit,
                $this->context,
            );
        }

        Bus::batch($jobs)
            ->then(function (Batch $batch) use ($importJob) {
                $importJob->refresh();
                $importJob->completed_at = now();

                if ($importJob->failed_rows === $importJob->total_rows) {
                    $importJob->status = ImportJob::STATUS_FAILED;
                } else {
                    $importJob->status = ImportJob::STATUS_COMPLETED;
                }

                $importJob->save();
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($importJob) {
                $importJob->refresh();
                $importJob->status = ImportJob::STATUS_FAILED;
                $importJob->completed_at = now();
                $importJob->save();
            })
            ->dispatch();
    }
}
