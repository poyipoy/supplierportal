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
    Route::middleware(['role:accounting,finance', 'throttle:60,1'])->group(function () {
        Route::post('/invoices/{invoice}/physical-verification', [InvoiceWorkflowController::class, 'physicalVerification'])->name('invoices.physical-verification');
        Route::post('/invoices/{invoice}/start-review', [InvoiceWorkflowController::class, 'startReview'])->name('invoices.start-review');
        Route::post('/invoices/{invoice}/request-revision', [InvoiceWorkflowController::class, 'requestRevision'])->name('invoices.request-revision');
        Route::post('/invoices/{invoice}/reject', [InvoiceWorkflowController::class, 'reject'])->name('invoices.reject');
        Route::post('/invoices/{invoice}/approve', [InvoiceWorkflowController::class, 'approve'])->name('invoices.approve');
        Route::post('/invoices/{invoice}/schedule-payment', [InvoiceWorkflowController::class, 'schedulePayment'])->name('invoices.schedule-payment');
        Route::post('/invoices/{invoice}/complete-payment', [InvoiceWorkflowController::class, 'completePayment'])->name('invoices.complete-payment');
    });
});
