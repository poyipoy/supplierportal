{{-- Alur status audit (status nyata saja; penilaian offline bukan status sistem). Input: $audit. --}}
@php
    $status = $audit->status;
    $position = match ($status) {
        \App\Models\SupplierAudit::STATUS_ASSIGNED,
        \App\Models\SupplierAudit::STATUS_DRAFT,
        \App\Models\SupplierAudit::STATUS_REVISION_REQUESTED => 1,
        \App\Models\SupplierAudit::STATUS_SUBMITTED => 2,
        \App\Models\SupplierAudit::STATUS_RESULT_PUBLISHED => 4,
        default => null,
    };
    $flowSteps = [
        __('supplier_audit.flow.assigned'),
        $status === \App\Models\SupplierAudit::STATUS_REVISION_REQUESTED ? __('supplier_audit.flow.revision') : __('supplier_audit.flow.filling'),
        __('supplier_audit.flow.submitted'),
        __('supplier_audit.flow.published'),
    ];
@endphp
@if($position !== null)
    <ol class="tw-m-0 tw-flex tw-list-none tw-flex-wrap tw-items-center tw-gap-x-2 tw-gap-y-1 tw-p-0 tw-text-ui-xs" aria-label="{{ __('supplier_audit.flow.label') }}" data-supplier-audit-flow>
        @foreach($flowSteps as $index => $label)
            @php $state = $index < $position ? 'done' : ($index === $position ? 'current' : 'todo'); @endphp
            <li class="tw-flex tw-items-center tw-gap-2" @if($state === 'current') aria-current="step" @endif>
                <span class="tw-inline-flex tw-h-5 tw-w-5 tw-shrink-0 tw-items-center tw-justify-center tw-rounded-full tw-border tw-text-[10px] tw-font-semibold {{ $state === 'done' ? 'tw-border-success tw-bg-success tw-text-success-foreground' : ($state === 'current' ? 'tw-border-primary tw-text-primary' : 'tw-border-outline-variant tw-text-on-surface-variant') }}"
                    style="font-variant-numeric: tabular-nums;" aria-hidden="true">
                    @if($state === 'done')
                        <x-ui.icon name="check" size="sm" class="tw-h-3 tw-w-3" />
                    @else
                        {{ $index + 1 }}
                    @endif
                </span>
                <span class="{{ $state === 'current' ? 'tw-font-semibold tw-text-on-surface' : ($state === 'done' ? 'tw-text-on-surface' : 'tw-text-on-surface-variant') }}">{{ $label }}</span>
                @unless($loop->last)
                    <span class="tw-h-px tw-w-6 {{ $index < $position ? 'tw-bg-success' : 'tw-bg-outline-variant' }}" aria-hidden="true"></span>
                @endunless
            </li>
        @endforeach
    </ol>
@endif
