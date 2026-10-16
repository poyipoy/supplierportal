@extends('layouts.app')
@section('title', __('supplier_audit.title').' · '.$audit->period_label)
@section('page-title', __('supplier_audit.title'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header :title="__('supplier_audit.title').' · '.$audit->period_label" :description="__('supplier_audit.description')" :eyebrow="__('supplier_audit.eyebrow')">
        <x-slot:status>
            <x-ui.status-chip :tone="\App\Support\StatusHelper::supplierAuditTone($audit->status)">{{ \App\Support\StatusHelper::supplierAuditLabel($audit->status) }}</x-ui.status-chip>
            @if($audit->isLate())
                <x-ui.status-chip tone="error" icon="clock">{{ __('supplier_audit.labels.late') }}</x-ui.status-chip>
            @endif
        </x-slot:status>
        <x-slot:meta>
            <span style="font-variant-numeric: tabular-nums;">{{ __('supplier_audit.fields.due_date') }}: <strong class="tw-text-on-surface">{{ $audit->due_date ? $regionalFormatter->date($audit->due_date) : __('supplier_audit.fields.no_deadline') }}</strong></span>
            <span style="font-variant-numeric: tabular-nums;">{{ __('supplier_audit.fields.submitted_at') }}: <strong class="tw-text-on-surface">{{ $audit->submitted_at ? $regionalFormatter->timestamp($audit->submitted_at) : '—' }}</strong></span>
            <span style="font-variant-numeric: tabular-nums;">{{ __('supplier_audit.labels.progress', $progress) }}</span>
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.supplier-audits.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('supplier_audit.actions.back_to_list') }}</span>
            </x-ui.button>
            @can('fill', $audit)
                <x-ui.button :href="route('local-supplier.supplier-audits.edit', $audit)" variant="primary" size="sm">
                    <x-ui.icon name="clipboard-check" size="sm" />
                    <span>{{ $progress['filled'] > 0 ? __('supplier_audit.actions.continue') : __('supplier_audit.actions.fill') }}</span>
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @include('supplier-audits._status-flow', ['audit' => $audit])

    @if($audit->status === \App\Models\SupplierAudit::STATUS_REVISION_REQUESTED && $audit->revision_note)
        <x-ui.alert tone="warning" :title="__('supplier_audit.fields.revision_note')">{{ $audit->revision_note }}</x-ui.alert>
    @elseif($audit->status === \App\Models\SupplierAudit::STATUS_CANCELLED)
        <x-ui.alert tone="info" :title="__('supplier_audit.labels.cancelled_note')">{{ $audit->cancel_reason }}</x-ui.alert>
    @elseif(in_array($audit->status, [\App\Models\SupplierAudit::STATUS_SUBMITTED, \App\Models\SupplierAudit::STATUS_RESULT_PUBLISHED], true))
        <x-ui.alert tone="info">{{ __('supplier_audit.labels.locked') }}</x-ui.alert>
    @endif

    @if(in_array($audit->status, [\App\Models\SupplierAudit::STATUS_SUBMITTED, \App\Models\SupplierAudit::STATUS_RESULT_PUBLISHED], true))
        <x-ui.card :title="__('supplier_audit.labels.results')" data-supplier-audit-result>
            @if($audit->status === \App\Models\SupplierAudit::STATUS_RESULT_PUBLISHED && $audit->latestResult)
                <div class="tw-flex tw-flex-col tw-gap-3 sm:tw-flex-row sm:tw-items-center sm:tw-justify-between">
                    <span class="tw-min-w-0">
                        <span class="tw-block tw-truncate tw-text-ui-sm tw-font-medium tw-text-on-surface">{{ $audit->latestResult->file_name }}</span>
                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;">{{ $regionalFormatter->timestamp($audit->latestResult->created_at) }}</span>
                    </span>
                    <x-ui.button :href="route('attachments.show', $audit->latestResult)" variant="primary" size="sm" target="_blank" rel="noopener">
                        <x-ui.icon name="download" size="sm" />
                        <span>{{ __('supplier_audit.actions.download_result') }}</span>
                    </x-ui.button>
                </div>
            @else
                <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant">{{ __('supplier_audit.labels.result_pending') }}</p>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card :title="__('supplier_audit.labels.answers')">
        @include('supplier-audits._answers-readonly', ['sections' => $sections])
    </x-ui.card>
</div>
@endsection
