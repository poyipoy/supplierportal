@extends('layouts.auth')

@section('title', __('auth.password_assistance.title'))
@section('page-heading', __('auth.login.help'))

@section('content')
@php
    $supportWhatsapp ??= null;
    $supportWhatsappUrl ??= null;
@endphp
<div class="tw:grid tw:min-w-0 tw:gap-5" data-password-assistance>
    <header>
        <h1 class="tw:m-0 tw:text-ui-xl tw:font-bold tw:text-on-surface">{{ __('auth.login.help') }}</h1>
        <p class="tw:m-0 tw:mt-2 tw:text-ui-sm tw:text-pretty tw:text-on-surface-variant">{{ __('auth.password_assistance.description') }}</p>
    </header>

    @if($mailto)
        <section aria-labelledby="email-cta-heading" class="tw:min-w-0 tw:grid tw:gap-2.5">
            <h2 id="email-cta-heading" class="tw:m-0 tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ __('auth.password_assistance.email_cta_heading') }}</h2>
            <p class="tw:m-0 tw:text-ui-sm tw:text-pretty tw:text-on-surface-variant">{{ __('auth.password_assistance.email_cta_help') }}</p>
            <x-ui.button :href="$mailto" class="tw:mt-1">{{ __('auth.password_assistance.open_app') }}</x-ui.button>
        </section>
    @endif

    @if($supportWhatsapp && $supportWhatsappUrl)
        <section aria-labelledby="support-whatsapp-heading" class="tw:min-w-0 tw:grid tw:gap-1.5">
            <h2 id="support-whatsapp-heading" class="tw:m-0 tw:flex tw:items-center tw:gap-2 tw:text-ui-sm tw:font-semibold tw:text-on-surface">
                <x-ui.icon name="whatsapp" size="sm" class="tw:text-on-surface-variant" />
                {{ __('auth.password_assistance.whatsapp') }}
            </h2>
            <a href="{{ $supportWhatsappUrl }}" target="_blank" rel="noopener noreferrer" class="ui-focus-ring tw:w-fit tw:rounded-ui-xs tw:text-ui-sm tw:font-semibold tw:text-primary">{{ $supportWhatsapp }}<span class="tw:sr-only"> {{ __('auth.password_assistance.opens_new_tab') }}</span></a>
        </section>
    @endif

    @if(!$supportEmail && !$supportWhatsapp)
        <p class="tw:m-0 tw:text-ui-sm tw:text-pretty">{{ __('auth.password_assistance.no_support') }}</p>
    @endif

    <details class="tw:min-w-0 tw:border-x-0 tw:border-b-0 tw:border-t tw:border-solid tw:border-outline-variant tw:pt-3" @if(!$mailto) open @endif>
        <summary class="ui-focus-ring tw:cursor-pointer tw:rounded-ui-xs tw:py-2 tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ __('auth.password_assistance.manual_divider') }}</summary>

        <div class="tw:mt-3 tw:grid tw:gap-5">
            @if($supportEmail)
                <section aria-labelledby="support-email-heading" class="tw:min-w-0 tw:grid tw:gap-1.5">
                    <h2 id="support-email-heading" class="tw:m-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __('auth.password_assistance.support') }}</h2>
                    <p id="assistance-email" class="tw:m-0 tw:wrap-anywhere tw:text-ui-sm tw:text-on-surface">{{ $supportEmail }}</p>
                    <x-ui.button type="button" variant="outline" size="sm" class="tw:justify-self-start tw:max-sm:min-h-11" data-password-assistance-copy="assistance-email" data-copy-label="{{ __('auth.password_assistance.email_copy_label') }}">{{ __('auth.password_assistance.copy_email') }}</x-ui.button>
                </section>
            @endif

            <section aria-labelledby="subject-heading" class="tw:min-w-0 tw:grid tw:gap-1.5">
                <h2 id="subject-heading" class="tw:m-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __('auth.password_assistance.subject_label') }}</h2>
                <p id="assistance-subject" class="tw:m-0 tw:wrap-break-word tw:text-ui-sm tw:text-on-surface">{{ $subject }}</p>
                <x-ui.button type="button" variant="outline" size="sm" class="tw:justify-self-start tw:max-sm:min-h-11" data-password-assistance-copy="assistance-subject" data-copy-label="{{ __('auth.password_assistance.subject_label') }}">{{ __('auth.password_assistance.copy_subject') }}</x-ui.button>
            </section>

            <section aria-labelledby="template-heading" class="tw:min-w-0 tw:grid tw:gap-1.5">
                <h2 id="template-heading" class="tw:m-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __('auth.password_assistance.template_label') }}</h2>
                <pre id="assistance-template" class="tw:m-0 tw:whitespace-pre-wrap tw:wrap-break-word tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:p-3 tw:font-sans tw:text-ui-sm tw:text-on-surface">{{ $template }}</pre>
                <x-ui.button type="button" variant="outline" size="sm" class="tw:mt-1 tw:justify-self-start tw:max-sm:min-h-11" data-password-assistance-copy="assistance-template" data-copy-label="{{ __('auth.password_assistance.template_copy_label') }}">{{ __('auth.password_assistance.copy_template') }}</x-ui.button>
            </section>

            <p role="status" aria-live="polite" aria-atomic="true" data-copy-status class="tw:m-0 tw:text-ui-sm tw:text-on-surface-variant"></p>
        </div>
    </details>

    <a href="{{ route('login') }}" class="ui-focus-ring tw:rounded-ui-xs tw:text-ui-sm tw:font-semibold tw:text-primary">{{ __('auth.password_assistance.back') }}</a>
</div>
@endsection
