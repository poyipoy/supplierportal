<?php

namespace Tests\Support;

use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Synthetic Infor LN exports for the Local PO/GR import tests.
 *
 * They reproduce the layout of the real `whinh3512m600` (Receipts) and
 * `tdpur4100m000` (Purchase Orders) exports the reader was built against:
 * position-based columns, blank headers (including the GR UoM column), native
 * date cells, integer quantities and amounts, and the extra `enums` sheet that
 * Infor appends. Every name, number and amount is fictional and deterministic,
 * so the files can be rebuilt anywhere and carry no company data.
 */
final class InforExportFixtures
{
    /** Receipt lines: one receipt with six lines, 13 with two, 16 with one. */
    public const GR_ROWS = 48;

    public const GR_RECEIPTS = 30;

    public const GR_PURCHASE_ORDERS = 17;

    public const PO_ROWS = 4;

    /** One supplier per PO row (as in the real export); the first PO belongs to the first supplier. */
    public const PO_SUPPLIERS = [
        'PT SINTESIS NUSANTARA',
        'PT. CONTOH TEKNIK MANDIRI',
        'CV UJI COBA SEJAHTERA',
        'PT FIKTIF DATA PRIMA',
    ];

    public const FIRST_PO_NUMBER = 'SYNPO1001';

    private const GR_HEADER = [
        'Import Status', 'Import Code', 'Import Message', 'Company', 'Warehouse', '', 'Item', '', '',
        'Receipt', 'Line', 'Order Line', '', '', '', 'Received Quantity', '', 'Final Receipt',
        'Line Status', 'Actual Receipt Date', 'Packing Slip',
    ];

    private const PO_HEADER = [
        'Import Status', 'Import Code', 'Import Message', 'Company', 'Order', 'Buy-from Business Partner',
        '', 'Buyer', '', 'Order Date', 'Order Amount', '', 'Status', 'Workflow Status', 'Reference A',
        'Tax Classification',
    ];

    /** @var list<string> */
    private static array $created = [];

    public static function goodsReceipts(): string
    {
        $rows = [];
        $line = 0;
        for ($receipt = 1; $receipt <= self::GR_RECEIPTS; $receipt++) {
            $lines = $receipt === 1 ? 6 : ($receipt <= 14 ? 2 : 1);
            $poNumber = sprintf('SYNPO%04d', (($receipt - 1) % self::GR_PURCHASE_ORDERS) + 1);
            $date = new DateTimeImmutable(sprintf('2026-09-%02d', 2 + (($receipt - 1) % 23)));
            for ($position = 1; $position <= $lines; $position++) {
                $line++;
                $rows[] = [
                    '', '', '', 999, 'SYNWH', 'SYN-LOC', '',
                    sprintf('SYN-ITEM-%08d', $line),
                    sprintf('Synthetic part %02d', $line),
                    sprintf('SYNGR%04d', $receipt),
                    $position,
                    $poNumber,
                    10 * $position, 0, 0,
                    1 + ($line % 5),
                    'pcs', 'Yes', 'Confirmed', $date, '',
                ];
            }
        }

        return self::write('gr', self::GR_HEADER, $rows, [
            ['tcyesno', '', 'whinh.lstc', ''],
            ['value', 'description', 'value', 'description'],
            [1, 'Yes', 1, 'Open'],
            [2, 'No', 2, 'Confirmed'],
        ]);
    }

    public static function purchaseOrders(): string
    {
        $rows = [];
        foreach (self::PO_SUPPLIERS as $index => $supplier) {
            $number = $index + 1;
            $rows[] = [
                '', '', '', 999,
                sprintf('SYNPO%04d', 1000 + $number),
                sprintf('SYNBP%03d', $number),
                $supplier,
                sprintf('B%03d', $number),
                sprintf('SYN BUYER %03d', $number),
                new DateTimeImmutable(sprintf('2026-09-%02d', 15 + $number)),
                1250000 * $number,
                'IDR', 'Free', 'Approved',
                $number % 2 === 0 ? '' : sprintf('SYN-REF-%03d', $number),
                'PPN',
            ];
        }

        return self::write('po', self::PO_HEADER, $rows, [
            ['tdpur.hdst', '', 'ttocm.wfst'],
            ['value', 'description', 'value', 'description'],
            [5, 'Approved', 1, 'Approved'],
            [10, 'Free', 2, 'Not Applicable'],
        ]);
    }

    public static function cleanup(): void
    {
        foreach (self::$created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        self::$created = [];
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<mixed>>  $rows
     * @param  list<list<mixed>>  $enums
     */
    private static function write(string $kind, array $header, array $rows, array $enums): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'infor-'.$kind.'-synthetic-'.uniqid('', true).'.xlsx';
        $dateStyle = (new Style)->setFormat('yyyy-mm-dd');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('data');
        $writer->addRow(Row::fromValues($header));
        foreach ($rows as $values) {
            $writer->addRow(new Row(array_map(
                fn (mixed $value): Cell => Cell::fromValue($value, $value instanceof DateTimeImmutable ? $dateStyle : null),
                $values,
            )));
        }
        $writer->addNewSheetAndMakeItCurrent()->setName('enums');
        foreach ($enums as $values) {
            $writer->addRow(Row::fromValues($values));
        }
        $writer->close();
        self::$created[] = $path;

        return $path;
    }
}
