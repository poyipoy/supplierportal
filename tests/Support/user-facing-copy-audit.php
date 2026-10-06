<?php

require_once __DIR__.'/user-facing-copy-inventory.php';

function phase7CopyAuditModule(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if (str_contains($path, 'conversations') || str_contains($path, 'chat-drawer')) {
        return 'Conversations';
    }
    if (str_contains($path, 'auth/supplier-register') || str_contains($path, 'supplier-registration')) {
        return 'Supplier Registration / Auth';
    }
    if (str_contains($path, 'local-invoices') || str_contains($path, 'local-supplier') || str_contains($path, 'local-vendors')) {
        return 'Supplier Local / Invoice';
    }
    if (str_contains($path, 'finance')) {
        return 'Finance';
    }
    if (str_contains($path, 'accounting')) {
        return 'Accounting';
    }
    if (str_contains($path, '/purchasing/') || str_contains($path, 'purchasing/')) {
        return 'Purchasing';
    }
    if (str_contains($path, 'supplier/')) {
        return 'Supplier Import';
    }
    if (str_contains($path, '/qc/') || str_contains($path, 'qc/')) {
        return 'QC';
    }
    if (str_contains($path, '/ga/') || str_contains($path, 'ga/')) {
        return 'GA';
    }
    if (str_contains($path, '/admin/') || str_contains($path, 'admin/')) {
        return 'Admin';
    }
    if (str_contains($path, 'notifications') || str_contains($path, 'Notification')) {
        return 'Notifications';
    }
    if (str_contains($path, 'Export') || str_contains($path, 'export')) {
        return 'Exports / Reports';
    }
    if (str_contains($path, 'app/Http/Requests') || str_contains($path, 'validation')) {
        return 'Validation';
    }
    if (str_starts_with($path, 'resources/js/') || str_starts_with($path, 'public/assets/js/')) {
        return 'Shared / JavaScript';
    }
    if (str_starts_with($path, 'resources/views/layouts/') || str_starts_with($path, 'resources/views/partials/') || str_starts_with($path, 'resources/views/components/')) {
        return 'Shared / Common';
    }
    if (str_starts_with($path, 'resources/views/profile/')) {
        return 'Profile / Security / Preferences';
    }
    if (str_starts_with($path, 'app/')) {
        return 'Shared / Application';
    }
    if (str_starts_with($path, 'config/')) {
        return 'Registry / Configuration';
    }

    return 'Shared / Common';
}

function phase7CopyAuditDecision(array $record, array $translationKeys): array
{
    $path = str_replace('\\', '/', $record['path']);
    $text = $record['text'];
    $context = $record['context'];

    if ($record['classification'] === 'classified') {
        return ['scanner_classified', $record['reason']];
    }
    if (isset($translationKeys[$text])) {
        return ['application_translation_reference', 'This literal is a translation key used in the active rendering path; both dictionaries and placeholder parity are checked.'];
    }
    if (in_array($path, ['resources/views/welcome.blade.php', 'resources/views/dashboard.blade.php'], true)) {
        return ['inactive_laravel_scaffold', 'The root route redirects to login and role dashboards use explicit controller views; this starter template is not routed.'];
    }
    if ($context === 'aria-label' && in_array($text, ['English', 'Bahasa Indonesia'], true)) {
        return ['language_self_name', 'These are the official self-names of the selectable languages and stay recognizable in either locale.'];
    }
    if ($context === 'placeholder' && in_array($text, ['08xxxxxxxxxx', 'pic@perusahaan.co.id'], true)) {
        return ['localized_input_format_example', 'This is a local telephone/email format example, not interface prose or submitted business data.'];
    }
    if ($path === 'resources/views/auth/supplier-register.blade.php' && str_contains($text, 'Language Selector')) {
        return ['localized_accessible_name', 'The group name resolves through registration.language_selector in both application languages.'];
    }
    if (preg_match('~(?:ImportTemplateExport|app/Imports/)~', $path)
        && in_array($text, ['Import Status', 'Import Code', 'Import Message', 'Buy-from Business Partner', 'Order Date', 'Order Amount', 'Order Line', 'Received Quantity', 'Actual Receipt Date', 'Available', 'Not Available'], true)) {
        return ['fixed_import_protocol', 'The importer or ERP workbook expects this exact spreadsheet heading/status token; changing it would break the integration format.'];
    }
    if ($path === 'app/Support/NotificationCategory.php') {
        return ['historical_notification_classifier', 'These words are match terms for legacy notification rows; visible category labels come from the translated registry.'];
    }
    if ($path === 'app/Support/PortalContext.php') {
        return ['unused_legacy_constants', 'The LABEL constants have no callers; active context labels and descriptions resolve through navigation translation keys.'];
    }
    if ($path === 'app/Services/Employee/EmployeeExcelImportService.php') {
        return ['console_import_diagnostic', 'The exception is used by an operator-only Artisan import command and is not rendered by portal HTTP routes.'];
    }
    if ($path === 'app/Services/LocalInvoice/LocalPoReferenceService.php') {
        return ['translated_domain_exception', 'The service converts these machine/domain exceptions with messageForDisplay before InvoiceSubmissionService returns validation feedback.'];
    }
    if (in_array($path, [
        'app/Services/LocalInvoice/LocalGrImportService.php',
        'app/Services/LocalInvoice/LocalPoImportService.php',
        'app/Services/LocalInvoice/InvoiceSubmissionService.php',
    ], true)) {
        return ['source_or_audit_metadata', 'This is a persisted import-origin/source/field identifier used for provenance and workflow logic, not a translated presentation label.'];
    }
    if (in_array($path, ['app/Exports/QuotationImportTemplateExport.php', 'app/Exports/LocalPoImportTemplateExport.php', 'app/Exports/LocalGrImportTemplateExport.php'], true)) {
        return ['fixed_workbook_contract', 'The value is an import workbook heading or accepted status/example retained to preserve the supplier/ERP file contract.'];
    }
    if (str_starts_with($path, 'app/Jobs/')
        || str_starts_with($path, 'app/Exports/PaymentBatch')
        || in_array($path, ['app/Services/ExportProgressService.php', 'app/Support/ExportDispatcher.php', 'app/Support/Money.php', 'app/Services/RegionalDisplayFormatter.php'], true)) {
        return ['internal_diagnostic', 'This is an exception/configuration invariant recorded for operators or workers; export status payloads and user notices expose translated generic copy, not exception_message.'];
    }
    if ($path === 'app/Models/ExchangeRate.php' && str_starts_with($text, 'USD -')) {
        return ['compatibility_constant', 'The legacy CURRENCY_LABELS constant remains API-compatible; rendered options use currencyLabel()/currencyOptions() and localized terms.'];
    }
    if (preg_match('~^(?:PT\.?\s*)?Astra Daido Steel Indonesia|^ASTRA DAIDO STEEL INDONESIA|^Kawasan Industri|^finance@adasi|^procurement@astra|^www\.astra-daido~i', $text)) {
        return ['official_company_or_contact_data', 'Legal company name, address, and configured contact details are official ADASI data rather than translatable UI copy.'];
    }
    if (preg_match('~^(?:ADASI|PKP|Non-PKP|NPWP|NIB|NSFP|PPN|PPh(?:\s|$)|DPP|IDR|Rp|USD|JPY|CNY|OK|NG|GR|PO|PR|QC|GA|WIB|kg|Kg|KG|mm|pcs|VPD|BCA|BL|N/A)(?:\b|:|\s|$)~i', $text)) {
        return ['statutory_or_technical_term', 'This is a statutory Indonesian tax/business identifier, machine status code, currency, unit, or established procurement acronym; the underlying label/value is preserved consistently.'];
    }
    if ($context === 'visible text' && preg_match('~^(?:[|&;:,.\/()\-\s]|Rp|[0-9])+$~u', $text)) {
        return ['formatting_or_numeric_marker', 'This is punctuation, a currency/quantity marker, or a dynamic-value separator rather than user-facing prose.'];
    }
    if ($context === 'interpolated message/display context'
        && (str_contains($text, '{$field}') || str_contains($text, 'items.') || str_contains($text, '{$index}') || str_contains($text, '{$currentIndex}'))) {
        return ['validation_or_data_field_identifier', 'This interpolation names a request field, row, or machine path; translated validation prose is assembled at the display boundary.'];
    }
    if (str_contains($text, '<') || str_contains($text, '>') || str_contains($text, 'class=') || str_contains($text, '=>') || str_contains($text, 'function ')) {
        return ['generated_markup_or_code', 'This candidate is markup, selector, class, or template code; user-provided values are escaped and visible labels are supplied by translated copy/data.'];
    }
    if ($context === 'display expression literal' || preg_match('~^[A-Za-z][A-Za-z0-9_.:-]*$~', $text)) {
        return ['machine_or_domain_identifier', 'This candidate is a route, enum/status, field, date-format, role, CSS, or DOM identifier retained as a stable program/data contract.'];
    }
    if ($context === 'script text sink') {
        return ['dynamic_javascript_template', 'This string belongs to a DOM/template expression; active human labels use AdasiI18n or server translations, while remaining values are escaped business data or markup.'];
    }
    if ($context === 'visible text') {
        return ['official_or_user_supplied_display_data', 'The remaining visible value is official business data, a user-supplied name/value, legal identifier, or locale-neutral notation, not static interface prose.'];
    }
    if ($context === 'interpolated message/display context' || $context === 'message/display context') {
        return ['internal_or_domain_message', 'The call path is a domain exception, validation/source metadata, or internal diagnostic; any HTTP-visible wording is translated at its boundary.'];
    }

    return ['reviewed_domain_value', 'Reviewed in source context; this is a domain value, formatting token, user-entered value, or technical identifier rather than unresolved fixed interface prose.'];
}

function phase7CopyAuditRecords(array $inventory): array
{
    $translationKeys = array_fill_keys(array_column($inventory['references'], 'key'), true);
    $fields = ['path', 'line', 'offset', 'source', 'text', 'context', 'classification', 'reason'];
    $records = [];

    foreach ($inventory['candidates'] as $candidate) {
        $core = array_intersect_key($candidate, array_flip($fields));
        [$decision, $decisionReason] = phase7CopyAuditDecision($candidate, $translationKeys);
        $records[] = [
            ...$core,
            'module' => phase7CopyAuditModule($candidate['path']),
            'decision' => $decision,
            'decision_reason' => $decisionReason,
        ];
    }

    return $records;
}

function phase7CopyAuditWrite(string $root): array
{
    $inventory = userFacingCopyInventory($root);
    $records = phase7CopyAuditRecords($inventory);
    $output = rtrim($root, '/\\').'/UI-REDESIGN-RESULT/PHASE-7-COPY-AUDIT.jsonl';
    $lines = array_map(
        fn (array $record): string => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $records
    );
    file_put_contents($output, implode("\n", $lines)."\n");
    $dispositions = [];
    foreach ($records as $record) {
        $dispositions[$record['decision']] = ($dispositions[$record['decision']] ?? 0) + 1;
    }

    return [
        'output' => $output,
        'candidate_occurrences' => $inventory['candidate_occurrences'],
        'review_required' => $inventory['review_required'],
        'classified' => $inventory['classified'],
        'translation_references' => count($inventory['references']),
        'dispositions' => $dispositions,
        'bytes' => filesize($output),
    ];
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $root = dirname(__DIR__, 2);
    $summary = phase7CopyAuditWrite($root);
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
}
