@extends('layouts.app')
@section('title', __('supplier_audit.title').' · '.$audit->supplierName())
@section('page-title', __('supplier_audit.title'))

@section('content')
@php
    $todayDate = \App\Support\BusinessTime::today()->toDateString();
    $deadlinePassed = $audit->due_date !== null && $audit->due_date->toDateString() < $todayDate;
    $isPublished = $audit->status === \App\Models\SupplierAudit::STATUS_RESULT_PUBLISHED;
    $isSubmitted = $audit->status === \App\Models\SupplierAudit::STATUS_SUBMITTED;
    $isCancelled = $audit->status === \App\Models\SupplierAudit::STATUS_CANCELLED;
    $canExport = Gate::allows('export', $audit);
    $canRevise = Gate::allows('requestRevision', $audit);
    $canDeadline = Gate::allows('changeDeadline', $audit);
    $canCancel = Gate::allows('cancel', $audit);
@endphp
<div class="tw-grid tw-gap-5 tw-pb-16">
    <x-ui.page-header :title="$audit->supplierName()" :description="$audit->template?->title" :eyebrow="__('supplier_audit.title')">
        <x-slot:status>
            <x-ui.status-chip :tone="\App\Support\StatusHelper::supplierAuditTone($audit->status)">{{ \App\Support\StatusHelper::supplierAuditLabel($audit->status) }}</x-ui.status-chip>
            @if($audit->isLate())
                <x-ui.status-chip tone="error" icon="lock">{{ __('supplier_audit.labels.late') }} · {{ __('supplier_audit.invoice_block.chip') }}</x-ui.status-chip>
            @endif
        </x-slot:status>
        <x-slot:meta>
            <span>{{ __('supplier_audit.fields.period') }}: <strong class="tw-text-on-surface">{{ $audit->period_label }}</strong></span>
            <span style="font-variant-numeric: tabular-nums;">{{ __('supplier_audit.fields.due_date') }}:
                <strong class="tw-text-on-surface">{{ $audit->due_date ? $regionalFormatter->date($audit->due_date) : __('supplier_audit.fields.no_deadline') }}</strong>
                @if($audit->isEditableBySupplier() && $audit->dueRelativeLabel())
                    <span class="{{ $audit->isLate() ? 'tw-font-semibold tw-text-error' : '' }}">({{ $audit->dueRelativeLabel() }})</span>
                @endif
            </span>
            <span style="font-variant-numeric: tabular-nums;">{{ __('supplier_audit.fields.submitted_at') }}: <strong class="tw-text-on-surface">{{ $audit->submitted_at ? $regionalFormatter->timestamp($audit->submitted_at) : '—' }}</strong></span>
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.supplier-audits.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('supplier_audit.actions.back_to_list') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('supplier-audits._status-flow', ['audit' => $audit])

    {{-- U7: Langkah berikutnya — satu aksi utama sesuai status --}}
    <section class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface" aria-labelledby="next-step-title" data-next-step="{{ strtolower($audit->status) }}">
        <header class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-2 tw-border-b tw-border-outline-variant tw-px-4 tw-py-3">
            <h2 id="next-step-title" class="tw-m-0 tw-text-ui-base tw-font-semibold tw-text-on-surface">{{ __('supplier_audit.next_step.title') }}</h2>
            @if($canRevise || $canDeadline || $canCancel)
                <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-1" x-data>
                    <span class="tw-me-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('supplier_audit.next_step.more') }}:</span>
                    @if($canRevise)
                        <x-ui.button type="button" variant="ghost" size="sm" x-on:click="$dispatch('open-ui-dialog', 'supplier-audit-revision')">
                            <x-ui.icon name="rotate-ccw" size="sm" /><span>{{ __('supplier_audit.actions.request_revision') }}</span>
                        </x-ui.button>
                    @endif
                    @if($canDeadline)
                        <x-ui.button type="button" variant="ghost" size="sm" x-on:click="$dispatch('open-ui-dialog', 'supplier-audit-deadline')">
                            <x-ui.icon name="calendar-clock" size="sm" /><span>{{ __('supplier_audit.actions.change_deadline') }}</span>
                        </x-ui.button>
                    @endif
                    @if($canCancel)
                        <x-ui.button type="button" variant="ghost" size="sm" class="tw-text-error" x-on:click="$dispatch('open-ui-dialog', 'supplier-audit-cancel')">
                            <x-ui.icon name="circle-x" size="sm" /><span>{{ __('supplier_audit.actions.cancel') }}</span>
                        </x-ui.button>
                    @endif
                </div>
            @endif
        </header>

        <div class="tw-p-4">
            @if($isSubmitted)
                <ol class="tw-m-0 tw-grid tw-list-none tw-gap-5 tw-p-0 md:tw-grid-cols-3">
                    <li class="tw-grid tw-content-start tw-gap-2">
                        <p class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-on-surface"><span class="tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;">1.</span> {{ __('supplier_audit.next_step.export') }}</p>
                        @if($canExport)
                            <x-ui.button :href="route('purchasing.export.supplier-audits.detail', $audit)" variant="primary" size="sm" class="tw-justify-self-start"
                                data-async-export data-export-source-count="1" data-export-filtered="false"
                                data-export-source-singular="{{ __('supplier_audit.title') }}" data-export-source-plural="{{ __('supplier_audit.title') }}">
                                <x-ui.icon name="file-spreadsheet" size="sm" /><span>{{ __('supplier_audit.actions.export') }}</span>
                            </x-ui.button>
                        @endif
                    </li>
                    <li class="tw-grid tw-content-start tw-gap-2">
                        <p class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-on-surface"><span class="tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;">2.</span> {{ __('supplier_audit.next_step.assess') }}</p>
                        <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant" style="text-wrap: pretty;">{{ __('supplier_audit.labels.summary_help') }}</p>
                    </li>
                    <li class="tw-grid tw-content-start tw-gap-2">
                        <p class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-on-surface"><span class="tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;">3.</span> {{ __('supplier_audit.next_step.upload') }}</p>
                        @include('purchasing.supplier-audits._result-form', ['audit' => $audit, 'replacing' => false])
                    </li>
                </ol>
            @elseif($isPublished)
                <p class="tw-m-0 tw-mb-3 tw-text-ui-sm tw-text-on-surface">{{ __('supplier_audit.next_step.published') }}</p>
                <div class="tw-grid tw-gap-5 md:tw-grid-cols-2">
                    @include('purchasing.supplier-audits._result-files', ['audit' => $audit])
                    <div class="tw-grid tw-content-start tw-gap-2">
                        @if($canExport)
                            <x-ui.button :href="route('purchasing.export.supplier-audits.detail', $audit)" variant="outline" size="sm" class="tw-justify-self-start"
                                data-async-export data-export-source-count="1" data-export-filtered="false"
                                data-export-source-singular="{{ __('supplier_audit.title') }}" data-export-source-plural="{{ __('supplier_audit.title') }}">
                                <x-ui.icon name="file-spreadsheet" size="sm" /><span>{{ __('supplier_audit.actions.export') }}</span>
                            </x-ui.button>
                        @endif
                        <p class="tw-m-0 tw-mt-2 tw-text-ui-xs tw-font-semibold tw-text-on-surface">{{ __('supplier_audit.actions.replace_result') }}</p>
                        @include('purchasing.supplier-audits._result-form', ['audit' => $audit, 'replacing' => true])
                    </div>
                </div>
            @elseif($isCancelled)
                <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface">{{ __('supplier_audit.next_step.cancelled') }}</p>
                @if($audit->cancel_reason)
                    <p class="tw-m-0 tw-mt-1 tw-text-ui-sm tw-text-on-surface-variant" style="text-wrap: pretty;">{{ __('supplier_audit.fields.cancel_reason') }}: {{ $audit->cancel_reason }}</p>
                @endif
            @else
                <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-x-6 tw-gap-y-2">
                    <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface">{{ __('supplier_audit.next_step.waiting') }}</p>
                    <div class="tw-flex tw-items-center tw-gap-2">
                        <div class="tw-h-1.5 tw-w-32 tw-overflow-hidden tw-rounded-ui-full tw-bg-surface-container" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $progress['total'] }}" aria-valuenow="{{ $progress['filled'] }}" aria-label="{{ __('supplier_audit.fields.progress') }}">
                            <div class="tw-h-full tw-bg-primary" style="width: {{ $progress['total'] ? round($progress['filled'] / $progress['total'] * 100) : 0 }}%"></div>
                        </div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;">{{ __('supplier_audit.next_step.waiting_progress', $progress) }}</span>
                    </div>
                </div>
                @if($audit->status === \App\Models\SupplierAudit::STATUS_REVISION_REQUESTED && $audit->revision_note)
                    <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant" style="text-wrap: pretty;">{{ __('supplier_audit.fields.revision_note') }}: {{ $audit->revision_note }}</p>
                @endif
            @endif
        </div>
    </section>

    <div class="tw-grid tw-gap-5 xl:tw-grid-cols-[minmax(0,1fr)_20rem]">
        <section class="tw-grid tw-min-w-0 tw-content-start tw-gap-3" aria-labelledby="answers-title">
            <div class="tw-flex tw-flex-wrap tw-items-baseline tw-justify-between tw-gap-2">
                <h2 id="answers-title" class="tw-m-0 tw-text-ui-base tw-font-semibold tw-text-on-surface">{{ __('supplier_audit.labels.answers') }}</h2>
                <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;" data-answer-summary>
                    <span class="tw-sr-only">{{ __('supplier_audit.labels.summary') }}:</span>
                    {{ __('supplier_audit.labels.yes_count') }} {{ $answerCounts['yes'] }} · {{ __('supplier_audit.labels.no_count') }} {{ $answerCounts['no'] }} · {{ __('supplier_audit.labels.empty_count') }} {{ $answerCounts['empty'] }}
                </p>
            </div>
            @include('supplier-audits._answers-readonly', ['sections' => $sections])
        </section>

        <aside class="tw-grid tw-content-start tw-gap-4">
            <section class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-4" aria-labelledby="assignment-title">
                <h2 id="assignment-title" class="tw-m-0 tw-mb-2 tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ __('supplier_audit.fields.template') }}</h2>
                <dl class="tw-m-0 tw-grid tw-grid-cols-[auto_1fr] tw-gap-x-3 tw-gap-y-1 tw-text-ui-xs">
                    <dt class="tw-text-on-surface-variant">{{ __('supplier_audit.fields.assigned_by') }}</dt>
                    <dd class="tw-m-0 tw-text-on-surface">{{ $audit->assigner?->name ?? '-' }}</dd>
                    <dt class="tw-text-on-surface-variant">{{ __('supplier_audit.fields.assigned_at') }}</dt>
                    <dd class="tw-m-0 tw-text-on-surface" style="font-variant-numeric: tabular-nums;">{{ $regionalFormatter->timestamp($audit->assigned_at) }}</dd>
                    <dt class="tw-text-on-surface-variant">{{ __('supplier_audit.fields.template') }}</dt>
                    <dd class="tw-m-0 tw-text-on-surface">v{{ $audit->template?->version }}</dd>
                </dl>
            </section>

            <section class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-4" aria-labelledby="history-title">
                <h2 id="history-title" class="tw-m-0 tw-mb-3 tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ __('supplier_audit.labels.history') }}</h2>
                <ol class="tw-m-0 tw-grid tw-list-none tw-gap-3 tw-p-0" data-supplier-audit-history>
                    @foreach($audit->statusHistories->reverse() as $history)
                        <li class="tw-border-s-2 tw-border-outline-variant tw-ps-3">
                            <p class="tw-m-0 tw-text-ui-sm tw-font-medium tw-text-on-surface">{{ __('supplier_audit.history.events.'.$history->event) }}</p>
                            <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant" style="font-variant-numeric: tabular-nums;">
                                {{ $regionalFormatter->timestamp($history->created_at) }} · {{ __('supplier_audit.history.by', ['name' => $history->actor?->name ?? '-']) }}
                            </p>
                            @if($history->notes)
                                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface" style="text-wrap: pretty;">{{ $history->notes }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
        </aside>
    </div>

    @if($canDeadline)
        <x-ui.dialog name="supplier-audit-deadline" :title="__('supplier_audit.deadline.change_title')" :description="__('supplier_audit.deadline.change_help')">
            <form method="POST" action="{{ route('purchasing.supplier-audits.deadline', $audit) }}" id="supplierAuditDeadlineForm" data-async-submit class="tw-grid tw-gap-4">
                @csrf
                <x-ui.date-picker name="due_date" id="due_date_change" :label="__('supplier_audit.fields.due_date')" :value="$audit->due_date?->toDateString()" :min="$todayDate" />
                <x-ui.textarea name="reason" id="due_date_change_reason" :label="__('supplier_audit.deadline.reason')" rows="2" maxlength="500" />
            </form>
            @if($audit->due_date)
                <form method="POST" action="{{ route('purchasing.supplier-audits.deadline', $audit) }}" id="supplierAuditDeadlineRemoveForm" data-async-submit>
                    @csrf
                    <input type="hidden" name="due_date" value="">
                </form>
            @endif
            <x-slot:actions>
                <x-ui.button type="button" variant="outline" x-on:click="$dispatch('close-ui-dialog', 'supplier-audit-deadline')">{{ __('supplier_audit.actions.close') }}</x-ui.button>
                @if($audit->due_date)
                    <x-ui.button type="submit" form="supplierAuditDeadlineRemoveForm" variant="ghost">{{ __('supplier_audit.deadline.remove') }}</x-ui.button>
                @endif
                <x-ui.button type="submit" form="supplierAuditDeadlineForm" variant="primary">{{ __('supplier_audit.deadline.save') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.dialog>
    @endif

    @if($canRevise)
        <x-ui.dialog name="supplier-audit-revision" :title="__('supplier_audit.revision.title')" :description="__('supplier_audit.revision.help')">
            <form method="POST" action="{{ route('purchasing.supplier-audits.request-revision', $audit) }}" id="supplierAuditRevisionForm" data-async-submit class="tw-grid tw-gap-4"
                x-data="{ changeDue: @js($deadlinePassed) }">
                @csrf
                <x-ui.textarea name="note" id="supplier_audit_revision_note" :label="__('supplier_audit.fields.revision_note')" rows="4" required maxlength="2000" />
                <label class="tw-inline-flex tw-min-h-10 tw-cursor-pointer tw-items-center tw-gap-2 tw-text-ui-sm" for="supplier_audit_change_due_date">
                    <input type="hidden" name="change_due_date" value="0">
                    <input type="checkbox" class="form-check-input tw-m-0" id="supplier_audit_change_due_date" name="change_due_date" value="1" x-model="changeDue">
                    <span>{{ __('supplier_audit.deadline.change_on_revision') }}</span>
                </label>
                @if($deadlinePassed)
                    <p class="tw-m-0 tw-flex tw-items-start tw-gap-1.5 tw-text-ui-xs tw-text-on-surface" x-show="!changeDue" role="status">
                        <x-ui.icon name="triangle-alert" size="sm" class="tw-mt-0.5 tw-shrink-0 tw-text-warning" /> <span>{{ __('supplier_audit.deadline.revision_warning') }}</span>
                    </p>
                @endif
                <div x-show="changeDue" x-cloak>
                    <x-ui.date-picker name="due_date" id="due_date_revision" :label="__('supplier_audit.fields.due_date')" :min="$todayDate" :helper="__('supplier_audit.deadline.change_help')" />
                </div>
            </form>
            <x-slot:actions>
                <x-ui.button type="button" variant="outline" x-on:click="$dispatch('close-ui-dialog', 'supplier-audit-revision')">{{ __('supplier_audit.actions.close') }}</x-ui.button>
                <x-ui.button type="submit" form="supplierAuditRevisionForm" variant="primary">{{ __('supplier_audit.revision.submit') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.dialog>
    @endif

    @if($canCancel)
        <x-ui.dialog name="supplier-audit-cancel" :title="__('supplier_audit.cancel.title')" :description="__('supplier_audit.cancel.help')">
            <form method="POST" action="{{ route('purchasing.supplier-audits.cancel', $audit) }}" id="supplierAuditCancelForm" data-async-submit class="tw-grid tw-gap-4">
                @csrf
                <x-ui.textarea name="reason" id="supplier_audit_cancel_reason" :label="__('supplier_audit.fields.cancel_reason')" rows="3" required maxlength="2000" />
            </form>
            <x-slot:actions>
                <x-ui.button type="button" variant="outline" x-on:click="$dispatch('close-ui-dialog', 'supplier-audit-cancel')">{{ __('supplier_audit.actions.close') }}</x-ui.button>
                <x-ui.button type="submit" form="supplierAuditCancelForm" variant="danger">{{ __('supplier_audit.confirm.cancel_confirm') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.dialog>
    @endif
</div>
@endsection
