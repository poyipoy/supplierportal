@extends('layouts.auth')

@section('title', __('auth.password_assistance.title'))
@section('page-heading', __('auth.login.help'))

@section('content')
<div class="tw-grid tw-min-w-0 tw-gap-5" data-password-assistance>
    <header>
        <h1 class="tw-m-0 tw-text-ui-xl tw-font-bold tw-text-on-surface">{{ __('auth.login.help') }}</h1>
        <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-on-surface-variant">{{ __('auth.password_assistance.description') }}</p>
    </header>
    @if($supportEmail)
        <section aria-labelledby="support-email-heading" class="tw-min-w-0">
            <h2 id="support-email-heading" class="tw-text-ui-sm tw-font-semibold">{{ __('auth.password_assistance.support') }}</h2>
            <p id="assistance-email" class="tw-break-all tw-text-ui-sm">{{ $supportEmail }}</p>
            <x-ui.button type="button" variant="outlined" data-password-assistance-copy="assistance-email" data-copy-label="{{ __('auth.login.email') }}">{{ __('auth.password_assistance.copy_email') }}</x-ui.button>
        </section>
    @else
        <p class="tw-text-ui-sm">{{ __('auth.password_assistance.no_support') }}</p>
    @endif
    <section aria-labelledby="subject-heading" class="tw-min-w-0">
        <h2 id="subject-heading" class="tw-text-ui-sm tw-font-semibold">{{ __('auth.password_assistance.subject_label') }}</h2>
        <p id="assistance-subject" class="tw-break-words tw-text-ui-sm">{{ $subject }}</p>
        <x-ui.button type="button" variant="outlined" data-password-assistance-copy="assistance-subject" data-copy-label="{{ __('auth.password_assistance.subject_label') }}">{{ __('auth.password_assistance.copy_subject') }}</x-ui.button>
    </section>
    <section aria-labelledby="template-heading" class="tw-min-w-0">
        <h2 id="template-heading" class="tw-text-ui-sm tw-font-semibold">{{ __('auth.password_assistance.template_label') }}</h2>
        <pre id="assistance-template" class="tw-m-0 tw-whitespace-pre-wrap tw-break-words tw-font-sans tw-text-ui-sm">{{ $template }}</pre>
        <x-ui.button type="button" variant="outlined" class="tw-mt-3" data-password-assistance-copy="assistance-template" data-copy-label="{{ __('auth.password_assistance.template_copy_label') }}">{{ __('auth.password_assistance.copy_template') }}</x-ui.button>
    </section>
    <p role="status" aria-live="polite" aria-atomic="true" data-copy-status class="tw-m-0 tw-text-ui-sm"></p>
    @if($mailto)
        <x-ui.button :href="$mailto">{{ __('auth.password_assistance.open_app') }}</x-ui.button>
    @endif
    <a href="{{ route('login') }}" class="ui-focus-ring tw-rounded-ui-xs tw-text-ui-sm tw-font-semibold tw-text-primary">{{ __('auth.password_assistance.back') }}</a>
</div>
@endsection