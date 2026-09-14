<?php

use App\Http\Controllers\Finance\FinanceDashboardController;
use App\Http\Controllers\Finance\FinanceDrpController;
use App\Http\Controllers\Finance\FinanceInvoiceController;
use App\Http\Controllers\Finance\FinanceVendorController;
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

    // DRP Supplier
    Route::get('/drp-supplier', [FinanceDrpController::class, 'indexSupplier'])->name('drp.supplier');
    Route::post('/drp-supplier', [FinanceDrpController::class, 'createSupplierBatch'])->name('drp.supplier.create');

    // DRP GA
    Route::get('/drp-ga', [FinanceDrpController::class, 'indexGa'])->name('drp.ga');

    // DRP Batch Detail, Finalize, Item Removal, Fee Override, Voucher, Pay
    Route::get('/drp/{batch}', [FinanceDrpController::class, 'show'])->name('drp.show');
    Route::post('/drp/{batch}/finalize', [FinanceDrpController::class, 'finalize'])->name('drp.finalize');
    Route::post('/drp-items/{item}/remove', [FinanceDrpController::class, 'removeItem'])->name('drp.remove-item');
    Route::post('/drp-groups/{group}/fee-override', [FinanceDrpController::class, 'overrideFee'])->name('drp.override-fee');
    Route::post('/drp-groups/{group}/voucher', [FinanceDrpController::class, 'assignVoucher'])->name('drp.assign-voucher');
    Route::post('/drp-groups/{group}/pay', [FinanceDrpController::class, 'markPaid'])->name('drp.mark-paid');

    // Master Invoice (Reporting / Query Repository)
    Route::get('/master-invoices', [FinanceInvoiceController::class, 'masterInvoice'])->name('master-invoices');

    // Vendor Master & Change Approvals
    Route::get('/vendor-master', [FinanceVendorController::class, 'index'])->name('vendor-master.index');
    Route::get('/vendor-master/{vendor}', [FinanceVendorController::class, 'show'])->name('vendor-master.show');
    Route::post('/vendor-change-requests/{request}/approve', [FinanceVendorController::class, 'approveChange'])->name('vendor-change-requests.approve');
    Route::post('/vendor-change-requests/{request}/reject', [FinanceVendorController::class, 'rejectChange'])->name('vendor-change-requests.reject');
});
