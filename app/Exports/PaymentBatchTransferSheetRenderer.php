<?php

namespace App\Exports;

use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\User;
use App\Support\BankTransferMapping;
use App\Support\SpreadsheetCellSanitizer;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Renders a "TARIKAN TRANSFER" workbook with exactly ONE sheet named "Data".
 *
 * All selected PaymentBatches' groups are flattened into a single table (A:U).
 * One PaymentGroup = one transfer row.
 *
 * @see PaymentBatchTransferExport
 */
class PaymentBatchTransferSheetRenderer
{
    /**
     * ADASI's debiting account for BCA. Configurable via config.
     */
    public const DEFAULT_DEBIT_ACCOUNT = '5220310053';

    /**
     * Header row position in the template/output (Row 1 matches TARIKAN TRANSFER.xlsx).
     */
    public const HEADER_ROW = 1;

    /**
     * First data row position (Row 2 matches TARIKAN TRANSFER.xlsx).
     */
    public const DATA_START_ROW = 2;

    /**
     * Column layout A:U as specified in the implementation plan.
     */
    public const COLUMNS = [
        'A' => 'No',
        'B' => 'Transaction ID',
        'C' => 'Transfer Type',
        'D' => 'Debited Acc.',
        'E' => 'Beneficiary ID',
        'F' => 'Credited Acc.',
        'G' => 'Amount',
        'H' => 'Eff. Date',
        'I' => 'Transaction Purpose',
        'J' => 'Currency',
        'K' => 'Charges Type',
        'L' => 'Charges Acc.',
        'M' => 'Remark 1',
        'N' => 'Remark 2',
        'O' => 'Receiver Bank Cd',
        'P' => 'Receiver Bank Name',
        'Q' => 'Receiver Name',
        'R' => 'Receiver Cust. Type',
        'S' => 'Receiver Cust. Residen',
        'T' => 'Transaction Cd',
        'U' => 'Beneficiary Email',
    ];

    /**
     * Maximum length for Remark 1 / Remark 2 fields.
     * KlikBCA Bisnis (KBB) / BCA Multi-Transfer strictly limits Remarks to 18 characters.
     */
    public const MAX_REMARK_LENGTH = 18;

    /**
     * Render all batches into a single-sheet workbook.
     *
     * @param  Collection<int, PaymentBatch>  $batches  Eagerly loaded with groups→items→payable→supplier.supplier
     */
    public function render(Collection $batches): Spreadsheet
    {
        if ($batches->isEmpty()) {
            throw new RuntimeException('No batches provided for transfer export.');
        }

        $dataRows = $this->buildAllTransferRows($batches);

        if (empty($dataRows)) {
            throw new RuntimeException('No active payment groups found across selected batches for transfer export.');
        }

        $spreadsheet = new Spreadsheet;

        // Exact default font matching TARIKAN TRANSFER.xlsx
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');
        $sheet->setShowGridlines(true);

        // Remove any other default sheets
        while ($spreadsheet->getSheetCount() > 1) {
            $spreadsheet->removeSheetByIndex($spreadsheet->getSheetCount() - 1);
        }

        $this->writeHeaders($sheet);
        $this->writeDataRows($sheet, $dataRows);

        return $spreadsheet;
    }

    /**
     * Write column headers on row 1 matching TARIKAN TRANSFER.xlsx exactly:
     * Calibri 11pt bold white font on solid black fill, thin white borders, center alignment.
     */
    private function writeHeaders(Worksheet $sheet): void
    {
        $headerStyle = [
            'font' => [
                'name' => 'Calibri',
                'size' => 11,
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => false,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFFFFFFF'],
                ],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF000000'],
            ],
        ];

        foreach (self::COLUMNS as $col => $label) {
            $cell = $col.self::HEADER_ROW;
            $sheet->setCellValue($cell, $label);
        }

        $sheet->getStyle('A'.self::HEADER_ROW.':U'.self::HEADER_ROW)->applyFromArray($headerStyle);

        // Exact column widths from TARIKAN TRANSFER.xlsx
        $widths = [
            'A' => 6.55,
            'B' => 14.55,
            'C' => 13.22,
            'D' => 12.22,
            'E' => 13.44,
            'F' => 15.89,
            'G' => 11.55,
            'H' => 15.22,
            'I' => 19.22,
            'J' => 8.78,
            'K' => 12.66,
            'L' => 12.00,
            'M' => 22.89,
            'N' => 14.55,
            'O' => 16.22,
            'P' => 19.44,
            'Q' => 39.00,
            'R' => 18.55,
            'S' => 21.55,
            'T' => 14.00,
            'U' => 34.33,
        ];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    /**
     * Write data rows starting at DATA_START_ROW (Row 2).
     *
     * In TARIKAN TRANSFER.xlsx:
     * - No borders added to data rows (standard Excel gridlines)
     * - Default row height
     * - Debited Acc. & Charges Acc.: text format '@' with vertical center
     * - Credited Acc.: text format '@'
     * - Amount: pure numeric value with General format
     *
     * @param  list<array<string, mixed>>  $dataRows
     */
    private function writeDataRows(Worksheet $sheet, array $dataRows): void
    {
        // Exact Data Validation rules matching TARIKAN TRANSFER.xlsx
        // sqref M1:N1048576 covers columns M & N for all rows, current and newly added.
        $validation18 = new DataValidation;
        $validation18->setType(DataValidation::TYPE_TEXTLENGTH);
        $validation18->setOperator(DataValidation::OPERATOR_LESSTHANOREQUAL);
        $validation18->setAllowBlank(true);
        $validation18->setShowInputMessage(true);
        $validation18->setShowErrorMessage(true);
        $validation18->setPrompt('Disamakan dengan kolom Transaction ID');
        $validation18->setFormula1('18');
        $validation18->setSqref('M1:N1048576');
        $sheet->setDataValidation('M1:N1048576', $validation18);

        // sqref Q1:Q1048576 covers column Q for all rows, current and newly added.
        $validation70 = new DataValidation;
        $validation70->setType(DataValidation::TYPE_TEXTLENGTH);
        $validation70->setOperator(DataValidation::OPERATOR_LESSTHANOREQUAL);
        $validation70->setAllowBlank(true);
        $validation70->setShowInputMessage(true);
        $validation70->setShowErrorMessage(true);
        $validation70->setPrompt('Tidak lebih dari 70 karakter');
        $validation70->setFormula1('70');
        $validation70->setSqref('Q1:Q1048576');
        $sheet->setDataValidation('Q1:Q1048576', $validation70);

        foreach ($dataRows as $i => $row) {
            $r = self::DATA_START_ROW + $i;

            // A: No
            $sheet->setCellValue("A{$r}", $row['no']);

            // B: Transaction ID
            $sheet->setCellValueExplicit("B{$r}", (string) $row['transaction_id'], DataType::TYPE_STRING);

            // C: Transfer Type
            $sheet->setCellValue("C{$r}", $row['transfer_type']);

            // D: Debited Acc. — text format '@' and vertical center
            $sheet->setCellValueExplicit("D{$r}", (string) $row['debited_acc'], DataType::TYPE_STRING);
            $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode('@');
            $sheet->getStyle("D{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

            // E: Beneficiary ID (empty if no source)
            $sheet->setCellValue("E{$r}", $row['beneficiary_id'] ?? '');

            // F: Credited Acc. — text format '@' preserving leading zeros
            $sheet->setCellValueExplicit("F{$r}", (string) $row['credited_acc'], DataType::TYPE_STRING);
            $sheet->getStyle("F{$r}")->getNumberFormat()->setFormatCode('@');

            // G: Amount — numeric with General format
            $sheet->setCellValueExplicit("G{$r}", $row['amount'], DataType::TYPE_NUMERIC);

            // H: Eff. Date
            $sheet->setCellValue("H{$r}", $row['eff_date'] ?? '');

            // I: Transaction Purpose
            $sheet->setCellValue("I{$r}", $row['transaction_purpose'] ?? '');

            // J: Currency
            $sheet->setCellValue("J{$r}", 'IDR');

            // K: Charges Type
            $sheet->setCellValue("K{$r}", 'OUR');

            // L: Charges Acc. — text format '@' and vertical center
            $sheet->setCellValueExplicit("L{$r}", (string) $row['charges_acc'], DataType::TYPE_STRING);
            $sheet->getStyle("L{$r}")->getNumberFormat()->setFormatCode('@');
            $sheet->getStyle("L{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

            // M: Remark 1
            $sheet->setCellValue("M{$r}", $row['remark_1'] ?? '');

            // N: Remark 2
            $sheet->setCellValue("N{$r}", $row['remark_2'] ?? '');

            // O: Receiver Bank Cd
            $sheet->setCellValue("O{$r}", $row['receiver_bank_cd']);

            // P: Receiver Bank Name
            $sheet->setCellValue("P{$r}", $row['receiver_bank_name']);

            // Q: Receiver Name
            $sheet->setCellValue("Q{$r}", $row['receiver_name']);

            // R: Receiver Cust. Type (1 in TARIKAN TRANSFER.xlsx)
            $sheet->setCellValue("R{$r}", $row['receiver_cust_type'] ?? '1');

            // S: Receiver Cust. Residen (1 in TARIKAN TRANSFER.xlsx)
            $sheet->setCellValue("S{$r}", '1');

            // T: Transaction Cd (99 in TARIKAN TRANSFER.xlsx)
            $sheet->setCellValue("T{$r}", '99');

            // U: Beneficiary Email
            $sheet->setCellValue("U{$r}", $row['beneficiary_email'] ?? '');
        }
    }

    /**
     * Flatten all batches' active groups into ordered transfer rows.
     *
     * Ordering: PaymentBatch.created_at ASC, PaymentBatch.id ASC, PaymentGroup.id ASC
     *
     * @param  Collection<int, PaymentBatch>  $batches
     * @return list<array<string, mixed>>
     */
    public function buildAllTransferRows(Collection $batches): array
    {
        $rows = [];
        $globalNo = 1;
        $debitAccount = config('finance.transfer_debit_account', self::DEFAULT_DEBIT_ACCOUNT);

        // Batches are already ordered by created_at ASC, id ASC from the query
        foreach ($batches as $batch) {
            $groupSeq = 1;
            $activeGroups = $batch->groups
                ->filter(fn ($g) => $g->status !== PaymentGroup::STATUS_CANCELLED)
                ->sortBy('id');

            foreach ($activeGroups as $group) {
                $activeItems = $group->items
                    ->filter(fn ($item) => $item->status === PaymentItem::STATUS_ACTIVE
                        && $item->payable_type === LocalInvoice::class)
                    ->sortBy('id');

                if ($activeItems->isEmpty()) {
                    continue;
                }

                // Bank resolution
                $bankMapping = BankTransferMapping::resolve((string) $group->bank_name);
                if ($bankMapping === null) {
                    throw new RuntimeException(
                        "Cannot resolve bank mapping for batch [{$batch->batch_number}], "
                        ."group ID [{$group->id}], bank_name [{$group->bank_name}]. "
                        .'Export aborted.'
                    );
                }

                // Transaction ID: deterministic
                $batchSuffix = str_replace('DRP-', '', $batch->batch_number);
                $transactionId = sprintf('%s-%03d', $batchSuffix, $groupSeq);

                // Invoice references for Remark 1 / Remark 2
                $invoiceNumbers = [];
                foreach ($activeItems as $item) {
                    $invNum = $item->payable?->invoice_number ?? '';
                    if ($invNum !== '') {
                        $invoiceNumbers[] = $invNum;
                    }
                }
                [$remark1, $remark2] = $this->buildRemarks($invoiceNumbers);

                // Effective date: use transfer_date for paid groups, otherwise leave empty
                $effDate = '';
                if ($group->transfer_date !== null) {
                    $effDate = $group->transfer_date->format('d/m/Y');
                }

                // Supplier PIC email from the payee's supplier profile
                $supplierEmail = $this->resolveSupplierEmail($group);

                $rows[] = [
                    'no' => $globalNo,
                    'transaction_id' => SpreadsheetCellSanitizer::text($transactionId, ''),
                    'transfer_type' => $bankMapping['transfer_type'],
                    'debited_acc' => $debitAccount,
                    'beneficiary_id' => '',
                    'credited_acc' => SpreadsheetCellSanitizer::text($group->account_number, ''),
                    'amount' => (float) $group->net_payment_amount,
                    'eff_date' => $effDate,
                    'transaction_purpose' => '',
                    'charges_acc' => $debitAccount,
                    'remark_1' => SpreadsheetCellSanitizer::text($remark1, ''),
                    'remark_2' => SpreadsheetCellSanitizer::text($remark2, ''),
                    'receiver_bank_cd' => $bankMapping['sandi_bic'],
                    'receiver_bank_name' => $bankMapping['bank_name'],
                    'receiver_name' => SpreadsheetCellSanitizer::text($group->account_holder_name, ''),
                    'receiver_cust_type' => '1', // 1 in TARIKAN TRANSFER.xlsx
                    'beneficiary_email' => $supplierEmail,
                ];

                $globalNo++;
                $groupSeq++;
            }
        }

        return $rows;
    }

    /**
     * Build Remark 1 and Remark 2 from invoice numbers.
     *
     * @param  list<string>  $invoiceNumbers
     * @return array{string, string} [remark_1, remark_2]
     */
    private function buildRemarks(array $invoiceNumbers): array
    {
        if (empty($invoiceNumbers)) {
            return ['', ''];
        }

        $joined = implode(', ', $invoiceNumbers);

        // If everything fits in remark 1
        if (strlen($joined) <= self::MAX_REMARK_LENGTH) {
            return [$joined, ''];
        }

        // Split across remark 1 and remark 2
        $remark1 = '';
        $remark2 = '';
        $remaining = $invoiceNumbers;

        // Pack as many as possible into remark 1
        $first = [];
        foreach ($remaining as $idx => $inv) {
            $candidate = empty($first) ? $inv : implode(', ', array_merge($first, [$inv]));
            if (strlen($candidate) <= self::MAX_REMARK_LENGTH) {
                $first[] = $inv;
                unset($remaining[$idx]);
            } else {
                break;
            }
        }
        $remaining = array_values($remaining);

        if (empty($first) && ! empty($remaining)) {
            $inv = array_shift($remaining);
            $remark1 = substr($inv, 0, self::MAX_REMARK_LENGTH);
        } else {
            $remark1 = implode(', ', $first);
        }

        if (! empty($remaining)) {
            $remark2Text = implode(', ', $remaining);
            if (strlen($remark2Text) > self::MAX_REMARK_LENGTH) {
                $remark2 = substr($remark2Text, 0, self::MAX_REMARK_LENGTH);
            } else {
                $remark2 = $remark2Text;
            }
        }

        return [$remark1, $remark2];
    }

    /**
     * Resolve supplier PIC email from the PaymentGroup's payee.
     */
    private function resolveSupplierEmail(PaymentGroup $group): string
    {
        if ($group->payee_type !== 'supplier') {
            return '';
        }

        // payee_id references users.id
        $user = User::find($group->payee_id);
        if ($user === null) {
            return '';
        }

        $supplier = $user->supplier;

        return $supplier?->pic_email ?? '';
    }
}
