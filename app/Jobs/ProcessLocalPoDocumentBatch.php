<?php

namespace App\Jobs;

use App\Services\LocalInvoice\LocalPoDocumentBatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessLocalPoDocumentBatch implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $batchId, public string $dispatchToken)
    {
        $this->tries = max(1, (int) config('po_documents.retry_limit'));
        $this->timeout = max(1, (int) config('po_documents.timeout_seconds'));
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(LocalPoDocumentBatchService $service): void
    {
        $service->process($this->batchId, $this->dispatchToken);
    }
}
