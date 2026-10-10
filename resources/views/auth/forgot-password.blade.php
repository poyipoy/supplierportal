@extends('layouts.auth')

@section('title', __('auth.password_assistance.title'))
@section('page-heading', __('auth.login.help'))

@section('content')
<div class="tw-grid tw-min-w-0 tw-gap-5" data-password-assistance>
    <header>
        <h1 class="tw-m-0 tw-text-ui-xl tw-font-bold tw-text-on-surface">{{ __('auth.login.help') }}</h1>
        <p class="tw-m-0 tw-mt-2 tw-text-ui-sm tw-text-pretty tw-text-on-surface-variant">{{ __('auth.password_assistance.description') }}</p>
    </header>

    @if($mailto)
        <section aria-labelledby="email-cta-heading" class="tw-min-w-0 tw-grid tw-gap-2.5">
            <h2 id="email-cta-heading" class="tw-m-0 tw-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-font-semibold tw-text-on-surface">
                <x-ui.icon name="send" size="sm" class="tw-text-primary" />
                {{ __('auth.password_assistance.email_cta_heading') }}
            </h2>
            <p class="tw-m-0 tw-text-ui-sm tw-text-pretty tw-text-on-surface-variant">{{ __('auth.password_assistance.email_cta_help') }}</p>
            <x-ui.button :href="$mailto" class="tw-mt-1">{{ __('auth.password_assistance.open_app') }}</x-ui.button>
        </section>

        <div class="tw-flex tw-items-center tw-gap-3 tw-text-ui-xs tw-font-medium tw-uppercase tw-tracking-wider tw-text-on-surface-variant" role="separator">
            <span class="tw-h-px tw-flex-1 tw-bg-outline-variant" aria-hidden="true"></span>
            <span>{{ __('auth.password_assistance.manual_divider') }}</span>
            <span class="tw-h-px tw-flex-1 tw-bg-outline-variant" aria-hidden="true"></span>
        </div>
    @endif

    @if($supportEmail)
        <section aria-labelledby="support-email-heading" class="tw-min-w-0 tw-grid tw-gap-1.5">
            <h2 id="support-email-heading" class="tw-m-0 tw-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-font-semibold">
                <x-ui.icon name="at-sign" size="sm" class="tw-text-on-surface-variant" />
                {{ __('auth.password_assistance.support') }}
            </h2>
            <p id="assistance-email" class="tw-m-0 tw-break-all tw-text-ui-sm">{{ $supportEmail }}</p>
            <x-ui.button type="button" variant="outline" size="sm" class="tw-justify-self-start" data-password-assistance-copy="assistance-email" data-copy-label="{{ __('auth.password_assistance.email_copy_label') }}">{{ __('auth.password_assistance.copy_email') }}</x-ui.button>
        </section>
    @else
        <p class="tw-m-0 tw-text-ui-sm tw-text-pretty">{{ __('auth.password_assistance.no_support') }}</p>
    @endif

    <section aria-labelledby="subject-heading" class="tw-min-w-0 tw-grid tw-gap-1.5">
        <h2 id="subject-heading" class="tw-m-0 tw-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-font-semibold">
            <x-ui.icon name="text-cursor-input" size="sm" class="tw-text-on-surface-variant" />
            {{ __('auth.password_assistance.subject_label') }}
        </h2>
        <p id="assistance-subject" class="tw-m-0 tw-break-words tw-text-ui-sm">{{ $subject }}</p>
        <x-ui.button type="button" variant="outline" size="sm" class="tw-justify-self-start" data-password-assistance-copy="assistance-subject" data-copy-label="{{ __('auth.password_assistance.subject_label') }}">{{ __('auth.password_assistance.copy_subject') }}</x-ui.button>
    </section>

    <section aria-labelledby="template-heading" class="tw-min-w-0 tw-grid tw-gap-1.5">
        <h2 id="template-heading" class="tw-m-0 tw-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-font-semibold">
            <x-ui.icon name="file-text" size="sm" class="tw-text-on-surface-variant" />
            {{ __('auth.password_assistance.template_label') }}
        </h2>
        <pre id="assistance-template" class="tw-m-0 tw-whitespace-pre-wrap tw-break-words tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3 tw-font-sans tw-text-ui-sm">{{ $template }}</pre>
        <x-ui.button type="button" variant="outline" size="sm" class="tw-mt-1 tw-justify-self-start" data-password-assistance-copy="assistance-template" data-copy-label="{{ __('auth.password_assistance.template_copy_label') }}">{{ __('auth.password_assistance.copy_template') }}</x-ui.button>
    </section>

    <p role="status" aria-live="polite" aria-atomic="true" data-copy-status class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant"></p>

    <a href="{{ route('login') }}" class="ui-focus-ring tw-rounded-ui-xs tw-text-ui-sm tw-font-semibold tw-text-primary">{{ __('auth.password_assistance.back') }}</a>
</div>
@endsection