<?php

use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__.'/local-invoice-browser-bootstrap.php';
if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'adasi_portal_test') {
    throw new RuntimeException('Browser fixtures require the isolated test database.');
}
Notification::fake();
$supplier = User::updateOrCreate(['email' => 'po-gr-browser@example.test'], [
    'name' => 'PO GR Browser QA', 'password' => 'LocalBrowser!2026', 'role' => 'supplier',
    'is_active' => true, 'account_status' => 'ACTIVE',
]);
$supplier->supplierScopes()->firstOrCreate(['scope' => 'local']);
Supplier::updateOrCreate(['user_id' => $supplier->id], [
    'company_name' => 'PO GR Browser QA', 'category' => 'Jasa', 'vendor_category' => 'Jasa',
    'is_pkp' => false, 'payment_term_days' => 30,
]);
$operator = User::updateOrCreate(['email' => 'po-gr-finance-browser@example.test'], [
    'name' => 'PO GR Finance QA', 'password' => 'LocalBrowser!2026', 'role' => 'finance',
    'is_active' => true, 'account_status' => 'ACTIVE',
]);
$master = app(LocalProcurementMasterService::class);
$po = LocalPurchaseOrder::where('po_number', 'PO-BROWSER-REQUIRED')->first()
    ?? $master->createPurchaseOrder($operator, ['supplier_id' => $supplier->id, 'po_number' => 'PO-BROWSER-REQUIRED',
        'po_date' => BusinessTime::today()->toDateString(), 'total_amount' => '1000.00']);
if (! $po->goodsReceipts()->exists()) {
    $master->createGoodsReceipt($operator, $po, ['gr_number' => 'GR-BROWSER-REQUIRED',
        'gr_date' => BusinessTime::today()->toDateString(), 'qty' => '1', 'uom' => 'pcs']);
}
$supplier->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'locale' => 'en']);
echo "Browser account and PO/GR prepared in adasi_portal_test only.\n";
