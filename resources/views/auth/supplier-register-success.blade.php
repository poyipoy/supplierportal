@extends('layouts.auth')

@section('title', __('registration.submitted_title'))

@section('content')
<style>
    .auth-form-surface { max-width: 32rem !important; }
    .receipt-sheet { display: none; }
    .receipt-btn { transition-property: background-color, color, box-shadow, transform; transition-duration: 160ms; transition-timing-function: ease-out; }
    .receipt-btn:active { transform: scale(0.96); }
    .receipt-icon-swap { position: relative; display: inline-flex; width: 14px; height: 14px; }
    .receipt-icon-swap > * { position: absolute; inset: 0; transition-property: opacity, transform, filter; transition-duration: 200ms; transition-timing-function: ease-out; }
    .receipt-icon-swap > [data-state="off"] { opacity: 0; transform: scale(0.25); filter: blur(4px); }
    .receipt-icon-swap.is-on > [data-state="on"] { opacity: 0; transform: scale(0.25); filter: blur(4px); }
    .receipt-icon-swap.is-on > [data-state="off"] { opacity: 1; transform: scale(1); filter: blur(0); }
    @media (prefers-reduced-motion: reduce) {
        .receipt-btn, .receipt-icon-swap > * { transition: none; }
        .receipt-btn:active { transform: none; }
    }

    @media print {
        @page { margin: 18mm; }
        html, body { background: #fff !important; }
        .auth-brand-panel, .auth-mobile-brand, .auth-footer-copy, .no-print, [class*="adasi-toast"] { display: none !important; }
        .auth-shell { display: block !important; min-height: 0 !important; }
        .auth-form-panel { padding: 0 !important; min-height: 0 !important; background: #fff !important; display: block !important; }
        .auth-form-surface { max-width: none !important; width: 100% !important; box-shadow: none !important; border: 0 !important; padding: 0 !important; background: #fff !important; }
        .receipt-screen { display: none !important; }
        .receipt-sheet { display: block !important; color: #000; font-family: system-ui, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .receipt-sheet h1 { font-size: 20pt; margin: 0 0 4pt; text-wrap: balance; }
        .receipt-sheet table { width: 100%; border-collapse: collapse; margin-top: 14pt; }
        .receipt-sheet th { text-align: left; width: 34%; padding: 7pt 8pt; border: 1px solid #999; background: #f1f1f1 !important; font-size: 10pt; }
        .receipt-sheet td { padding: 7pt 8pt; border: 1px solid #999; font-family: ui-monospace, monospace; font-size: 11pt; word-break: break-all; font-variant-numeric: tabular-nums; }
        .receipt-sheet td.plain { font-family: inherit; }
        .receipt-sheet ol { margin: 6pt 0 0 16pt; padding: 0; font-size: 10.5pt; line-height: 1.5; }
        .receipt-sheet .warn { margin-top: 14pt; padding: 8pt 10pt; border: 1px solid #000; font-size: 10pt; font-weight: 600; }
        .receipt-sheet .foot { margin-top: 18pt; font-size: 8.5pt; color: #444; }
    }
</style>

<div
    class="tw-text-center tw-mb-4"
    x-data="{
        copiedRef: false,
        copiedKey: false,
        copiedBoth: false,
        reference: @js($reference),
        accessKey: @js($accessKey),
        flash(prop) { this[prop] = true; setTimeout(() => this[prop] = false, 2000); },
        copy(text, prop) { navigator.clipboard.writeText(text).then(() => this.flash(prop)); },
    }"
>
    <div class="receipt-screen">
        <div class="tw-inline-flex tw-items-center tw-justify-center tw-w-16 tw-h-16 tw-rounded-full tw-bg-success/15 tw-text-success tw-mb-3">
            <x-ui.icon name="check-circle" size="lg" />
        </div>

        <h2 class="tw-m-0 tw-text-ui-xl tw-font-bold tw-text-on-surface" style="text-wrap: balance;">{{ __('registration.submitted') }}</h2>
        <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant" style="text-wrap: pretty;">
            {{ __('registration.thanks', ['company' => $companyName]) }}
        </p>

        {{-- STATUS CHIP --}}
        <div class="tw-mt-3">
            <span class="ui-status-chip ui-status-chip--warning">
                <x-ui.icon name="clock" size="xs" />
                {{ __('common.fields.status') }}: {{ __('registration.statuses.pending') }}
            </span>
        </div>

        {{-- CREDENTIALS CARD --}}
        <div class="tw-mt-5 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-4 tw-text-start">
            <div class="tw-flex tw-items-start tw-gap-2.5 tw-text-warning-dim tw-bg-warning/10 tw-p-3 tw-rounded-ui-xs tw-mb-4">
                <x-ui.icon name="alert-triangle" size="sm" class="tw-shrink-0 tw-mt-0.5" />
                <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface" style="text-wrap: pretty;">
                    {{ __('registration.important_key') }}
                </p>
            </div>

            {{-- REGISTRATION REFERENCE --}}
            <div class="tw-mb-4">
                <label class="tw-block tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-uppercase tw-tracking-wider tw-mb-1">
                    {{ __('registration.reference') }}
                </label>
                <div class="tw-flex tw-items-center tw-justify-between tw-bg-surface tw-border tw-border-outline-variant tw-rounded-ui-xs tw-p-2.5">
                    <span class="tw-font-mono tw-font-bold tw-text-ui-sm tw-text-primary" style="font-variant-numeric: tabular-nums;">{{ $reference }}</span>
                    <button
                        type="button"
                        class="receipt-btn ui-focus-ring tw-inline-flex tw-min-h-10 tw-items-center tw-gap-1 tw-px-2 tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-underline tw-border-0 tw-bg-transparent"
                        @click="copy(reference, 'copiedRef')"
                    >
                        <span class="receipt-icon-swap" :class="copiedRef && 'is-on'">
                            <x-ui.icon name="copy" size="xs" data-state="on" />
                            <x-ui.icon name="check" size="xs" data-state="off" />
                        </span>
                        <span x-text="copiedRef ? @js(__('registration.copied')) : @js(__('common.actions.copy'))">{{ __('common.actions.copy') }}</span>
                    </button>
                </div>
            </div>

            {{-- REGISTRATION ACCESS KEY --}}
            <div>
                <label class="tw-block tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-uppercase tw-tracking-wider tw-mb-1">
                    {{ __('registration.private_key') }}
                </label>
                <div class="tw-flex tw-items-center tw-justify-between tw-bg-surface tw-border tw-border-outline-variant tw-rounded-ui-xs tw-p-2.5">
                    <span class="tw-font-mono tw-text-ui-xs tw-text-on-surface tw-break-all" style="user-select: all;">{{ $accessKey }}</span>
                    <button
                        type="button"
                        class="receipt-btn ui-focus-ring tw-inline-flex tw-min-h-10 tw-items-center tw-gap-1 tw-px-2 tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-underline tw-border-0 tw-bg-transparent tw-ml-2 tw-shrink-0"
                        @click="copy(accessKey, 'copiedKey')"
                    >
                        <span class="receipt-icon-swap" :class="copiedKey && 'is-on'">
                            <x-ui.icon name="copy" size="xs" data-state="on" />
                            <x-ui.icon name="check" size="xs" data-state="off" />
                        </span>
                        <span x-text="copiedKey ? @js(__('registration.copied')) : @js(__('common.actions.copy'))">{{ __('common.actions.copy') }}</span>
                    </button>
                </div>
            </div>

            {{-- RECEIPT ACTIONS --}}
            <div class="no-print tw-mt-4 tw-grid tw-grid-cols-2 tw-gap-2">
                <button
                    type="button"
                    id="receipt_print"
                    class="receipt-btn ui-focus-ring tw-flex tw-min-h-11 tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface tw-px-3 tw-text-ui-xs tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container"
                    @click="window.print()"
                >
                    <x-ui.icon name="printer" size="xs" />
                    <span>{{ __('registration.receipt.print') }}</span>
                </button>
                <button
                    type="button"
                    id="receipt_copy_both"
                    class="receipt-btn ui-focus-ring tw-flex tw-min-h-11 tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface tw-px-3 tw-text-ui-xs tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container"
                    @click="copy(reference + '\n' + accessKey, 'copiedBoth')"
                >
                    <span class="receipt-icon-swap" :class="copiedBoth && 'is-on'">
                        <x-ui.icon name="copy" size="xs" data-state="on" />
                        <x-ui.icon name="check" size="xs" data-state="off" />
                    </span>
                    <span x-text="copiedBoth ? @js(__('registration.receipt.copied_both')) : @js(__('registration.receipt.copy_both'))">{{ __('registration.receipt.copy_both') }}</span>
                </button>
            </div>

            <p class="no-print tw-m-0 tw-mt-3 tw-text-ui-xs tw-text-on-surface-variant" style="text-wrap: pretty;">
                {{ __('registration.receipt.lost_key_hint') }}
            </p>
        </div>

        {{-- ACTIONS --}}
        <div class="no-print tw-mt-5 tw-grid tw-gap-2.5">
            <a
                href="{{ route('supplier.registration.access-form') }}"
                class="receipt-btn ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground tw-no-underline hover:tw-brightness-95"
            >
                <x-ui.icon name="search" size="sm" />
                <span>{{ __('registration.check_status') }}</span>
            </a>

            <a
                href="{{ route('login') }}"
                class="receipt-btn ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-text-ui-sm tw-font-semibold tw-text-on-surface tw-no-underline hover:tw-bg-surface-container"
            >
                <span>{{ __('registration.return_login') }}</span>
            </a>
        </div>
    </div>

    {{-- PRINT-ONLY RECEIPT --}}
    <section class="receipt-sheet tw-text-start" id="registration_receipt" aria-label="{{ __('registration.receipt.title') }}">
        <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="ADASI" style="height: 36px; width: auto; margin-bottom: 10pt;">
        <h1>{{ __('registration.receipt.title') }}</h1>
        <p style="margin: 0; font-size: 10pt;">{{ __('common.review.supplier_portal') }}</p>

        <table>
            <tbody>
                <tr><th>{{ __('registration.receipt.company') }}</th><td class="plain">{{ $companyName }}</td></tr>
                <tr><th>{{ __('registration.receipt.submitted_at') }}</th><td class="plain">{{ $submittedAt }}</td></tr>
                <tr><th>{{ __('registration.reference') }}</th><td>{{ $reference }}</td></tr>
                <tr><th>{{ __('registration.key') }}</th><td>{{ $accessKey }}</td></tr>
            </tbody>
        </table>

        <h3 style="margin: 16pt 0 0; font-size: 11pt;">{{ __('registration.receipt.steps_title') }}</h3>
        <ol>
            <li>{{ __('registration.receipt.step_1') }}</li>
            <li>{{ __('registration.receipt.step_2') }}</li>
            <li>{{ __('registration.receipt.step_3') }}</li>
        </ol>
        <p style="margin: 6pt 0 0; font-size: 10pt;">{{ route('supplier.registration.access-form') }}</p>

        <p class="warn">{{ __('registration.receipt.warning') }}</p>
        <p class="foot">{{ __('registration.receipt.generated') }}</p>
    </section>
</div>
@endsection
