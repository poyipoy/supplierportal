{{-- Area yang diganti server tabs: filter + tabel + paginasi + empty state untuk satu antrean. --}}
@php
    $baseQuery = array_filter(['period' => $filters['period'] ?? null, 'supplier' => $filters['supplier'] ?? null]);
    $auditResetUrl = route('purchasing.supplier-audits.index', ['queue' => $queue]);
    $auditChips = [];
    if (! empty($filters['period'])) {
        $auditChips[] = [
            'key' => 'period',
            'label' => __('supplier_audit.fields.period'),
            'value' => $periodOptions[$filters['period']] ?? (string) $filters['period'],
            'removeUrl' => route('purchasing.supplier-audits.index', array_filter(['queue' => $queue, 'supplier' => $filters['supplier'] ?? null])),
        ];
    }
    if (! empty($filters['supplier'])) {
        $auditChips[] = [
            'key' => 'supplier',
            'label' => __('supplier_audit.fields.supplier'),
            'value' => $supplierOptions[$filters['supplier']] ?? (string) $filters['supplier'],
            'removeUrl' => route('purchasing.supplier-audits.index', array_filter(['queue' => $queue, 'period' => $filters['period'] ?? null])),
        ];
    }
@endphp
<x-ui.data-table :empty="$audits->isEmpty()">
    <x-slot:filters>
        <x-ui.filter-bar
            id="supplierAuditFilterForm"
            :action="route('purchasing.supplier-audits.index')"
            variant="embedded"
            :server-tabs="true"
            :has-active-filters="(bool) $baseQuery"
            :reset-url="$auditResetUrl"
            :reset-label="__('supplier_audit.actions.reset')"
            :submit-label="__('supplier_audit.actions.filter')"
        >
            <x-slot:hidden>
                <input type="hidden" name="queue" value="{{ $queue }}">
            </x-slot:hidden>

            <x-slot:filters>
                <label for="supplierAuditPeriodFilter" class="tw:sr-only">{{ __('supplier_audit.fields.period') }}</label>
                <select name="period" id="supplierAuditPeriodFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-44">
                    <option value="">{{ __('supplier_audit.filters.period_all') }}</option>
                    @foreach($periodOptions as $periodValue => $periodLabel)
                        <option value="{{ $periodValue }}" @selected((string) ($filters['period'] ?? '') === (string) $periodValue)>{{ $periodLabel }}</option>
                    @endforeach
                </select>

                <label for="supplierAuditSupplierFilter" class="tw:sr-only">{{ __('supplier_audit.fields.supplier') }}</label>
                <select name="supplier" id="supplierAuditSupplierFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-56">
                    <option value="">{{ __('supplier_audit.filters.supplier_all') }}</option>
                    @foreach($supplierOptions as $supplierValue => $supplierLabel)
                        <option value="{{ $supplierValue }}" @selected((string) ($filters['supplier'] ?? '') === (string) $supplierValue)>{{ $supplierLabel }}</option>
                    @endforeach
                </select>
            </x-slot:filters>

            <x-slot:chips>
                <x-ui.filter-chips :items="$auditChips" :clear-url="$auditResetUrl" :server-tabs="true" />
            </x-slot:chips>
        </x-ui.filter-bar>
    </x-slot:filters>

    <x-slot:emptyState>
        @if($queue === 'review')
            <x-ui.empty-state icon="clipboard-check" :title="__('supplier_audit.queues.empty_review')" :description="__('supplier_audit.queues.empty_review_hint')"
                :action-url="route('purchasing.supplier-audits.index', [...$baseQuery, 'queue' => 'waiting'])" :action-text="__('supplier_audit.queues.view_waiting')" action-icon="arrow-right" />
        @else
            <x-ui.empty-state icon="clipboard-check" :title="__('supplier_audit.queues.empty_other')" />
        @endif
    </x-slot:emptyState>

    <table class="table table-hover align-middle tw:m-0 tw:text-ui-sm w-100">
        <thead class="table-light">
            <tr>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_audit.fields.supplier') }}</th>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_audit.fields.period') }}</th>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_audit.columns.progress') }}</th>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_audit.fields.due_date') }}</th>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_audit.fields.status') }}</th>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('supplier_audit.fields.submitted_at') }}</th>
                <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-right text-end">{{ __('common.table.action') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($audits as $audit)
                @php
                    $total = (int) $audit->total_answers_count;
                    $filled = (int) $audit->completed_answers_count;
                    $percent = $total > 0 ? round($filled / $total * 100) : 0;
                @endphp
                <tr>
                    <td>
                        <a href="{{ route('purchasing.supplier-audits.show', $audit) }}" class="tw:font-semibold tw:text-on-surface tw:no-underline tw:hover:underline">{{ $audit->supplierName() }}</a>
                        <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant">{{ $audit->supplier?->email }}</span>
                    </td>
                    <td>{{ $audit->period_label }}</td>
                    <td class="tw:min-w-32">
                        <div class="tw:flex tw:items-center tw:gap-2">
                            <div class="tw:h-1.5 tw:w-16 tw:overflow-hidden tw:rounded-ui-full tw:bg-surface-container" aria-hidden="true">
                                <div class="tw:h-full {{ $total > 0 && $filled === $total ? 'tw:bg-success' : 'tw:bg-primary' }}" style="width: {{ $percent }}%"></div>
                            </div>
                            <span class="tw:text-ui-xs tw:text-on-surface-variant" style="font-variant-numeric: tabular-nums;" data-audit-progress>{{ $filled }}/{{ $total }}</span>
                        </div>
                    </td>
                    <td class="tw:whitespace-nowrap" style="font-variant-numeric: tabular-nums;">
                        @if($audit->due_date)
                            <span class="tw:block">{{ $regionalFormatter->date($audit->due_date) }}</span>
                            @if($audit->isEditableBySupplier())
                                @if($audit->isLate())
                                    <x-ui.status-chip tone="error" size="sm" icon="lock">{{ $audit->dueRelativeLabel() }} · {{ __('supplier_audit.invoice_block.chip') }}</x-ui.status-chip>
                                @else
                                    <span class="tw:text-ui-xs tw:text-on-surface-variant">{{ $audit->dueRelativeLabel() }}</span>
                                @endif
                            @endif
                        @else
                            <span class="tw:text-on-surface-variant">{{ __('supplier_audit.fields.no_deadline') }}</span>
                        @endif
                    </td>
                    <td><x-ui.status-chip :tone="\App\Support\StatusHelper::supplierAuditTone($audit->status)" size="sm">{{ \App\Support\StatusHelper::supplierAuditLabel($audit->status) }}</x-ui.status-chip></td>
                    <td class="tw:whitespace-nowrap tw:text-ui-xs" style="font-variant-numeric: tabular-nums;">{{ $audit->submitted_at ? $regionalFormatter->timestamp($audit->submitted_at) : '—' }}</td>
                    <td class="tw:text-right text-end">
                        @if($audit->status === \App\Models\SupplierAudit::STATUS_SUBMITTED)
                            <x-ui.row-action
                                :href="route('purchasing.supplier-audits.show', $audit)"
                                :label="__('supplier_audit.row_actions.review')"
                                :context="$audit->supplierName()"
                            />
                        @else
                            <x-ui.row-action
                                :href="route('purchasing.supplier-audits.show', $audit)"
                                :context="$audit->supplierName()"
                            />
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($audits->hasPages())
        <x-slot:pagination>
            {{ $audits->links() }}
        </x-slot:pagination>
    @endif
</x-ui.data-table>
