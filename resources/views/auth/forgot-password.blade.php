@extends('layouts.auth')

@section('title', 'Password Assistance - ADASI Supplier Portal')
@section('page-heading', 'Password Assistance')

@section('content')
<div class="tw-grid tw-min-w-0 tw-gap-5" data-password-assistance>
    <header>
        <h1 class="tw-m-0 tw-text-ui-xl tw-font-bold tw-text-on-surface">Password Assistance</h1>
        <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">For security reasons, password reset requests are handled manually by our internal team. Please contact the support team using your registered company email address.</p>
    </header>
    @if($supportEmail)
        <section aria-labelledby="support-email-heading" class="tw-min-w-0">
            <h2 id="support-email-heading" class="tw-text-ui-sm tw-font-semibold">Support Email</h2>
            <p id="assistance-email" class="tw-break-all tw-text-ui-sm">{{ $supportEmail }}</p>
            <x-ui.button type="button" variant="outlined" data-password-assistance-copy="assistance-email" data-copy-label="Email address">Copy Email Address</x-ui.button>
        </section>
    @else
        <p class="tw-text-ui-sm">Please contact your company representative for support contact details.</p>
    @endif
    <section aria-labelledby="subject-heading" class="tw-min-w-0">
        <h2 id="subject-heading" class="tw-text-ui-sm tw-font-semibold">Subject</h2>
        <p id="assistance-subject" class="tw-break-words tw-text-ui-sm">{{ $subject }}</p>
        <x-ui.button type="button" variant="outlined" data-password-assistance-copy="assistance-subject" data-copy-label="Subject">Copy Subject</x-ui.button>
    </section>
    <section aria-labelledby="template-heading" class="tw-min-w-0">
        <h2 id="template-heading" class="tw-text-ui-sm tw-font-semibold">Email Template</h2>
        <pre id="assistance-template" class="tw-m-0 tw-whitespace-pre-wrap tw-break-words tw-font-sans tw-text-ui-sm">{{ $template }}</pre>
        <x-ui.button type="button" variant="outlined" class="tw-mt-3" data-password-assistance-copy="assistance-template" data-copy-label="Email template">Copy Email Template</x-ui.button>
    </section>
    <p role="status" aria-live="polite" aria-atomic="true" data-copy-status class="tw-m-0 tw-text-ui-sm"></p>
    @if($mailto)
        <x-ui.button :href="$mailto">Open Email App</x-ui.button>
    @endif
    <a href="{{ route('login') }}" class="ui-focus-ring tw-rounded-ui-xs tw-text-ui-sm tw-font-semibold tw-text-primary">Back to Sign In</a>
</div>
@endsection