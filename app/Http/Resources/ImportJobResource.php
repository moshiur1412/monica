<?php

namespace App\Http\Resources;

use App\Helpers\DateHelper;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportJobResource extends JsonResource
{
    public function toArray($request): array
    {
        $elapsed = null;
        $estimatedRemaining = null;

        if ($this->started_at) {
            $elapsed = max(now()->diffInSeconds($this->started_at), 1);
        }

        if ($this->isProcessing() && $this->started_at) {
            if ($this->processed_rows > 0) {
                $rate = $this->processed_rows / $elapsed;
                $remaining = $this->total_rows - $this->processed_rows;
                $estimatedRemaining = (int) ceil($remaining / max($rate, 0.01));
            } elseif ($elapsed > 10) {
                $estimatedRemaining = 0;
            }
        }

        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'failed_rows' => $this->failed_rows,
            'status' => $this->status,
            'progress_pct' => $this->progressPct(),
            'errors' => $this->when($this->isCompleted() || $this->isFailed() || $this->isProcessing(), function () {
                return $this->errors ? array_slice($this->errors, 0, 10) : [];
            }),
            'started_at' => $this->started_at ? DateHelper::getTimestamp($this->started_at) : null,
            'completed_at' => $this->completed_at ? DateHelper::getTimestamp($this->completed_at) : null,
            'processing_time_sec' => $this->completed_at
                ? (int) $this->started_at->diffInSeconds($this->completed_at)
                : ($this->started_at ? $elapsed : null),
            'estimated_remaining_sec' => $estimatedRemaining,
            'created_at' => DateHelper::getTimestamp($this->created_at),
            'updated_at' => DateHelper::getTimestamp($this->updated_at),
        ];
    }
}
