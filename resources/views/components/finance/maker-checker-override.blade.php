@props(['id' => 'maker-checker-override'])
@if(auth()->user()?->isAdmin() && config('finance.maker_checker.allow_admin_override', true))
    <div class="tw:my-2">
        <label for="{{ $id }}" class="form-label tw:text-ui-xs">{{ __('finance_controls.override_label') }}</label>
        <textarea id="{{ $id }}" name="override_reason" rows="2" maxlength="1000" class="form-control form-control-sm">{{ old('override_reason') }}</textarea>
        <p class="tw:mb-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __('finance_controls.override_help') }}</p>
    </div>
@endif
