# Phase 7 exact file manifest

Status: review manifest; no staging/commit/push. Compare against the preserved pre-Phase-7 baseline, not only Git HEAD.

Files: 537; production: 456; tests: 81. Deleted files: 0.

## Production ? New

| Exact file | Module | Classification | Reason | Mixed-work overlap |
|---|---|---|---|---|
| `app/Exports/Concerns/LocalizesExport.php` | Exports | CLEAN | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | - |
| `app/Http/Controllers/LocaleController.php` | Preferences / Runtime Locale | CLEAN | Provide the bounded en/id guest-session switch and persist authenticated POST changes through UserPreferenceService. | - |
| `app/Http/Middleware/ApplyUserLocale.php` | Preferences / Runtime Locale | CLEAN | Apply authenticated account locale before controllers/validation/views. | - |
| `app/Support/JsTranslations.php` | Shared / Localization | CLEAN | Expose only bounded UI copy in the synchronous bootstrap. | - |
| `database/migrations/2026_10_04_000001_add_locale_to_user_preferences_table.php` | Shared / Localization | CLEAN | Add exactly one VARCHAR(2) non-null locale default en; rollback only that column. | - |
| `lang/en/accounting.php` | Translation domain accounting | CLEAN | Paired accounting semantic keys, manually authored copy and placeholders. | - |
| `lang/en/admin.php` | Translation domain admin | CLEAN | Paired admin semantic keys, manually authored copy and placeholders. | - |
| `lang/en/claims.php` | Translation domain claims | CLEAN | Paired claims semantic keys, manually authored copy and placeholders. | - |
| `lang/en/common.php` | Translation domain common | CLEAN | Paired common semantic keys, manually authored copy and placeholders. | - |
| `lang/en/customization.php` | Translation domain customization | CLEAN | Paired customization semantic keys, manually authored copy and placeholders. | - |
| `lang/en/dashboard.php` | Translation domain dashboard | CLEAN | Paired dashboard semantic keys, manually authored copy and placeholders. | - |
| `lang/en/datatables.php` | Translation domain datatables | CLEAN | Paired datatables semantic keys, manually authored copy and placeholders. | - |
| `lang/en/documents.php` | Translation domain documents | CLEAN | Paired documents semantic keys, manually authored copy and placeholders. | - |
| `lang/en/exports.php` | Translation domain exports | CLEAN | Paired exports semantic keys, manually authored copy and placeholders. | - |
| `lang/en/finance.php` | Translation domain finance | CLEAN | Paired finance semantic keys, manually authored copy and placeholders. | - |
| `lang/en/ga.php` | Translation domain ga | CLEAN | Paired ga semantic keys, manually authored copy and placeholders. | - |
| `lang/en/js.php` | Translation domain js | CLEAN | Paired js semantic keys, manually authored copy and placeholders. | - |
| `lang/en/local_invoice.php` | Translation domain local_invoice | CLEAN | Paired local_invoice semantic keys, manually authored copy and placeholders. | - |
| `lang/en/local_procurement.php` | Translation domain local_procurement | CLEAN | Paired local_procurement semantic keys, manually authored copy and placeholders. | - |
| `lang/en/materials.php` | Translation domain materials | CLEAN | Paired materials semantic keys, manually authored copy and placeholders. | - |
| `lang/en/navigation.php` | Translation domain navigation | CLEAN | Paired navigation semantic keys, manually authored copy and placeholders. | - |
| `lang/en/notifications.php` | Translation domain notifications | CLEAN | Paired notifications semantic keys, manually authored copy and placeholders. | - |
| `lang/en/profile.php` | Translation domain profile | CLEAN | Paired profile semantic keys, manually authored copy and placeholders. | - |
| `lang/en/purchasing.php` | Translation domain purchasing | CLEAN | Paired purchasing semantic keys, manually authored copy and placeholders. | - |
| `lang/en/qc.php` | Translation domain qc | CLEAN | Paired qc semantic keys, manually authored copy and placeholders. | - |
| `lang/en/registration.php` | Translation domain registration | CLEAN | Paired registration semantic keys, manually authored copy and placeholders. | - |
| `lang/en/security.php` | Translation domain security | CLEAN | Paired security semantic keys, manually authored copy and placeholders. | - |
| `lang/en/shipments.php` | Translation domain shipments | CLEAN | Paired shipments semantic keys, manually authored copy and placeholders. | - |
| `lang/en/status.php` | Translation domain status | CLEAN | Paired status semantic keys, manually authored copy and placeholders. | - |
| `lang/en/supplier.php` | Translation domain supplier | CLEAN | Paired supplier semantic keys, manually authored copy and placeholders. | - |
| `lang/en/terms.php` | Translation domain terms | CLEAN | Paired terms semantic keys, manually authored copy and placeholders. | - |
| `lang/id/accounting.php` | Translation domain accounting | CLEAN | Paired accounting semantic keys, manually authored copy and placeholders. | - |
| `lang/id/admin.php` | Translation domain admin | CLEAN | Paired admin semantic keys, manually authored copy and placeholders. | - |
| `lang/id/auth.php` | Translation domain auth | CLEAN | Paired auth semantic keys, manually authored copy and placeholders. | - |
| `lang/id/claims.php` | Translation domain claims | CLEAN | Paired claims semantic keys, manually authored copy and placeholders. | - |
| `lang/id/common.php` | Translation domain common | CLEAN | Paired common semantic keys, manually authored copy and placeholders. | - |
| `lang/id/customization.php` | Translation domain customization | CLEAN | Paired customization semantic keys, manually authored copy and placeholders. | - |
| `lang/id/dashboard.php` | Translation domain dashboard | CLEAN | Paired dashboard semantic keys, manually authored copy and placeholders. | - |
| `lang/id/datatables.php` | Translation domain datatables | CLEAN | Paired datatables semantic keys, manually authored copy and placeholders. | - |
| `lang/id/documents.php` | Translation domain documents | CLEAN | Paired documents semantic keys, manually authored copy and placeholders. | - |
| `lang/id/exports.php` | Translation domain exports | CLEAN | Paired exports semantic keys, manually authored copy and placeholders. | - |
| `lang/id/finance.php` | Translation domain finance | CLEAN | Paired finance semantic keys, manually authored copy and placeholders. | - |
| `lang/id/ga.php` | Translation domain ga | CLEAN | Paired ga semantic keys, manually authored copy and placeholders. | - |
| `lang/id/js.php` | Translation domain js | CLEAN | Paired js semantic keys, manually authored copy and placeholders. | - |
| `lang/id/local_invoice.php` | Translation domain local_invoice | CLEAN | Paired local_invoice semantic keys, manually authored copy and placeholders. | - |
| `lang/id/local_procurement.php` | Translation domain local_procurement | CLEAN | Paired local_procurement semantic keys, manually authored copy and placeholders. | - |
| `lang/id/materials.php` | Translation domain materials | CLEAN | Paired materials semantic keys, manually authored copy and placeholders. | - |
| `lang/id/navigation.php` | Translation domain navigation | CLEAN | Paired navigation semantic keys, manually authored copy and placeholders. | - |
| `lang/id/notifications.php` | Translation domain notifications | CLEAN | Paired notifications semantic keys, manually authored copy and placeholders. | - |
| `lang/id/pagination.php` | Translation domain pagination | CLEAN | Paired pagination semantic keys, manually authored copy and placeholders. | - |
| `lang/id/passwords.php` | Translation domain passwords | CLEAN | Paired passwords semantic keys, manually authored copy and placeholders. | - |
| `lang/id/profile.php` | Translation domain profile | CLEAN | Paired profile semantic keys, manually authored copy and placeholders. | - |
| `lang/id/purchasing.php` | Translation domain purchasing | CLEAN | Paired purchasing semantic keys, manually authored copy and placeholders. | - |
| `lang/id/qc.php` | Translation domain qc | CLEAN | Paired qc semantic keys, manually authored copy and placeholders. | - |
| `lang/id/registration.php` | Translation domain registration | CLEAN | Paired registration semantic keys, manually authored copy and placeholders. | - |
| `lang/id/security.php` | Translation domain security | CLEAN | Paired security semantic keys, manually authored copy and placeholders. | - |
| `lang/id/shipments.php` | Translation domain shipments | CLEAN | Paired shipments semantic keys, manually authored copy and placeholders. | - |
| `lang/id/status.php` | Translation domain status | CLEAN | Paired status semantic keys, manually authored copy and placeholders. | - |
| `lang/id/supplier.php` | Translation domain supplier | CLEAN | Paired supplier semantic keys, manually authored copy and placeholders. | - |
| `lang/id/terms.php` | Translation domain terms | CLEAN | Paired terms semantic keys, manually authored copy and placeholders. | - |
| `lang/id/validation.php` | Translation domain validation | CLEAN | Paired validation semantic keys, manually authored copy and placeholders. | - |
| `public/assets/js/adasi-i18n.js` | Shared / Localization | CLEAN | Provide plain-text interpolation/plural helper before module consumers. | - |
| `resources/js/i18n.js` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/partials/i18n-bootstrap.blade.php` | Shared / Localization | CLEAN | Emit inert HEX-encoded JSON and synchronous helper. | - |

## Production ? Modified

| Exact file | Module | Classification | Reason | Mixed-work overlap |
|---|---|---|---|---|
| `app/Data/Materials/HsCodeConditionSet.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Data/Materials/WeightCalculationResult.php` | Shared / Localization | CLEAN | Carry optional trusted display-message key while preserving pure calculator behavior. | - |
| `app/Exports/Concerns/InteractsWithExportProgress.php` | Exports | CLEAN | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | - |
| `app/Exports/InspectionsExport.php` | Exports | MIXED | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | BusinessTime: copy/display boundary only |
| `app/Exports/LocalInvoicesExport.php` | Exports | MIXED | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | BusinessTime: copy/display boundary only |
| `app/Exports/PaymentBatchDrpExport.php` | Exports | CLEAN | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | - |
| `app/Exports/PaymentBatchDrpSheetRenderer.php` | Exports | MIXED | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | BusinessTime: copy/display boundary only |
| `app/Exports/PaymentBatchTransferExport.php` | Exports | CLEAN | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | - |
| `app/Exports/PaymentBatchTransferSheetRenderer.php` | Exports | MIXED | Localize the human-facing workbook validation prompts while retaining bank/ERP columns and transfer data. | Existing transfer-account configuration hardening |
| `app/Exports/PurchaseOrderDetailExport.php` | Exports | MIXED | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | BusinessTime: copy/display boundary only |
| `app/Exports/PurchaseOrdersExport.php` | Exports | MIXED | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | BusinessTime: copy/display boundary only |
| `app/Exports/PurchaseRequisitionDetailExport.php` | Exports | CLEAN | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | - |
| `app/Exports/QuotationDetailExport.php` | Exports | MIXED | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | BusinessTime: copy/display boundary only |
| `app/Exports/QuotationsExport.php` | Exports | MIXED | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | BusinessTime: copy/display boundary only |
| `app/Exports/RequisitionsExport.php` | Exports | MIXED | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | BusinessTime: copy/display boundary only |
| `app/Exports/ShipmentsExport.php` | Exports | CLEAN | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | - |
| `app/Exports/SupplierPriceHistoryExport.php` | Exports | CLEAN | Localize human captions/status display and capture locale; preserve data/formulas/integration cells. | - |
| `app/Http/Controllers/Accounting/InvoiceController.php` | Accounting | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Accounting/InvoiceWorkflowController.php` | Accounting | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Accounting/ReportController.php` | Accounting | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Admin/AdminController.php` | Admin | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Admin/AnnouncementController.php` | Admin | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Admin/AuthAuditLogController.php` | Admin | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Admin/ExchangeRateController.php` | Admin | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Admin/HsCodeRuleController.php` | Admin | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Admin/MaterialMasterController.php` | Admin | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Admin/UserController.php` | Admin | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Admin/UserTwoFactorController.php` | Admin | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Auth/ConfirmablePasswordController.php` | Auth / Security | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Auth/PasswordAssistanceController.php` | Auth / Security | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Auth/ProfileTwoFactorController.php` | Auth / Security | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Auth/RevokeSessionController.php` | Auth / Security | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Auth/SupplierRegistrationController.php` | Auth / Security | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `app/Http/Controllers/Auth/TwoFactorChallengeController.php` | Auth / Security | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/ConversationMessageController.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/ExportDownloadController.php` | Exports | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `app/Http/Controllers/Finance/FinanceDrpController.php` | Finance | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only; Hashid/private attachment contracts |
| `app/Http/Controllers/Finance/FinanceDrpPaidController.php` | Finance | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Finance/FinanceGaClaimController.php` | Finance | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Finance/FinanceInvoiceController.php` | Finance | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Finance/FinanceVendorController.php` | Finance | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Finance/LocalInvoiceSettlementController.php` | Finance | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Finance/LocalProcurementController.php` | Finance | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Ga/EmployeeController.php` | GA | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Ga/GaController.php` | GA | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `app/Http/Controllers/LocalInvoiceReceiptController.php` | Shared / Localization | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `app/Http/Controllers/LocalSupplier/InvoiceController.php` | Supplier Local | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/LocalSupplier/VendorProfileController.php` | Supplier Local | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/NotificationController.php` | Notifications | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `app/Http/Controllers/Purchasing/AwardConsolidationController.php` | Purchasing | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Purchasing/ConversationController.php` | Purchasing | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Purchasing/ExportController.php` | Purchasing | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Purchasing/MaterialCalculationController.php` | Purchasing | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Purchasing/MaterialClaimController.php` | Purchasing | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only; Regional: display copy only |
| `app/Http/Controllers/Purchasing/PeriodController.php` | Purchasing | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Purchasing/PoDocumentController.php` | Purchasing | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Purchasing/PoItemProgressController.php` | Purchasing | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only; Regional: display copy only |
| `app/Http/Controllers/Purchasing/PrItemController.php` | Purchasing | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Purchasing/PriceComparisonController.php` | Purchasing | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Purchasing/PurchaseOrderController.php` | Purchasing | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only; Regional: display copy only; Hashid/private attachment contracts |
| `app/Http/Controllers/Purchasing/PurchaseRequisitionController.php` | Purchasing | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Purchasing/PurchasingController.php` | Purchasing | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Purchasing/PurchasingLocalVendorController.php` | Purchasing | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Purchasing/QuotationListController.php` | Purchasing | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Purchasing/ShipmentController.php` | Purchasing | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only; Regional: display copy only |
| `app/Http/Controllers/Qc/QcExportController.php` | QC | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Qc/QcInspectionController.php` | QC | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/ReceiptVerificationController.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Supplier/ClaimController.php` | Supplier Import | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only; Regional: display copy only |
| `app/Http/Controllers/Supplier/ExportController.php` | Supplier Import | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Supplier/PoItemProgressController.php` | Supplier Import | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only; Regional: display copy only |
| `app/Http/Controllers/Supplier/QuotationController.php` | Supplier Import | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Supplier/SupplierPriceHistoryController.php` | Supplier Import | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/Supplier/SupplierPurchaseOrderController.php` | Supplier Import | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/Supplier/SupplierShipmentController.php` | Supplier Import | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | Regional: display copy only |
| `app/Http/Controllers/SupplierRegistrationReviewController.php` | Shared / Localization | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Controllers/UserNotificationPreferenceController.php` | Notifications | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Controllers/UserPreferenceController.php` | Preferences / Runtime Locale | CLEAN | Save self-account locale; flash feedback in the newly saved/reset language. | - |
| `app/Http/Middleware/EnforceAuthSessionSecurity.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Middleware/EnsurePasswordConfirmation.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Middleware/EnsureRegistrationSession.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Middleware/RoleMiddleware.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Requests/Auth/SupplierRegistrationRequest.php` | Auth / Security | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Requests/LocalInvoice/ResubmitLocalInvoiceRequest.php` | Local Invoice | CLEAN | Translate immutable invoice-number revision validation. | - |
| `app/Http/Requests/LocalInvoice/StoreLocalInvoiceRequest.php` | Local Invoice | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Http/Requests/LocalInvoice/UploadLocalGrImportRequest.php` | Local Invoice | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Requests/LocalInvoice/UploadLocalPoDocumentRequest.php` | Local Invoice | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Requests/LocalInvoice/UploadLocalPoImportRequest.php` | Local Invoice | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Requests/SavePurchaseRequisitionRequest.php` | Shared / Localization | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `app/Http/Requests/UpdateNotificationPreferenceRequest.php` | Notifications | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Http/Requests/UpdateUserPreferenceRequest.php` | Preferences / Runtime Locale | CLEAN | Allow only en/id and localize custom validation feedback. | - |
| `app/Imports/AbstractPreviewImport.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Imports/LocalGrImport.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Imports/LocalPoImport.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Imports/PrItemsImport.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Imports/QuotationItemsImport.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Jobs/GenerateWorkbookJob.php` | Shared / Localization | CLEAN | Apply captured workbook locale and restore it in finally. | - |
| `app/Jobs/ProcessExportJob.php` | Exports | CLEAN | Apply captured locale for construction/count/queue preparation and restore worker locale. | - |
| `app/Listeners/LogAuthenticationEvent.php` | Shared / Localization | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Models/Conversation.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Models/ExportJob.php` | Exports | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Models/GaClaim.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Models/LocalInvoice.php` | Shared / Localization | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only; Hashid/private attachment contracts |
| `app/Models/PrItem.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Models/PurchaseOrder.php` | Shared / Localization | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only; Hashid/private attachment contracts |
| `app/Models/Quotation.php` | Shared / Localization | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only; Hashid/private attachment contracts |
| `app/Models/QuotationItem.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Models/UserPreference.php` | Preferences / Runtime Locale | CLEAN | Add locale to the preference fillable contract. | - |
| `app/Notifications/SystemNotification.php` | Notifications | CLEAN | Render trusted template keys/replacements explicitly per recipient. | - |
| `app/Services/Auth/CompleteLoginService.php` | Auth / Security | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/Dashboard/DashboardWidgetService.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/ExportProgressService.php` | Exports | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/Ga/GaClaimService.php` | GA | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Services/Ga/GaVerificationService.php` | GA | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/LocalInvoice/DeliveryScheduleValidator.php` | Local Invoice | MIXED: pre-existing untracked | Translate validation feedback only on pre-existing untracked BusinessTime validator. | BusinessTime: copy/display boundary only; Pre-existing untracked source; never recreated/reset |
| `app/Services/LocalInvoice/InvoiceDocumentService.php` | Local Invoice | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/LocalInvoice/InvoiceExpiryService.php` | Local Invoice | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `app/Services/LocalInvoice/InvoiceFilenameParser.php` | Local Invoice | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `app/Services/LocalInvoice/InvoiceNotificationService.php` | Local Invoice | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/LocalInvoice/InvoicePhysicalReceiptService.php` | Local Invoice | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Services/LocalInvoice/InvoiceSubmissionService.php` | Local Invoice | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Services/LocalInvoice/InvoiceVerificationService.php` | Local Invoice | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/LocalInvoice/InvoiceWorkflowService.php` | Local Invoice | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/LocalInvoice/LocalGrImportService.php` | Local Invoice | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/LocalInvoice/LocalGrReservationService.php` | Local Invoice | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/LocalInvoice/LocalPoDocumentService.php` | Local Invoice | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Services/LocalInvoice/LocalPoGrImportService.php` | Local Invoice | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/LocalInvoice/LocalPoImportService.php` | Local Invoice | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/LocalInvoice/LocalPoReferenceService.php` | Local Invoice | CLEAN | Translate internal reference exceptions only at the display boundary; preserve machine classification. | - |
| `app/Services/LocalInvoice/LocalProcurementMasterService.php` | Local Invoice | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/MaterialProgressService.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/Materials/HsCodeResolver.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/Materials/MaterialDataQualityService.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/Materials/MaterialWeightCalculator.php` | Shared / Localization | CLEAN | Preserve numeric calculations and English pure result; attach display key. | - |
| `app/Services/Materials/PrItemProcessor.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/Materials/PurchaseRequisitionItemSynchronizer.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/NotificationPreferenceService.php` | Notifications | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/NotificationService.php` | Notifications | CLEAN | Freeze recipient-locale copy with fresh batched preferences and existing delivery policy. | - |
| `app/Services/Payment/LocalInvoicePaymentService.php` | Payment | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/Payment/LocalInvoiceVoucherService.php` | Payment | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/Payment/PaymentBatchService.php` | Payment | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Services/Payment/PaymentExecutionService.php` | Payment | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/Payment/PaymentForecastService.php` | Payment | MIXED | Translate week display templates; preserve dates, month keys, queries and amounts. | BusinessTime: copy/display boundary only |
| `app/Services/Payment/PaymentVoucherService.php` | Payment | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Services/Payment/SupplierOverpaymentService.php` | Payment | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Services/PrItemAwardService.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/PurchaseOrderGenerationService.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/QuickAccessService.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/ShipmentService.php` | Shared / Localization | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Services/SupplierRegistrationService.php` | Shared / Localization | MIXED | Translate registration feedback and five actual notification events with recipient templates. | BusinessTime: copy/display boundary only |
| `app/Services/UserPreferenceService.php` | Preferences / Runtime Locale | CLEAN | Resolve, normalize and preserve locale in existing transaction/cache/reset contracts. | - |
| `app/Services/VendorMaster/VendorChangeRequestService.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Services/VendorMaster/VendorMasterService.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Support/ConversationPresenter.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Support/ExportDispatcher.php` | Exports | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Support/NotificationCategory.php` | Notifications | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Support/PortalContext.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Support/RateLimitResponse.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Support/SpreadsheetImportReader.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `app/Support/StatusHelper.php` | Shared / Localization | MIXED | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | BusinessTime: copy/display boundary only |
| `app/Support/SupplierPriceHistoryBuilder.php` | Shared / Localization | CLEAN | Localize audited feedback/display/templates with named placeholders; preserve domain behavior. | - |
| `bootstrap/app.php` | Shared / Localization | CLEAN | Register locale middleware after session in the existing web lifecycle. | - |
| `config/app.php` | Shared / Localization | MIXED | Lock application default and fallback to en. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `config/dashboard_widgets.php` | Shared / Localization | CLEAN | Use semantic display keys; preserve machine option/event/widget identifiers. | - |
| `config/notification_categories.php` | Notifications | CLEAN | Use semantic display keys; preserve machine option/event/widget identifiers. | - |
| `config/notification_preferences.php` | Notifications | CLEAN | Use semantic display keys; preserve machine option/event/widget identifiers. | - |
| `config/quick_access.php` | Shared / Localization | CLEAN | Use semantic display keys; preserve machine option/event/widget identifiers. | - |
| `config/regional_display.php` | Shared / Localization | CLEAN | Use semantic display keys; preserve machine option/event/widget identifiers. | - |
| `config/user_preferences.php` | Preferences / Runtime Locale | CLEAN | Declare en/id options and default en. | - |
| `lang/en/auth.php` | Translation domain auth | CLEAN | Paired auth semantic keys, manually authored copy and placeholders. | - |
| `lang/en/pagination.php` | Translation domain pagination | CLEAN | Paired pagination semantic keys, manually authored copy and placeholders. | - |
| `lang/en/validation.php` | Translation domain validation | CLEAN | Paired validation semantic keys, manually authored copy and placeholders. | - |
| `public/assets/js/adasi-alert.js` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `public/assets/js/async-export.js` | Exports | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/js/app.js` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/js/calendar-core.js` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/js/calendar.js` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/js/file-upload.js` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/js/number-input-helper.js` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/js/password-assistance.js` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/js/preferences.js` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/js/server-tabs.js` | Shared / Localization | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/js/unsaved-changes.js` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/accounting/dashboard.blade.php` | Accounting | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/accounting/invoices/index.blade.php` | Accounting | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/accounting/invoices/show.blade.php` | Accounting | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/accounting/reports/index.blade.php` | Accounting | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/admin/announcements/create.blade.php` | Admin | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/admin/announcements/edit.blade.php` | Admin | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/admin/announcements/index.blade.php` | Admin | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/admin/auth-audit-logs/index.blade.php` | Admin | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/admin/dashboard.blade.php` | Admin | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/admin/exchange-rates/index.blade.php` | Admin | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/admin/material-hs-code/_material_form.blade.php` | Admin | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/admin/material-hs-code/_rule_form.blade.php` | Admin | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/admin/material-hs-code/_script.blade.php` | Admin | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/admin/material-hs-code/index.blade.php` | Admin | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/admin/requisitions/show.blade.php` | Admin | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/admin/users/create.blade.php` | Admin | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/admin/users/edit.blade.php` | Admin | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/admin/users/index.blade.php` | Admin | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/admin/users/supplier-scopes.blade.php` | Admin | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/auth/confirm-password.blade.php` | Auth / Security | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/auth/forgot-password.blade.php` | Auth / Security | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/auth/login.blade.php` | Auth / Security | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/auth/password-confirmation-continuation.blade.php` | Auth / Security | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/auth/rate-limited.blade.php` | Auth / Security | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/auth/register.blade.php` | Auth / Security | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/auth/supplier-register-success.blade.php` | Auth / Security | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/auth/supplier-register.blade.php` | Auth / Security | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/auth/supplier-registration-access.blade.php` | Auth / Security | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/auth/supplier-registration-edit.blade.php` | Auth / Security | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/auth/supplier-registration-status.blade.php` | Auth / Security | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/auth/two-factor-challenge.blade.php` | Auth / Security | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/purchasing/comparison-tabs.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/supplier/price-history-tabs.blade.php` | Supplier Import | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/alert.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/avatar.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/breadcrumb.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/button.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/dashboard-layout.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/data-table.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/date-picker.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/date-range-picker.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/dialog.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/drawer.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/empty-state.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/file-upload.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/image-lightbox.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/input.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/multi-select.blade.php` | Shared / Localization | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/components/ui/pagination.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/searchable-select.blade.php` | Shared / Localization | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/components/ui/select.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/tabs.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/textarea.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/toast-container.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/toast.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/components/ui/toolbar.blade.php` | Shared / Localization | CLEAN | Stack toolbar controls on narrow screens; desktop row layout remains. | - |
| `resources/views/conversations/show.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/dashboard.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/errors/403.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/errors/404.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/errors/419.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/errors/429.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/errors/500.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/exports/index.blade.php` | Exports | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/dashboard.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/drp/_paid_table_content.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/drp/ga.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/drp/paid.blade.php` | Finance | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Polling: copy/layout only |
| `resources/views/finance/drp/show.blade.php` | Finance | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Polling: copy/layout only |
| `resources/views/finance/drp/supplier.blade.php` | Finance | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Polling: copy/layout only |
| `resources/views/finance/ga-claims/index.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/ga-claims/show.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/invoices/index.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/invoices/show.blade.php` | Finance | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | BusinessTime: copy/display boundary only; Regional: display copy only |
| `resources/views/finance/local-procurement/_import_gr_modal.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/local-procurement/_import_po_modal.blade.php` | Finance | CLEAN | Translate preview/errors; escape raw PO/supplier values and preserve token/action/row cap. | - |
| `resources/views/finance/local-procurement/_upload_po_modal.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/local-procurement/form.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/local-procurement/index.blade.php` | Finance | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Hashid/private attachment contracts |
| `resources/views/finance/local-procurement/show.blade.php` | Finance | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Hashid/private attachment contracts |
| `resources/views/finance/master-invoices/index.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/overpayments/index.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/vendors/index.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/vendors/show.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/finance/vouchers/print.blade.php` | Finance | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | BusinessTime: copy/display boundary only |
| `resources/views/finance/vouchers/show.blade.php` | Finance | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/ga/claims/create.blade.php` | GA | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/ga/claims/index.blade.php` | GA | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/ga/claims/receipt.blade.php` | GA | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | BusinessTime: copy/display boundary only |
| `resources/views/ga/claims/revision.blade.php` | GA | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/ga/claims/show.blade.php` | GA | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/ga/dashboard.blade.php` | GA | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/ga/drp/draft.blade.php` | GA | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/ga/employees/index.blade.php` | GA | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/layouts/app.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/layouts/auth.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/layouts/guest.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/local-invoices/detail.blade.php` | Local Invoice | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | BusinessTime: copy/display boundary only; Regional: display copy only; Hashid/private attachment contracts |
| `resources/views/local-invoices/filters.blade.php` | Local Invoice | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/local-invoices/form.blade.php` | Local Invoice | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | BusinessTime: copy/display boundary only |
| `resources/views/local-invoices/partials/_documents_grid.blade.php` | Local Invoice | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/local-invoices/scripts.blade.php` | Local Invoice | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/local-invoices/table.blade.php` | Local Invoice | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/local-supplier/context.blade.php` | Supplier Local | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/local-supplier/dashboard.blade.php` | Supplier Local | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/local-supplier/information.blade.php` | Supplier Local | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/local-supplier/invoices/create.blade.php` | Supplier Local | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/local-supplier/invoices/index.blade.php` | Supplier Local | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/local-supplier/invoices/receipt.blade.php` | Supplier Local | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/local-supplier/invoices/revision.blade.php` | Supplier Local | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/local-supplier/invoices/show.blade.php` | Supplier Local | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/local-supplier/purchase-orders/index.blade.php` | Supplier Local | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Hashid/private attachment contracts |
| `resources/views/local-supplier/purchase-orders/show.blade.php` | Supplier Local | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Hashid/private attachment contracts |
| `resources/views/local-supplier/vendor-profile/show.blade.php` | Supplier Local | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/notifications/index.blade.php` | Notifications | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/partials/alerts.blade.php` | Shared / Localization | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/partials/chat-drawer.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/partials/navbar.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/partials/notification-panel.blade.php` | Notifications | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/partials/sidebar.blade.php` | Shared / Localization | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/partials/vendor-change-request-details.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/pdf/po-pdf.blade.php` | Shared / Localization | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | BusinessTime: copy/display boundary only |
| `resources/views/pdf/qc-inspection-pdf.blade.php` | Shared / Localization | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | BusinessTime: copy/display boundary only |
| `resources/views/profile/customization.blade.php` | Profile / Preferences | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/profile/edit.blade.php` | Profile / Preferences | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/profile/notifications.blade.php` | Profile / Preferences | CLEAN | Translate display registry and wrap longer controls at 320 CSS px. | - |
| `resources/views/profile/partials/active-sessions.blade.php` | Profile / Preferences | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/profile/partials/logout-other-devices-form.blade.php` | Profile / Preferences | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/profile/partials/two-factor-authentication-form.blade.php` | Profile / Preferences | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/profile/partials/update-password-form.blade.php` | Profile / Preferences | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/profile/partials/update-profile-information-form.blade.php` | Profile / Preferences | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/profile/security.blade.php` | Profile / Preferences | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/profile/two-factor-recovery-codes.blade.php` | Profile / Preferences | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/profile/two-factor-setup.blade.php` | Profile / Preferences | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/claims/create.blade.php` | Purchasing | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Hashid/private attachment contracts |
| `resources/views/purchasing/claims/index.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/claims/show.blade.php` | Purchasing | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Regional: display copy only; Hashid/private attachment contracts |
| `resources/views/purchasing/comparison/_historical_content.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/comparison/_inter_supplier_content.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/comparison/_scripts.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/comparison/_vs_best_content.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/comparison/historical.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/comparison/inter-supplier.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/comparison/vs-best.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/conversations/index.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/dashboard.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/drp/_paid_table_content.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/drp/ga.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/drp/paid.blade.php` | Purchasing | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/purchasing/drp/show.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/drp/supplier.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/local-vendors/index.blade.php` | Purchasing | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/purchasing/local-vendors/invoice-show.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/local-vendors/show.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/periods/index.blade.php` | Purchasing | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/purchasing/po/consolidate-awards.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/po/create.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/po/index.blade.php` | Purchasing | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/purchasing/po/show.blade.php` | Purchasing | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Regional: display copy only; Hashid/private attachment contracts |
| `resources/views/purchasing/pr/_import.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/pr/_import_controls.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/pr/_item_row.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/pr/_material_shape_script.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/pr/_supplier_picker_modal.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/pr/create.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/pr/edit.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/pr/index.blade.php` | Purchasing | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/purchasing/pr/show.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/quotations/index.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/quotations/show.blade.php` | Purchasing | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Regional: display copy only; Hashid/private attachment contracts |
| `resources/views/purchasing/reports/index.blade.php` | Purchasing | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/purchasing/shipments/index.blade.php` | Purchasing | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Regional: display copy only |
| `resources/views/purchasing/shipments/show.blade.php` | Purchasing | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Regional: display copy only; Hashid/private attachment contracts |
| `resources/views/qc/dashboard.blade.php` | QC | CLEAN | Translate QC copy and fix chart-grid reflow at 320 CSS px. | - |
| `resources/views/qc/inspections/create.blade.php` | QC | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/qc/inspections/index.blade.php` | QC | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/qc/inspections/show.blade.php` | QC | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Hashid/private attachment contracts |
| `resources/views/receipts/verify-ga.blade.php` | Shared / Localization | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | BusinessTime: copy/display boundary only |
| `resources/views/receipts/verify-supplier.blade.php` | Shared / Localization | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | BusinessTime: copy/display boundary only |
| `resources/views/supplier-registrations/index.blade.php` | Supplier Registration | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | BusinessTime: copy/display boundary only |
| `resources/views/supplier-registrations/show.blade.php` | Supplier Registration | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/supplier/announcements/index.blade.php` | Supplier Import | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/supplier/announcements/show.blade.php` | Supplier Import | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/supplier/claims/index.blade.php` | Supplier Import | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/supplier/claims/show.blade.php` | Supplier Import | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Regional: display copy only; Hashid/private attachment contracts |
| `resources/views/supplier/conversations/index.blade.php` | Supplier Import | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/supplier/dashboard.blade.php` | Supplier Import | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/supplier/po/index.blade.php` | Supplier Import | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/supplier/po/show.blade.php` | Supplier Import | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Regional: display copy only; Hashid/private attachment contracts |
| `resources/views/supplier/price-history/historical.blade.php` | Supplier Import | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/supplier/price-history/index.blade.php` | Supplier Import | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/supplier/quotations/_import_controls.blade.php` | Supplier Import | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/supplier/quotations/create.blade.php` | Supplier Import | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Hashid/private attachment contracts |
| `resources/views/supplier/quotations/index.blade.php` | Supplier Import | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/supplier/quotations/period.blade.php` | Supplier Import | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `resources/views/supplier/quotations/show.blade.php` | Supplier Import | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Regional: display copy only; Hashid/private attachment contracts |
| `resources/views/supplier/shipments/create.blade.php` | Supplier Import | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/supplier/shipments/index.blade.php` | Supplier Import | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Regional: display copy only |
| `resources/views/supplier/shipments/show.blade.php` | Supplier Import | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/vendor/pagination/simple-tailwind.blade.php` | Shared / Localization | CLEAN | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | - |
| `resources/views/vendor/pagination/tailwind.blade.php` | Shared / Localization | MIXED | Translate audited display/accessible/JS copy; preserve data, selectors and routes. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `app/Http/Controllers/AttachmentController.php` | Shared / Security | MIXED | Localize the missing-file response while retaining private file streaming and response hardening. | Pre-existing attachment streaming/security changes |
| `app/Models/ExchangeRate.php` | Purchasing / Regional | CLEAN | Localize rendered currency option labels while preserving codes and the legacy CURRENCY_LABELS constant. | - |
| `app/Models/MaterialMaster.php` | Admin / Master Data | CLEAN | Add locale-aware display labels for controlled category, density and manufacturer-scope values. | - |
| `app/Services/RegionalDisplayFormatter.php` | Shared / Regional | MIXED | Localize month names in account-language date displays; retain configured formats and business-time conversion. | Regional preferences / BusinessTime |
| `resources/css/app.css` | Shared / Accessibility | MIXED | Raise on-surface-variant contrast for readable translated copy. | Phase 6 palette and theme tokens |
| `resources/views/components/empty-state.blade.php` | Shared / Common | CLEAN | Localize the default empty-state title. | - |
| `routes/auth.php` | Auth / Password Assistance | CLEAN | Allow the informational assistance page to use an authenticated account locale; no password reset or mail flow is added. | - |
| `routes/web.php` | Shared / Runtime Locale | CLEAN | Register the allow-listed locale switch used by the public supplier-registration selector. | - |

## Tests ? New

| Exact file | Module | Classification | Reason | Mixed-work overlap |
|---|---|---|---|---|
| `tests/Feature/DataTablesLocalizationTest.php` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Feature/LocaleRuntimeTest.php` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Feature/LocalizationExportTest.php` | Exports | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Feature/LocalizationImportRenderingTest.php` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Feature/LocalizationLocalRenderingTest.php` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Feature/LocalizationPerformanceTest.php` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Feature/LocalizationRenderingTest.php` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Feature/LocalizationValidationTest.php` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Feature/NotificationLocaleTest.php` | Notifications | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Feature/SharedLocalizationRenderingTest.php` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Feature/UserLocalePreferenceMigrationTest.php` | Preferences / Runtime Locale | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Feature/UserLocalePreferencesTest.php` | Preferences / Runtime Locale | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Support/user-facing-copy-inventory.php` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Unit/TranslationContentTest.php` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Unit/TranslationParityTest.php` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Unit/UserFacingCopyInventoryTest.php` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/js/i18n-fixture.mjs` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/js/local-po-preview-localization.test.mjs` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/js/localization-interactions.test.mjs` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/js/localization.test.mjs` | Verification | CLEAN | Add Phase 7 behavior/security/parity regression coverage; inspect exact test methods. | - |
| `tests/Feature/SupplierRegistration/SupplierRegistrationLocaleTest.php` | Supplier Registration | CLEAN | Cover guest session switching, safe redirects, localized form copy and authenticated POST persistence. | - |
| `tests/Support/copy-inventory-fixtures.php` | Verification | CLEAN | Provide focused fixtures for conditional Blade, JS references, interpolation and Alpine text bindings. | - |
| `tests/Support/user-facing-copy-audit.php` | Verification | CLEAN | Rebuild the source-located semantic copy-decision ledger and its disposition summary. | - |

## Tests ? Modified

| Exact file | Module | Classification | Reason | Mixed-work overlap |
|---|---|---|---|---|
| `tests/Feature/AsyncExportQueueTest.php` | Exports | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/CalendarComponentTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/CustomAdasiAlertTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/CustomAdasiToastTest.php` | Verification | MIXED | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | Polling: copy/layout only |
| `tests/Feature/ErrorPagesTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/Finance/PaymentBatchDrpExportTest.php` | Finance | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/Finance/PaymentBatchTransferExportTest.php` | Finance | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/FinanceDrpPaidTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/FinanceVerificationV2Test.php` | Verification | MIXED | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | Pre-existing user work; inspect Phase 7 diff against saved baseline |
| `tests/Feature/GaClaimWorkflowTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/LocalInvoice/InvoiceNotificationCopyTest.php` | Local Invoice | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/LocalInvoice/LocalGrImportTest.php` | Local Invoice | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/LocalInvoice/LocalInvoiceTest.php` | Local Invoice | MIXED | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | Hashid/private attachment contracts |
| `tests/Feature/LocalInvoice/LocalPoImportTest.php` | Local Invoice | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/LocalInvoice/LocalSupplierOverpaymentTransparencyTest.php` | Local Invoice | MIXED | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | Hashid/private attachment contracts |
| `tests/Feature/LocalInvoice/LocalSupplierWholeGrSettlementTest.php` | Local Invoice | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/NotificationCategoryOrderTest.php` | Notifications | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/NotificationPreferenceDeliveryTest.php` | Notifications | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/NotificationPreferencesUiTest.php` | Notifications | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/PaymentForecastAndReportingTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/Purchasing/PurchasingDrpReadOnlyTest.php` | Purchasing | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/RegionalDashboardRenderingTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/RegionalExportHistoryTest.php` | Exports | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/RegionalGaClaimDisplayTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/RegionalInvoiceDetailCalendarTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/RegionalScopeProtectionTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/RegionalSettingsUiTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/RenderedComponentTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/ShipmentUiAndExportTest.php` | Exports | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/SidebarShellTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/Supplier/SupplierPortalContextV2Test.php` | Supplier Import | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/SupplierPriceHistoryBuilderTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/SupplierRegistration/SupplierRegistrationTest.php` | Supplier Registration | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/UnifiedPaymentEngineTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/UserCustomizationTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/UserDashboardRenderingTest.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/UserNotificationPreferencesTest.php` | Notifications | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/UserPreferenceConcurrencyTest.php` | Preferences / Runtime Locale | MIXED | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | Regional: display copy only |
| `tests/Feature/UserPreferenceMigrationTest.php` | Preferences / Runtime Locale | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/_user-preference-concurrency-worker.php` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/js/calendar.test.mjs` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/js/dashboard-customization.test.mjs` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/js/dashboard-drag-drop.test.mjs` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/js/password-assistance.test.mjs` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/js/po-progress-history.test.mjs` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/js/regional-async-export-counts.test.mjs` | Exports | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/js/regional-export-history.test.mjs` | Exports | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/js/unsaved-changes.test.mjs` | Verification | CLEAN | Keep existing behavioral assertions; adapt copy expectations or add explicit locale regression. | - |
| `tests/Feature/AdminMaterialHsCodeTest.php` | Admin / Master Data | CLEAN | Verify bilingual category/density/scope/shape labels without changing stored codes. | - |
| `tests/Feature/Auth/AuthAuditSecurityTest.php` | Auth / Security | CLEAN | Verify translated audit display labels while preserving event codes and escaped output. | - |
| `tests/Feature/CashierReceiptAndExpiryTest.php` | Local Invoice / Finance | MIXED | Verify localized historical status messages and receipt rendering. | Existing cashier QR, Finance and BusinessTime work |
| `tests/Feature/PoItemMaterialProgressTest.php` | Purchasing | CLEAN | Verify material-progress summary text in EN/ID while retaining item states. | - |
| `tests/Feature/PriceComparisonPerformanceRegressionTest.php` | Purchasing | CLEAN | Update count assertions to complete localized singular/plural messages. | - |
| `tests/Feature/PurchaseRequisitionMaterialAutomationTest.php` | Purchasing | CLEAN | Assert the localized weight-unit label without changing the import contract. | - |
| `tests/Feature/RegionalFinalClosureSweepTest.php` | Shared / Regional | MIXED | Verify the localized fixed-date presentation method in the regional closure guard. | Regional display and BusinessTime work |
| `tests/Unit/RegionalDisplayFormatterTest.php` | Shared / Regional | MIXED | Verify English/Indonesian month names while preserving regional date patterns. | Regional preference formatter |
| `tests/js/preferences.test.mjs` | Shared / Accessibility | MIXED | Verify contrast for muted translated copy on light/dark work surfaces. | Phase 6 theme palette |
| `tests/js/regional-preferences.test.mjs` | Shared / Regional | MIXED | Verify localized month names without changing configured date/time/number preferences. | Regional preferences |

## Reused verification files

All tracked PHP test files are included by `git ls-files tests/Feature/*Test.php tests/Unit/*Test.php`; untracked unrelated Timezone/Architecture suites remain outside the Phase 7 pass set. All `tests/js/*.test.mjs` are run, including the new preview security renderer test.

## Preservation limitations

CLEAN means no pre-existing tracked diff at intake; it is not a correctness verdict. MIXED files contain user work predating Phase 7. The preserved patch reconstructs the 157 tracked dirty files; available copied baseline files total 319. Source, tests and browser evidence are still required before completion.
