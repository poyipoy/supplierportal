{{-- Area yang diganti server tabs: filter + tabel + paginasi + empty state untuk satu antrean. --}}
@php
    $baseQuery = array_filter(['period' => $filters['period'] ?? null, 'supplier' => $filters['supplier'] ?? null]);
@endphp
<x-ui.data-table :empty="$audits->isEmpty()">
    <x-slot:filters>
        <form method="GET" action="{{ route('purchasing.supplier-audits.index') }}" class="tw-flex tw-w-full tw-flex-col tw-gap-2.5 shell:tw-flex-row shell:tw-flex-wrap shell:tw-items-end" role="search" data-server-tabs-form>
            <input type="hidden" name="queue" value="{{ $queue }}">
            <x-ui.select name="period" id="supplierAuditPeriodFilter" :label="__('supplier_audit.fields.period')" :placeholder="__('supplier_audit.filters.period_all')" :options="$periodOptions" :value="$filters['period'] ?? null" class="tw-min-w-44" />
            <x-ui.select name="supplier" id="supplierAuditSupplierFilter" :label="__('supplier_audit.fields.supplier')" :placeholder="__('supplier_audit.filters.supplier_all')" :options="$supplierOptions" :value="$filters['supplier'] ?? null" class="tw-min-w-56" />
            <div class="tw-flex tw-gap-2">
                <x-ui.button type="submit" variant="outline" size="sm">
                    <x-ui.icon name="filter" size="sm" />
                    <span>{{ __('supplier_audit.actions.filter') }}</span>
                </x-ui.button>
                @if($baseQuery)
                    <x-ui.button :href="route('purchasing.supplier-audits.index', ['queue' => $queue])" variant="ghost" size="sm">
                        <x-ui.icon name="rotate-ccw" size="sm" />
                        <span>{{ __('supplier_audit.actions.reset') }}</span>
                    </x-ui.button>
                @endif
            </div>
        </form>
    </x-slot:filters>

    <x-slot:emptyState>
        @if($queue === 'review')
            <x-ui.empty-state icon="clipboard-check" :title="__('supplier_audit.queues.empty_review')" :description="__('supplier_audit.queues.empty_review_hint')"
                :action-url="route('purchasing.supplier-audits.index', [...$baseQuery, 'queue' => 'waiting'])" :action-text="__('supplier_audit.queues.view_waiting')" action-icon="arrow-right" />
        @else
            <x-ui.empty-state icon="clipboard-check" :title="__('supplier_audit.queues.empty_other')" />
        @endif
    </x-slot:emptyState>

    <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
        <thead class="table-light">
            <tr>
                <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('supplier_audit.fields.supplier') }}</th>
                <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('supplier_audit.fields.period') }}</th>
                <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('supplier_audit.columns.progress') }}</th>
                <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('supplier_audit.fields.due_date') }}</th>
                <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('supplier_audit.fields.status') }}</th>
                <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('supplier_audit.fields.submitted_at') }}</th>
                <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end"><span class="tw-sr-only">{{ __('supplier_audit.columns.action') }}</span></th>
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
                        <a href="{{ route('purchasing.supplier-audits.show', $audit) }}" class="tw-font-semibold tw-text-on-surface tw-no-underline hover:tw-underline">{{ $audit->supplierName() }}</a>
                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $audit->supplier?->email }}</span>
                    </td>
                    <td>{{ $audit->period_label }}</td>
                    <td class="tw-min-w-32">
                        <div class="tw-flex tw-items-center tw-gap-2">
                            <div class="tw-h-1.5 tw-w-16 tw-overflow-hidden tw-rounded-ui-full tw-bg-surface-container" aria-hidden="true">
                                <div class="tw-h-full {{ $total > 0 && $filled === $total ? 'tw-bg-success' : 'tw-bg-primary' }}" style="width: {{ $percent }}%"></div>
                            </div>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;" data-audit-progress>{{ $filled }}/{{ $total }}</span>
                        </div>
                    </td>
                    <td class="tw-whitespace-nowrap" style="font-variant-numeric: tabular-nums;">
                        @if($audit->due_date)
                            <span class="tw-block">{{ $regionalFormatter->date($audit->due_date) }}</span>
                            @if($audit->isEditableBySupplier())
                                @if($audit->isLate())
                                    <x-ui.status-chip tone="error" size="sm" icon="lock">{{ $audit->dueRelativeLabel() }} · {{ __('supplier_audit.invoice_block.chip') }}</x-ui.status-chip>
                                @else
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ $audit->dueRelativeLabel() }}</span>
                                @endif
                            @endif
                        @else
                            <span class="tw-text-on-surface-variant">{{ __('supplier_audit.fields.no_deadline') }}</span>
                        @endif
                    </td>
                    <td><x-ui.status-chip :tone="\App\Support\StatusHelper::supplierAuditTone($audit->status)" size="sm">{{ \App\Support\StatusHelper::supplierAuditLabel($audit->status) }}</x-ui.status-chip></td>
                    <td class="tw-whitespace-nowrap tw-text-ui-xs" style="font-variant-numeric: tabular-nums;">{{ $audit->submitted_at ? $regionalFormatter->timestamp($audit->submitted_at) : '—' }}</td>
                    <td class="text-end">
                        @if($audit->status === \App\Models\SupplierAudit::STATUS_SUBMITTED)
                            <x-ui.button :href="route('purchasing.supplier-audits.show', $audit)" variant="primary" size="sm">{{ __('supplier_audit.row_actions.review') }}</x-ui.button>
                        @else
                            <x-ui.button :href="route('purchasing.supplier-audits.show', $audit)" variant="ghost" size="sm">
                                <span>{{ __('supplier_audit.actions.view') }}</span>
                                <x-ui.icon name="chevron-right" size="sm" />
                            </x-ui.button>
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
