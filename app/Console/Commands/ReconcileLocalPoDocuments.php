<?php

namespace App\Console\Commands;

use App\Services\LocalInvoice\LocalPoDocumentBatchService;
use Illuminate\Console\Command;

class ReconcileLocalPoDocuments extends Command
{
    protected $signature = 'po-documents:reconcile';

    protected $description = 'Recover abandoned PO document attempts and clean retained private staging.';

    public function handle(LocalPoDocumentBatchService $service): int
    {
        $service->reconcile();

        return self::SUCCESS;
    }
}
