<?php

use App\Http\Controllers\Accounting\InvoiceController as AccountingInvoiceController;
use App\Http\Controllers\Accounting\ReportController as AccountingReportController;
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
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\LogoutOtherDevicesController;
use App\Http\Controllers\Auth\PasswordAssistanceController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\ProfileTwoFactorController;
use App\Http\Controllers\Auth\RevokeSessionController;
use App\Http\Controllers\Auth\SupplierRegistrationController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\ConversationMessageController;
use App\Http\Controllers\ExportDefinitionController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\ExportPresetController;
use App\Http\Controllers\Finance\FinanceDashboardController;
use App\Http\Controllers\Finance\FinanceDrpController;
use App\Http\Controllers\Finance\FinanceDrpPaidController;
use App\Http\Controllers\Finance\FinanceGaClaimController;
use App\Http\Controllers\Finance\FinanceInvoiceController;
use App\Http\Controllers\Finance\FinanceVendorController;
use App\Http\Controllers\Finance\LocalInvoiceSettlementController;
use App\Http\Controllers\Finance\LocalPoDocumentBatchController;
use App\Http\Controllers\Finance\LocalProcurementController;
use App\Http\Controllers\Finance\LocalProcurementImportController;
use App\Http\Controllers\Ga\EmployeeController;
use App\Http\Controllers\Ga\GaController;
use App\Http\Controllers\GaClaimDocumentController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LocalInvoiceDocumentController;
use App\Http\Controllers\LocalInvoiceReceiptController;
use App\Http\Controllers\LocalSupplier\InformationController;
use App\Http\Controllers\LocalSupplier\InvoiceController as LocalSupplierInvoiceController;
use App\Http\Controllers\LocalSupplier\PurchaseOrderController as LocalSupplierPurchaseOrderController;
use App\Http\Controllers\LocalSupplier\SupplierAuditController as LocalSupplierAuditController;
use App\Http\Controllers\LocalSupplier\VendorProfileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Purchasing\AwardConsolidationController;
use App\Http\Controllers\Purchasing\ConversationController;
use App\Http\Controllers\Purchasing\ExportController;
use App\Http\Controllers\Purchasing\MaterialCalculationController;
use App\Http\Controllers\Purchasing\MaterialClaimController;
use App\Http\Controllers\Purchasing\MaterialMasterSearchController;
use App\Http\Controllers\Purchasing\PdfController;
use App\Http\Controllers\Purchasing\PeriodController;
use App\Http\Controllers\Purchasing\PoDocumentController;
use App\Http\Controllers\Purchasing\PoItemProgressController;
use App\Http\Controllers\Purchasing\PriceComparisonController;
use App\Http\Controllers\Purchasing\PrItemController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\PurchasingController;
use App\Http\Controllers\Purchasing\PurchasingDrpController;
use App\Http\Controllers\Purchasing\PurchasingLocalVendorController;
use App\Http\Controllers\Purchasing\QuotationListController;
use App\Http\Controllers\Purchasing\ReportController;
use App\Http\Controllers\Purchasing\ShipmentController;
use App\Http\Controllers\Purchasing\SupplierAuditController as PurchasingSupplierAuditController;
use App\Http\Controllers\Qc\DashboardController;
use App\Http\Controllers\Qc\QcExportController;
use App\Http\Controllers\Qc\QcInspectionController;
use App\Http\Controllers\ReceiptVerificationController;
use App\Http\Controllers\Supplier\ClaimController;
use App\Http\Controllers\Supplier\ExportController as SupplierExportController;
use App\Http\Controllers\Supplier\QuotationController;
use App\Http\Controllers\Supplier\SupplierController;
use App\Http\Controllers\Supplier\SupplierPriceHistoryController;
use App\Http\Controllers\Supplier\SupplierPurchaseOrderController;
use App\Http\Controllers\Supplier\SupplierShipmentController;
use App\Http\Controllers\SupplierContextController;
use App\Http\Controllers\SupplierMasterDocumentController;
use App\Http\Controllers\SupplierRegistrationReviewController;
use App\Http\Controllers\UserNotificationPreferenceController;
use App\Http\Controllers\UserPreferenceController;
use App\Models\PurchaseRequisition;
use App\Support\PortalContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Local Supplier, Context and Shared Local Documents
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:supplier'])->group(function () {
    Route::get('/supplier-context', [SupplierContextController::class, 'index'])->name('supplier-context.index');
    Route::post('/supplier-context', [SupplierContextController::class, 'store'])->name('supplier-context.store');
});
Route::middleware(['auth', 'role:supplier', 'supplier.scope:local'])->prefix('local-supplier')->name('local-supplier.')->group(function () {
    Route::get('/dashboard', [LocalSupplierInvoiceController::class, 'dashboard'])->name('dashboard');
    Route::get('/purchase-orders', [LocalSupplierPurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('/purchase-orders/search', [LocalSupplierInvoiceController::class, 'searchPurchaseOrders'])->name('purchase-orders.search');
    Route::get('/purchase-orders/{purchase_order}', [LocalSupplierPurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::get('/invoices', [LocalSupplierInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/create', [LocalSupplierInvoiceController::class, 'create'])->name('invoices.create');
    Route::post('/invoices', [LocalSupplierInvoiceController::class, 'store'])->middleware('throttle:30,1')->name('invoices.store');
    Route::get('/invoices/{invoice}', [LocalSupplierInvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/revision', [LocalSupplierInvoiceController::class, 'revision'])->name('invoices.revision');
    Route::post('/invoices/{invoice}/resubmit', [LocalSupplierInvoiceController::class, 'resubmit'])->middleware('throttle:30,1')->name('invoices.resubmit');
    Route::post('/invoices/{invoice}/cancel', [LocalSupplierInvoiceController::class, 'cancel'])->middleware('throttle:30,1')->name('invoices.cancel');
    Route::get('/invoices/{invoice}/receipt', [LocalInvoiceReceiptController::class, 'show'])->name('invoices.receipt');
    Route::get('/vendor-profile', [VendorProfileController::class, 'show'])->name('vendor-profile.show');
    Route::post('/vendor-profile/change-requests', [VendorProfileController::class, 'storeChangeRequest'])->name('vendor-profile.change-requests.store');
    Route::post('/vendor-profile/documents', [VendorProfileController::class, 'uploadDocument'])->name('vendor-profile.documents.upload');
    Route::get('/information', [InformationController::class, 'index'])->name('information');
    // Supplier Audit
    Route::get('/supplier-audits', [LocalSupplierAuditController::class, 'index'])->name('supplier-audits.index');
    Route::get('/supplier-audits/{supplierAudit}', [LocalSupplierAuditController::class, 'show'])->name('supplier-audits.show');
    Route::get('/supplier-audits/{supplierAudit}/edit', [LocalSupplierAuditController::class, 'edit'])->name('supplier-audits.edit');
    Route::put('/supplier-audits/{supplierAudit}', [LocalSupplierAuditController::class, 'update'])->middleware('throttle:30,1')->name('supplier-audits.update');
    Route::patch('/supplier-audits/{supplierAudit}/answers', [LocalSupplierAuditController::class, 'autosave'])->middleware('throttle:120,1')->name('supplier-audits.autosave');
});
Route::get('/local-invoice-documents/{document}', [LocalInvoiceDocumentController::class, 'show'])->middleware(['auth', 'role:supplier,accounting,finance,admin,purchasing'])->name('local-invoice-documents.show');
Route::get('/supplier-master-documents/{document}', [SupplierMasterDocumentController::class, 'show'])->middleware(['auth', 'role:supplier,finance,purchasing,admin'])->name('supplier-master-documents.show');
Route::get('/ga-claim-documents/{document}', [GaClaimDocumentController::class, 'show'])->middleware(['auth', 'role:ga,finance,admin'])->name('ga-claim-documents.show');

/*
|--------------------------------------------------------------------------
| Accounting Compatibility Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:accounting,finance,admin'])->prefix('accounting')->name('accounting.')->group(function () {
    Route::get('/dashboard', [AccountingInvoiceController::class, 'dashboard'])->name('dashboard');
    Route::get('/invoices', [AccountingInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/physical-verification', [AccountingInvoiceController::class, 'index'])->name('physical-verification');
    Route::get('/payment-schedule', [AccountingInvoiceController::class, 'index'])->name('payment-schedule');
    Route::get('/invoices/{invoice}', [AccountingInvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/receipt', [LocalInvoiceReceiptController::class, 'show'])->name('invoices.receipt');
    Route::get('/reports', [AccountingReportController::class, 'index'])->name('reports');
    Route::post('/reports/export', [AccountingReportController::class, 'export'])->name('reports.export');
});

/*
|--------------------------------------------------------------------------
| Finance Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:finance,admin,purchasing'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('/invoices/{invoice}/receipt', [LocalInvoiceReceiptController::class, 'show'])->name('invoices.receipt');
});

Route::middleware(['auth', 'role:finance,admin'])->prefix('finance')->name('finance.')->group(function () {
    // Dashboard & Forecast
    Route::get('/dashboard', [FinanceDashboardController::class, 'dashboard'])->name('dashboard');
    Route::get('/forecast', [FinanceDashboardController::class, 'forecast'])->name('forecast');

    // Invoice Register & Verification
    Route::get('/invoices', [FinanceInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [FinanceInvoiceController::class, 'show'])->name('invoices.show');

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
    Route::post('/drp-ga', [FinanceDrpController::class, 'createGaBatch'])->name('drp.ga.create');

    // DRP Paid
    Route::get('/drp-paid', [FinanceDrpPaidController::class, 'index'])->name('drp.paid.index');
    Route::post('/drp-paid/{batch}/mark-paid', [FinanceDrpPaidController::class, 'markBatchPaid'])->name('drp.paid.mark-paid');

    // GA Claims Register & Verification
    Route::get('/ga-claims', [FinanceGaClaimController::class, 'index'])->name('ga-claims.index');
    Route::get('/ga-claims/{claim}', [FinanceGaClaimController::class, 'show'])->name('ga-claims.show');
    Route::post('/ga-claims/{claim}/verify', [FinanceGaClaimController::class, 'verify'])->name('ga-claims.verify');

    // DRP Bulk Transfer Export (multi-batch → single TARIKAN TRANSFER workbook)
    Route::post('/drp/export-transfer', [FinanceDrpController::class, 'exportTransferBulk'])->name('drp.export-transfer');

    // DRP Batch Detail, Finalize, Item Removal, Fee Override, Voucher, Pay
    Route::get('/drp/{batch}/export', [FinanceDrpController::class, 'export'])->name('drp.export');
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
        Route::get('/imports', [LocalProcurementImportController::class, 'index'])->name('imports.index');
        Route::get('/imports/{procurementImport}', [LocalProcurementImportController::class, 'status'])->name('imports.status');
        Route::get('/imports/{procurementImport}/records', [LocalProcurementImportController::class, 'records'])->name('imports.records');
        Route::get('/imports/{procurementImport}/errors', [LocalProcurementImportController::class, 'errors'])->name('imports.errors');
        Route::post('/imports/{procurementImport}/cancel', [LocalProcurementImportController::class, 'cancel'])->middleware('throttle:15,1')->name('imports.cancel');
        Route::post('/', [LocalProcurementController::class, 'store'])->name('store');
        Route::get('/import/po/template', [LocalProcurementController::class, 'poTemplate'])->name('import.po.template');
        Route::post('/import/po/preview', [LocalProcurementController::class, 'poPreview'])->middleware('throttle:15,1')->name('import.po.preview');
        Route::post('/import/po/confirm', [LocalProcurementController::class, 'poConfirm'])->middleware('throttle:15,1')->name('import.po.confirm');
        Route::get('/import/gr/template', [LocalProcurementController::class, 'grTemplate'])->name('import.gr.template');
        Route::post('/import/gr/preview', [LocalProcurementController::class, 'grPreview'])->middleware('throttle:15,1')->name('import.gr.preview');
        Route::post('/import/gr/confirm', [LocalProcurementController::class, 'grConfirm'])->middleware('throttle:15,1')->name('import.gr.confirm');
        Route::post('/upload-po', [LocalProcurementController::class, 'uploadPo'])->name('upload-po');
        Route::get('/po-documents/{poDocumentBatch}', [LocalPoDocumentBatchController::class, 'status'])->name('po-documents.status');
        Route::post('/po-documents/{poDocumentBatch}/retry', [LocalPoDocumentBatchController::class, 'retry'])->middleware('throttle:15,1')->name('po-documents.retry');
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
    Route::match(['get', 'post'], '/master-invoices/export', [FinanceInvoiceController::class, 'exportMasterInvoice'])->name('master-invoices.export');

    // Vendor Master & Change Approvals
    Route::get('/vendor-master', [FinanceVendorController::class, 'index'])->name('vendor-master.index');
    Route::get('/vendor-master/{vendor}', [FinanceVendorController::class, 'show'])->name('vendor-master.show');
    Route::post('/vendor-change-requests/{request}/approve', [FinanceVendorController::class, 'approveChange'])->name('vendor-change-requests.approve');
    Route::post('/vendor-change-requests/{request}/reject', [FinanceVendorController::class, 'rejectChange'])->name('vendor-change-requests.reject');
});

/*
|--------------------------------------------------------------------------
| General Affairs Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:ga,admin,finance'])->prefix('ga')->name('ga.')->group(function () {
    Route::get('/claims/{claim}/receipt', [GaController::class, 'receipt'])->name('claims.receipt');
});

Route::middleware(['auth', 'role:ga,admin'])->prefix('ga')->name('ga.')->group(function () {
    Route::get('/dashboard', [GaController::class, 'dashboard'])->name('dashboard');
    Route::get('/claims', [GaController::class, 'index'])->name('claims.index');
    Route::get('/claims/create', [GaController::class, 'create'])->name('claims.create');
    Route::post('/claims', [GaController::class, 'store'])->name('claims.store');
    Route::get('/claims/{claim}', [GaController::class, 'show'])->name('claims.show');
    Route::get('/claims/{claim}/revision', [GaController::class, 'revision'])->name('claims.revision');
    Route::post('/claims/{claim}/resubmit', [GaController::class, 'resubmit'])->name('claims.resubmit');
    Route::post('/claims/{claim}/basic-verify', [GaController::class, 'basicVerify'])->name('claims.basic-verify');

    // Employee Master
    Route::resource('employees', EmployeeController::class)->only(['index', 'store', 'update']);
    Route::post('/employees/{employee}/toggle-status', [EmployeeController::class, 'toggleStatus'])->name('employees.toggle-status');

});

/*
|--------------------------------------------------------------------------
| Guest / Public Routes
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return redirect()->route('login');
});
Route::get('/verify-receipt/supplier/{receipt}', [ReceiptVerificationController::class, 'verifySupplier'])
    ->middleware('throttle:60,1')
    ->name('receipts.verify-supplier');
Route::get('/verify-receipt/ga/{receipt}', [ReceiptVerificationController::class, 'verifyGa'])
    ->middleware('throttle:60,1')
    ->name('receipts.verify-ga');

// Application Locale Switcher (Guest & Authenticated)
Route::match(['get', 'post'], '/locale/{locale?}', [LocaleController::class, 'switch'])
    ->name('locale.switch');

// Supplier Public Registration & Status Tracking
Route::get('/supplier/register', [SupplierRegistrationController::class, 'create'])->name('supplier.register');
Route::post('/supplier/register', [SupplierRegistrationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('supplier.register.store');

Route::prefix('supplier/registration')->name('supplier.registration.')->group(function () {
    Route::get('/access', [SupplierRegistrationController::class, 'showAccessForm'])->name('access-form');
    Route::post('/access', [SupplierRegistrationController::class, 'authenticateAccess'])
        ->middleware('throttle:10,1')
        ->name('access');
    Route::post('/access/credentials', [SupplierRegistrationController::class, 'authenticateCredentials'])
        ->middleware('throttle:10,1')
        ->name('access.credentials');
    Route::post('/logout', [SupplierRegistrationController::class, 'logoutAccess'])->name('logout');
    Route::get('/success', [SupplierRegistrationController::class, 'success'])->name('success');

    // Registration session protected routes
    Route::middleware('registration.session')->group(function () {
        Route::get('/status', [SupplierRegistrationController::class, 'status'])->name('status');
        Route::get('/edit', [SupplierRegistrationController::class, 'edit'])->name('edit');
        Route::post('/resubmit', [SupplierRegistrationController::class, 'resubmit'])
            ->middleware('throttle:10,1')
            ->name('resubmit');
        Route::get('/documents/{document}', [SupplierRegistrationController::class, 'downloadDocument'])->name('document.download');
    });
});

// Supplier Registrations Reviewer (Admin, Finance, Purchasing)
Route::middleware(['auth', 'role:admin,finance,purchasing'])
    ->prefix('supplier-registrations')
    ->name('supplier-registrations.')
    ->group(function () {
        Route::get('/', [SupplierRegistrationReviewController::class, 'index'])->name('index');
        Route::get('/{attempt}', [SupplierRegistrationReviewController::class, 'show'])->name('show');
        Route::post('/{attempt}/revision', [SupplierRegistrationReviewController::class, 'requestRevision'])->name('revision');
        Route::post('/{attempt}/reject', [SupplierRegistrationReviewController::class, 'reject'])->name('reject');
        Route::post('/{attempt}/approve', [SupplierRegistrationReviewController::class, 'approve'])->name('approve');
        Route::get('/{attempt}/documents/{document}', [SupplierRegistrationReviewController::class, 'downloadDocument'])->name('document');
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
            'supplier', 'accounting', 'finance' => redirect(PortalContext::dashboard(auth()->user())),
            'ga' => redirect()->route('ga.dashboard'),
            'qc' => redirect()->route('qc.dashboard'),
            default => redirect()->route('login'),
        };
    })->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('/profile/security', [ProfileController::class, 'security'])
        ->middleware('no-store')->name('profile.security');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/customization', [UserPreferenceController::class, 'edit'])->name('profile.customization');
    Route::patch('/profile/customization', [UserPreferenceController::class, 'update'])->name('profile.customization.update');
    Route::delete('/profile/customization', [UserPreferenceController::class, 'reset'])->name('profile.customization.reset');
    Route::middleware('role:admin,purchasing,supplier,qc,accounting,finance,ga')->group(function () {
        Route::get('/profile/notifications', [UserNotificationPreferenceController::class, 'index'])->name('profile.notifications');
        Route::patch('/profile/notifications', [UserNotificationPreferenceController::class, 'update'])->name('profile.notifications.update');
        Route::delete('/profile/notifications', [UserNotificationPreferenceController::class, 'reset'])->name('profile.notifications.reset');
    });
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

    Route::middleware('role:admin,purchasing,supplier,qc,accounting,finance,ga')->group(function () {
        Route::get('/exports', [ExportDownloadController::class, 'index'])->name('exports.index');
        Route::get('/exports/definitions/{exportKey}', [ExportDefinitionController::class, 'show'])->middleware('no-store')->name('exports.definitions.show');
        Route::middleware('no-store')->group(function () {
            Route::get('/export-presets', [ExportPresetController::class, 'index'])->name('export-presets.index');
            Route::post('/export-presets', [ExportPresetController::class, 'store'])->name('export-presets.store');
            Route::put('/export-presets/{preset}', [ExportPresetController::class, 'update'])->name('export-presets.update');
            Route::delete('/export-presets/{preset}', [ExportPresetController::class, 'destroy'])->name('export-presets.destroy');
            Route::post('/export-presets/{preset}/default', [ExportPresetController::class, 'makeDefault'])->name('export-presets.default');
        });
        Route::get('/exports/{exportJob}/status', [ExportDownloadController::class, 'status'])->name('exports.status');
        Route::post('/exports/{exportJob}/cancel', [ExportDownloadController::class, 'cancel'])->name('exports.cancel');
        Route::get('/exports/{exportJob}/download', [ExportDownloadController::class, 'download'])->name('exports.download');
    });

    // Notifications
    Route::middleware('role:admin,purchasing,supplier,qc,accounting,finance,ga')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::get('/notifications/summary', [NotificationController::class, 'summary'])->name('notifications.summary');
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    });

    // Conversations (Shared)
    Route::get('/conversations/drawer', [ConversationMessageController::class, 'drawerIndex'])->name('conversations.drawer.index');
    Route::get('/conversations/{id}/drawer', [ConversationMessageController::class, 'drawerShow'])->name('conversations.drawer.show');
    Route::post('/conversations/{id}/messages', [ConversationMessageController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('conversations.messages.store');
    Route::post('/conversations/{id}/quick-action', [ConversationMessageController::class, 'quickAction'])
        ->middleware('throttle:60,1')
        ->name('conversations.quick-action');
    Route::post('/conversations/{id}/read', [ConversationMessageController::class, 'markRead'])->name('conversations.read');
    Route::post('/conversations/{id}/mute', [ConversationMessageController::class, 'mute'])
        ->middleware('throttle:60,1')
        ->name('conversations.mute');
    Route::delete('/conversations/{id}/mute', [ConversationMessageController::class, 'unmute'])
        ->middleware('throttle:60,1')
        ->name('conversations.unmute');
    Route::post('/conversations/{id}/unmute', [ConversationMessageController::class, 'unmute'])
        ->middleware('throttle:60,1');
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
    Route::post('/requisitions/import-preview', [App\Http\Controllers\Purchasing\PurchaseRequisitionController::class, 'importPreview'])
        ->middleware('throttle:15,1')
        ->name('requisitions.import-preview');
    Route::put('/requisitions/{id}/submit', [App\Http\Controllers\Purchasing\PurchaseRequisitionController::class, 'submitDraft'])->name('requisitions.submit');
    Route::resource('requisitions', App\Http\Controllers\Purchasing\PurchaseRequisitionController::class);
    Route::resource('pr-items', PrItemController::class)->only(['store', 'update', 'destroy']);
    Route::get('/purchase-orders/create/{quotation_id}', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('/purchase-orders/consolidate-awards', [AwardConsolidationController::class, 'create'])->name('purchase-orders.consolidate-awards');
    Route::post('/purchase-orders/consolidate-awards', [AwardConsolidationController::class, 'store'])->name('purchase-orders.consolidate-awards.store');
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::get('/purchase-orders/{po_id}/items/{award_id}/progress/history', [PoItemProgressController::class, 'history'])->name('purchase-orders.item-progress.history');
    Route::post('/purchase-orders/{id}/confirm-arrival', [PurchaseOrderController::class, 'confirmArrival'])->name('purchase-orders.confirm-arrival');
    Route::resource('shipments', ShipmentController::class)->only(['index', 'show']);
    Route::post('/shipments/{id}/confirm-arrival', [ShipmentController::class, 'confirmArrival'])->name('shipments.confirm-arrival');
    Route::put('/shipments/{id}/documents/{document_id}/status', [ShipmentController::class, 'updateDocumentStatus'])->name('shipments.documents.status');
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
    Route::post('/quotations/{id}/generate-po', [QuotationListController::class, 'generatePo'])->name('quotations.generate-po');
    Route::get('/quotations/{id}', [QuotationListController::class, 'show'])->name('quotations.show');
    // Perbandingan Harga
    Route::get('/comparison/inter-supplier', [PriceComparisonController::class, 'interSupplier'])->name('comparison.inter-supplier');
    Route::get('/comparison/historical', [PriceComparisonController::class, 'historical'])->name('comparison.historical');
    Route::get('/comparison/historical/materials', [PriceComparisonController::class, 'historicalMaterials'])->name('comparison.historical.materials');
    Route::get('/comparison/vs-best', [PriceComparisonController::class, 'vsBestPrice'])->name('comparison.vs-best');
    Route::get('/comparison/vs-best/data', [PriceComparisonController::class, 'vsBestPriceData'])->name('comparison.vs-best.data');
    Route::post('/comparison/awards', [PriceComparisonController::class, 'saveItemAwards'])->name('comparison.save-awards');
    Route::get('/comparison/{pr_id}', function ($pr_id) {
        $requisition = PurchaseRequisition::findOrFail($pr_id);

        return redirect()->route('purchasing.comparison.inter-supplier', ['pr_id' => $requisition]);
    })->name('comparison.show');
    // Laporan
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    // Export
    Route::match(['get', 'post'], '/export/requisitions', [ExportController::class, 'requisitions'])->name('export.requisitions');
    Route::get('/export/requisitions/{purchaseRequisition}', [ExportController::class, 'requisitionDetail'])->name('export.requisitions.detail');
    Route::match(['get', 'post'], '/export/purchase-orders', [ExportController::class, 'purchaseOrders'])->name('export.purchase-orders');
    Route::get('/export/purchase-orders/{purchaseOrder}', [ExportController::class, 'purchaseOrderDetail'])->name('export.purchase-orders.detail');
    Route::match(['get', 'post'], '/export/quotations', [ExportController::class, 'quotations'])->name('export.quotations');
    Route::get('/export/quotations/{quotation}', [ExportController::class, 'quotationDetail'])->name('export.quotations.detail');
    Route::match(['get', 'post'], '/export/shipments', [ExportController::class, 'shipments'])->name('export.shipments');
    Route::get('/export/supplier-audits/{supplierAudit}', [ExportController::class, 'supplierAuditDetail'])->name('export.supplier-audits.detail');
    // Supplier Audit (Supplier Local)
    Route::get('/supplier-audits', [PurchasingSupplierAuditController::class, 'index'])->name('supplier-audits.index');
    Route::get('/supplier-audits/create', [PurchasingSupplierAuditController::class, 'create'])->name('supplier-audits.create');
    Route::post('/supplier-audits', [PurchasingSupplierAuditController::class, 'store'])->middleware('throttle:30,1')->name('supplier-audits.store');
    Route::get('/supplier-audits/{supplierAudit}', [PurchasingSupplierAuditController::class, 'show'])->name('supplier-audits.show');
    Route::post('/supplier-audits/{supplierAudit}/deadline', [PurchasingSupplierAuditController::class, 'changeDeadline'])->middleware('throttle:30,1')->name('supplier-audits.deadline');
    Route::post('/supplier-audits/{supplierAudit}/revision', [PurchasingSupplierAuditController::class, 'requestRevision'])->middleware('throttle:30,1')->name('supplier-audits.request-revision');
    Route::post('/supplier-audits/{supplierAudit}/cancel', [PurchasingSupplierAuditController::class, 'cancel'])->middleware('throttle:30,1')->name('supplier-audits.cancel');
    Route::post('/supplier-audits/{supplierAudit}/result', [PurchasingSupplierAuditController::class, 'uploadResult'])->middleware('throttle:30,1')->name('supplier-audits.result');
    // Local Vendors & Read-Only Invoices
    Route::get('/local-vendors', [PurchasingLocalVendorController::class, 'index'])->name('local-vendors.index');
    Route::get('/local-vendors/{vendor}', [PurchasingLocalVendorController::class, 'show'])->name('local-vendors.show');
    Route::post('/local-vendors/change-requests/{request}/approve', [PurchasingLocalVendorController::class, 'approveChange'])->name('local-vendors.change-requests.approve');
    Route::post('/local-vendors/change-requests/{request}/reject', [PurchasingLocalVendorController::class, 'rejectChange'])->name('local-vendors.change-requests.reject');
    Route::get('/local-invoices/{invoice}', [PurchasingLocalVendorController::class, 'showInvoice'])->name('local-invoices.show');

    Route::prefix('local-procurement')->name('local-procurement.')->controller(LocalProcurementController::class)->group(function () {
        Route::get('/imports', [LocalProcurementImportController::class, 'index'])->name('imports.index');
        Route::get('/imports/{procurementImport}', [LocalProcurementImportController::class, 'status'])->name('imports.status');
        Route::get('/imports/{procurementImport}/records', [LocalProcurementImportController::class, 'records'])->name('imports.records');
        Route::get('/imports/{procurementImport}/errors', [LocalProcurementImportController::class, 'errors'])->name('imports.errors');
        Route::post('/imports/{procurementImport}/cancel', [LocalProcurementImportController::class, 'cancel'])->middleware('throttle:15,1')->name('imports.cancel');
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/import/po/template', 'poTemplate')->name('import.po.template');
        Route::post('/import/po/preview', 'poPreview')->middleware('throttle:15,1')->name('import.po.preview');
        Route::post('/import/po/confirm', 'poConfirm')->middleware('throttle:15,1')->name('import.po.confirm');
        Route::get('/import/gr/template', 'grTemplate')->name('import.gr.template');
        Route::post('/import/gr/preview', 'grPreview')->middleware('throttle:15,1')->name('import.gr.preview');
        Route::post('/import/gr/confirm', 'grConfirm')->middleware('throttle:15,1')->name('import.gr.confirm');
        Route::post('/upload-po', 'uploadPo')->name('upload-po');
        Route::get('/po-documents/{poDocumentBatch}', [LocalPoDocumentBatchController::class, 'status'])->name('po-documents.status');
        Route::post('/po-documents/{poDocumentBatch}/retry', [LocalPoDocumentBatchController::class, 'retry'])->middleware('throttle:15,1')->name('po-documents.retry');
        Route::get('/{purchaseOrder}', 'show')->name('show');
        Route::get('/{purchaseOrder}/edit', 'edit')->name('edit');
        Route::put('/{purchaseOrder}', 'update')->name('update');
        Route::post('/{purchaseOrder}/close', 'close')->name('close');
        Route::post('/{purchaseOrder}/cancel', 'cancel')->name('cancel');
        Route::post('/{purchaseOrder}/goods-receipts', 'storeGoodsReceipt')->name('goods-receipts.store');
        Route::put('/goods-receipts/{goodsReceipt}', 'updateGoodsReceipt')->name('goods-receipts.update');
        Route::post('/goods-receipts/{goodsReceipt}/cancel', 'cancelGoodsReceipt')->name('goods-receipts.cancel');
    });

    // Read-Only DRP (Supplier, GA, Paid) & Vouchers
    Route::prefix('drp')->name('drp.')->controller(PurchasingDrpController::class)->group(function () {
        Route::get('/supplier', 'indexSupplier')->name('supplier');
        Route::get('/ga', 'indexGa')->name('ga');
        Route::get('/paid', 'indexPaid')->name('paid.index');
        Route::get('/{batch}', 'show')->name('show');
    });
    Route::get('/vouchers/{voucher}/print', [PurchasingDrpController::class, 'printVoucher'])->name('vouchers.print');
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
Route::middleware(['auth', 'role:supplier', 'supplier.scope:import'])->prefix('supplier')->name('supplier.')->group(function () {
    Route::get('/dashboard', [SupplierController::class, 'dashboard'])->name('dashboard');
    Route::match(['get', 'post'], '/export/quotations', [SupplierExportController::class, 'quotations'])->name('export.quotations');
    Route::get('/export/quotations/{quotation}', [SupplierExportController::class, 'quotationDetail'])->name('export.quotations.detail');
    Route::match(['get', 'post'], '/export/purchase-orders', [SupplierExportController::class, 'purchaseOrders'])->name('export.purchase-orders');
    Route::get('/export/purchase-orders/{purchaseOrder}', [SupplierExportController::class, 'purchaseOrderDetail'])->name('export.purchase-orders.detail');
    Route::get('/quotations/period/{period_id}', [QuotationController::class, 'period'])->name('quotations.period');
    Route::get('/quotations/{pr_id}/import-template', [QuotationController::class, 'importTemplate'])->name('quotations.import-template');
    Route::post('/quotations/{pr_id}/import-preview', [QuotationController::class, 'importPreview'])
        ->middleware('throttle:15,1')
        ->name('quotations.import-preview');
    Route::get('/quotations/{pr_id}/create', [QuotationController::class, 'create'])->name('quotations.create');
    Route::post('/quotations/{pr_id}', [QuotationController::class, 'store'])->name('quotations.store');
    Route::resource('quotations', QuotationController::class)->only(['index', 'show']);
    Route::get('/purchase-orders', [SupplierPurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('/purchase-orders/{id}', [SupplierPurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::post('/purchase-orders/{po_id}/items/{award_id}/progress', [App\Http\Controllers\Supplier\PoItemProgressController::class, 'update'])->name('purchase-orders.item-progress.update');
    Route::get('/purchase-orders/{po_id}/items/{award_id}/progress/history', [App\Http\Controllers\Supplier\PoItemProgressController::class, 'history'])->name('purchase-orders.item-progress.history');
    // Shipments
    Route::resource('shipments', SupplierShipmentController::class)
        ->only(['index', 'create', 'store', 'show', 'edit', 'update']);
    Route::post('/shipments/{id}/submit', [SupplierShipmentController::class, 'submit'])->name('shipments.submit');
    Route::post('/shipments/{id}/cancel', [SupplierShipmentController::class, 'cancel'])->name('shipments.cancel');
    Route::post('/shipments/{id}/documents/{document_id}', [SupplierShipmentController::class, 'uploadDocument'])->name('shipments.documents.upload');
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
    Route::match(['get', 'post'], '/price-history/export', [SupplierPriceHistoryController::class, 'export'])->name('price-history.export');
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
    Route::match(['get', 'post'], '/export/inspections', [QcExportController::class, 'inspections'])->name('export.inspections');
});

// Shared QC Inspection Detail (QC + Purchasing can access)
Route::middleware(['auth', 'role:qc,purchasing'])->prefix('qc')->name('qc.')->group(function () {
    Route::get('/inspections/{id}', [QcInspectionController::class, 'show'])->name('inspections.show');
});

/*
|--------------------------------------------------------------------------
| Authentication and Account Security Routes
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->middleware('no-store')->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('no-store')->name('login.store');

});

// This is an informational assistance page only. Allow an authenticated account
// to use its saved locale for the copied email template; no reset flow is added.
Route::get('forgot-password', [PasswordAssistanceController::class, 'show'])
    ->middleware('no-store')->name('password.request');

Route::middleware(['mfa.pending', 'no-store'])->group(function () {
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'show'])
        ->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:auth.mfa-code')->name('two-factor.challenge.store');
});

Route::middleware('auth')->group(function () {

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->middleware('no-store')->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware(['throttle:auth.credentials', 'no-store']);

    Route::put('password', [PasswordController::class, 'update'])
        ->middleware('throttle:auth.credentials')->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    Route::post('profile/logout-other-devices', LogoutOtherDevicesController::class)
        ->middleware(['auth.session', 'throttle:auth.credentials'])
        ->name('profile.logout-other-devices');

    // Revoke one specific session (as opposed to logout-other-devices above,
    // which nukes all of them at once and requires a password). This is a
    // narrower, lower-friction action: it can only ever target a row that
    // already belongs to the current user, scoped inside RevokeSessionController.
    Route::delete('profile/sessions', RevokeSessionController::class)
        ->middleware(['auth.session', 'throttle:auth.security-action'])
        ->name('profile.sessions.revoke');

    Route::post('profile/two-factor/setup', [ProfileTwoFactorController::class, 'start'])
        ->middleware(['auth.session', 'password.confirm', 'throttle:auth.security-action'])->name('profile.two-factor.start');
    Route::get('profile/two-factor/setup', [ProfileTwoFactorController::class, 'show'])
        ->middleware('no-store')->name('profile.two-factor.setup');
    Route::post('profile/two-factor/confirm', [ProfileTwoFactorController::class, 'confirm'])
        ->middleware(['throttle:auth.mfa-code', 'no-store'])->name('profile.two-factor.confirm');
    Route::post('profile/two-factor/recovery-codes', [ProfileTwoFactorController::class, 'recoveryCodes'])
        ->middleware(['password.confirm', 'throttle:auth.security-action', 'no-store'])->name('profile.two-factor.recovery-codes');
    Route::delete('profile/two-factor', [ProfileTwoFactorController::class, 'destroy'])
        ->middleware(['password.confirm', 'throttle:auth.mfa-code', 'no-store'])->name('profile.two-factor.destroy');
});
