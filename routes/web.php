<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\AuthAuditLogController;
use App\Http\Controllers\Admin\ExchangeRateController;
use App\Http\Controllers\Admin\HsCodeRuleController;
use App\Http\Controllers\Admin\MaterialHsCodeController;
use App\Http\Controllers\Admin\MaterialMasterController;
use App\Http\Controllers\Admin\PurchaseRequisitionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserTwoFactorController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\ConversationMessageController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Purchasing\ConversationController;
use App\Http\Controllers\Purchasing\ExportController;
use App\Http\Controllers\Purchasing\MaterialCalculationController;
use App\Http\Controllers\Purchasing\MaterialClaimController;
use App\Http\Controllers\Purchasing\MaterialMasterSearchController;
use App\Http\Controllers\Purchasing\PdfController;
use App\Http\Controllers\Purchasing\PeriodController;
use App\Http\Controllers\Purchasing\PoDocumentController;
use App\Http\Controllers\Purchasing\PriceComparisonController;
use App\Http\Controllers\Purchasing\PrItemController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\PurchasingController;
use App\Http\Controllers\Purchasing\QuotationListController;
use App\Http\Controllers\Purchasing\ReportController;
use App\Http\Controllers\Qc\DashboardController;
use App\Http\Controllers\Qc\QcExportController;
use App\Http\Controllers\Qc\QcInspectionController;
use App\Http\Controllers\Supplier\ClaimController;
use App\Http\Controllers\Supplier\ExportController as SupplierExportController;
use App\Http\Controllers\Supplier\QuotationController;
use App\Http\Controllers\Supplier\SupplierController;
use App\Http\Controllers\Supplier\SupplierPriceHistoryController;
use App\Http\Controllers\Supplier\SupplierPurchaseOrderController;
use App\Models\PurchaseRequisition;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest / Public Routes
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return redirect()->route('login');
});

/*
|--------------------------------------------------------------------------
| Authenticated (Shared) Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return match (auth()->user()->role) {
            'admin' => redirect()->route('admin.dashboard'),
            'purchasing' => redirect()->route('purchasing.dashboard'),
            'supplier' => redirect()->route('supplier.dashboard'),
            'qc' => redirect()->route('qc.dashboard'),
            default => redirect()->route('login'),
        };
    })->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->middleware('throttle:auth.credentials')->name('profile.destroy');
    Route::get('/attachments/{id}', [AttachmentController::class, 'show'])->name('attachments.show');

    Route::middleware('role:admin,purchasing,supplier,qc')->group(function () {
        Route::get('/exports', [ExportDownloadController::class, 'index'])->name('exports.index');
        Route::get('/exports/{exportJob}/status', [ExportDownloadController::class, 'status'])->name('exports.status');
        Route::post('/exports/{exportJob}/cancel', [ExportDownloadController::class, 'cancel'])->name('exports.cancel');
        Route::get('/exports/{exportJob}/download', [ExportDownloadController::class, 'download'])->name('exports.download');
    });

    // Notifications
    Route::middleware('role:admin,purchasing,supplier,qc')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::get('/notifications/summary', [NotificationController::class, 'summary'])->name('notifications.summary');
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    });

    // Conversations (Shared)
    Route::get('/conversations/drawer', [ConversationMessageController::class, 'drawerIndex'])->name('conversations.drawer.index');
    Route::get('/conversations/{id}/drawer', [ConversationMessageController::class, 'drawerShow'])->name('conversations.drawer.show');
    Route::post('/conversations/{id}/messages', [ConversationMessageController::class, 'store'])->name('conversations.messages.store');
    Route::post('/conversations/{id}/quick-action', [ConversationMessageController::class, 'quickAction'])->name('conversations.quick-action');
    Route::post('/conversations/{id}/read', [ConversationMessageController::class, 'markRead'])->name('conversations.read');
    Route::get('/conversations/{id}/messages/latest', [ConversationMessageController::class, 'latest'])->name('conversations.messages.latest');
    Route::get('/conversations/unread-count', [ConversationMessageController::class, 'unreadCount'])->name('conversations.unread-count');
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/material-hs-code', [MaterialHsCodeController::class, 'index'])->name('material-hs-code.index');
    Route::get('/master-data-quality', [MaterialHsCodeController::class, 'quality'])->name('master-data-quality.index');
    Route::get('/material-masters/data', [MaterialMasterController::class, 'data'])->name('material-masters.data');
    Route::post('/material-masters', [MaterialMasterController::class, 'store'])->name('material-masters.store');
    Route::put('/material-masters/{materialMaster}', [MaterialMasterController::class, 'update'])->name('material-masters.update');
    Route::patch('/material-masters/{materialMaster}/status', [MaterialMasterController::class, 'status'])->name('material-masters.status');
    Route::get('/hs-code-rules/data', [HsCodeRuleController::class, 'data'])->name('hs-code-rules.data');
    Route::post('/hs-code-rules', [HsCodeRuleController::class, 'store'])->name('hs-code-rules.store');
    Route::put('/hs-code-rules/{hsCodeRule}', [HsCodeRuleController::class, 'update'])->name('hs-code-rules.update');
    Route::patch('/hs-code-rules/{hsCodeRule}/status', [HsCodeRuleController::class, 'status'])->name('hs-code-rules.status');
    Route::get('/requisitions/{requisition}', [PurchaseRequisitionController::class, 'show'])->name('requisitions.show');
    Route::post('/kurs/update', [AdminController::class, 'updateKurs'])->name('kurs.update');
    Route::get('/auth-audit-logs', [AuthAuditLogController::class, 'index'])->name('auth-audit-logs.index');
    Route::get('/auth-audit-logs/data', [AuthAuditLogController::class, 'data'])->name('auth-audit-logs.data');

    // Manajemen User & Kurs
    Route::delete('/users/{user}/two-factor', [UserTwoFactorController::class, 'destroy'])
        ->middleware(['password.confirm', 'throttle:auth.security-action'])->name('users.two-factor.destroy');
    Route::resource('users', UserController::class);
    Route::resource('exchange-rates', ExchangeRateController::class)->only(['index', 'store']);

    // Pengumuman
    Route::resource('announcements', AnnouncementController::class);
    Route::post('/announcements/{announcement}/toggle-publish', [AnnouncementController::class, 'togglePublish'])->name('announcements.toggle-publish');
});

/*
|--------------------------------------------------------------------------
| Purchasing Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:purchasing', 'purchasing.navigation'])->prefix('purchasing')->name('purchasing.')->group(function () {
    Route::get('/dashboard', [PurchasingController::class, 'dashboard'])->name('dashboard');
    Route::post('/kurs/update', [PurchasingController::class, 'updateKurs'])->name('kurs.update');
    // Manajemen Periode
    Route::resource('periods', PeriodController::class)->only(['index', 'store', 'update']);

    Route::get('/material-masters/search', MaterialMasterSearchController::class)
        ->middleware('throttle:120,1')
        ->name('material-masters.search');
    Route::post('/material-calculations/preview', [MaterialCalculationController::class, 'preview'])
        ->middleware('throttle:120,1')
        ->name('material-calculations.preview');

    Route::get('/requisitions/import-template', [App\Http\Controllers\Purchasing\PurchaseRequisitionController::class, 'importTemplate'])->name('requisitions.import-template');
    Route::post('/requisitions/import-preview', [App\Http\Controllers\Purchasing\PurchaseRequisitionController::class, 'importPreview'])->name('requisitions.import-preview');
    Route::put('/requisitions/{id}/submit', [App\Http\Controllers\Purchasing\PurchaseRequisitionController::class, 'submitDraft'])->name('requisitions.submit');
    Route::resource('requisitions', App\Http\Controllers\Purchasing\PurchaseRequisitionController::class);
    Route::resource('pr-items', PrItemController::class)->only(['store', 'update', 'destroy']);
    Route::get('/purchase-orders/create/{quotation_id}', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::post('/purchase-orders/{id}/confirm-arrival', [PurchaseOrderController::class, 'confirmArrival'])->name('purchase-orders.confirm-arrival');
    Route::put('/po-documents/{id}', [PoDocumentController::class, 'update'])->name('po-documents.update');
    Route::get('/claims/data-action', [MaterialClaimController::class, 'dataActionNeeded'])->name('claims.data-action');
    Route::get('/claims/data-history', [MaterialClaimController::class, 'dataHistory'])->name('claims.data-history');
    Route::get('/claims/create/{inspection_id}', [MaterialClaimController::class, 'create'])->name('claims.create');
    Route::resource('claims', MaterialClaimController::class)->except(['create', 'edit', 'update', 'destroy']);
    Route::post('/claims/{id}/resolve', [MaterialClaimController::class, 'resolve'])->name('claims.resolve');
    // Conversations
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/{id}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::post('/conversations/start-pr/{pr_id}/{supplier_id}', [ConversationController::class, 'startFromPr'])->name('conversations.start.pr');
    Route::post('/conversations/start-po/{po_id}', [ConversationController::class, 'startFromPo'])->name('conversations.start.po');
    // Penawaran (view-only dari sisi Purchasing)
    Route::get('/quotations', [QuotationListController::class, 'index'])->name('quotations.index');
    Route::post('/quotations/{id}/accept', [QuotationListController::class, 'accept'])->name('quotations.accept');
    Route::post('/quotations/{id}/reject', [QuotationListController::class, 'reject'])->name('quotations.reject');
    Route::post('/quotations/{id}/request-revision', [QuotationListController::class, 'requestRevision'])->name('quotations.request-revision');
    Route::get('/quotations/{id}', [QuotationListController::class, 'show'])->name('quotations.show');
    // Perbandingan Harga
    Route::get('/comparison/inter-supplier', [PriceComparisonController::class, 'interSupplier'])->name('comparison.inter-supplier');
    Route::get('/comparison/historical', [PriceComparisonController::class, 'historical'])->name('comparison.historical');
    Route::get('/comparison/historical/materials', [PriceComparisonController::class, 'historicalMaterials'])->name('comparison.historical.materials');
    Route::get('/comparison/vs-best', [PriceComparisonController::class, 'vsBestPrice'])->name('comparison.vs-best');
    Route::get('/comparison/vs-best/data', [PriceComparisonController::class, 'vsBestPriceData'])->name('comparison.vs-best.data');
    Route::get('/comparison/{pr_id}', function ($pr_id) {
        $requisition = PurchaseRequisition::findOrFail($pr_id);

        return redirect()->route('purchasing.comparison.inter-supplier', ['pr_id' => $requisition]);
    })->name('comparison.show');
    // Laporan
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    // Export
    Route::get('/export/requisitions', [ExportController::class, 'requisitions'])->name('export.requisitions');
    Route::get('/export/requisitions/{purchaseRequisition}', [ExportController::class, 'requisitionDetail'])->name('export.requisitions.detail');
    Route::get('/export/purchase-orders', [ExportController::class, 'purchaseOrders'])->name('export.purchase-orders');
    Route::get('/export/purchase-orders/{purchaseOrder}', [ExportController::class, 'purchaseOrderDetail'])->name('export.purchase-orders.detail');
    Route::get('/export/quotations', [ExportController::class, 'quotations'])->name('export.quotations');
    Route::get('/export/quotations/{quotation}', [ExportController::class, 'quotationDetail'])->name('export.quotations.detail');
});

/*
|--------------------------------------------------------------------------
| Shared PDF Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->prefix('shared')->name('shared.')->group(function () {
    Route::get('/pdf/purchase-order/{id}', [PdfController::class, 'purchaseOrder'])
        ->middleware('role:purchasing,supplier,admin')
        ->name('pdf.purchase-order');

    Route::get('/pdf/qc-inspection/{id}', [PdfController::class, 'qcInspection'])
        ->middleware('role:purchasing,qc,admin')
        ->name('pdf.qc-inspection');
});

/*
|--------------------------------------------------------------------------
| Supplier Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:supplier'])->prefix('supplier')->name('supplier.')->group(function () {
    Route::get('/dashboard', [SupplierController::class, 'dashboard'])->name('dashboard');
    Route::get('/export/quotations', [SupplierExportController::class, 'quotations'])->name('export.quotations');
    Route::get('/export/quotations/{quotation}', [SupplierExportController::class, 'quotationDetail'])->name('export.quotations.detail');
    Route::get('/export/purchase-orders', [SupplierExportController::class, 'purchaseOrders'])->name('export.purchase-orders');
    Route::get('/export/purchase-orders/{purchaseOrder}', [SupplierExportController::class, 'purchaseOrderDetail'])->name('export.purchase-orders.detail');
    Route::get('/quotations/period/{period_id}', [QuotationController::class, 'period'])->name('quotations.period');
    Route::get('/quotations/{pr_id}/import-template', [QuotationController::class, 'importTemplate'])->name('quotations.import-template');
    Route::post('/quotations/{pr_id}/import-preview', [QuotationController::class, 'importPreview'])->name('quotations.import-preview');
    Route::get('/quotations/{pr_id}/create', [QuotationController::class, 'create'])->name('quotations.create');
    Route::post('/quotations/{pr_id}', [QuotationController::class, 'store'])->name('quotations.store');
    Route::resource('quotations', QuotationController::class)->only(['index', 'show']);
    Route::get('/purchase-orders', [SupplierPurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('/purchase-orders/{id}', [SupplierPurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::get('/claims', [ClaimController::class, 'index'])->name('claims.index');
    Route::get('/claims/{id}', [ClaimController::class, 'show'])->name('claims.show');
    Route::post('/claims/{id}/respond', [ClaimController::class, 'respond'])->name('claims.respond');
    // Conversations
    Route::get('/conversations', [App\Http\Controllers\Supplier\ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/{id}', [App\Http\Controllers\Supplier\ConversationController::class, 'show'])->name('conversations.show');
    // Riwayat Harga
    Route::get('/price-history', [SupplierPriceHistoryController::class, 'index'])->name('price-history.index');
    Route::get('/price-history/historical', [SupplierPriceHistoryController::class, 'historical'])->name('price-history.historical');
    Route::get('/price-history/materials', [SupplierPriceHistoryController::class, 'materials'])->name('price-history.materials');
    Route::get('/price-history/export', [SupplierPriceHistoryController::class, 'export'])->name('price-history.export');
    // Announcements
    Route::get('/announcements', [App\Http\Controllers\Supplier\AnnouncementController::class, 'index'])->name('announcements.index');
    Route::get('/announcements/{announcement}', [App\Http\Controllers\Supplier\AnnouncementController::class, 'show'])->name('announcements.show');
});

/*
|--------------------------------------------------------------------------
| QC Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:qc'])->prefix('qc')->name('qc.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'dashboard'])->name('dashboard');
    Route::get('/inspections/data-waiting', [QcInspectionController::class, 'dataWaiting'])->name('inspections.data-waiting');
    Route::get('/inspections/data-history', [QcInspectionController::class, 'dataHistory'])->name('inspections.data-history');
    Route::get('/inspections/{po_id}/create', [QcInspectionController::class, 'create'])->name('inspections.create');
    Route::post('/inspections/{po_id}', [QcInspectionController::class, 'store'])->name('inspections.store');
    Route::post('/inspections/{id}/attachments', [QcInspectionController::class, 'storeAttachments'])->name('inspections.attachments.store');
    Route::get('/inspections', [QcInspectionController::class, 'index'])->name('inspections.index');
    Route::get('/export/inspections', [QcExportController::class, 'inspections'])->name('export.inspections');
});

// Shared QC Inspection Detail (QC + Purchasing can access)
Route::middleware(['auth', 'role:qc,purchasing'])->prefix('qc')->name('qc.')->group(function () {
    Route::get('/inspections/{id}', [QcInspectionController::class, 'show'])->name('inspections.show');
});

require __DIR__.'/auth.php';
