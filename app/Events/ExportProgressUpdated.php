<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ExportProgressUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public readonly int $userId,
        public readonly string $exportJobId,
        public readonly string $status,
        public readonly string $stage,
        public readonly string $message,
        public readonly ?int $progress = null,
        public readonly int $processedRows = 0,
        public readonly int $totalRows = 0,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'export.progress';
    }

    /** @return array<string, int|string|null> */
    public function broadcastWith(): array
    {
        return [
            'export_job_id' => $this->exportJobId,
            'status' => $this->status,
            'stage' => $this->stage,
            'message' => $this->message,
            'progress' => $this->progress,
            'processed_rows' => $this->processedRows,
            'total_rows' => $this->totalRows,
        ];
    }
}
