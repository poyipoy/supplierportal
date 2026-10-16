<?php

namespace App\Exports;

use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\User;
use App\Services\Payment\PaymentTransferGuard;
use App\Support\BankTransferMapping;
use App\Support\Money;
use App\Support\SpreadsheetCellSanitizer;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Renders a "TARIKAN TRANSFER" workbook with text transfer cells and three supporting sheets.
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
     * Render all batches into the Data sheet of the original transfer template.
     *
     * @param  Collection<int, PaymentBatch>  $batches  Eagerly loaded with groups→items→payable→supplier.supplier
     */
    public function render(Collection $batches, ?string $templatePath = null): Spreadsheet
    {
        if ($batches->isEmpty()) {
            throw new RuntimeException('No batches provided for transfer export.');
        }

        $dataRows = $this->buildAllTransferRows($batches);

        if (empty($dataRows)) {
            throw new RuntimeException('No active payment groups found across selected batches for transfer export.');
        }

        $templatePath ??= resource_path('templates/drp/TARIKAN TRANSFER.xlsx');
        if (! is_file($templatePath)) {
            throw new RuntimeException(__('exports.feedback.transfer_template_unavailable'));
        }
        $spreadsheet = IOFactory::load($templatePath);
        if ($spreadsheet->getSheetNames() !== ['Data', 'Legend', 'Form Responses 1', 'bank code', 'Swift Code']) {
            $spreadsheet->disconnectWorksheets();
            throw new RuntimeException(__('exports.feedback.transfer_template_unavailable'));
        }
        $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($spreadsheet->getSheetByName('Form Responses 1')));
        $sheet = $spreadsheet->getSheetByName('Data');
        if ($sheet->getHighestRow() >= self::DATA_START_ROW) {
            $sheet->removeRow(self::DATA_START_ROW, $sheet->getHighestRow() - self::HEADER_ROW);
        }
        $sheet->setShowGridlines(true);

        $this->writeHeaders($sheet);
        $this->writeDataRows($sheet, $dataRows);
        $sheet->getStyle('A'.self::HEADER_ROW.':U'.(self::HEADER_ROW + count($dataRows)))
            ->getNumberFormat()->setFormatCode('@');
        $spreadsheet->setActiveSheetIndex(0);

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
     * All transfer columns are written as explicit strings and formatted as Text.
     * Eff. Date remains blank.
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
        $validation18->setPrompt(__('exports.prompts.transfer_remark_match'));
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
        $validation70->setPrompt(__('exports.prompts.recipient_name_length'));
        $validation70->setFormula1('70');
        $validation70->setSqref('Q1:Q1048576');
        $sheet->setDataValidation('Q1:Q1048576', $validation70);

        foreach ($dataRows as $i => $row) {
            $r = self::DATA_START_ROW + $i;

            $values = [
                'A' => $row['no'],
                'B' => $row['transaction_id'],
                'C' => $row['transfer_type'],
                'D' => $row['debited_acc'],
                'E' => $row['beneficiary_id'] ?? '',
                'F' => $row['credited_acc'],
                'G' => $row['amount'],
                'H' => null,
                'I' => $row['transaction_purpose'] ?? '',
                'J' => 'IDR',
                'K' => 'OUR',
                'L' => $row['charges_acc'],
                'M' => $row['remark_1'] ?? '',
                'N' => $row['remark_2'] ?? '',
                'O' => $row['receiver_bank_cd'],
                'P' => $row['receiver_bank_name'],
                'Q' => $row['receiver_name'],
                'R' => $row['receiver_cust_type'] ?? '1',
                'S' => '1',
                'T' => '99',
                'U' => $row['beneficiary_email'] ?? '',
            ];
            foreach ($values as $column => $value) {
                $sheet->setCellValueExplicit(
                    $column.$r,
                    $value === null ? null : (string) $value,
                    $value === null ? DataType::TYPE_NULL : DataType::TYPE_STRING,
                );
            }
            foreach (['D', 'L'] as $column) {
                $sheet->getStyle($column.$r)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            }
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
        app(PaymentTransferGuard::class)->assertEligible($batches);
        $rows = [];
        $globalNo = 1;
        $debitAccount = config('finance.transfer_debit_account');
        if (empty($debitAccount)) {
            if (app()->isProduction()) {
                throw new RuntimeException('Missing required production configuration: ADASI_TRANSFER_DEBIT_ACCOUNT');
            }
            $debitAccount = self::DEFAULT_DEBIT_ACCOUNT;
        }

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
                $currentGroupSequence = $groupSeq++;

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
                $transactionId = sprintf('%s-%03d', $batchSuffix, $currentGroupSequence);

                // Invoice references for Remark 1 / Remark 2
                $invoiceNumbers = [];
                foreach ($activeItems as $item) {
                    $invNum = $item->payable?->invoice_number ?? '';
                    if ($invNum !== '') {
                        $invoiceNumbers[] = $invNum;
                    }
                }
                [$remark1, $remark2] = $this->buildRemarks($invoiceNumbers);

                // Supplier PIC email from the payee's supplier profile
                $supplierEmail = $this->resolveSupplierEmail($group);

                $rows[] = [
                    'no' => $globalNo,
                    'transaction_id' => SpreadsheetCellSanitizer::text($transactionId, ''),
                    'transfer_type' => BankTransferMapping::transferType((string) $group->bank_name, $group->subtotal_amount),
                    'debited_acc' => $debitAccount,
                    'beneficiary_id' => '',
                    'credited_acc' => SpreadsheetCellSanitizer::text($group->account_number, ''),
                    'amount' => Money::normalize($group->net_payment_amount),
                    'eff_date' => '',
                    'transaction_purpose' => '',
                    'charges_acc' => $debitAccount,
                    'remark_1' => $remark1,
                    'remark_2' => $remark2,
                    'receiver_bank_cd' => $bankMapping['sandi_bic'],
                    'receiver_bank_name' => $bankMapping['bank_name'],
                    'receiver_name' => SpreadsheetCellSanitizer::text($group->account_holder_name, ''),
                    'receiver_cust_type' => '1', // 1 in TARIKAN TRANSFER.xlsx
                    'beneficiary_email' => $supplierEmail,
                ];

                $globalNo++;
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

        // Sanitize once before measuring; explicit string cells also protect a
        // continuation that starts with a formula prefix after splitting.
        $joined = SpreadsheetCellSanitizer::text(implode(', ', $invoiceNumbers), '');
        $remark1 = mb_substr($joined, 0, self::MAX_REMARK_LENGTH, 'UTF-8');
        $remark2 = mb_substr($joined, self::MAX_REMARK_LENGTH, null, 'UTF-8');
        if (mb_strlen($remark2, 'UTF-8') > self::MAX_REMARK_LENGTH) {
            $remark2 = mb_substr($remark2, 0, self::MAX_REMARK_LENGTH - 3, 'UTF-8').'...';
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
