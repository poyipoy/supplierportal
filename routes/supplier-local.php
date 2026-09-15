<?php

use App\Http\Controllers\GaClaimDocumentController;
use App\Http\Controllers\LocalInvoiceDocumentController;
use App\Http\Controllers\LocalInvoiceReceiptController;
use App\Http\Controllers\LocalSupplier\InformationController;
use App\Http\Controllers\LocalSupplier\InvoiceController;
use App\Http\Controllers\LocalSupplier\VendorProfileController;
use App\Http\Controllers\SupplierContextController;
use App\Http\Controllers\SupplierMasterDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:supplier'])->group(function () {
    Route::get('/supplier-context', [SupplierContextController::class, 'index'])->name('supplier-context.index');
    Route::post('/supplier-context', [SupplierContextController::class, 'store'])->name('supplier-context.store');
});
Route::middleware(['auth', 'role:supplier', 'supplier.scope:local'])->prefix('local-supplier')->name('local-supplier.')->group(function () {
    Route::get('/dashboard', [InvoiceController::class, 'dashboard'])->name('dashboard');
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/purchase-orders/search', [InvoiceController::class, 'searchPurchaseOrders'])->name('purchase-orders.search');
    Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
    Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('throttle:30,1')->name('invoices.store');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/revision', [InvoiceController::class, 'revision'])->name('invoices.revision');
    Route::post('/invoices/{invoice}/resubmit', [InvoiceController::class, 'resubmit'])->middleware('throttle:30,1')->name('invoices.resubmit');
    Route::get('/invoices/{invoice}/receipt', [LocalInvoiceReceiptController::class, 'show'])->name('invoices.receipt');
    Route::get('/vendor-profile', [VendorProfileController::class, 'show'])->name('vendor-profile.show');
    Route::post('/vendor-profile/change-requests', [VendorProfileController::class, 'storeChangeRequest'])->name('vendor-profile.change-requests.store');
    Route::post('/vendor-profile/documents', [VendorProfileController::class, 'uploadDocument'])->name('vendor-profile.documents.upload');
    Route::get('/information', [InformationController::class, 'index'])->name('information');
});
Route::get('/local-invoice-documents/{document}', [LocalInvoiceDocumentController::class, 'show'])->middleware(['auth', 'role:supplier,accounting,finance,admin,purchasing'])->name('local-invoice-documents.show');
Route::get('/supplier-master-documents/{document}', [SupplierMasterDocumentController::class, 'show'])->middleware(['auth', 'role:supplier,finance,purchasing,admin'])->name('supplier-master-documents.show');
Route::get('/ga-claim-documents/{document}', [GaClaimDocumentController::class, 'show'])->middleware(['auth', 'role:ga,finance,admin'])->name('ga-claim-documents.show');
