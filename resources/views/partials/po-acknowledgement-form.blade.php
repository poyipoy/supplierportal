@php
    $todayObj = \App\Support\BusinessTime::today();
    $todayString = $todayObj->toDateString();
    $initialMode = old('mode', 'confirm');
    $candidateDate = $candidateProposedDate ?? $po->supplier_promised_date ?? null;
    $defaultProposedDate = ($candidateDate && $candidateDate->gte($todayObj))
        ? $candidateDate->format('Y-m-d')
        : $todayString;
@endphp

@if($po->canAcknowledge())
    <form
        method="POST"
        action="{{ $action }}"
        class="tw:mt-4 tw:border-t tw:border-outline-variant tw:pt-4 tw:grid tw:gap-3 tw:md:grid-cols-12 tw:items-start"
        x-data="{ mode: @js($initialMode) }"
    >
        @csrf
        <div class="tw:md:col-span-3">
            <label for="{{ $idPrefix }}-mode" class="form-label tw:text-ui-xs tw:font-semibold">{{ __('purchasing.po_ack.fields.action_mode') }}</label>
            <select name="mode" id="{{ $idPrefix }}-mode" x-model="mode" class="ui-focus-ring form-select form-select-sm tw:min-h-[36px]">
                <option value="confirm">{{ __('purchasing.po_ack.actions.confirm_po') }}</option>
                <option value="propose_change">{{ __('purchasing.po_ack.actions.propose_date') }}</option>
            </select>
        </div>

        <div
            class="tw:md:col-span-3"
            x-show="mode === 'propose_change'"
            x-cloak
            x-effect="const isPropose = mode === 'propose_change'; $el.querySelectorAll('input, [data-calendar-trigger]').forEach((el) => { el.disabled = !isPropose; }); const dp = $el.querySelector('[data-adasi-date-picker]'); if (dp) { dp.dataset.calendarDisabled = isPropose ? 'false' : 'true'; }"
        >
            <x-ui.date-picker
                :id="$dateFieldId"
                name="supplier_promised_date"
                :label="__('purchasing.po_ack.fields.proposed_date')"
                :value="old('supplier_promised_date', $defaultProposedDate)"
                :min="$todayString"
                :disabled="$initialMode !== 'propose_change'"
            />
        </div>

        <div class="tw:md:col-span-4">
            <label for="{{ $idPrefix }}-notes" class="form-label tw:text-ui-xs tw:font-semibold">
                {{ __('purchasing.po_ack.fields.notes') }}
                <span class="tw:text-error" x-show="mode === 'propose_change'" x-cloak aria-hidden="true">*</span>
                <span class="tw:sr-only" x-show="mode === 'propose_change'" x-cloak> {{ __('common.required') }}</span>
            </label>
            <input
                type="text"
                name="acknowledgement_notes"
                id="{{ $idPrefix }}-notes"
                class="ui-focus-ring form-control form-control-sm tw:min-h-[36px]"
                value="{{ old('acknowledgement_notes', $po->acknowledgement_notes) }}"
                x-bind:required="mode === 'propose_change'"
                x-bind:aria-required="mode === 'propose_change' ? 'true' : 'false'"
                x-bind:placeholder="mode === 'propose_change' ? @js(__('purchasing.po_ack.placeholders.notes_required')) : @js(__('purchasing.po_ack.placeholders.notes_optional'))"
                aria-describedby="{{ $idPrefix }}-notes-hint"
            >
            <p id="{{ $idPrefix }}-notes-hint" class="tw:m-0 tw:mt-1 tw:text-[11px] tw:text-on-surface-variant" x-show="mode === 'propose_change'" x-cloak>
                {{ __('purchasing.po_ack.notes_required_hint') }}
            </p>
        </div>

        <div class="tw:md:col-span-2 tw:md:pt-6 tw:flex tw:justify-end">
            <x-ui.button type="submit" variant="primary" size="sm" class="ui-focus-ring tw:min-h-[36px] tw:w-full tw:justify-center">
                <x-ui.icon name="check" size="sm" />
                <span>{{ __('purchasing.po_ack.actions.submit') }}</span>
            </x-ui.button>
        </div>
    </form>
@elseif($po->acknowledgementStatusEnum() === \App\Enums\PoAcknowledgementStatus::CHANGE_REQUESTED)
    <div
        role="status"
        aria-live="polite"
        class="tw:mt-4 tw:border-t tw:border-outline-variant tw:pt-4"
    >
        <div class="tw:flex tw:items-start tw:gap-2.5 tw:rounded-ui-sm tw:border tw:border-warning/30 tw:bg-warning-container tw:px-3 tw:py-2.5 tw:text-ui-xs tw:text-on-warning-container">
            <x-ui.icon name="clock" size="sm" class="tw:shrink-0 tw:mt-0.5" />
            <span>{{ __('purchasing.po_ack.awaiting_review_notice') }}</span>
        </div>
    </div>
@endif

