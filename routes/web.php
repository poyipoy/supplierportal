<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Purchasing\PurchasingController;
use App\Http\Controllers\Supplier\SupplierController;
use App\Http\Controllers\Qc\QcController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest / Public Routes
|--------------------------------------------------------------------------
*/
Route::get('/', function () { return redirect()->route('login'); });

/*
|--------------------------------------------------------------------------
| Authenticated (Shared) Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/attachments/{id}', [\App\Http\Controllers\AttachmentController::class, 'show'])->name('attachments.show');
    // Notifications
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/unread-count', [\App\Http\Controllers\NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::get('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/mark-all-read', [\App\Http\Controllers\NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
    
    // Conversations (Shared)
    Route::get('/conversations/drawer', [\App\Http\Controllers\ConversationMessageController::class, 'drawerIndex'])->name('conversations.drawer.index');
    Route::get('/conversations/{id}/drawer', [\App\Http\Controllers\ConversationMessageController::class, 'drawerShow'])->name('conversations.drawer.show');
    Route::post('/conversations/{id}/messages', [\App\Http\Controllers\ConversationMessageController::class, 'store'])->name('conversations.messages.store');
    Route::post('/conversations/{id}/read', [\App\Http\Controllers\ConversationMessageController::class, 'markRead'])->name('conversations.read');
    Route::get('/conversations/{id}/messages/latest', [\App\Http\Controllers\ConversationMessageController::class, 'latest'])->name('conversations.messages.latest');
    Route::get('/conversations/unread-count', [\App\Http\Controllers\ConversationMessageController::class, 'unreadCount'])->name('conversations.unread-count');
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::post('/kurs/update', [AdminController::class, 'updateKurs'])->name('kurs.update');
    
    // Manajemen User & Kurs
    Route::resource('users', \App\Http\Controllers\Admin\UserController::class);
    Route::resource('exchange-rates', \App\Http\Controllers\Admin\ExchangeRateController::class)->only(['index', 'store']);
    
    // Pengumuman
    Route::resource('announcements', \App\Http\Controllers\Admin\AnnouncementController::class);
    Route::post('/announcements/{announcement}/toggle-publish', [\App\Http\Controllers\Admin\AnnouncementController::class, 'togglePublish'])->name('announcements.toggle-publish');
});

/*
|--------------------------------------------------------------------------
| Purchasing Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:purchasing'])->prefix('purchasing')->name('purchasing.')->group(function () {
    Route::get('/dashboard', [PurchasingController::class, 'dashboard'])->name('dashboard');
    Route::post('/kurs/update', [PurchasingController::class, 'updateKurs'])->name('kurs.update');
    // Manajemen Periode
    Route::resource('periods', \App\Http\Controllers\Purchasing\PeriodController::class)->only(['index', 'store', 'update']);
    
    Route::resource('requirements', \App\Http\Controllers\Purchasing\PurchaseRequirementController::class);
    Route::resource('pr-items', \App\Http\Controllers\Purchasing\PrItemController::class)->only(['store', 'update', 'destroy']);
    Route::get('/purchase-orders/create/{quotation_id}', [\App\Http\Controllers\Purchasing\PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
    Route::post('/purchase-orders', [\App\Http\Controllers\Purchasing\PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::get('/purchase-orders', [\App\Http\Controllers\Purchasing\PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('/purchase-orders/{id}', [\App\Http\Controllers\Purchasing\PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::post('/purchase-orders/{id}/confirm-arrival', [\App\Http\Controllers\Purchasing\PurchaseOrderController::class, 'confirmArrival'])->name('purchase-orders.confirm-arrival');
    Route::put('/po-documents/{id}', [\App\Http\Controllers\Purchasing\PoDocumentController::class, 'update'])->name('po-documents.update');
    Route::get('/claims/create/{inspection_id}', [\App\Http\Controllers\Purchasing\MaterialClaimController::class, 'create'])->name('claims.create');
    Route::resource('claims', \App\Http\Controllers\Purchasing\MaterialClaimController::class)->except(['create', 'edit', 'update', 'destroy']);
    Route::post('/claims/{id}/resolve', [\App\Http\Controllers\Purchasing\MaterialClaimController::class, 'resolve'])->name('claims.resolve');
    // Conversations
    Route::get('/conversations', [\App\Http\Controllers\Purchasing\ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/{id}', [\App\Http\Controllers\Purchasing\ConversationController::class, 'show'])->name('conversations.show');
    Route::post('/conversations/start-pr/{pr_id}/{supplier_id}', [\App\Http\Controllers\Purchasing\ConversationController::class, 'startFromPr'])->name('conversations.start.pr');
    Route::post('/conversations/start-po/{po_id}', [\App\Http\Controllers\Purchasing\ConversationController::class, 'startFromPo'])->name('conversations.start.po');
    // Penawaran (view-only dari sisi Purchasing)
    Route::get('/quotations', [\App\Http\Controllers\Purchasing\QuotationListController::class, 'index'])->name('quotations.index');
    Route::get('/quotations/{id}', [\App\Http\Controllers\Purchasing\QuotationListController::class, 'show'])->name('quotations.show');
    // Perbandingan Harga
    Route::get('/comparison/inter-supplier', [\App\Http\Controllers\Purchasing\PriceComparisonController::class, 'interSupplier'])->name('comparison.inter-supplier');
    Route::get('/comparison/historical', [\App\Http\Controllers\Purchasing\PriceComparisonController::class, 'historical'])->name('comparison.historical');
    Route::get('/comparison/vs-best', [\App\Http\Controllers\Purchasing\PriceComparisonController::class, 'vsBestPrice'])->name('comparison.vs-best');
    Route::get('/comparison/{pr_id}', function ($pr_id) {
        return redirect()->route('purchasing.comparison.inter-supplier', ['pr_id' => $pr_id]);
    })->whereNumber('pr_id')->name('comparison.show');
    // Laporan
    Route::get('/reports', [\App\Http\Controllers\Purchasing\ReportController::class, 'index'])->name('reports.index');
    // Export
    Route::get('/export/requirements', [\App\Http\Controllers\Purchasing\ExportController::class, 'requirements'])->name('export.requirements');
    Route::get('/export/purchase-orders', [\App\Http\Controllers\Purchasing\ExportController::class, 'purchaseOrders'])->name('export.purchase-orders');
});

/*
|--------------------------------------------------------------------------
| Supplier Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:supplier'])->prefix('supplier')->name('supplier.')->group(function () {
    Route::get('/dashboard', [SupplierController::class, 'dashboard'])->name('dashboard');
    Route::get('/quotations/period/{period_id}', [\App\Http\Controllers\Supplier\QuotationController::class, 'period'])->name('quotations.period');
    Route::get('/quotations/{pr_id}/create', [\App\Http\Controllers\Supplier\QuotationController::class, 'create'])->name('quotations.create');
    Route::post('/quotations/{pr_id}', [\App\Http\Controllers\Supplier\QuotationController::class, 'store'])->name('quotations.store');
    Route::resource('quotations', \App\Http\Controllers\Supplier\QuotationController::class)->only(['index', 'show']);
    Route::get('/purchase-orders', [\App\Http\Controllers\Supplier\SupplierPurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('/purchase-orders/{id}', [\App\Http\Controllers\Supplier\SupplierPurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::get('/claims', [\App\Http\Controllers\Supplier\ClaimController::class, 'index'])->name('claims.index');
    Route::get('/claims/{id}', [\App\Http\Controllers\Supplier\ClaimController::class, 'show'])->name('claims.show');
    Route::post('/claims/{id}/respond', [\App\Http\Controllers\Supplier\ClaimController::class, 'respond'])->name('claims.respond');
    // Conversations
    Route::get('/conversations', [\App\Http\Controllers\Supplier\ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/{id}', [\App\Http\Controllers\Supplier\ConversationController::class, 'show'])->name('conversations.show');
    // Announcements
    Route::get('/announcements', [\App\Http\Controllers\Supplier\AnnouncementController::class, 'index'])->name('announcements.index');
    Route::get('/announcements/{announcement}', [\App\Http\Controllers\Supplier\AnnouncementController::class, 'show'])->name('announcements.show');
});

/*
|--------------------------------------------------------------------------
| QC Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:qc'])->prefix('qc')->name('qc.')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Qc\DashboardController::class, 'dashboard'])->name('dashboard');
    Route::get('/inspections/{po_id}/create', [\App\Http\Controllers\Qc\QcInspectionController::class, 'create'])->name('inspections.create');
    Route::post('/inspections/{po_id}', [\App\Http\Controllers\Qc\QcInspectionController::class, 'store'])->name('inspections.store');
    Route::get('/inspections', [\App\Http\Controllers\Qc\QcInspectionController::class, 'index'])->name('inspections.index');
    Route::get('/export/inspections', [\App\Http\Controllers\Qc\QcExportController::class, 'inspections'])->name('export.inspections');
});

// Shared QC Inspection Detail (QC + Purchasing can access)
Route::middleware(['auth', 'role:qc,purchasing'])->prefix('qc')->name('qc.')->group(function () {
    Route::get('/inspections/{id}', [\App\Http\Controllers\Qc\QcInspectionController::class, 'show'])->name('inspections.show');
});

require __DIR__ . '/auth.php';
