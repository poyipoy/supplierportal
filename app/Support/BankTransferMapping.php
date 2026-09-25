<?php

namespace App\Support;

use App\Exports\PaymentBatchTransferSheetRenderer;
use App\Models\LocalInvoice;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use Illuminate\Support\Collection;

/**
 * Deterministic mapping from PaymentGroup.bank_name to BCA Transfer Type,
 * SANDI BIC (Receiver Bank Cd), and canonical bank name for the
 * TARIKAN TRANSFER export.
 *
 * BCA transfers use "BCA" type. Non-BCA transfers use "LLG".
 *
 * @see PaymentBatchTransferSheetRenderer
 */
final class BankTransferMapping
{
    /**
     * BCA is the debiting bank in this project.
     * Transfers TO BCA use internal transfer type "BCA".
     * Transfers TO other banks use "LLG" (Bank Indonesia clearing).
     */
    public const TRANSFER_TYPE_BCA = 'BCA';

    public const TRANSFER_TYPE_LLG = 'LLG';

    /**
     * Known bank mappings: normalized_key => [sandi_bic, canonical_name].
     *
     * Keys are uppercase, trimmed bank names. This list covers the most common
     * Indonesian banks. Extend as needed when suppliers register new banks.
     */
    private const MAPPINGS = [
        'BCA' => ['CENAIDJA', 'PT BANK CENTRAL ASIA TBK'],
        'BANK BCA' => ['CENAIDJA', 'PT BANK CENTRAL ASIA TBK'],
        'BANK CENTRAL ASIA' => ['CENAIDJA', 'PT BANK CENTRAL ASIA TBK'],
        'PT BANK CENTRAL ASIA' => ['CENAIDJA', 'PT BANK CENTRAL ASIA TBK'],
        'MANDIRI' => ['BMRIIDJA', 'PT BANK MANDIRI (PERSERO) TBK'],
        'BANK MANDIRI' => ['BMRIIDJA', 'PT BANK MANDIRI (PERSERO) TBK'],
        'BNI' => ['BNINIDJA', 'PT BANK NEGARA INDONESIA (PERSERO) TBK'],
        'BANK BNI' => ['BNINIDJA', 'PT BANK NEGARA INDONESIA (PERSERO) TBK'],
        'BANK NEGARA INDONESIA' => ['BNINIDJA', 'PT BANK NEGARA INDONESIA (PERSERO) TBK'],
        'BRI' => ['BRINIDJA', 'PT BANK RAKYAT INDONESIA (PERSERO) TBK'],
        'BANK BRI' => ['BRINIDJA', 'PT BANK RAKYAT INDONESIA (PERSERO) TBK'],
        'BANK RAKYAT INDONESIA' => ['BRINIDJA', 'PT BANK RAKYAT INDONESIA (PERSERO) TBK'],
        'CIMB NIAGA' => ['BNIAIDJA', 'PT BANK CIMB NIAGA TBK'],
        'BANK CIMB NIAGA' => ['BNIAIDJA', 'PT BANK CIMB NIAGA TBK'],
        'DANAMON' => ['BDINIDJA', 'PT BANK DANAMON INDONESIA TBK'],
        'BANK DANAMON' => ['BDINIDJA', 'PT BANK DANAMON INDONESIA TBK'],
        'PERMATA' => ['BBBAIDJA', 'PT BANK PERMATA TBK'],
        'BANK PERMATA' => ['BBBAIDJA', 'PT BANK PERMATA TBK'],
        'BTN' => ['BTANIDJA', 'PT BANK TABUNGAN NEGARA (PERSERO) TBK'],
        'BANK BTN' => ['BTANIDJA', 'PT BANK TABUNGAN NEGARA (PERSERO) TBK'],
        'BANK TABUNGAN NEGARA' => ['BTANIDJA', 'PT BANK TABUNGAN NEGARA (PERSERO) TBK'],
        'PANIN' => ['PINBIDJA', 'PT BANK PAN INDONESIA TBK'],
        'BANK PANIN' => ['PINBIDJA', 'PT BANK PAN INDONESIA TBK'],
        'OCBC NISP' => ['NISPIDJA', 'PT BANK OCBC NISP TBK'],
        'BANK OCBC NISP' => ['NISPIDJA', 'PT BANK OCBC NISP TBK'],
        'MAYBANK' => ['MABORIDJ', 'PT BANK MAYBANK INDONESIA TBK'],
        'BANK MAYBANK' => ['MABORIDJ', 'PT BANK MAYBANK INDONESIA TBK'],
        'MEGA' => ['MEGAIDJA', 'PT BANK MEGA TBK'],
        'BANK MEGA' => ['MEGAIDJA', 'PT BANK MEGA TBK'],
        'SINARMAS' => ['SABORIDJ', 'PT BANK SINARMAS TBK'],
        'BANK SINARMAS' => ['SABORIDJ', 'PT BANK SINARMAS TBK'],
        'BUKOPIN' => ['BBUKIDJA', 'PT BANK KB BUKOPIN TBK'],
        'BANK BUKOPIN' => ['BBUKIDJA', 'PT BANK KB BUKOPIN TBK'],
        'MUAMALAT' => ['MUABIDJA', 'PT BANK MUAMALAT INDONESIA TBK'],
        'BANK MUAMALAT' => ['MUABIDJA', 'PT BANK MUAMALAT INDONESIA TBK'],
        'JAGO' => ['ABORIDJA', 'PT BANK JAGO TBK'],
        'BANK JAGO' => ['ABORIDJA', 'PT BANK JAGO TBK'],
        'BSI' => ['BSMDIDJA', 'PT BANK SYARIAH INDONESIA TBK'],
        'BANK SYARIAH INDONESIA' => ['BSMDIDJA', 'PT BANK SYARIAH INDONESIA TBK'],
        'JENIUS' => ['BTPNIDJA', 'PT BANK BTPN TBK'],
        'BTPN' => ['BTPNIDJA', 'PT BANK BTPN TBK'],
        'BANK BTPN' => ['BTPNIDJA', 'PT BANK BTPN TBK'],
        'DKI' => ['BDKIIDJA', 'PT BANK DKI'],
        'BANK DKI' => ['BDKIIDJA', 'PT BANK DKI'],
        'JATIM' => ['PDJTIDJ1', 'PT BPD JAWA TIMUR TBK'],
        'BANK JATIM' => ['PDJTIDJ1', 'PT BPD JAWA TIMUR TBK'],
    ];

    /**
     * Resolve a PaymentGroup.bank_name to its transfer metadata.
     *
     * @return array{transfer_type: string, sandi_bic: string, bank_name: string}|null
     *                                                                                 null when the bank_name cannot be resolved
     */
    public static function resolve(string $bankName): ?array
    {
        $normalized = strtoupper(trim($bankName));

        // Strip common parenthetical branch suffixes like "BCA (KCU Karawang)"
        $normalized = preg_replace('/\s*\(.*\)$/', '', $normalized) ?: $normalized;

        if (! isset(self::MAPPINGS[$normalized])) {
            return null;
        }

        [$sandiBic, $canonicalName] = self::MAPPINGS[$normalized];

        $isBca = str_starts_with($normalized, 'BCA')
            || str_starts_with($normalized, 'BANK BCA')
            || $normalized === 'BANK CENTRAL ASIA'
            || str_starts_with($normalized, 'PT BANK CENTRAL ASIA');

        return [
            'transfer_type' => $isBca ? self::TRANSFER_TYPE_BCA : self::TRANSFER_TYPE_LLG,
            'sandi_bic' => $sandiBic,
            'bank_name' => $canonicalName,
        ];
    }

    /**
     * Validate that every PaymentGroup in a batch collection has a resolvable bank.
     *
     * @param  Collection  $batches  Collection<PaymentBatch>
     * @return list<array{batch_number: string, group_id: int, bank_name: string}>
     *                                                                             List of unresolvable groups (empty if all resolve)
     */
    public static function validateBatches(Collection $batches): array
    {
        $failures = [];

        foreach ($batches as $batch) {
            foreach ($batch->groups as $group) {
                if ($group->status === PaymentGroup::STATUS_CANCELLED) {
                    continue;
                }

                $hasActiveSupplierItem = $group->items->contains(
                    fn ($item) => $item->status === PaymentItem::STATUS_ACTIVE
                        && $item->payable_type === LocalInvoice::class
                );

                if (! $hasActiveSupplierItem) {
                    continue;
                }

                if (self::resolve((string) $group->bank_name) === null) {
                    $failures[] = [
                        'batch_number' => $batch->batch_number,
                        'group_id' => $group->id,
                        'bank_name' => $group->bank_name,
                    ];
                }
            }
        }

        return $failures;
    }
}
