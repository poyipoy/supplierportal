<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\LocalInvoice\Contracts\LocalPoProviderInterface;
use InvalidArgumentException;

class LocalPoReferenceService
{
    /**
     * In-memory registry for testing or external provider simulation.
     * Format: [supplier_id => [po_number => ['value' => float, 'gr' => string, 'description' => string]]]
     */
    protected static array $mockInternalPos = [];

    public function __construct(
        protected ?LocalPoProviderInterface $provider = null
    ) {
        $this->provider = $provider ?? app(LocalPoProviderInterface::class);
    }

    public static function registerInternalPo(int $supplierId, string $poNumber, float $value, ?string $grReference = null, string $description = ''): void
    {
        self::$mockInternalPos[$supplierId][$poNumber] = [
            'value' => $value,
            'gr' => $grReference,
            'description' => $description,
        ];
    }

    public static function clearMockPos(): void
    {
        self::$mockInternalPos = [];
    }

    /**
     * Search available internal POs for a supplier.
     */
    public function searchInternalPos(User $supplierUser, string $query = '', int $limit = 10): array
    {
        $queryUpper = strtoupper(trim($query));
        $results = [];

        // 1. If mock internal POs exist for this supplier (e.g. during tests), include them
        if (isset(self::$mockInternalPos[$supplierUser->id])) {
            foreach (self::$mockInternalPos[$supplierUser->id] as $poNum => $data) {
                if ($queryUpper === '' || str_contains(strtoupper((string) $poNum), $queryUpper)) {
                    $details = $this->getInternalPoDetails($supplierUser, (string) $poNum);
                    if ($details) {
                        $results[$poNum] = [
                            'po_number' => $poNum,
                            'description' => $details['description'] ?? '',
                            'total_amount' => (float) $details['po_value'],
                            'formatted_total_amount' => 'Rp '.number_format($details['po_value'], 0, ',', '.'),
                            'remaining_amount' => (float) $details['remaining_value'],
                            'formatted_remaining_amount' => 'Rp '.number_format($details['remaining_value'], 0, ',', '.'),
                            'has_gr' => (bool) $details['has_gr'],
                            'gr_reference' => $details['gr_reference'],
                        ];
                    }
                }
            }
        }

        // 2. Query database LocalPurchaseOrder
        $dbPos = LocalPurchaseOrder::where('supplier_id', $supplierUser->id)
            ->when($query !== '', function ($q) use ($query) {
                $q->where('po_number', 'like', "%{$query}%");
            })
            ->with('goodsReceipts')
            ->latest('id')
            ->limit($limit)
            ->get();

        foreach ($dbPos as $po) {
            if (! isset($results[$po->po_number])) {
                $details = $this->getInternalPoDetails($supplierUser, $po->po_number);
                $gr = $po->goodsReceipts->first();
                $hasGr = $gr !== null;
                $poValue = (float) $po->total_amount;
                $remainingValue = $details ? (float) $details['remaining_value'] : $poValue;

                $results[$po->po_number] = [
                    'po_number' => $po->po_number,
                    'description' => $po->description ?? '',
                    'total_amount' => $poValue,
                    'formatted_total_amount' => 'Rp '.number_format($poValue, 0, ',', '.'),
                    'remaining_amount' => $remainingValue,
                    'formatted_remaining_amount' => 'Rp '.number_format($remainingValue, 0, ',', '.'),
                    'has_gr' => $hasGr,
                    'gr_reference' => $gr?->gr_number,
                ];
            }
        }

        return array_values(array_slice($results, 0, $limit));
    }

    /**
     * Get authoritative internal PO details for a supplier.
     */
    public function getInternalPoDetails(User $supplierUser, string $poNumber): ?array
    {
        // 1. Check registered/mocked internal POs (for test overrides)
        if (isset(self::$mockInternalPos[$supplierUser->id][$poNumber])) {
            $data = self::$mockInternalPos[$supplierUser->id][$poNumber];
            $poValue = (float) $data['value'];
            $gr = $data['gr'];
            $description = $data['description'];
        } elseif ($providerData = $this->provider?->getPoData($supplierUser, $poNumber)) {
            $poValue = (float) $providerData['po_value'];
            $gr = $providerData['gr_reference'];
            $description = $providerData['description'];
        } else {
            return null;
        }

        // Calculate previously invoiced value from active/paid local invoices
        $previouslyInvoiced = (float) LocalInvoice::where('supplier_id', $supplierUser->id)
            ->where(function ($q) use ($poNumber) {
                $q->where('internal_po_reference', $poNumber)
                    ->orWhere('po_number', $poNumber);
            })
            ->whereNotIn('status', [LocalInvoice::STATUS_REJECTED, LocalInvoice::STATUS_EXPIRED])
            ->sum('invoice_amount');

        $remaining = max(0.0, round($poValue - $previouslyInvoiced, 2));

        return [
            'po_number' => $poNumber,
            'description' => $description,
            'po_value' => $poValue,
            'previously_invoiced' => $previouslyInvoiced,
            'remaining_value' => $remaining,
            'has_gr' => ! empty($gr),
            'gr_reference' => $gr,
        ];
    }

    /**
     * Validate PO & GR references server-side and calculate discrepancy.
     */
    public function validateAndResolve(
        User $supplierUser,
        string $poSource,
        ?string $internalPoRef,
        ?string $manualPoNumber,
        float $invoiceDpp,
        ?string $manualGrRef = null
    ): array {
        $poSource = strtoupper(trim($poSource));

        if ($poSource === 'INTERNAL') {
            if (empty($internalPoRef)) {
                throw new InvalidArgumentException('Internal PO reference is required.');
            }

            $details = $this->getInternalPoDetails($supplierUser, $internalPoRef);
            if (! $details) {
                throw new InvalidArgumentException("Internal PO [{$internalPoRef}] does not exist or does not belong to this supplier.");
            }

            if (! $details['has_gr']) {
                throw new InvalidArgumentException("Goods Receipt (GR) is not yet available for PO [{$internalPoRef}]. Submission cannot proceed.");
            }

            $remaining = $details['remaining_value'];
            $hasDiscrepancy = $invoiceDpp > $remaining;

            return [
                'po_source' => 'INTERNAL',
                'po_number' => $details['po_number'],
                'internal_po_reference' => $details['po_number'],
                'manual_po_number' => null,
                'internal_gr_reference' => $details['gr_reference'],
                'manual_gr_reference' => null,
                'po_value_snapshot' => $details['po_value'],
                'po_invoiced_snapshot' => $details['previously_invoiced'],
                'po_remaining_snapshot' => $remaining,
                'has_po_discrepancy' => $hasDiscrepancy,
            ];
        }

        if ($poSource === 'MANUAL') {
            if (empty($manualPoNumber)) {
                throw new InvalidArgumentException('Manual PO number is required.');
            }
            if (empty($manualGrRef)) {
                throw new InvalidArgumentException('Manual Goods Receipt (GR) reference is required.');
            }

            return [
                'po_source' => 'MANUAL',
                'po_number' => trim($manualPoNumber),
                'internal_po_reference' => null,
                'manual_po_number' => trim($manualPoNumber),
                'internal_gr_reference' => null,
                'manual_gr_reference' => trim($manualGrRef),
                'po_value_snapshot' => null,
                'po_invoiced_snapshot' => null,
                'po_remaining_snapshot' => null,
                'has_po_discrepancy' => false,
            ];
        }

        throw new InvalidArgumentException("Invalid PO source [{$poSource}]. Must be INTERNAL or MANUAL.");
    }
}
