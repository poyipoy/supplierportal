<?php

use App\Http\Controllers\Accounting\InvoiceController;
use App\Http\Controllers\Accounting\InvoiceWorkflowController;
use App\Http\Controllers\Accounting\ReportController;
use App\Http\Controllers\LocalInvoiceReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:accounting,finance,admin'])->prefix('accounting')->name('accounting.')->group(function () {
    Route::get('/dashboard', [InvoiceController::class, 'dashboard'])->name('dashboard');
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/physical-verification', [InvoiceController::class, 'index'])->name('physical-verification');
    Route::get('/payment-schedule', [InvoiceController::class, 'index'])->name('payment-schedule');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/receipt', [LocalInvoiceReceiptController::class, 'show'])->name('invoices.receipt');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports');
    Route::post('/reports/export', [ReportController::class, 'export'])->name('reports.export');
});
