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

    @if(!$supportEmail && !$supportWhatsapp)
        <p class="tw:m-0 tw:text-ui-sm tw:text-pretty">{{ __('auth.password_assistance.no_support') }}</p>
    @endif

    <div class="tw:grid tw:min-w-0 tw:gap-2.5">
        @if($mailto)
            <x-ui.button :href="$mailto" class="tw:max-sm:min-h-11">{{ __('auth.password_assistance.open_app') }}</x-ui.button>
        @endif

        @if($supportWhatsapp && $supportWhatsappUrl)
            <x-ui.button variant="outline" :href="$supportWhatsappUrl" target="_blank" rel="noopener noreferrer" class="tw:border-solid tw:max-sm:min-h-11" data-password-assistance-whatsapp>
                <x-slot:leading><x-ui.icon name="whatsapp" size="md" /></x-slot:leading>
                {{ __('auth.password_assistance.whatsapp_cta') }}<span class="tw:sr-only"> {{ __('auth.password_assistance.opens_new_tab') }}</span>
                <x-slot:trailing><x-ui.icon name="external-link" size="sm" class="tw:text-on-surface-variant" /></x-slot:trailing>
            </x-ui.button>
        @endif

        {{-- Manual copy is the fallback: a ghost action beside the email and chat CTAs, the primary path when neither exists. --}}
        <x-ui.button
            type="button"
            :variant="$mailto ? 'ghost' : 'primary'"
            class="tw:max-sm:min-h-11"
            aria-haspopup="dialog"
            x-data
            x-on:click="$el.focus(); $dispatch('open-ui-dialog', 'password-assistance-manual')"
        >
            <x-slot:leading><x-ui.icon name="copy" size="sm" /></x-slot:leading>
            {{ __('auth.password_assistance.manual_divider') }}
        </x-ui.button>
    </div>

    <x-ui.dialog name="password-assistance-manual" :title="__('auth.password_assistance.manual_dialog_title')">
        <div class="tw:grid tw:min-w-0 tw:gap-5">
            @if($supportEmail)
                <section aria-labelledby="support-email-heading" class="tw:min-w-0 tw:grid tw:gap-1.5">
                    <h3 id="support-email-heading" class="tw:m-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __('auth.password_assistance.support') }}</h3>
                    <p id="assistance-email" class="tw:m-0 tw:wrap-anywhere tw:text-ui-sm tw:text-on-surface">{{ $supportEmail }}</p>
                    <x-ui.button type="button" variant="outline" size="sm" class="tw:justify-self-start tw:border-solid tw:max-sm:min-h-11" data-password-assistance-copy="assistance-email" data-copy-label="{{ __('auth.password_assistance.email_copy_label') }}">{{ __('auth.password_assistance.copy_email') }}</x-ui.button>
                </section>
            @endif

            <section aria-labelledby="subject-heading" class="tw:min-w-0 tw:grid tw:gap-1.5">
                <h3 id="subject-heading" class="tw:m-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __('auth.password_assistance.subject_label') }}</h3>
                <p id="assistance-subject" class="tw:m-0 tw:wrap-break-word tw:text-ui-sm tw:text-on-surface">{{ $subject }}</p>
                <x-ui.button type="button" variant="outline" size="sm" class="tw:justify-self-start tw:border-solid tw:max-sm:min-h-11" data-password-assistance-copy="assistance-subject" data-copy-label="{{ __('auth.password_assistance.subject_label') }}">{{ __('auth.password_assistance.copy_subject') }}</x-ui.button>
            </section>

            <section aria-labelledby="template-heading" class="tw:min-w-0 tw:grid tw:gap-1.5">
                <h3 id="template-heading" class="tw:m-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __('auth.password_assistance.template_label') }}</h3>
                <pre id="assistance-template" class="tw:m-0 tw:whitespace-pre-wrap tw:wrap-break-word tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:p-3 tw:font-sans tw:text-ui-sm tw:text-on-surface">{{ $template }}</pre>
                <x-ui.button type="button" variant="outline" size="sm" class="tw:mt-1 tw:justify-self-start tw:border-solid tw:max-sm:min-h-11" data-password-assistance-copy="assistance-template" data-copy-label="{{ __('auth.password_assistance.template_copy_label') }}">{{ __('auth.password_assistance.copy_template') }}</x-ui.button>
            </section>

            <p role="status" aria-live="polite" aria-atomic="true" data-copy-status class="tw:m-0 tw:min-h-5 tw:text-ui-sm tw:text-on-surface-variant"></p>
        </div>
    </x-ui.dialog>

    <a href="{{ route('login') }}" class="ui-focus-ring tw:rounded-ui-xs tw:text-ui-sm tw:font-semibold tw:text-primary">{{ __('auth.password_assistance.back') }}</a>
</div>
@endsection
