<?php

namespace App\Exports;

use App\Contracts\GeneratesWorkbook;
use App\Contracts\TracksExportProgress;
use App\Exports\Concerns\InteractsWithExportProgress;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class PaymentBatchDrpExport implements GeneratesWorkbook, TracksExportProgress
{
    use InteractsWithExportProgress;

    /** @var Collection<int, PaymentBatch>|null */
    private ?Collection $cachedBatches = null;

    /**
     * @param  int  $actorId  User ID initiating the export
     * @param  list<int>  $batchIds  PaymentBatch IDs to export
     */
    public function __construct(
        public readonly int $actorId,
        public readonly array $batchIds,
    ) {}

    /**
     * Generate the spreadsheet and save to storage.
     */
    public function generateWorkbook(string $path, string $disk): void
    {
        $this->authorizeActor();

        $batches = $this->getBatches();

        if ($batches->isEmpty()) {
            throw new RuntimeException('No eligible supplier payment batches found for export.');
        }

        // Validate that every requested batch has active items
        foreach ($batches as $batch) {
            $hasActiveItems = $batch->groups->contains(function (PaymentGroup $group) {
                return $group->status !== PaymentGroup::STATUS_CANCELLED
                    && $group->items->contains(fn (PaymentItem $item) => $item->status === PaymentItem::STATUS_ACTIVE && $item->payable_type === LocalInvoice::class);
            });

            if (! $hasActiveItems) {
                throw new RuntimeException("Batch {$batch->batch_number} contains no active payment items.");
            }
        }

        $renderer = app(PaymentBatchDrpSheetRenderer::class);
        $spreadsheet = $renderer->render($batches);

        $tempPath = tempnam(sys_get_temp_dir(), 'drp_exp_');
        if ($tempPath === false) {
            throw new RuntimeException('Failed to create temporary file for spreadsheet export.');
        }

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempPath);

            $stream = fopen($tempPath, 'r');
            try {
                Storage::disk($disk)->put($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Estimated total rows for progress tracking.
     */
    public function progressTotalRows(): int
    {
        $batches = $this->getBatches();
        $total = 0;

        foreach ($batches as $batch) {
            foreach ($batch->groups as $group) {
                if ($group->status !== PaymentGroup::STATUS_CANCELLED) {
                    $activeItemsCount = $group->items
                        ->where('status', PaymentItem::STATUS_ACTIVE)
                        ->where('payable_type', LocalInvoice::class)
                        ->count();

                    if ($activeItemsCount > 0) {
                        $total++;
                    }
                }
            }
        }

        return max(1, $total);
    }

    /**
     * Eager-load the payment batches with only active, non-cancelled data.
     *
     * @return Collection<int, PaymentBatch>
     */
    public function getBatches(): Collection
    {
        if ($this->cachedBatches !== null) {
            return $this->cachedBatches;
        }

        $cleanBatchIds = array_values(array_filter(array_map('intval', $this->batchIds)));

        if (empty($cleanBatchIds)) {
            return $this->cachedBatches = collect();
        }

        return $this->cachedBatches = PaymentBatch::query()
            ->where('batch_type', PaymentBatch::TYPE_SUPPLIER)
            ->whereIn('id', $cleanBatchIds)
            ->where('status', '!=', PaymentBatch::STATUS_CANCELLED)
            ->with([
                'groups' => fn ($q) => $q
                    ->where('status', '!=', PaymentGroup::STATUS_CANCELLED)
                    ->orderBy('id')
                    ->with([
                        'items' => fn ($q) => $q
                            ->where('status', PaymentItem::STATUS_ACTIVE)
                            ->where('payable_type', LocalInvoice::class)
                            ->orderBy('id')
                            ->with('payable'),
                    ]),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    private function authorizeActor(): void
    {
        $actor = User::find($this->actorId);

        if ($actor === null || (! $actor->isFinance() && ! $actor->isAdmin())) {
            throw new RuntimeException('Unauthorized actor for DRP export.');
        }
    }
}
