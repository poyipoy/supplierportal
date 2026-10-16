@extends('layouts.app')
@section('title', __('supplier_audit.title'))
@section('page-title', __('supplier_audit.title'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('supplier_audit.title')"
        :description="__('supplier_audit.supplier_description')"
        :eyebrow="__('supplier_audit.eyebrow')"
    />

    @if($activeAudit)
        @php
            $percent = $progress['total'] > 0 ? round($progress['filled'] / $progress['total'] * 100) : 0;
            $editable = $activeAudit->isEditableBySupplier();
        @endphp
        <x-ui.card :title="__('supplier_audit.labels.active_audit')" data-supplier-audit-active>
            <div class="tw-grid tw-gap-4 md:tw-grid-cols-[minmax(0,1fr)_auto] md:tw-items-center">
                <div class="tw-grid tw-gap-2">
                    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                        <span class="tw-text-ui-lg tw-font-semibold tw-text-on-surface">{{ $activeAudit->period_label }}</span>
                        <x-ui.status-chip :tone="\App\Support\StatusHelper::supplierAuditTone($activeAudit->status)" size="sm">{{ \App\Support\StatusHelper::supplierAuditLabel($activeAudit->status) }}</x-ui.status-chip>
                        @if($activeAudit->isLate())
                            <x-ui.status-chip tone="error" size="sm" icon="clock">{{ __('supplier_audit.labels.late') }}</x-ui.status-chip>
                        @endif
                    </div>
                    <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;">
                        {{ __('supplier_audit.fields.due_date') }}:
                        <strong class="tw-text-on-surface">{{ $activeAudit->due_date ? $regionalFormatter->date($activeAudit->due_date) : __('supplier_audit.fields.no_deadline') }}</strong>
                        @if($activeAudit->isEditableBySupplier() && $activeAudit->dueRelativeLabel())
                            <span class="{{ $activeAudit->isLate() ? 'tw-font-semibold tw-text-error' : '' }}">({{ $activeAudit->dueRelativeLabel() }})</span>
                        @endif
                    </p>
                    @include('supplier-audits._status-flow', ['audit' => $activeAudit])
                    <div class="tw-flex tw-max-w-md tw-items-center tw-gap-3">
                        <div class="tw-h-2 tw-flex-1 tw-overflow-hidden tw-rounded-ui-full tw-bg-surface-container" role="progressbar"
                            aria-valuemin="0" aria-valuemax="{{ $progress['total'] }}" aria-valuenow="{{ $progress['filled'] }}" aria-label="{{ __('supplier_audit.fields.progress') }}">
                            <div class="tw-h-full tw-bg-primary" style="width: {{ $percent }}%"></div>
                        </div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;">{{ __('supplier_audit.labels.progress', $progress) }}</span>
                    </div>
                    @if($activeAudit->status === \App\Models\SupplierAudit::STATUS_REVISION_REQUESTED && $activeAudit->revision_note)
                        <x-ui.alert tone="warning" :title="__('supplier_audit.fields.revision_note')">{{ $activeAudit->revision_note }}</x-ui.alert>
                    @endif
                </div>
                <div class="tw-flex tw-flex-wrap tw-gap-2 md:tw-justify-end">
                    @if($editable)
                        <x-ui.button :href="route('local-supplier.supplier-audits.edit', $activeAudit)" variant="primary">
                            <x-ui.icon name="clipboard-check" size="sm" />
                            <span>{{ $progress['filled'] > 0 ? __('supplier_audit.actions.continue') : __('supplier_audit.actions.fill') }}</span>
                        </x-ui.button>
                    @else
                        <x-ui.button :href="route('local-supplier.supplier-audits.show', $activeAudit)" variant="outline">
                            <span>{{ __('supplier_audit.actions.view') }}</span>
                            <x-ui.icon name="chevron-right" size="sm" />
                        </x-ui.button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    @else
        {{-- D2: menu selalu tampil; tanpa penugasan aktif tampilkan informasi akses. --}}
        <x-ui.card>
            <x-ui.empty-state icon="clipboard-check" :title="__('supplier_audit.empty.no_access_title')" :description="__('supplier_audit.empty.no_access')" />
        </x-ui.card>
    @endif

    <x-ui.data-table :title="__('supplier_audit.labels.audit_history')" :empty="$history->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="history" :title="__('supplier_audit.empty.history')" />
        </x-slot:emptyState>

        <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
            <thead class="table-light">
                <tr>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('supplier_audit.fields.period') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('supplier_audit.fields.status') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('supplier_audit.fields.submitted_at') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end"><span class="tw-sr-only">{{ __('supplier_audit.actions.view') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach($history as $audit)
                    <tr>
                        <td class="tw-font-medium tw-text-on-surface">{{ $audit->period_label }}</td>
                        <td><x-ui.status-chip :tone="\App\Support\StatusHelper::supplierAuditTone($audit->status)" size="sm">{{ \App\Support\StatusHelper::supplierAuditLabel($audit->status) }}</x-ui.status-chip></td>
                        <td class="tw-whitespace-nowrap" style="font-variant-numeric: tabular-nums;">{{ $audit->submitted_at ? $regionalFormatter->timestamp($audit->submitted_at) : '—' }}</td>
                        <td class="text-end">
                            <x-ui.button :href="route('local-supplier.supplier-audits.show', $audit)" variant="ghost" size="sm">
                                <span>{{ __('supplier_audit.actions.view') }}</span>
                                <x-ui.icon name="chevron-right" size="sm" />
                            </x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($history->hasPages())
            <x-slot:pagination>
                {{ $history->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
