<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class CalendarComponentTest extends TestCase
{
    public function test_date_picker_preserves_a_native_date_input_until_progressive_enhancement_runs(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-ui.date-picker name="deadline" id="claimDeadline" label="Response Deadline" value="2026-08-27" min="2026-08-28" required helper="Choose a business deadline." />
BLADE);

        $this->assertStringContainsString('data-adasi-date-picker', $html);
        $this->assertStringContainsString('id="claimDeadline"', $html);
        $this->assertStringContainsString('name="deadline"', $html);
        $this->assertStringContainsString('type="date"', $html);
        $this->assertStringContainsString('value="2026-08-27"', $html);
        $this->assertStringContainsString('min="2026-08-28"', $html);
        $this->assertStringContainsString('data-calendar-native-input', $html);
        $this->assertStringContainsString('data-calendar-trigger', $html);
        $this->assertStringContainsString('data-calendar-label="Response Deadline"', $html);
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        $this->assertStringContainsString('Choose a business deadline.', $html);
        $this->assertStringContainsString('data-calendar-prev', $html);
        $this->assertStringContainsString('data-calendar-next', $html);
        $this->assertStringContainsString('data-calendar-year-toggle', $html);
        $this->assertStringContainsString('data-calendar-year-panel', $html);
        $this->assertStringContainsString('data-calendar-days-grid', $html);
        $this->assertStringContainsString('data-calendar-today', $html);
        $this->assertStringNotContainsString('#', $html, 'Date picker must not introduce raw colour literals.');
    }

    public function test_date_range_picker_keeps_names_ids_and_range_commit_contract(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-ui.date-range-picker id="quotationDateRangeControl" granularity="month" start-name="date_from" start-id="quotationDateFrom" start-label="From" start-value="2026-05" end-name="date_to" end-id="quotationDateTo" end-label="To" end-value="2026-08" error-id="quotationDateError" compact />
BLADE);

        $this->assertStringContainsString('id="quotationDateRangeControl"', $html);
        $this->assertStringContainsString('data-adasi-date-range', $html);
        $this->assertStringContainsString('id="quotationDateFrom"', $html);
        $this->assertStringContainsString('id="quotationDateTo"', $html);
        $this->assertStringContainsString('name="date_from"', $html);
        $this->assertStringContainsString('name="date_to"', $html);
        $this->assertStringContainsString('type="month"', $html);
        $this->assertStringContainsString('id="quotationDateError"', $html);
        $this->assertStringContainsString('data-calendar-apply', $html);
        $this->assertStringContainsString('data-calendar-month-grid', $html);
        $this->assertStringNotContainsString('#', $html, 'Calendar components must not introduce raw colour literals.');
    }

    public function test_day_range_uses_cally_grid_with_monday_first_and_explicit_actions(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-ui.date-range-picker start-name="audit_date_from" start-id="auditDateFrom" end-name="audit_date_to" end-id="auditDateTo" />
BLADE);
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('calendar-range data-calendar-day-grid months="2" first-day-of-week="1"', $html);
        $this->assertStringContainsString('data-calendar-cancel', $html);
        $this->assertStringContainsString('data-calendar-clear', $html);
        $this->assertStringContainsString('--ui-calendar-cell-size: 2rem;', $styles);
        $this->assertStringContainsString('--ui-calendar-anchor-gap: var(--ui-space-1);', $styles);
        $this->assertStringContainsString('.ui-calendar-mobile-scrim', $styles);
        $this->assertStringContainsString('calendar-month::part(range-inner)', $styles);
        $this->assertStringNotContainsString('linear-gradient', implode('', [
            file_get_contents(resource_path('views/components/ui/date-picker.blade.php')),
            file_get_contents(resource_path('views/components/ui/date-range-picker.blade.php')),
        ]));
    }

    public function test_calendar_runtime_uses_dynamic_engine_loading_and_a_single_range_commit_event(): void
    {
        $runtime = file_get_contents(resource_path('js/calendar.js'));

        $this->assertStringContainsString("await import('cally')", $runtime);
        $this->assertStringContainsString("'adasi:date-range-commit'", $runtime);
        $this->assertStringContainsString("'adasi:calendar-reset'", $runtime);
        $this->assertStringContainsString('data-calendar-native-input', $runtime);
        $this->assertStringContainsString('MOBILE_BREAKPOINT', $runtime);
        $this->assertStringContainsString("getComputedStyle(this.panel).getPropertyValue('--ui-calendar-anchor-gap')", $runtime);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', file_get_contents(resource_path('css/app.css')));
    }

    public function test_active_date_controls_use_the_shared_calendar_components_and_keep_sensitive_identifiers(): void
    {
        $audit = file_get_contents(resource_path('views/admin/auth-audit-logs/index.blade.php'));
        $quotations = file_get_contents(resource_path('views/purchasing/quotations/index.blade.php'));
        $report = file_get_contents(resource_path('views/purchasing/reports/index.blade.php'));
        $supplierQuotation = file_get_contents(resource_path('views/supplier/quotations/create.blade.php'));

        $this->assertStringContainsString('x-ui.date-range-picker', $audit);
        $this->assertStringContainsString("'adasi:date-range-commit'", $audit);
        $this->assertStringContainsString('quotationDateFrom', $quotations);
        $this->assertStringContainsString('quotationDateTo', $quotations);
        $this->assertStringContainsString('quotationDateRangeControl', $quotations);
        $this->assertStringContainsString('quotationDateError', $quotations);
        $this->assertStringContainsString('data-calendar-native-submit', $quotations);
        $this->assertStringContainsString('x-ui.date-range-picker', $report);
        $this->assertStringContainsString('id="validityPeriod"', $supplierQuotation);
    }

    public function test_calendar_and_table_layout_contracts_keep_controls_compact_and_aligned(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $supplierQuotation = file_get_contents(resource_path('views/supplier/quotations/create.blade.php'));

        $this->assertStringContainsString('.ui-date-range-picker__native-compact', $styles);
        $this->assertStringContainsString('.quotation-material-section > .ui-form-section__header', $styles);
        $this->assertStringContainsString('top: var(--topbar-height);', $styles);
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $styles);
        $this->assertStringContainsString('--ui-calendar-panel-padding: var(--ui-space-1);', $styles);
        $this->assertStringContainsString('--ui-calendar-grid-gap: var(--ui-space-1);', $styles);
        $this->assertStringContainsString('column-gap: var(--ui-space-2);', $styles);
        $this->assertStringContainsString('margin-inline-end: var(--ui-space-2);', $styles);
        $this->assertStringContainsString('min-width: max-content;', $styles);
        $this->assertStringContainsString('padding-inline-end: 2.25rem !important;', $styles);
        $this->assertStringContainsString('.sorting_asc_disabled', $styles);
        $this->assertStringContainsString('.quotation-col-weight { width: 145px; }', $supplierQuotation);
        $this->assertStringContainsString('.quotation-col-total-weight { width: 130px; }', $supplierQuotation);
        $this->assertStringContainsString('<th scope="col">KG / Unit</th>', $supplierQuotation);
        $this->assertStringContainsString('<th scope="col">Total KG</th>', $supplierQuotation);
        $this->assertStringContainsString('.quotation-sticky-row-type', $supplierQuotation);
        $this->assertStringContainsString('min-width: 2180px !important;', $supplierQuotation);
        $this->assertStringContainsString('.availability-toggle', $supplierQuotation);
        $this->assertStringContainsString('quotation-material-section', $supplierQuotation);
    }
}
