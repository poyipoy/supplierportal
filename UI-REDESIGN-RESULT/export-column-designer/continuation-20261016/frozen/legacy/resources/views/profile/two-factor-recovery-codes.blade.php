@extends('layouts.auth')

@section('title', __('security.recovery_title'))

@section('content')
<header class="tw-mb-5">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-success">{{ __('security.enabled_short') }}</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">{{ __('security.save_codes') }}</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">{{ __('security.codes_help') }}</p>
</header>

<div class="tw-grid tw-grid-cols-2 tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface-container tw-p-3 tw-font-mono tw-text-ui-sm tw-mb-5" id="recoveryCodes">
    @foreach ($codes as $code)<div class="tw-select-all tw-rounded-ui-xs tw-bg-surface tw-border tw-border-outline-variant tw-p-2 tw-text-center">{{ $code }}</div>@endforeach
</div>

<div class="tw-grid tw-gap-3">
    <button type="button" id="copyRecoveryCodes" class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-text-ui-sm tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container">
        <x-ui.icon name="clipboard" /> {{ __('security.copy_codes') }}
    </button>
    <a href="{{ route('profile.security') }}" class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground tw-no-underline hover:tw-brightness-95">
        {{ __('security.saved_codes') }}
    </a>
</div>
@endsection

@section('scripts')
<script>
document.getElementById('copyRecoveryCodes').addEventListener('click', async function () {
    const codes = @json($codes);
    try {
        await navigator.clipboard.writeText(codes.join('\n'));
        window.AdasiToast?.success(@js(__('security.codes_copied')), {
            title: @js(__('security.codes_copied_title')),
            autoClose: 2500,
        });
    } catch (error) {
        window.AdasiToast?.error(@js(__('security.copy_failed')), {
            title: @js(__('security.copy_failed_title')),
            autoClose: 5000,
        });
    }
});
</script>
@endsection
