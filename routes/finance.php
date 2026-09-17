<?php

use App\Http\Controllers\Finance\FinanceDashboardController;
use App\Http\Controllers\Finance\FinanceDrpController;
use App\Http\Controllers\Finance\FinanceDrpPaidController;
use App\Http\Controllers\Finance\FinanceInvoiceController;
use App\Http\Controllers\Finance\FinanceVendorController;
use App\Http\Controllers\Finance\LocalInvoiceSettlementController;
use App\Http\Controllers\Finance\LocalProcurementController;
use App\Http\Controllers\LocalInvoiceReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:finance,admin'])->prefix('finance')->name('finance.')->group(function () {
    // Dashboard & Forecast
    Route::get('/dashboard', [FinanceDashboardController::class, 'dashboard'])->name('dashboard');
    Route::get('/forecast', [FinanceDashboardController::class, 'forecast'])->name('forecast');

    // Invoice Register & Verification
    Route::get('/invoices', [FinanceInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [FinanceInvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/receipt', [LocalInvoiceReceiptController::class, 'show'])->name('invoices.receipt');

    // Cashier Physical Receipt
    Route::post('/invoices/{invoice}/receive-physical', [FinanceInvoiceController::class, 'receivePhysical'])->name('invoices.receive-physical');

    // Section A & Section B Verification
    Route::post('/invoices/{invoice}/verify-section-a', [FinanceInvoiceController::class, 'verifySectionA'])->name('invoices.verify-section-a');
    Route::post('/invoices/{invoice}/verify-section-b', [FinanceInvoiceController::class, 'verifySectionB'])->name('invoices.verify-section-b');
    Route::post('/invoices/{invoice}/approve-ready-to-pay', [FinanceInvoiceController::class, 'approveReadyToPay'])->name('invoices.approve-ready-to-pay');
    Route::post('/invoices/{invoice}/request-revision', [FinanceInvoiceController::class, 'requestRevision'])->name('invoices.request-revision');
    Route::post('/invoices/{invoice}/reject', [FinanceInvoiceController::class, 'reject'])->name('invoices.reject');

    // DRP Supplier
    Route::get('/drp-supplier', [FinanceDrpController::class, 'indexSupplier'])->name('drp.supplier');
    Route::post('/drp-supplier', [FinanceDrpController::class, 'createSupplierBatch'])->name('drp.supplier.create');

    // DRP GA
    Route::get('/drp-ga', [FinanceDrpController::class, 'indexGa'])->name('drp.ga');

    // DRP Paid
    Route::get('/drp-paid', [FinanceDrpPaidController::class, 'index'])->name('drp.paid.index');
    Route::post('/drp-paid/{batch}/mark-paid', [FinanceDrpPaidController::class, 'markBatchPaid'])->name('drp.paid.mark-paid');

    // GA Claims Register & Verification
    Route::get('/ga-claims', [\App\Http\Controllers\Finance\FinanceGaClaimController::class, 'index'])->name('ga-claims.index');
    Route::get('/ga-claims/{claim}', [\App\Http\Controllers\Finance\FinanceGaClaimController::class, 'show'])->name('ga-claims.show');
    Route::post('/ga-claims/{claim}/verify', [\App\Http\Controllers\Finance\FinanceGaClaimController::class, 'verify'])->name('ga-claims.verify');

    // DRP Batch Detail, Finalize, Item Removal, Fee Override, Voucher, Pay
    Route::get('/drp/{batch}', [FinanceDrpController::class, 'show'])->name('drp.show');
    Route::post('/drp/{batch}/finalize', [FinanceDrpController::class, 'finalize'])->name('drp.finalize');
    Route::post('/drp/{batch}/cancel', [FinanceDrpController::class, 'cancelBatch'])->name('drp.cancel');
    Route::post('/drp-items/{item}/remove', [FinanceDrpController::class, 'removeItem'])->name('drp.remove-item');
    Route::post('/drp-groups/{group}/fee-override', [FinanceDrpController::class, 'overrideFee'])->name('drp.override-fee');
    Route::post('/drp-groups/{group}/voucher', [FinanceDrpController::class, 'assignVoucher'])->name('drp.assign-voucher');
    Route::post('/drp-groups/{group}/pay', [FinanceDrpController::class, 'markPaid'])->name('drp.mark-paid');

    // Authoritative Local PO / whole-GR master (no delete routes)
    Route::prefix('local-procurement')->name('local-procurement.')->group(function () {
        Route::get('/', [LocalProcurementController::class, 'index'])->name('index');
        Route::get('/create', [LocalProcurementController::class, 'create'])->name('create');
        Route::post('/', [LocalProcurementController::class, 'store'])->name('store');
        Route::get('/import/template', [LocalProcurementController::class, 'template'])->name('import.template');
        Route::post('/import/preview', [LocalProcurementController::class, 'preview'])->name('import.preview');
        Route::post('/import/confirm', [LocalProcurementController::class, 'confirm'])->name('import.confirm');
        Route::get('/{purchaseOrder}', [LocalProcurementController::class, 'show'])->name('show');
        Route::get('/{purchaseOrder}/edit', [LocalProcurementController::class, 'edit'])->name('edit');
        Route::put('/{purchaseOrder}', [LocalProcurementController::class, 'update'])->name('update');
        Route::post('/{purchaseOrder}/close', [LocalProcurementController::class, 'close'])->name('close');
        Route::post('/{purchaseOrder}/cancel', [LocalProcurementController::class, 'cancel'])->name('cancel');
        Route::post('/{purchaseOrder}/goods-receipts', [LocalProcurementController::class, 'storeGoodsReceipt'])->name('goods-receipts.store');
        Route::put('/goods-receipts/{goodsReceipt}', [LocalProcurementController::class, 'updateGoodsReceipt'])->name('goods-receipts.update');
        Route::post('/goods-receipts/{goodsReceipt}/cancel', [LocalProcurementController::class, 'cancelGoodsReceipt'])->name('goods-receipts.cancel');
    });

    // One invoice -> one Voucher Bayar -> one settlement; corrections are separate transfers.
    Route::post('/drp-items/{item}/voucher', [LocalInvoiceSettlementController::class, 'finalizeVoucher'])->name('vouchers.finalize');
    Route::post('/drp-items/{item}/voucher/generate', [LocalInvoiceSettlementController::class, 'generateVoucher'])->name('vouchers.generate');
    Route::get('/vouchers/{voucher}', [LocalInvoiceSettlementController::class, 'showVoucher'])->name('vouchers.show');
    Route::get('/vouchers/{voucher}/print', [LocalInvoiceSettlementController::class, 'printVoucher'])->name('vouchers.print');
    Route::post('/vouchers/{voucher}/payments', [LocalInvoiceSettlementController::class, 'primaryPayment'])->name('settlements.primary');
    Route::post('/settlements/{payment}/corrections', [LocalInvoiceSettlementController::class, 'correction'])->name('settlements.correction');
    Route::get('/supplier-overpayments', [LocalInvoiceSettlementController::class, 'overpayments'])->name('overpayments.index');
    Route::post('/supplier-overpayments/{refund}/settle', [LocalInvoiceSettlementController::class, 'refund'])->name('overpayments.refund');

    // Master Invoice (Reporting / Query Repository)
    Route::get('/master-invoices', [FinanceInvoiceController::class, 'masterInvoice'])->name('master-invoices');
    Route::get('/master-invoices/export', [FinanceInvoiceController::class, 'exportMasterInvoice'])->name('master-invoices.export');

    // Vendor Master & Change Approvals
    Route::get('/vendor-master', [FinanceVendorController::class, 'index'])->name('vendor-master.index');
    Route::get('/vendor-master/{vendor}', [FinanceVendorController::class, 'show'])->name('vendor-master.show');
    Route::post('/vendor-change-requests/{request}/approve', [FinanceVendorController::class, 'approveChange'])->name('vendor-change-requests.approve');
    Route::post('/vendor-change-requests/{request}/reject', [FinanceVendorController::class, 'rejectChange'])->name('vendor-change-requests.reject');
});
