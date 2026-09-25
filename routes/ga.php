<?php

use App\Http\Controllers\Ga\EmployeeController;
use App\Http\Controllers\Ga\GaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:ga,admin'])->prefix('ga')->name('ga.')->group(function () {
    Route::get('/dashboard', [GaController::class, 'dashboard'])->name('dashboard');
    Route::get('/claims', [GaController::class, 'index'])->name('claims.index');
    Route::get('/claims/create', [GaController::class, 'create'])->name('claims.create');
    Route::post('/claims', [GaController::class, 'store'])->name('claims.store');
    Route::get('/claims/{claim}', [GaController::class, 'show'])->name('claims.show');
    Route::get('/claims/{claim}/receipt', [GaController::class, 'receipt'])->name('claims.receipt');
    Route::get('/claims/{claim}/revision', [GaController::class, 'revision'])->name('claims.revision');
    Route::post('/claims/{claim}/resubmit', [GaController::class, 'resubmit'])->name('claims.resubmit');
    Route::post('/claims/{claim}/basic-verify', [GaController::class, 'basicVerify'])->name('claims.basic-verify');

    // Employee Master
    Route::resource('employees', EmployeeController::class)->only(['index', 'store', 'update']);
    Route::post('/employees/{employee}/toggle-status', [EmployeeController::class, 'toggleStatus'])->name('employees.toggle-status');

    // DRP GA Draft
    Route::get('/drp-draft', [GaController::class, 'drpDraft'])->name('drp-draft');
    Route::post('/drp-draft', [GaController::class, 'createDrpDraft'])->name('drp-draft.store');
});
