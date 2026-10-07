<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UserFacingCopyInventoryTest extends TestCase
{
    private function scanFixture(string $name, string $path): array
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $fixtures = require dirname(__DIR__).'/Support/copy-inventory-fixtures.php';

        return userFacingCopyInventorySource($path, $fixtures[$name]);
    }

    public function test_conditional_blade_literals_and_visible_uppercase_statuses_remain_reviewable(): void
    {
        $scan = $this->scanFixture('blade', 'resources/views/fixture.blade.php');
        $records = $scan['candidates'];
        foreach (['READY_TO_PAY', 'Pending approval', 'OPEN', 'Waiting for inspection', 'No inspection found'] as $text) {
            $matches = array_values(array_filter($records, fn (array $record): bool => $record['text'] === $text));
            $this->assertNotEmpty($matches, $text);
            $this->assertSame('review_required', $matches[0]['classification'], $text);
        }
        $this->assertNotContains('Retained offset', array_column($records, 'text'));
    }

    public function test_scanner_preserves_original_line_and_byte_offsets_through_masked_regions(): void
    {
        $fixtures = require dirname(__DIR__).'/Support/copy-inventory-fixtures.php';
        $scan = $this->scanFixture('blade', 'resources/views/fixture.blade.php');
        $record = array_values(array_filter($scan['candidates'], fn (array $record): bool => $record['text'] === 'OPEN'))[0];
        $this->assertSame(8, $record['line']);
        $this->assertSame(strpos($fixtures['blade'], 'OPEN'), $record['offset']);
        $reference = array_values(array_filter($scan['references'], fn (array $reference): bool => $reference['key'] === 'js.files'))[0];
        $this->assertSame(16, $reference['line']);
        $this->assertSame(strpos($fixtures['blade'], 'js.files'), $reference['offset']);
    }

    public function test_php_interpolated_messages_are_detected_as_complete_templates(): void
    {
        $scan = $this->scanFixture('php', 'app/Services/Fixture.php');
        $messages = array_values(array_filter($scan['candidates'], fn (array $record): bool => $record['context'] === 'interpolated message/display context'));
        $this->assertCount(2, $messages);
        $this->assertSame('Shipment {$shipment->number} has arrived.', $messages[0]['text']);
        $this->assertSame(4, $messages[0]['line']);
        $this->assertSame('Invoice {$invoice} cannot be paid.', $messages[1]['text']);
        $this->assertSame(5, $messages[1]['line']);
        $this->assertSame(['review_required'], array_values(array_unique(array_column($messages, 'classification'))));
    }

    public function test_php_enum_and_validation_tokens_are_classified_without_hiding_branch_copy(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = implode("\n", [
            "{{ \$status === 'APPROVED' ? 'Approved' : 'Pending' }}",
            "{{ in_array(\$status, ['READY_TO_PAY', 'PAID'], true) ? 'Ready to Pay' : 'Waiting for payment' }}",
            "{{ trans_choice('common.chat.material_count', \$count, ['count' => \$count]) }}",
            "@php \$rules = ['required', 'string', 'max:120']; @endphp",
        ]);
        $scan = userFacingCopyInventorySource('resources/views/fixture.blade.php', $source);
        $records = array_column($scan['candidates'], null, 'text');

        foreach (['APPROVED', 'READY_TO_PAY', 'PAID', 'count'] as $token) {
            $this->assertSame('classified', $records[$token]['classification'] ?? null, $token);
        }
        foreach (['Approved', 'Pending', 'Ready to Pay', 'Waiting for payment'] as $copy) {
            $this->assertSame('review_required', $records[$copy]['classification'] ?? null, $copy);
        }
        foreach (['required', 'string', 'max:120'] as $rule) {
            $this->assertArrayNotHasKey($rule, $records);
        }
    }

    public function test_blade_model_and_array_field_keys_are_not_reported_as_display_text(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = '{{ $row[\'status\'] }} {{ $item->attributes[\'label\'] }}';
        $scan = userFacingCopyInventorySource('resources/views/fixture.blade.php', $source);
        $records = array_column($scan['candidates'], null, 'text');

        foreach (['status', 'label'] as $field) {
            $this->assertSame('classified', $records[$field]['classification'] ?? null, $field);
            $this->assertStringContainsString('field access', $records[$field]['reason']);
        }
    }

    public function test_js_t_and_choice_references_are_collected_without_translating_runtime_values(): void
    {
        $scan = $this->scanFixture('js', 'resources/js/fixture.js');
        $this->assertSame(['js.saved', 'js.files'], array_column($scan['references'], 'key'));
        $this->assertSame(['t', 'choice'], array_column($scan['references'], 'call'));
        $this->assertSame([2, 3], array_column($scan['references'], 'line'));
        $templates = array_values(array_filter($scan['candidates'], fn (array $record): bool => str_contains($record['text'], '${count}')));
        $this->assertCount(1, $templates);
        $this->assertSame(6, $templates[0]['line']);
    }

    public function test_alpine_attribute_copy_is_scanned_without_reporting_expression_code_as_visible_text(): void
    {
        $scan = $this->scanFixture('blade_alpine', 'resources/views/fixture.blade.php');
        $copy = array_values(array_filter($scan['candidates'], fn (array $record): bool => $record['text'] === 'No options are available'));
        $this->assertCount(1, $copy);
        $this->assertSame('script text sink', $copy[0]['context']);
        $this->assertSame(1, $copy[0]['line']);
        $this->assertContains('Visible copy', array_column($scan['candidates'], 'text'));
        $this->assertNotContains('if (this.disabled) return;', array_column($scan['candidates'], 'text'));
    }

    public function test_alpine_text_bindings_keep_conditional_copy_and_translation_references_reviewable(): void
    {
        $fixtures = require dirname(__DIR__).'/Support/copy-inventory-fixtures.php';
        $scan = $this->scanFixture('blade_x_text', 'resources/views/fixture.blade.php');
        $ready = array_values(array_filter($scan['candidates'], fn (array $record): bool => $record['text'] === 'Ready'))[0] ?? null;

        $this->assertNotNull($ready);
        $this->assertSame('script text sink', $ready['context']);
        $this->assertSame('review_required', $ready['classification'], json_encode($ready));
        $this->assertSame(1, $ready['line']);
        $this->assertSame(strpos($fixtures['blade_x_text'], 'Ready'), $ready['offset']);
        $this->assertSame(['js.waiting'], array_column($scan['references'], 'key'));
    }

    public function test_admin_hs_code_empty_reference_state_uses_localized_copy(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/material-hs-code/_script.blade.php');

        $this->assertNotFalse($source);
        $this->assertStringContainsString(".text(@json(__('admin.copy.none')))", $source);
        $this->assertStringNotContainsString(".text('None.')", $source);
    }

    public function test_final_semantic_ledger_covers_every_current_copy_candidate_with_a_reason(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-audit.php';
        $root = dirname(__DIR__, 2);
        $inventory = userFacingCopyInventory($root);
        $ledgerPath = $root.'/UI-REDESIGN-RESULT/PHASE-7-COPY-AUDIT.jsonl';
        $lines = file($ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines, 'The Phase 7 copy audit ledger must be present.');
        $expectedRecords = phase7CopyAuditRecords($inventory);
        $ledgerRecords = [];
        $unreviewed = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $ledgerRecords[] = $entry;

            if (! is_string($entry['module'] ?? null)
                || ! is_string($entry['decision'] ?? null)
                || trim((string) ($entry['decision_reason'] ?? '')) === '') {
                $unreviewed[] = $entry['path'].':'.$entry['line'];
            }
        }

        $fingerprint = static fn (array $record): string => hash('sha256', json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        $ledgerKeys = array_map($fingerprint, $ledgerRecords);
        $currentKeys = array_map($fingerprint, $expectedRecords);
        sort($ledgerKeys);
        sort($currentKeys);

        $this->assertSame($currentKeys, $ledgerKeys, 'Every changed/new candidate must be reviewed and stale decisions removed.');
        $this->assertSame([], $unreviewed, 'Every candidate decision must retain a module and rationale.');
        $this->assertCount($inventory['candidate_occurrences'], $ledgerRecords);
    }

    public function test_balanced_blade_directives_do_not_leak_php_expressions_into_visible_copy(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = implode("\n", [
            '@props([',
            "    'title' => 'Component title',",
            "    'config' => ['selector' => '[data-config]', 'empty' => __('common.states.empty')],",
            '])',
            "@if(\$allowed && (in_array(\$status, ['OPEN', 'DRAFT'], true)))",
            '    Visible confirmation copy',
            '@endif',
        ]);
        $scan = userFacingCopyInventorySource('resources/views/fixture.blade.php', $source);
        $texts = array_column($scan['candidates'], 'text');

        $this->assertContains('Component title', $texts);
        $this->assertContains('Visible confirmation copy', $texts);
        $this->assertNotContains('OPEN', $texts);
        $this->assertNotContains('DRAFT', $texts);
        $this->assertNotContains('data-config', $texts);

        $visible = array_values(array_filter($scan['candidates'], fn (array $record): bool => $record['text'] === 'Visible confirmation copy'))[0];
        $this->assertSame(6, $visible['line']);
        $this->assertSame(strpos($source, 'Visible confirmation copy'), $visible['offset']);
    }

    public function test_framework_route_names_are_classified_as_machine_identifiers(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = '<a href="{{ route(\'dashboard\') }}">{{ __(\'navigation.dashboard\') }}</a>';
        $scan = userFacingCopyInventorySource('resources/views/fixture.blade.php', $source);
        $route = array_values(array_filter($scan['candidates'], fn (array $record): bool => $record['text'] === 'dashboard'))[0];

        $this->assertSame('classified', $route['classification']);
        $this->assertStringContainsString('route name', $route['reason']);
        $this->assertSame(['navigation.dashboard'], array_column($scan['references'], 'key'));
    }

    public function test_aria_role_values_are_not_collected_as_translatable_copy(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = '<div role="{{ $tone === \'error\' ? \'alert\' : \'status\' }}" aria-label="Notification status"></div>';
        $scan = userFacingCopyInventorySource('resources/views/fixture.blade.php', $source);
        $texts = array_column($scan['candidates'], 'text');

        $this->assertNotContains('error', $texts);
        $this->assertNotContains('alert', $texts);
        $this->assertNotContains('status', $texts);
        $this->assertContains('Notification status', $texts);
    }

    public function test_markup_fragments_are_not_copy_but_text_inside_markup_stays_reviewable(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = <<<'PHP'
<?php
$message = '<span class="ui-status-chip ui-status-chip--neutral">';
$message = '<span class="ui-status-chip">Ready to Pay</span>';
PHP;
        $scan = userFacingCopyInventorySource('app/Http/Controllers/FixtureController.php', $source);
        $tag = array_values(array_filter($scan['candidates'], fn (array $record): bool => $record['text'] === '<span class="ui-status-chip ui-status-chip--neutral">'))[0];
        $visibleMarkup = array_values(array_filter($scan['candidates'], fn (array $record): bool => $record['text'] === '<span class="ui-status-chip">Ready to Pay</span>'))[0];

        $this->assertSame('classified', $tag['classification']);
        $this->assertStringContainsString('markup fragment', $tag['reason']);
        $this->assertSame('review_required', $visibleMarkup['classification']);
    }

    public function test_javascript_class_tokens_are_not_reported_as_display_copy(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = "const cell = { className: 'text-center fw-semibold ui-tabular-nums' };";
        $scan = userFacingCopyInventorySource('resources/js/fixture.js', $source);
        $classList = array_values(array_filter($scan['candidates'], fn (array $record): bool => $record['text'] === 'text-center fw-semibold ui-tabular-nums'))[0];

        $this->assertSame('classified', $classList['classification']);
        $this->assertStringContainsString('CSS class tokens', $classList['reason']);
    }

    public function test_javascript_event_names_and_data_fields_are_machine_values(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = "const column = { data: 'status_badge', name: 'status' }; button.addEventListener('click', handler); document.addEventListener('DOMContentLoaded', handler); button.classList.add('d-none'); const job = { stage: 'processing' }; const node = document.createElement('div'); node.style.display = 'none'; const ready = false;";
        $scan = userFacingCopyInventorySource('resources/js/fixture.js', $source);
        $records = $scan['candidates'];

        foreach (['status_badge', 'status', 'click', 'DOMContentLoaded', 'd-none', 'processing', 'div', 'none'] as $value) {
            $record = array_values(array_filter($records, fn (array $candidate): bool => $candidate['text'] === $value))[0];
            $this->assertSame('classified', $record['classification'], $value);
        }
        $this->assertNotContains('false', array_column($records, 'text'));
    }

    public function test_javascript_dom_selectors_classes_and_state_values_are_classified(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = "const node = document.querySelector('[data-notes-popover]'); node.classList.toggle('is-visible'); node.addEventListener('input change', handler); const job = { stage: 'processing' };";
        $scan = userFacingCopyInventorySource('resources/js/fixture.js', $source);

        foreach (['[data-notes-popover]', 'is-visible', 'input change', 'processing'] as $value) {
            $record = array_values(array_filter($scan['candidates'], fn (array $candidate): bool => $candidate['text'] === $value))[0];
            $this->assertSame('classified', $record['classification'], $value);
        }
    }

    public function test_blade_formatter_modes_and_replacement_keys_are_classified_but_output_copy_is_not(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = "{{ \$regionalFormatter->date(\$value, 'human') }} {{ __('common.chat.material_count', ['count' => \$count]) }} {{ \$count === 1 ? 'One record' : 'Many records' }}";
        $scan = userFacingCopyInventorySource('resources/views/fixture.blade.php', $source);
        $records = $scan['candidates'];
        $human = array_values(array_filter($records, fn (array $record): bool => $record['text'] === 'human'))[0];
        $count = array_values(array_filter($records, fn (array $record): bool => $record['text'] === 'count'))[0];

        $this->assertSame('classified', $human['classification']);
        $this->assertStringContainsString('Formatting profile', $human['reason']);
        $this->assertSame('classified', $count['classification']);
        $this->assertStringContainsString('map key', $count['reason']);
        $this->assertSame('review_required', array_values(array_filter($records, fn (array $record): bool => $record['text'] === 'One record'))[0]['classification']);
        $this->assertSame('review_required', array_values(array_filter($records, fn (array $record): bool => $record['text'] === 'Many records'))[0]['classification']);
    }

    public function test_dynamic_attributes_keep_literal_copy_and_do_not_duplicate_expression_literals(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = implode("\n", [
            '<!-- preserved line -->',
            '<button aria-label="Open {{ $number }}">',
            '<span title="{{ $visible ? \'Open\' : \'Closed\' }}"></span>',
            '</button>',
        ]);
        $scan = userFacingCopyInventorySource('resources/views/fixture.blade.php', $source);
        $attribute = array_values(array_filter($scan['candidates'], fn (array $record): bool => $record['context'] === 'aria-label'));
        $this->assertCount(1, $attribute);
        $this->assertSame('Open {{ $number }}', $attribute[0]['text']);
        $this->assertSame(2, $attribute[0]['line']);
        $this->assertSame(strpos($source, 'Open {{'), $attribute[0]['offset']);
        $closed = array_values(array_filter($scan['candidates'], fn (array $record): bool => $record['text'] === 'Closed'));
        $this->assertCount(1, $closed);
        $this->assertSame('review_required', $closed[0]['classification']);
    }

    public function test_localized_attribute_expressions_do_not_appear_as_untranslated_copy(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = '<input placeholder="{{ __(\'common.search.label\') }}"><input placeholder="Search for a supplier">';
        $scan = userFacingCopyInventorySource('resources/views/fixture.blade.php', $source);
        $records = array_column($scan['candidates'], null, 'text');

        $this->assertSame('classified', $records['common.search.label']['classification'] ?? null);
        $this->assertSame('review_required', $records['Search for a supplier']['classification'] ?? null);
        $this->assertSame('common.search.label', $scan['references'][0]['key'] ?? null);
    }

    public function test_export_source_labels_are_scanned_as_copy_and_translation_keys_are_parity_checked(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = '<button data-export-source-singular="purchase order" data-export-source-plural="{{ __(\'exports.sources.purchase_orders\') }}"></button>';
        $scan = userFacingCopyInventorySource('resources/views/fixture.blade.php', $source);
        $records = array_column($scan['candidates'], null, 'text');

        $this->assertSame('review_required', $records['purchase order']['classification'] ?? null);
        $this->assertSame('classified', $records['exports.sources.purchase_orders']['classification'] ?? null);
        $this->assertSame('exports.sources.purchase_orders', $scan['references'][0]['key'] ?? null);
    }

    public function test_human_component_metadata_is_scanned_without_input_or_selector_identifiers(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = '<x-ui.metric-card name="invoice_amount" id="balanceMetric" data-selector="#invoice-table" meta="Remaining budget" subtitle="Invoice progress" caption="Pending verification" />';
        $scan = userFacingCopyInventorySource('resources/views/fixture.blade.php', $source);
        $texts = array_column($scan['candidates'], 'text');
        foreach (['Remaining budget', 'Invoice progress', 'Pending verification'] as $text) {
            $this->assertContains($text, $texts);
        }
        foreach (['invoice_amount', 'balanceMetric', '#invoice-table'] as $identifier) {
            $this->assertNotContains($identifier, $texts);
        }
    }

    public function test_literal_blade_document_title_segments_remain_reviewable(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = "@section('title', 'Voucher: ' . \$number . ' - ADASI Portal')";
        $scan = userFacingCopyInventorySource('resources/views/fixture.blade.php', $source);
        $titles = array_values(array_filter($scan['candidates'], fn (array $record): bool => $record['context'] === 'document title literal'));

        $this->assertSame(['Voucher:', '- ADASI Portal'], array_column($titles, 'text'));
        $this->assertSame([1, 1], array_column($titles, 'line'));
        $this->assertSame([strpos($source, 'Voucher:'), strpos($source, ' - ADASI Portal') + 1], array_column($titles, 'offset'));
        $this->assertSame('review_required', $titles[0]['classification']);
        $this->assertSame('classified', $titles[1]['classification']);
        $this->assertStringContainsString('product branding', $titles[1]['reason']);

        $gaScan = userFacingCopyInventorySource('resources/views/fixture.blade.php', "@section('title', 'Claim 123' . ' - GA')");
        $gaTitle = array_values(array_filter($gaScan['candidates'], fn (array $record): bool => $record['text'] === '- GA'))[0];
        $this->assertSame('classified', $gaTitle['classification']);
        $this->assertStringContainsString('technical acronym', $gaTitle['reason']);
    }

    public function test_registry_configuration_identifiers_are_classified_without_hiding_raw_copy(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = <<<'PHP'
<?php return [
    'label' => 'navigation.quick.dashboard',
    'icon' => 'gauge',
    'route' => 'admin.dashboard',
    'active' => 'admin.dashboard',
    'roles' => ['admin'],
    'source_event' => 'pr.submitted',
    'priority' => 'action_required',
    'scope' => 'import',
    'mutable_subject' => 'conversation',
    'eligibility' => 'export_owner',
    'priority_roles' => ['finance'],
    'emptyMessage' => 'Choose a supplier',
    'copy' => ['label' => 'Dashboard'],
];
PHP;
        $scan = userFacingCopyInventorySource('config/fixture.php', $source);
        $records = array_column($scan['candidates'], null, 'text');

        foreach (['navigation.quick.dashboard', 'gauge', 'admin.dashboard', 'admin', 'pr.submitted', 'action_required', 'import', 'conversation', 'export_owner', 'finance'] as $identifier) {
            $this->assertSame('classified', $records[$identifier]['classification'] ?? null, $identifier);
            $this->assertNotEmpty($records[$identifier]['reason'] ?? null, $identifier);
        }
        $this->assertSame('review_required', $records['Choose a supplier']['classification'] ?? null);
        $this->assertSame('review_required', $records['Dashboard']['classification'] ?? null);
    }

    public function test_javascript_console_diagnostics_are_excluded_but_dom_copy_remains_reviewable(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = "console.error('Failed to load forecast for month', month); element.textContent = 'Ready for review';";
        $scan = userFacingCopyInventorySource('resources/js/fixture.js', $source);
        $records = array_column($scan['candidates'], null, 'text');

        $this->assertSame('classified', $records['Failed to load forecast for month']['classification'] ?? null);
        $this->assertStringContainsString('Internal console diagnostic', $records['Failed to load forecast for month']['reason'] ?? '');
        $this->assertSame('review_required', $records['Ready for review']['classification'] ?? null);
    }

    public function test_status_helper_codes_and_tones_are_excluded_but_raw_labels_remain_reviewable(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = "// status labels use the translation registry\nreturn match (\$status) { 'READY_TO_PAY' => 'success', default => 'neutral' }; 'barang' => 'goods'; __('status.local_invoice.ready_to_pay'); 'Untranslated label';";
        $scan = userFacingCopyInventorySource('app/Support/StatusHelper.php', $source);
        $records = array_column($scan['candidates'], null, 'text');

        foreach (['READY_TO_PAY', 'success', 'neutral', 'goods', 'status.local_invoice.ready_to_pay'] as $identifier) {
            $this->assertSame('classified', $records[$identifier]['classification'] ?? null, $identifier);
        }
        $this->assertSame('review_required', $records['Untranslated label']['classification'] ?? null);
    }

    public function test_fixed_import_template_status_values_remain_machine_contracts(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $source = "<?php return ['headings' => ['availability' => 'Available', 'help' => 'Choose a supplier']];";
        $scan = userFacingCopyInventorySource('app/Exports/QuotationImportTemplateExport.php', $source);
        $records = array_column($scan['candidates'], null, 'text');

        $this->assertSame('classified', $records['Available']['classification'] ?? null);
        $this->assertStringContainsString('integration contract', strtolower($records['Available']['reason'] ?? ''));
        $this->assertSame('review_required', $records['Choose a supplier']['classification'] ?? null);
    }

    public function test_scan_reports_unresolved_copy_with_explicit_classifications_and_never_treats_keys_as_proof(): void
    {
        require_once dirname(__DIR__).'/Support/user-facing-copy-inventory.php';
        $inventory = userFacingCopyInventory(dirname(__DIR__, 2));
        $records = $inventory['candidates'];
        $this->assertNotEmpty($records);
        $classified = 0;
        foreach ($records as $record) {
            $this->assertContains($record['classification'], ['review_required', 'classified']);
            $this->assertArrayHasKey('path', $record);
            $this->assertArrayHasKey('line', $record);
            if ($record['classification'] === 'classified') {
                $this->assertNotEmpty($record['reason']);
                $classified++;
            }
        }
        $this->assertGreaterThan(0, $classified);
    }
}
