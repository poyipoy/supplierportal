<?php

/**
 * DB-free file-boundary proof for the column designer. Run from the repo root:
 *   php tests/Support/export-column-output-proof.php baseline
 *   php tests/Support/export-column-output-proof.php final
 *
 * This deliberately does not exercise HTTP, query selection, presets or queues.
 * It supplies unsaved real Eloquent models at the same Sheet::appendRows seam
 * used by Laravel Excel's query chunks, then uses the installed native Writer.
 * Literal goldens extend AdvancedExportBaselineTest's existing fixtures.
 */

use App\Exports\LocalInvoicesExport;
use App\Models\ExchangeRate;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceReceipt;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\QcItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Export\ExportDefinitions;
use App\Support\Export\ExportOptions;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Files\LocalTemporaryFile;
use Maatwebsite\Excel\Writer;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$phase = $argv[1] ?? '';
if (! in_array($phase, ['baseline', 'final'], true)) {
    fwrite(STDERR, "Usage: php tests/Support/export-column-output-proof.php baseline|final\n");
    exit(2);
}

// Process-local environment only; never edit .env or use a live PDO connection.
foreach (['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_DATABASE' => 'adasi_portal_test', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}

$queryAttempts = 0;
$pdoAttempts = 0;
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    throw new RuntimeException('Refusing cached configuration for the isolated output proof.');
}
$app->afterBootstrapping(RegisterProviders::class, function ($app) use (&$queryAttempts, &$pdoAttempts): void {
    // A grammar-only connection supports normal Eloquent date casts. Every SQL
    // execution and every PDO acquisition fails before a network connection.
    $app['db']->extend('export-proof-denied', function (array $config) use (&$queryAttempts, &$pdoAttempts) {
        $connection = new MySqlConnection(function () use (&$pdoAttempts) {
            $pdoAttempts++;
            throw new RuntimeException('OUTPUT_PROOF_PDO_DENIED');
        }, 'export_column_output_test', '', $config);
        $connection->beforeExecuting(function () use (&$queryAttempts): void {
            $queryAttempts++;
            throw new RuntimeException('OUTPUT_PROOF_SQL_DENIED');
        });

        return $connection;
    });
    foreach (array_keys($app['config']->get('database.connections')) as $name) {
        $app['config']->set('database.connections.'.$name.'.driver', 'export-proof-denied');
        $app['config']->set('database.connections.'.$name.'.url', null);
        $app['db']->purge($name);
    }
});
$app->make(Kernel::class)->bootstrap();
config(['app.timezone' => 'UTC', 'app.business_timezone' => 'Asia/Jakarta', 'excel.cache.driver' => 'memory']);
Carbon::setTestNow(Carbon::parse('2026-08-18 10:00:00', 'UTC'));

try {
    $app['db']->connection()->select('SELECT 1');
    throw new RuntimeException('SQL-denial canary unexpectedly executed.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'OUTPUT_PROOF_SQL_DENIED') {
        throw $exception;
    }
}

function proofAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function proofModel(string $class, array $attributes): Model
{
    return (new $class)->setRawAttributes($attributes);
}

function proofFixtures(): array
{
    $supplier = proofModel(User::class, ['id' => 901, 'role' => 'supplier', 'name' => 'Baseline Supplier']);
    $supplier->setRelation('supplier', proofModel(Supplier::class, ['user_id' => 901, 'company_name' => 'Baseline Company']));
    $period = proofModel(Period::class, ['id' => 901, 'name' => 'Baseline Period', 'month' => 8, 'year' => 2026]);
    $pr = proofModel(PurchaseRequisition::class, ['id' => 901, 'pr_number' => 'REQ/08/2026/901', 'status' => 'bidding', 'created_at' => '2026-08-15 18:30:00']);
    $pr->setRelation('period', $period);
    $item = proofModel(PrItem::class, ['id' => 901, 'pr_id' => 901, 'hs_code' => '7209.16.00', 'material_name' => '=Baseline Steel é, "quoted"', 'quantity' => 2, 'shape' => 'Flat', 'thickness' => 2.5, 'width' => 1000, 'length' => 2000, 'weight_needed' => 100, 'remark' => '+Baseline remark é, "quoted"']);
    $item->setRelation('purchaseRequisition', $pr);
    $pr->setRelation('items', $item->newCollection([$item]));
    $rate = proofModel(ExchangeRate::class, ['id' => 901, 'currency' => 'USD', 'rate_to_idr' => 16000]);
    $quotation = proofModel(Quotation::class, ['id' => 901, 'pr_id' => 901, 'supplier_id' => 901, 'currency' => 'USD', 'status' => 'submitted', 'submitted_at' => '2026-08-15 18:30:00']);
    $quotation->setRelation('purchaseRequisition', $pr)->setRelation('supplier', $supplier)->setRelation('exchange_rate', $rate);
    $quoteItem = proofModel(QuotationItem::class, ['id' => 901, 'quotation_id' => 901, 'pr_item_id' => 901, 'price_per_kg' => 2.5, 'amount' => 500, 'is_available' => true, 'available_qty' => 2, 'available_thickness' => 2.5, 'available_width' => 1000, 'available_length' => 2000, 'offered_weight_per_unit' => 100, 'offered_weight_source' => 'supplier', 'notes' => '@Baseline note é, "quoted"']);
    $quoteItem->setRelation('prItem', $item)->setRelation('quotation', $quotation);
    $quotation->setRelation('items', $quoteItem->newCollection([$quoteItem]));
    $po = proofModel(PurchaseOrder::class, ['id' => 901, 'supplier_id' => 901, 'currency' => 'USD', 'po_number' => 'PO/08/2026/901', 'status' => 'active', 'estimated_arrival' => '2026-08-31', 'notes' => '-Baseline PO note é, "quoted"', 'port_of_discharge' => 'TANJUNG_PRIOK', 'incoterm' => 'CIF']);
    $po->setRelation('supplier', $supplier)->setRelation('quotations', $quotation->newCollection([$quotation]))->setRelation('awards', $po->newCollection());
    $inspection = proofModel(QcInspection::class, ['id' => 901, 'po_id' => 901, 'status' => 'ok', 'inspected_at' => '2026-08-15 18:30:00']);
    $inspection->setRelation('purchaseOrder', $po);
    $qcItem = proofModel(QcItem::class, ['id' => 901, 'actual_thickness' => 2.5, 'actual_width' => 1000, 'actual_length' => 2000, 'status' => 'ok']);
    $qcItem->setRelation('prItem', $item)->setRelation('inspection', $inspection);
    $shipment = proofModel(Shipment::class, ['id' => 901, 'shipment_number' => 'SHP/08/2026/901', 'status' => 'draft', 'shipment_date' => '2026-08-17', 'estimated_arrival_date' => '2026-08-31', 'notes' => '=Baseline shipment é, "quoted"']);
    $shipmentItem = proofModel(ShipmentItem::class, ['id' => 901, 'shipped_qty' => 2, 'actual_weight_kg' => '123.4500']);
    $shipmentItem->setRelation('purchaseOrder', $po);
    $shipment->setRelation('supplier', $supplier)->setRelation('items', $shipmentItem->newCollection([$shipmentItem]));
    $invoice = proofModel(LocalInvoice::class, ['id' => 901, 'submission_number' => 'LSI-2026-00901', 'invoice_number' => '@INV é, "quoted"', 'po_number' => 'LOCAL-PO-901', 'currency' => 'IDR', 'invoice_amount' => '100000.25', 'tax_amount' => '11000.03', 'tax_invoice_number' => '010.000-26.12345678', 'status' => LocalInvoice::STATUS_READY_TO_PAY, 'submitted_at' => '2026-08-15 18:30:00', 'approved_at' => '2026-08-16 18:30:00', 'payment_term_days_snapshot' => 30, 'due_date' => '2026-09-15', 'scheduled_payment_date' => '2026-09-16']);
    $invoice->setRelation('supplier', $supplier)->setRelation('receipt', proofModel(LocalInvoiceReceipt::class, ['receipt_number' => 'RCP-BASELINE']));

    return ['po' => $po, 'quotation' => $quoteItem, 'pr' => $item, 'shipment' => $shipment, 'qc' => $qcItem, 'invoice' => $invoice];
}

function proofKeys(): array
{
    return [
        'po' => ['po_number', 'pr_number', 'supplier', 'material', 'currency', 'total_amount', 'total_idr', 'est_arrival', 'remark', 'status', 'port_of_discharge', 'incoterm'],
        'quotation' => ['pr_number', 'period', 'supplier', 'currency', 'material', 'hs_code', 'requested_quantity', 'requested_dimensions', 'offered_quantity', 'offered_dimensions', 'price_per_kg', 'amount', 'exchange_rate', 'total_idr', 'item_notes', 'status', 'submitted_at', 'availability', 'offered_length', 'offer_weight_unit', 'offer_weight_source', 'offer_total_weight', 'requested_amount', 'offer_amount'],
        'pr' => ['pr_number', 'period', 'material_name', 'specification', 'qty', 'weight_unit', 'total_weight', 'pr_total_kg', 'remark', 'status', 'date_created'],
        'shipment' => ['shipment_number', 'supplier', 'consolidated_pos', 'items_count', 'total_qty', 'actual_weight_kg', 'shipment_date', 'est_arrival_date', 'actual_arrival_date', 'status', 'notes_remarks'],
        'qc' => ['po_number', 'supplier', 'material', 'requested_specification', 'actual_dimensions', 'item_status', 'inspection_status', 'inspection_date'],
        'invoice' => ['submission', 'receipt', 'invoice', 'tax_invoice_number', 'po_reference', 'supplier', 'currency', 'invoice_amount', 'ppn', 'status', 'submitted', 'approved', 'payment_term_days', 'due_date', 'scheduled_payment', 'completed'],
    ];
}

function proofHeadings(string $kind, string $locale): array
{
    $goldens = [
        'en' => [
            'po' => ['PO Number', 'PR Number', 'Supplier', 'Material', 'Currency', 'Total Amount', 'Total IDR', 'Est. Arrival', 'Remark', 'Status', 'Port of Discharge', 'Incoterms'],
            'quotation' => ['PR Number', 'Period', 'Supplier', 'Currency', 'Material', 'HS Code', 'Requested Quantity', 'Requested Dimensions', 'Offered Quantity', 'Offered Dimensions', 'Price per Kg', 'Amount', 'Exchange Rate', 'Total IDR', 'Item Notes', 'Status', 'Submitted At (WIB)', 'Availability', 'Offered Length', 'Offer Weight/Unit', 'Offer Weight Source', 'Offer Total Weight', 'Requested Amount', 'Offer Amount'],
            'pr' => ['PR Number', 'Period', 'Material Name', 'Specification', 'Qty', 'Weight/Unit', 'Total Weight', 'PR Total KG', 'Remark', 'Status', 'Date Created (WIB)'],
            'shipment' => ['Shipment Number', 'Supplier', 'Consolidated POs', 'Items Count', 'Total Qty', 'Actual Weight (Kg)', 'Shipment Date', 'Est. Arrival Date', 'Actual Arrival Date', 'Status', 'Notes / Remarks'],
            'qc' => ['PO Number', 'Supplier', 'Material', 'Requested Specification', 'Actual Dimensions', 'Item Status', 'Inspection Status', 'Inspection Date (WIB)'],
            'invoice' => ['Submission', 'Receipt', 'Invoice', 'Tax Invoice Number', 'PO Reference', 'Supplier', 'Currency', 'Invoice Amount', 'PPN', 'Status', 'Submitted (WIB)', 'Approved (WIB)', 'Payment Term Days', 'Due Date', 'Scheduled Payment', 'Completed (WIB)'],
        ],
        'id' => [
            'po' => ['Nomor PO', 'Nomor PR', 'Supplier', 'Material', 'Mata Uang', 'Total Nilai', 'Total IDR', 'Est. Kedatangan', 'Catatan', 'Status', 'Port of Discharge', 'Incoterms'],
            'quotation' => ['Nomor PR', 'Periode', 'Supplier', 'Mata Uang', 'Material', 'Kode HS', 'Jumlah Permintaan', 'Dimensi Permintaan', 'Jumlah Penawaran', 'Dimensi Penawaran', 'Harga per Kg', 'Nilai', 'Kurs', 'Total IDR', 'Catatan Item', 'Status', 'Diajukan Pada (WIB)', 'Ketersediaan', 'Panjang Penawaran', 'Berat Penawaran/Unit', 'Sumber Berat Penawaran', 'Total Berat Penawaran', 'Nilai Permintaan', 'Nilai Penawaran'],
            'pr' => ['Nomor PR', 'Periode', 'Nama Material', 'Spesifikasi', 'Jumlah', 'Berat/Unit', 'Total Berat', 'Total KG PR', 'Catatan', 'Status', 'Tanggal Dibuat (WIB)'],
            'shipment' => ['Nomor Pengiriman', 'Supplier', 'PO Terkonsolidasi', 'Jumlah Item', 'Total Jumlah', 'Berat Aktual (Kg)', 'Tanggal Pengiriman', 'Est. Tanggal Kedatangan', 'Tanggal Kedatangan Aktual', 'Status', 'Catatan'],
            'qc' => ['Nomor PO', 'Supplier', 'Material', 'Spesifikasi Permintaan', 'Dimensi Aktual', 'Status Item', 'Status Inspeksi', 'Tanggal Inspeksi (WIB)'],
            'invoice' => ['Pengajuan', 'Tanda Terima', 'Invoice', 'Nomor Faktur Pajak', 'Referensi PO', 'Supplier', 'Mata Uang', 'Nilai Invoice', 'PPN', 'Status', 'Diajukan (WIB)', 'Disetujui (WIB)', 'Termin Pembayaran (Hari)', 'Jatuh Tempo', 'Jadwal Pembayaran', 'Selesai (WIB)'],
        ],
    ];

    return array_combine(proofKeys()[$kind], $goldens[$locale][$kind]);
}

function proofValues(string $kind, string $locale): array
{
    $english = $locale === 'en';
    $values = match ($kind) {
        'po' => ['PO/08/2026/901', 'REQ/08/2026/901', 'Baseline Supplier', "'=Baseline Steel é, \"quoted\"", 'USD', 500.0, 8000000.0, '2026-08-31', "'-Baseline PO note é, \"quoted\"", $english ? 'Active' : 'Aktif', 'Tanjung Priok', 'CIF — Cost, Insurance and Freight'],
        'quotation' => ['REQ/08/2026/901', 'Baseline Period (08/2026)', 'Baseline Company', 'USD', "'=Baseline Steel é, \"quoted\"", '7209.16.00', 2, '2.5 × 1000 × 2000', 2, '2.5 × 1000 × 2000', 2.5, 500.0, 16000.0, 8000000.0, "'@Baseline note é, \"quoted\"", $english ? 'Submitted' : 'Diajukan', '2026-08-16 01:30:00', $english ? 'Available' : 'Tersedia', '2000', 100.0, 'supplier', 200.0, 500.0, 500.0],
        'pr' => ['REQ/08/2026/901', 'Baseline Period (08/2026)', "'=Baseline Steel é, \"quoted\"", 'Flat | 2.5 × 1000 × 2000', 2, 100.0, 200.0, 200.0, "'+Baseline remark é, \"quoted\"", $english ? 'Bidding' : 'Penawaran Berlangsung', '2026-08-16 01:30:00'],
        'shipment' => ['SHP/08/2026/901', 'Baseline Supplier', 'PO/08/2026/901', 1, 2, '123.45', '2026-08-17', '2026-08-31', '-', $english ? 'Draft' : 'Draf', "'=Baseline shipment é, \"quoted\""],
        'qc' => ['PO/08/2026/901', 'Baseline Supplier', "'=Baseline Steel é, \"quoted\"", 'Flat | 2.5 × 1000 × 2000', 'T:2.5000 | W:1000.0000 | L:2000.0000', 'OK', 'OK', '16/08/2026 01:30'],
        'invoice' => ['LSI-2026-00901', 'RCP-BASELINE', "'@INV é, \"quoted\"", '010.000-26.12345678', 'LOCAL-PO-901', 'Baseline Company', 'IDR', '100000.25', '11000.03', $english ? 'Ready to Pay' : 'Siap Dibayar', '2026-08-16', '2026-08-17', '30', '2026-09-15', '2026-09-16', '-'],
    };

    return array_combine(proofKeys()[$kind], $values);
}

function proofRead(string $path, string $format): array
{
    if ($format === 'csv') {
        $bytes = file_get_contents($path);
        proofAssert(str_starts_with($bytes, "\xEF\xBB\xBF"), 'CSV lacks UTF-8 BOM.');
        proofAssert(substr_count($bytes, "\xEF\xBB\xBF") === 1, 'CSV has repeated BOM.');
        $stream = fopen($path, 'rb');
        fseek($stream, 3);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
    $book = IOFactory::load($path);
    try {
        proofAssert($book->getSheetCount() === 1, 'Unexpected extra worksheet.');
        $sheet = $book->getActiveSheet();
        foreach ($sheet->getCoordinates() as $coordinate) {
            proofAssert($sheet->getCell($coordinate)->getDataType() !== DataType::TYPE_FORMULA, 'Formula cell found: '.$coordinate);
        }

        return $sheet->toArray(null, false, false);
    } finally {
        $book->disconnectWorksheets();
    }
}

$outputRoot = dirname(__DIR__, 2).'/UI-REDESIGN-RESULT/export-column-designer';
$outputDir = $outputRoot.'/backend-output-'.$phase;
if (! is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}
$report = ['status' => 'PASS', 'phase' => $phase, 'command' => 'php tests/Support/export-column-output-proof.php '.$phase, 'php' => PHP_VERSION, 'scope' => 'Real models/catalog/map/headings and installed Maatwebsite Sheet/Writer; no query retrieval, HTTP, queue, preset persistence or browser bridge.', 'sql_denial_canary' => 'PASS', 'cases' => [], 'catalog_rejections' => [], 'unchanged_backend' => []];
$families = ['purchasing.po' => 'po', 'supplier.po' => 'po', 'purchasing.quotations' => 'quotation', 'supplier.quotations' => 'quotation', 'purchasing.pr' => 'pr', 'purchasing.shipments' => 'shipment', 'qc.inspections' => 'qc', 'finance.local-invoices' => 'invoice', 'accounting.local-invoices' => 'invoice'];
$subsets = ['po' => ['remark', 'po_number', 'currency'], 'quotation' => ['item_notes', 'pr_number', 'material'], 'pr' => ['remark', 'pr_number', 'material_name'], 'shipment' => ['notes_remarks', 'shipment_number', 'supplier'], 'qc' => ['material', 'po_number', 'inspection_date'], 'invoice' => ['invoice_amount', 'submission', 'invoice', 'tax_invoice_number']];
$fixtures = proofFixtures();

try {
    $baseline = json_decode(ltrim(file_get_contents($outputRoot.'/backend-baseline.json'), "\xEF\xBB\xBF"), true, flags: JSON_THROW_ON_ERROR);
    foreach ($baseline['files'] as $entry) {
        // This approved test file now adds the HTTP-to-file bridge; all runtime
        // backend/catalog files must still match the captured baseline exactly.
        if ($entry['path'] === 'tests/Feature/AdvancedPurchaseOrderExportTest.php') {
            continue;
        }
        proofAssert(hash_file('sha256', dirname(__DIR__, 2).'/'.$entry['path']) === $entry['sha256'], 'Baseline file changed: '.$entry['path']);
        $report['unchanged_backend'][] = $entry['path'];
    }
    foreach ($families as $key => $kind) {
        $definition = ExportDefinitions::get($key);
        $allKeys = array_map(fn ($column) => $column->key, ExportDefinitions::allowedColumns($definition));
        proofAssert($allKeys === proofKeys()[$kind], 'Catalog drift: '.$key);
        $defaultKeys = ExportDefinitions::defaultKeys($definition);
        proofAssert($defaultKeys === ($kind === 'po' ? array_slice($allKeys, 0, 10) : $allKeys), 'Default catalog drift: '.$key);
        foreach ([[], ['internal_margin', $allKeys[0]], [$allKeys[0], $allKeys[0]], [$allKeys[1]]] as $invalid) {
            try {
                ExportDefinitions::sanitizeKeys($definition, $invalid);
                throw new RuntimeException('Invalid keys accepted: '.$key);
            } catch (InvalidArgumentException) {
                $report['catalog_rejections'][] = ['key' => $key, 'columns' => $invalid, 'status' => 'PASS'];
            }
        }
        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);
            foreach (['default' => $defaultKeys, 'reverse-all' => array_reverse($allKeys), 'subset' => $subsets[$kind]] as $profile => $keys) {
                $heads = proofHeadings($kind, $locale);
                $values = proofValues($kind, $locale);
                $expected = [array_map(fn ($column) => $heads[$column], $keys), array_map(fn ($column) => $values[$column], $keys)];
                foreach (['xlsx' => Excel::XLSX, 'csv' => Excel::CSV] as $format => $writerType) {
                    $class = $definition->exportClass();
                    $export = $class === LocalInvoicesExport::class ? new $class(901) : new $class;
                    $export->setExportLocale($locale);
                    $export->applyOptions(new ExportOptions($keys, $format, explode('.', $key)[0]));
                    // Warm mapping/metadata before serializing the actual export.
                    proofAssert($export->headings() === $expected[0], 'Heading golden mismatch: '.$key);
                    proofAssert($export->map($fixtures[$kind]) === $expected[1], 'Mapped golden mismatch: '.$key);
                    $export = unserialize(serialize($export));
                    proofAssert($export->preferredLocale() === $locale, 'Export locale did not survive serialization.');
                    $file = str_replace('.', '-', $key).'-'.$locale.'-'.$profile.'.'.$format;
                    $path = $outputDir.'/'.$file;
                    $writer = app(Writer::class);
                    $writer->open($export);
                    $sheet = $writer->addNewSheet();
                    $sheet->open($export);
                    $sheet->appendRows([$fixtures[$kind]], $export);
                    $sheet->close($export);
                    $writer->write($export, new LocalTemporaryFile($path), $writerType);
                    $actual = proofRead($path, $format);
                    // Native XLSX binders may store numeric strings as numbers;
                    // compare their exact scalar text, never formatted display.
                    $wireExpected = array_map(fn ($row) => array_map(fn ($value) => (string) $value, $row), $expected);
                    $actual = array_map(fn ($row) => array_map(fn ($value) => (string) $value, $row), $actual);
                    proofAssert($actual === $wireExpected, 'Parsed file mismatch: '.$file.' '.json_encode(['expected' => $wireExpected, 'actual' => $actual], JSON_UNESCAPED_UNICODE));
                    $report['cases'][] = ['status' => 'PASS', 'key' => $key, 'locale' => $locale, 'format' => $format, 'profile' => $profile, 'columns' => $keys, 'file' => $file, 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path), 'headings' => $actual[0], 'values' => $actual[1]];
                }
            }
        }
    }
    proofAssert($queryAttempts === 1 && $pdoAttempts === 0, 'Output proof attempted SQL/PDO beyond the denial canary.');
    proofAssert(count($report['cases']) === 108, 'Output matrix incomplete.');
} catch (Throwable $exception) {
    $report['status'] = 'FAIL';
    $report['error'] = get_class($exception).': '.$exception->getMessage();
}
$report['query_attempts_excluding_canary'] = $queryAttempts - 1;
$report['pdo_attempts'] = $pdoAttempts;
$report['case_count'] = count($report['cases']);
$report['helper_sha256'] = hash_file('sha256', __FILE__);
file_put_contents($outputDir.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
Carbon::setTestNow();
echo $report['status'].' '.$report['case_count'].'/108 files; SQL='.$report['query_attempts_excluding_canary'].' PDO='.$pdoAttempts.'; report='.$outputDir.'/report.json'.PHP_EOL;
if (isset($report['error'])) {
    fwrite(STDERR, $report['error'].PHP_EOL);
}
exit($report['status'] === 'PASS' ? 0 : 1);
