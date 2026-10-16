@php
    $isPoRecovery = $recoveryKind === 'po';
    $recoveryId = $isPoRecovery ? 'restorePoModal' : 'restorePoGrModal';
    $recoveryBag = $errors->getBag($isPoRecovery ? 'restorePo' : 'restoreGr');
    $recoveryAction = $recoveryTarget ? route($routePrefix.($isPoRecovery ? '.restore' : '.goods-receipts.restore'), $recoveryTarget) : '';
    $hasRecoveryErrors = $recoveryBag->any() && $recoveryTarget;
@endphp
<div class="modal fade local-po-gr-modal" id="{{ $recoveryId }}" tabindex="-1" aria-labelledby="{{ $recoveryId }}Title" aria-hidden="true" data-recovery-modal data-recovery-reopen="{{ $hasRecoveryErrors ? '1' : '0' }}">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <form method="POST" action="{{ $recoveryAction }}">
            @csrf
            <input type="hidden" name="_recovery_kind" value="{{ $recoveryKind }}">
            <input type="hidden" name="_recovery_target" value="{{ $recoveryTarget?->hash }}">
            <div class="modal-header">
                <h2 class="modal-title tw-text-ui-base tw-font-semibold" id="{{ $recoveryId }}Title">{{ __('local_procurement.recovery.'.$recoveryKind.'_title') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
            </div>
            <div class="modal-body tw-space-y-4">
                <p class="tw-font-semibold tw-break-all" data-gr-modal-number>{{ $isPoRecovery ? $recoveryTarget?->po_number : $recoveryTarget?->gr_number }}</p>
                <p class="tw-text-ui-sm tw-text-on-surface-variant">{{ __('local_procurement.recovery.'.$recoveryKind.'_help') }}</p>
                @if($recoveryBag->has('status') || $recoveryBag->has('supplier_id'))
                    <div role="alert" data-recovery-error class="tw-text-error tw-text-ui-sm">{{ $recoveryBag->first('status') ?: $recoveryBag->first('supplier_id') }}</div>
                @endif
                <div>
                    <label for="{{ $recoveryId }}Reason" class="form-label">{{ __('local_procurement.recovery.reason') }} <span class="tw-text-error" aria-hidden="true">*</span></label>
                    <textarea name="reason" id="{{ $recoveryId }}Reason" rows="3" maxlength="1000" required class="form-control {{ $recoveryBag->has('reason') ? 'is-invalid' : '' }}" aria-describedby="{{ $recoveryId }}Hint{{ $recoveryBag->has('reason') ? ' '.$recoveryId.'ReasonError' : '' }}" @if($recoveryBag->has('reason')) aria-invalid="true" @endif>{{ $hasRecoveryErrors ? old('reason') : '' }}</textarea>
                    <p id="{{ $recoveryId }}Hint" class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-1">{{ __('local_procurement.recovery.reason_hint') }}</p>
                    @if($recoveryBag->has('reason'))<div id="{{ $recoveryId }}ReasonError" data-recovery-error class="invalid-feedback">{{ $recoveryBag->first('reason') }}</div>@endif
                </div>
                <div class="form-check">
                    <input id="{{ $recoveryId }}Confirm" type="checkbox" name="business_document_valid" value="1" required class="form-check-input {{ $recoveryBag->has('business_document_valid') ? 'is-invalid' : '' }}" @checked($hasRecoveryErrors && old('business_document_valid')) @if($recoveryBag->has('business_document_valid')) aria-invalid="true" aria-describedby="{{ $recoveryId }}ConfirmError" @endif>
                    <label class="form-check-label tw-text-ui-sm" for="{{ $recoveryId }}Confirm">{{ __('local_procurement.recovery.confirm_business_document') }}</label>
                    @if($recoveryBag->has('business_document_valid'))<div id="{{ $recoveryId }}ConfirmError" data-recovery-error class="invalid-feedback">{{ $recoveryBag->first('business_document_valid') }}</div>@endif
                </div>
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('local_procurement.recovery.submit') }}</x-ui.button>
            </div>
        </form>
    </div></div>
</div>
