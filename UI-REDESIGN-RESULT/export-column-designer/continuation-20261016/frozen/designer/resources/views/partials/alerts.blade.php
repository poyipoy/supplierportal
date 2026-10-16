@if(session('success'))
    <div hidden data-adasi-flash data-type="success" data-title="{{ __('js.toast.completed') }}" data-message="{{ session('success') }}" data-duration="4000"></div>
@endif

@if(session('error'))
    <div hidden data-adasi-flash data-type="error" data-title="{{ __('js.toast.failed') }}" data-message="{{ session('error') }}" data-duration="8000"></div>
@endif

@if(session('warning'))
    <div hidden data-adasi-flash data-type="warning" data-title="{{ __('js.toast.attention') }}" data-message="{{ session('warning') }}" data-duration="6000"></div>
@endif

@if(session('info'))
    <div hidden data-adasi-flash data-type="info" data-title="{{ __('js.toast.update') }}" data-message="{{ session('info') }}" data-duration="5000"></div>
@endif

@if(session('status'))
    @php
        $statusMessage = match (session('status')) {
            'verification-link-sent' => __('auth.feedback.verification_sent'),
            'profile-updated' => __('auth.feedback.profile_updated'),
            'password-updated' => __('auth.feedback.password_updated'),
            'two-factor-already-enabled' => __('auth.feedback.already_enabled'),
            'two-factor-disabled' => __('auth.feedback.disabled'),
            'other-devices-logged-out' => __('auth.feedback.other_sessions_out'),
            'session-revoked' => __('security.device_signed_out'),
            'session-not-found' => __('security.session_not_found'),
            default => session('status'),
        };
    @endphp
    <div hidden data-adasi-flash data-type="info" data-title="{{ __('auth.feedback.account_update') }}" data-message="{{ $statusMessage }}" data-duration="5000"></div>
@endif

{{-- Validation errors --}}
@if(isset($errors) && $errors->any() && ! request()->routeIs('local-supplier.invoices.create', 'local-supplier.invoices.revision'))
    <x-ui.alert tone="error" :title="__('common.validation.review')" class="tw-mb-4">
        <ul class="tw-mb-0 tw-ps-4">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-ui.alert>
@endif
