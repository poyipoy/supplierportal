@extends('layouts.app')
@section('title', __('local_procurement.registration.review_title', ['company' => $supplier?->company_name ?? $user->name]))
@section('page-title', __('local_procurement.registration.review_heading'))

@section('content')
<div class="tw-grid tw-gap-6" x-data="{ approveModal: false, revisionModal: false, rejectModal: false }">
    {{-- PAGE HEADER --}}
    <x-ui.page-header
        :title="$supplier?->company_name ?? $user->name"
        :description="__('local_procurement.registration.reference', ['reference' => $activeAccess?->registration_reference ?? '-', 'attempt' => $attempt->attempt_number, 'date' => $attempt->submitted_at ? $regionalFormatter->timestamp($attempt->submitted_at, 'datetime_comma') : '-'])"
        :eyebrow="__('local_procurement.registration.eyebrow')"
    >
        <x-slot:actions>
            <a href="{{ route('supplier-registrations.index') }}" class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-px-3.5 tw-py-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-text-ui-sm tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container tw-no-underline">
                <x-ui.icon name="arrow-left" size="xs" />
                <span>{{ __('local_procurement.registration.back') }}</span>
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))
        <div class="tw-rounded-ui-sm tw-bg-success/15 tw-p-3.5 tw-text-on-surface tw-flex tw-items-center tw-gap-2.5 tw-text-ui-sm tw-font-medium" role="alert">
            <x-ui.icon name="check-circle" size="sm" class="tw-text-success tw-shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="tw-rounded-ui-sm tw-bg-error-container tw-p-3.5 tw-text-on-error-container tw-flex tw-items-center tw-gap-2.5 tw-text-ui-sm tw-font-medium" role="alert">
            <x-ui.icon name="alert-circle" size="sm" class="tw-shrink-0" />
            <span>{{ session('error') }}</span>
        </div>
    @endif

    {{-- CURRENT STATUS CARD & REVIEWER ACTIONS --}}
    <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-5 tw-shadow-xs">
        <div class="tw-flex tw-flex-col md:tw-flex-row md:tw-items-center md:tw-justify-between tw-gap-4">
            <div>
                <div class="tw-flex tw-items-center tw-gap-2.5">
                    <span class="tw-text-ui-xs tw-font-bold tw-text-on-surface-variant tw-uppercase tw-tracking-wider">{{ __('local_procurement.registration.application_status') }}</span>
                    <x-ui.status-chip :tone="\App\Support\StatusHelper::registrationTone($attempt->status)">
                        {{ \App\Support\StatusHelper::registrationLabel($attempt->status) }}
                    </x-ui.status-chip>
                </div>

                @if ($attempt->reviewed_by)
                    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-xs tw-text-on-surface-variant">
                        {{ __('local_procurement.registration.last_review', ['name' => $attempt->reviewer?->name ?? __('local_procurement.registration.reviewer'), 'role' => $attempt->reviewer?->role, 'date' => $attempt->reviewed_at ? $regionalFormatter->timestamp($attempt->reviewed_at, 'datetime_comma') : '-']) }}
                    </p>
                @endif

                @if ($attempt->reviewer_notes)
                    <div class="tw-mt-2 tw-text-ui-xs tw-text-on-surface tw-bg-surface-container tw-p-2.5 tw-rounded-ui-xs">
                        <strong>{{ __('local_procurement.registration.review_notes') }}</strong> {{ $attempt->reviewer_notes }}
                    </div>
                @endif
            </div>

            {{-- ACTION BUTTONS FOR REVIEWER --}}
            @if ($attempt->status === \App\Models\SupplierRegistrationAttempt::STATUS_PENDING)
                <div class="tw-flex tw-items-center tw-gap-2 tw-flex-wrap">
                    {{-- REQUEST REVISION BUTTON --}}
                    <button
                        type="button"
                        class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-px-3.5 tw-py-2 tw-rounded-ui-sm tw-border tw-border-info tw-bg-info/10 tw-text-info tw-text-ui-sm tw-font-semibold hover:tw-bg-info/20"
                        @click="revisionModal = true"
                    >
                        <x-ui.icon name="edit" size="xs" />
                        <span>{{ __('finance.actions.request_revision') }}</span>
                    </button>

                    {{-- REJECT BUTTON --}}
                    <button
                        type="button"
                        class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-px-3.5 tw-py-2 tw-rounded-ui-sm tw-border tw-border-error tw-bg-error/10 tw-text-error tw-text-ui-sm tw-font-semibold hover:tw-bg-error/20"
                        @click="rejectModal = true"
                    >
                        <x-ui.icon name="x-circle" size="xs" />
                        <span>{{ __('local_procurement.registration.reject') }}</span>
                    </button>

                    {{-- APPROVE & ACTIVATE BUTTON --}}
                    <button
                        type="button"
                        class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-px-4 tw-py-2 tw-rounded-ui-sm tw-bg-success tw-text-on-success tw-text-ui-sm tw-font-semibold tw-border-0 hover:tw-brightness-95 tw-shadow-xs"
                        @click="approveModal = true"
                    >
                        <x-ui.icon name="check-circle" size="xs" />
                        <span>{{ __('local_procurement.registration.approve_activate') }}</span>
                    </button>
                </div>
            @endif
        </div>
    </div>

    {{-- 2-COLUMN DETAILS GRID --}}
    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-3 tw-gap-6">
        {{-- LEFT COLUMN: COMPANY & PIC & BANK (2 cols) --}}
        <div class="lg:tw-col-span-2 tw-space-y-6">
            {{-- COMPANY & TAX IDENTIFIER CARD --}}
            <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-5 tw-shadow-xs">
                <div class="tw-flex tw-items-center tw-gap-2 tw-mb-4 tw-text-primary">
                    <x-ui.icon name="building-2" size="sm" />
                    <h3 class="tw-m-0 tw-text-ui-base tw-font-bold tw-text-on-surface">{{ __('local_procurement.registration.company_tax') }}</h3>
                </div>

                <dl class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-x-4 tw-gap-y-3 tw-text-ui-sm tw-m-0">
                    <div>
                        <dt class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_procurement.registration.legal_title') }}</dt>
                        <dd class="tw-font-medium tw-text-on-surface tw-m-0">{{ $supplier?->company_title ?: __('local_procurement.registration.not_specified') }}</dd>
                    </div>
                    <div>
                        <dt class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_procurement.registration.company_name') }}</dt>
                        <dd class="tw-font-semibold tw-text-on-surface tw-m-0">{{ $supplier?->company_name ?? $user->name }}</dd>
                    </div>
                    <div>
                        <dt class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('registration.nib') }}</dt>
                        <dd class="tw-font-mono tw-font-semibold tw-text-on-surface tw-m-0">{{ $supplier?->nib ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_procurement.registration.tax_identity') }}</dt>
                        <dd class="tw-font-mono tw-font-semibold tw-text-on-surface tw-m-0">{{ $supplier?->npwp ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_procurement.registration.business_category') }}</dt>
                        <dd class="tw-font-medium tw-text-on-surface tw-m-0">{{ $supplier?->category ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_procurement.registration.pkp') }}</dt>
                        <dd class="tw-font-medium tw-text-on-surface tw-m-0">
                            @if ($supplier?->is_pkp)
                                <span class="ui-status-chip ui-status-chip--success">{{ __('local_procurement.registration.pkp_verified') }}</span>
                            @else
                                <span class="ui-status-chip ui-status-chip--neutral">Non-PKP</span>
                            @endif
                        </dd>
                    </div>
                    <div class="md:tw-col-span-2">
                        <dt class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_procurement.registration.legal_address') }}</dt>
                        <dd class="tw-font-medium tw-text-on-surface tw-m-0">{{ $supplier?->address ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_invoice.labels.company_phone') }}</dt>
                        <dd class="tw-font-medium tw-text-on-surface tw-m-0">{{ $supplier?->phone ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_procurement.registration.portal_email') }}</dt>
                        <dd class="tw-font-medium tw-text-on-surface tw-m-0">{{ $user->email }}</dd>
                    </div>
                </dl>
            </div>

            {{-- PIC & BANK ACCOUNT CARD --}}
            <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-6">
                {{-- PIC CARD --}}
                <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-5 tw-shadow-xs">
                    <div class="tw-flex tw-items-center tw-gap-2 tw-mb-3 tw-text-primary">
                        <x-ui.icon name="user" size="sm" />
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-on-surface">{{ __('local_procurement.registration.pic') }}</h3>
                    </div>
                    <div class="tw-space-y-2 tw-text-ui-sm">
                        <div>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('common.fields.full_name') }}</span>
                            <span class="tw-font-medium tw-text-on-surface">{{ $supplier?->pic_name ?? '-' }}</span>
                        </div>
                        <div>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('common.fields.email') }}</span>
                            <span class="tw-font-medium tw-text-on-surface">{{ $supplier?->pic_email ?? '-' }}</span>
                        </div>
                        <div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('local_procurement.registration.phone') }}</span>
                            <span class="tw-font-medium tw-text-on-surface">{{ $supplier?->pic_phone ?? '-' }}</span>
                        </div>
                    </div>
                </div>

                {{-- BANK ACCOUNT CARD --}}
                <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-5 tw-shadow-xs">
                    <div class="tw-flex tw-items-center tw-gap-2 tw-mb-3 tw-text-primary">
                        <x-ui.icon name="credit-card" size="sm" />
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-on-surface">{{ __('local_procurement.registration.bank_details') }}</h3>
                    </div>
                    <div class="tw-space-y-2 tw-text-ui-sm">
                        <div>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('registration.bank') }}</span>
                            <span class="tw-font-medium tw-text-on-surface">{{ $bankAccount?->bank_name ?? '-' }}</span>
                        </div>
                        <div>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('registration.account_number') }}</span>
                            <span class="tw-font-mono tw-font-semibold tw-text-on-surface">{{ $bankAccount?->account_number ?? '-' }}</span>
                        </div>
                        <div>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('local_invoice.labels.account_name') }}</span>
                            <span class="tw-font-medium tw-text-on-surface">{{ $bankAccount?->account_holder_name ?? '-' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- QUALITY & COMPLIANCE QUESTIONNAIRE CARD (answers of this attempt; never blocks approval) --}}
            @php
                $snapshotAnswers = is_array($attempt->snapshot['questionnaire'] ?? null)
                    ? \App\Support\SupplierComplianceQuestionnaire::normalize($attempt->snapshot['questionnaire'])
                    : \App\Support\SupplierComplianceQuestionnaire::answersFrom($supplier?->compliance_questionnaire);
                $flaggedAnswers = \App\Support\SupplierComplianceQuestionnaire::flagged($snapshotAnswers);
            @endphp
            <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-5 tw-shadow-xs">
                <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-2 tw-mb-3">
                    <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary">
                        <x-ui.icon name="clipboard-check" size="sm" />
                        <h3 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-on-surface">{{ __('local_procurement.registration.questionnaire') }}</h3>
                    </div>
                    @if ($snapshotAnswers !== [])
                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs tw-font-semibold {{ $flaggedAnswers ? 'tw-text-warning' : 'tw-text-on-surface-variant' }}">
                            <x-ui.icon :name="$flaggedAnswers ? 'alert-triangle' : 'check-circle'" size="xs" />
                            {{ trans_choice('local_procurement.registration.questionnaire_flagged', count($flaggedAnswers), ['count' => count($flaggedAnswers)]) }}
                        </span>
                    @endif
                </div>

                @if ($snapshotAnswers === [])
                    <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_procurement.registration.questionnaire_missing') }}</p>
                @else
                    <dl class="tw-m-0 tw-grid tw-gap-1.5 tw-text-ui-sm">
                        @foreach (\App\Support\SupplierComplianceQuestionnaire::keys() as $index => $questionKey)
                            @php
                                $answer = $snapshotAnswers[$questionKey] ?? null;
                                $isFlagged = \App\Support\SupplierComplianceQuestionnaire::isFlagged($questionKey, $answer);
                            @endphp
                            <div class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-rounded-ui-xs tw-border tw-px-3 tw-py-2 {{ $isFlagged ? 'tw-border-warning/50 tw-bg-warning/5' : 'tw-border-outline-variant' }}">
                                <dt class="tw-text-on-surface tw-text-pretty">{{ $index + 1 }}. {{ __('registration.questionnaire.questions.'.$questionKey) }}</dt>
                                <dd class="tw-m-0 tw-shrink-0 tw-text-end">
                                    <span class="tw-font-semibold tw-text-on-surface">
                                        {{ $answer === null ? __('registration.questionnaire.not_answered') : __('registration.questionnaire.'.$answer) }}
                                    </span>
                                    @if ($isFlagged)
                                        <span class="tw-mt-0.5 tw-flex tw-items-center tw-justify-end tw-gap-1 tw-text-[11px] tw-font-semibold tw-text-warning">
                                            <x-ui.icon name="alert-triangle" size="xs" />
                                            {{ __('registration.questionnaire.needs_attention') }}
                                        </span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>

            {{-- SUBMISSION SNAPSHOT CARD --}}
            @php
                $snapshot = is_array($attempt->snapshot) ? $attempt->snapshot : [];
                $companySnap = is_array($snapshot['company'] ?? null) ? $snapshot['company'] : [];
                $taxSnap = is_array($snapshot['tax'] ?? null) ? $snapshot['tax'] : [];
                $picSnap = is_array($snapshot['pic'] ?? null) ? $snapshot['pic'] : [];
                $bankSnap = is_array($snapshot['bank'] ?? null) ? $snapshot['bank'] : [];
                $docsSnap = is_array($snapshot['documents'] ?? null) ? $snapshot['documents'] : [];
                $questSnap = is_array($snapshot['questionnaire'] ?? null) ? \App\Support\SupplierComplianceQuestionnaire::normalize($snapshot['questionnaire']) : [];
                $revisionNotes = $snapshot['revision_notes'] ?? null;
                $checksum = $attempt->submission_checksum;
                $jsonPretty = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $isChecksumValid = !empty($checksum) && hash('sha256', json_encode($snapshot)) === $checksum;
            @endphp
            <div
                class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-5 tw-shadow-xs"
                x-data="{
                    expanded: true,
                    showRawJson: false,
                    copiedJson: false,
                    copiedChecksum: false,
                    copyText(text, key) {
                        if (navigator.clipboard && window.isSecureContext) {
                            navigator.clipboard.writeText(text).then(() => {
                                this[key] = true;
                                setTimeout(() => this[key] = false, 2000);
                            });
                        } else {
                            const el = document.createElement('textarea');
                            el.value = text;
                            el.style.position = 'fixed';
                            el.style.left = '-9999px';
                            document.body.appendChild(el);
                            el.focus();
                            el.select();
                            try {
                                document.execCommand('copy');
                                this[key] = true;
                                setTimeout(() => this[key] = false, 2000);
                            } catch (e) {}
                            document.body.removeChild(el);
                        }
                    }
                }"
            >
                {{-- CARD HEADER --}}
                <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
                    <div class="tw-flex tw-items-center tw-gap-2.5 tw-cursor-pointer" @click="expanded = !expanded">
                        <span class="tw-inline-flex tw-items-center tw-justify-center tw-w-8 tw-h-8 tw-rounded-ui-xs tw-bg-primary/10 tw-text-primary tw-shrink-0">
                            <x-ui.icon name="archive" size="sm" />
                        </span>
                        <div>
                            <div class="tw-flex tw-items-center tw-gap-2">
                                <h3 class="tw-m-0 tw-text-ui-base tw-font-bold tw-text-on-surface">
                                    {{ __('local_procurement.registration.snapshot', ['attempt' => $attempt->attempt_number]) }}
                                </h3>
                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded-full tw-text-[11px] tw-font-semibold {{ $isChecksumValid ? 'tw-bg-success/10 tw-text-success' : 'tw-bg-warning/10 tw-text-warning' }}">
                                    <x-ui.icon :name="$isChecksumValid ? 'shield-check' : 'shield-alert'" size="xs" />
                                    <span>{{ $isChecksumValid ? 'SHA-256 Verified' : 'Unverified' }}</span>
                                </span>
                            </div>
                            <p class="tw-m-0 tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5">
                                {{ $attempt->submitted_at ? $regionalFormatter->timestamp($attempt->submitted_at, 'datetime_comma') : '-' }} &bull; {{ __('local_procurement.registration.snapshot_integrity_verified') }}
                            </p>
                        </div>
                    </div>

                    <div class="tw-flex tw-items-center tw-gap-2">
                        {{-- MODE TOGGLE (Structured vs Raw JSON) --}}
                        <div class="tw-inline-flex tw-items-center tw-p-0.5 tw-rounded-ui-xs tw-bg-surface-container tw-border tw-border-outline-variant/60" x-show="expanded">
                            <button
                                type="button"
                                @click.stop="showRawJson = false"
                                :class="!showRawJson ? 'tw-bg-surface tw-text-primary tw-shadow-xs tw-font-bold' : 'tw-text-on-surface-variant hover:tw-text-on-surface'"
                                class="tw-px-2.5 tw-py-1 tw-rounded-[calc(var(--radius-xs)-2px)] tw-text-ui-xs tw-inline-flex tw-items-center tw-gap-1.5 tw-transition-all tw-duration-150 tw-border-0 tw-cursor-pointer"
                                title="{{ __('local_procurement.registration.snapshot_view_structured') }}"
                            >
                                <x-ui.icon name="layout-grid" size="xs" />
                                <span>{{ __('local_procurement.registration.snapshot_view_structured') }}</span>
                            </button>
                            <button
                                type="button"
                                @click.stop="showRawJson = true"
                                :class="showRawJson ? 'tw-bg-surface tw-text-primary tw-shadow-xs tw-font-bold' : 'tw-text-on-surface-variant hover:tw-text-on-surface'"
                                class="tw-px-2.5 tw-py-1 tw-rounded-[calc(var(--radius-xs)-2px)] tw-text-ui-xs tw-inline-flex tw-items-center tw-gap-1.5 tw-transition-all tw-duration-150 tw-border-0 tw-cursor-pointer"
                                title="{{ __('local_procurement.registration.snapshot_view_raw') }}"
                            >
                                <x-ui.icon name="code" size="xs" />
                                <span>{{ __('local_procurement.registration.snapshot_view_raw') }}</span>
                            </button>
                        </div>

                        {{-- COPY JSON BUTTON --}}
                        <button
                            type="button"
                            @click.stop="copyText({{ Js::from($jsonPretty) }}, 'copiedJson')"
                            class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-1.5 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface hover:tw-bg-surface-container tw-text-on-surface tw-text-ui-xs tw-font-semibold tw-transition-all tw-duration-150 active:tw-scale-95 tw-cursor-pointer"
                            title="{{ __('local_procurement.registration.snapshot_copy_json') }}"
                        >
                            <template x-if="copiedJson">
                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-text-success">
                                    <x-ui.icon name="check" size="xs" />
                                    <span>{{ __('local_procurement.registration.snapshot_json_copied') }}</span>
                                </span>
                            </template>
                            <template x-if="!copiedJson">
                                <span class="tw-inline-flex tw-items-center tw-gap-1">
                                    <x-ui.icon name="copy" size="xs" />
                                    <span>{{ __('local_procurement.registration.snapshot_copy_json') }}</span>
                                </span>
                            </template>
                        </button>

                        {{-- EXPAND / COLLAPSE BUTTON --}}
                        <button
                            type="button"
                            @click.stop="expanded = !expanded"
                            class="ui-focus-ring tw-inline-flex tw-items-center tw-justify-center tw-w-8 tw-h-8 tw-rounded-ui-xs tw-text-on-surface-variant hover:tw-text-on-surface hover:tw-bg-surface-container tw-border tw-border-outline-variant/60 tw-bg-surface tw-transition-colors tw-cursor-pointer"
                            aria-label="Toggle snapshot details"
                        >
                            <x-ui.icon name="chevron-up" size="sm" x-show="expanded" />
                            <x-ui.icon name="chevron-down" size="sm" x-show="!expanded" />
                        </button>
                    </div>
                </div>

                {{-- CARD CONTENT BODY --}}
                <div class="tw-mt-4 tw-pt-4 tw-border-t tw-border-outline-variant/60" x-show="expanded">
                    {{-- STRUCTURED VIEW --}}
                    <div class="tw-space-y-4" x-show="!showRawJson">
                        @if (!empty($revisionNotes))
                            {{-- REVISION NOTES FROM SUPPLIER --}}
                            <div class="tw-rounded-ui-xs tw-border tw-border-info/40 tw-bg-info/5 tw-p-3.5">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-text-info tw-font-semibold tw-text-ui-xs tw-mb-1">
                                    <x-ui.icon name="message-square" size="xs" />
                                    <span>{{ __('local_procurement.registration.snapshot_revision_notes') }}</span>
                                </div>
                                <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface tw-leading-relaxed tw-text-pretty">{{ $revisionNotes }}</p>
                            </div>
                        @endif

                        {{-- 1. COMPANY & TAX IDENTITY --}}
                        <div class="tw-rounded-ui-xs tw-border tw-border-outline-variant/70 tw-bg-surface-container-lowest tw-p-4 tw-shadow-xs">
                            <div class="tw-flex tw-items-center tw-justify-between tw-mb-3 tw-border-b tw-border-outline-variant/40 tw-pb-2.5">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary">
                                    <x-ui.icon name="building-2" size="xs" />
                                    <h4 class="tw-m-0 tw-text-ui-xs tw-font-bold tw-text-on-surface tw-uppercase tw-tracking-wider">
                                        {{ __('local_procurement.registration.snapshot_company_tax') }}
                                    </h4>
                                </div>
                                @if (!empty($companySnap['is_pkp']))
                                    <span class="ui-status-chip ui-status-chip--success tw-text-[11px] tw-py-0.5">
                                        {{ __('local_procurement.registration.pkp_verified') }}
                                    </span>
                                @else
                                    <span class="ui-status-chip ui-status-chip--neutral tw-text-[11px] tw-py-0.5">
                                        Non-PKP
                                    </span>
                                @endif
                            </div>

                            <dl class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-x-4 tw-gap-y-3 tw-text-ui-xs tw-m-0">
                                <div>
                                    <dt class="tw-text-on-surface-variant">{{ __('local_procurement.registration.company_label') }}</dt>
                                    <dd class="tw-font-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5 tw-m-0">
                                        {{ ($companySnap['company_title'] ?? '') ? $companySnap['company_title'] . ' ' : '' }}{{ $companySnap['company_name'] ?? '-' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="tw-text-on-surface-variant">{{ __('local_procurement.registration.business_category') }}</dt>
                                    <dd class="tw-font-medium tw-text-on-surface tw-mt-0.5 tw-m-0">
                                        {{ $companySnap['category'] ?? '-' }}
                                        @if (!empty($companySnap['vendor_category']))
                                            <span class="tw-text-on-surface-variant">({{ $companySnap['vendor_category'] }})</span>
                                        @endif
                                    </dd>
                                </div>

                                <div>
                                    <dt class="tw-text-on-surface-variant">{{ __('registration.nib') }}</dt>
                                    <dd class="tw-font-mono tw-font-semibold tw-text-on-surface tw-mt-0.5 tw-m-0 tw-tabular-nums">
                                        {{ $taxSnap['nib'] ?? '-' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="tw-text-on-surface-variant">{{ __('local_procurement.registration.tax_identity') }}</dt>
                                    <dd class="tw-font-mono tw-font-semibold tw-text-on-surface tw-mt-0.5 tw-m-0 tw-tabular-nums">
                                        {{ $taxSnap['npwp'] ?? '-' }}
                                    </dd>
                                </div>

                                <div class="md:tw-col-span-2">
                                    <dt class="tw-text-on-surface-variant">{{ __('local_procurement.registration.legal_address') }}</dt>
                                    <dd class="tw-font-medium tw-text-on-surface tw-mt-0.5 tw-m-0 tw-text-pretty">
                                        {{ $companySnap['address'] ?? '-' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="tw-text-on-surface-variant">{{ __('local_invoice.labels.company_phone') }}</dt>
                                    <dd class="tw-font-medium tw-text-on-surface tw-mt-0.5 tw-m-0">
                                        {{ $companySnap['phone'] ?? '-' }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        {{-- 2. PIC & BANK DETAILS --}}
                        <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-4">
                            {{-- PIC --}}
                            <div class="tw-rounded-ui-xs tw-border tw-border-outline-variant/70 tw-bg-surface-container-lowest tw-p-4 tw-shadow-xs">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-mb-3 tw-border-b tw-border-outline-variant/40 tw-pb-2.5">
                                    <x-ui.icon name="user" size="xs" />
                                    <h4 class="tw-m-0 tw-text-ui-xs tw-font-bold tw-text-on-surface tw-uppercase tw-tracking-wider">
                                        {{ __('local_procurement.registration.pic') }}
                                    </h4>
                                </div>
                                <dl class="tw-space-y-2.5 tw-text-ui-xs tw-m-0">
                                    <div>
                                        <dt class="tw-text-on-surface-variant">{{ __('common.fields.full_name') }}</dt>
                                        <dd class="tw-font-semibold tw-text-on-surface tw-mt-0.5 tw-m-0">{{ $picSnap['pic_name'] ?? '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="tw-text-on-surface-variant">{{ __('common.fields.email') }}</dt>
                                        <dd class="tw-font-medium tw-text-on-surface tw-mt-0.5 tw-m-0">{{ $picSnap['pic_email'] ?? '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="tw-text-on-surface-variant">{{ __('local_procurement.registration.phone') }}</dt>
                                        <dd class="tw-font-medium tw-text-on-surface tw-mt-0.5 tw-m-0">{{ $picSnap['pic_phone'] ?? '-' }}</dd>
                                    </div>
                                </dl>
                            </div>

                            {{-- BANK ACCOUNT --}}
                            <div class="tw-rounded-ui-xs tw-border tw-border-outline-variant/70 tw-bg-surface-container-lowest tw-p-4 tw-shadow-xs">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-mb-3 tw-border-b tw-border-outline-variant/40 tw-pb-2.5">
                                    <x-ui.icon name="credit-card" size="xs" />
                                    <h4 class="tw-m-0 tw-text-ui-xs tw-font-bold tw-text-on-surface tw-uppercase tw-tracking-wider">
                                        {{ __('local_procurement.registration.bank_details') }}
                                    </h4>
                                </div>
                                <dl class="tw-space-y-2.5 tw-text-ui-xs tw-m-0">
                                    <div>
                                        <dt class="tw-text-on-surface-variant">{{ __('registration.bank') }}</dt>
                                        <dd class="tw-font-semibold tw-text-on-surface tw-mt-0.5 tw-m-0">{{ $bankSnap['bank_name'] ?? '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="tw-text-on-surface-variant">{{ __('registration.account_number') }}</dt>
                                        <dd class="tw-font-mono tw-font-bold tw-text-on-surface tw-mt-0.5 tw-m-0 tw-tabular-nums tw-tracking-wide">
                                            {{ $bankSnap['account_number'] ?? '-' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="tw-text-on-surface-variant">{{ __('local_invoice.labels.account_name') }}</dt>
                                        <dd class="tw-font-medium tw-text-on-surface tw-mt-0.5 tw-m-0">{{ $bankSnap['account_holder_name'] ?? '-' }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        {{-- 3. ARCHIVED DOCUMENTS --}}
                        <div class="tw-rounded-ui-xs tw-border tw-border-outline-variant/70 tw-bg-surface-container-lowest tw-p-4 tw-shadow-xs">
                            <div class="tw-flex tw-items-center tw-justify-between tw-mb-3 tw-border-b tw-border-outline-variant/40 tw-pb-2.5">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary">
                                    <x-ui.icon name="file-text" size="xs" />
                                    <h4 class="tw-m-0 tw-text-ui-xs tw-font-bold tw-text-on-surface tw-uppercase tw-tracking-wider">
                                        {{ __('local_procurement.registration.snapshot_documents') }}
                                    </h4>
                                </div>
                                <span class="tw-text-[11px] tw-font-semibold tw-text-on-surface-variant tw-tabular-nums">
                                    {{ count($docsSnap) }} {{ __('registration.documents') }}
                                </span>
                            </div>

                            @if (empty($docsSnap))
                                <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">
                                    {{ __('local_procurement.registration.snapshot_no_docs') }}
                                </p>
                            @else
                                <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-3">
                                    @foreach ($docsSnap as $docType => $docInfo)
                                        @php
                                            $docTypeLabel = match ($docType) {
                                                'SURAT_PERNYATAAN_REKENING' => __('local_procurement.registration.doc_bank_declaration'),
                                                'SKD' => __('local_procurement.registration.doc_domicile'),
                                                'COMPANY_PROFILE' => __('local_procurement.registration.doc_company_profile'),
                                                'OTHER' => __('local_procurement.registration.doc_other'),
                                                default => $docType,
                                            };
                                            $docHash = is_array($docInfo) ? ($docInfo['hash'] ?? null) : null;
                                            $docName = is_array($docInfo) ? ($docInfo['original_filename'] ?? '-') : '-';
                                            $docSize = is_array($docInfo) ? ($docInfo['file_size'] ?? 0) : 0;
                                            $docDate = is_array($docInfo) ? ($docInfo['uploaded_at'] ?? null) : null;
                                        @endphp
                                        <div class="tw-flex tw-flex-col tw-justify-between tw-p-3 tw-rounded-ui-xs tw-border tw-border-outline-variant/60 tw-bg-surface hover:tw-border-primary/40 tw-transition-colors">
                                            <div>
                                                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-1">
                                                    <span class="tw-font-bold tw-text-ui-xs tw-text-primary tw-truncate" title="{{ $docTypeLabel }}">
                                                        {{ $docTypeLabel }}
                                                    </span>
                                                    <span class="tw-text-[11px] tw-text-on-surface-variant tw-tabular-nums tw-shrink-0">
                                                        {{ number_format($docSize / 1024, 1) }} KB
                                                    </span>
                                                </div>
                                                <p class="tw-m-0 tw-text-[11px] tw-text-on-surface tw-truncate tw-font-medium" title="{{ $docName }}">
                                                    {{ $docName }}
                                                </p>
                                                @if ($docDate)
                                                    <span class="tw-text-[10px] tw-text-on-surface-variant tw-block tw-mt-0.5 tw-tabular-nums">
                                                        {{ \Illuminate\Support\Carbon::parse($docDate)->format('d M Y, H:i') }}
                                                    </span>
                                                @endif
                                            </div>

                                            @if ($docHash)
                                                <div class="tw-mt-2.5 tw-pt-2 tw-border-t tw-border-outline-variant/40 tw-flex tw-items-center tw-justify-between">
                                                    <span class="tw-font-mono tw-text-[10px] tw-text-on-surface-variant/80 tw-truncate" title="Doc Hash: {{ $docHash }}">
                                                        #{{ $docHash }}
                                                    </span>
                                                    <a
                                                        href="{{ route('supplier-registrations.document', ['attempt' => $attempt->hash, 'document' => $docHash]) }}"
                                                        class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-text-[11px] tw-font-semibold tw-text-primary hover:tw-underline tw-no-underline"
                                                    >
                                                        <x-ui.icon name="download" size="xs" />
                                                        <span>{{ __('local_procurement.registration.download') }}</span>
                                                    </a>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- 4. QUESTIONNAIRE SNAPSHOT (IF PRESENT) --}}
                        @if (!empty($questSnap))
                            @php
                                $flaggedInSnapshot = \App\Support\SupplierComplianceQuestionnaire::flagged($questSnap);
                            @endphp
                            <div class="tw-rounded-ui-xs tw-border tw-border-outline-variant/70 tw-bg-surface-container-lowest tw-p-4 tw-shadow-xs">
                                <div class="tw-flex tw-items-center tw-justify-between tw-mb-3 tw-border-b tw-border-outline-variant/40 tw-pb-2.5">
                                    <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary">
                                        <x-ui.icon name="clipboard-check" size="xs" />
                                        <h4 class="tw-m-0 tw-text-ui-xs tw-font-bold tw-text-on-surface tw-uppercase tw-tracking-wider">
                                            {{ __('local_procurement.registration.snapshot_questionnaire') }}
                                        </h4>
                                    </div>
                                    @if ($flaggedInSnapshot)
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-text-[11px] tw-font-semibold tw-text-warning">
                                            <x-ui.icon name="alert-triangle" size="xs" />
                                            {{ trans_choice('local_procurement.registration.questionnaire_flagged', count($flaggedInSnapshot), ['count' => count($flaggedInSnapshot)]) }}
                                        </span>
                                    @else
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-text-[11px] tw-font-semibold tw-text-success">
                                            <x-ui.icon name="check-circle" size="xs" />
                                            {{ trans_choice('local_procurement.registration.questionnaire_flagged', 0, ['count' => 0]) }}
                                        </span>
                                    @endif
                                </div>

                                <dl class="tw-m-0 tw-grid tw-gap-1.5 tw-text-ui-xs">
                                    @foreach (\App\Support\SupplierComplianceQuestionnaire::keys() as $index => $qKey)
                                        @php
                                            $qAnswer = $questSnap[$qKey] ?? null;
                                            $qFlagged = \App\Support\SupplierComplianceQuestionnaire::isFlagged($qKey, $qAnswer);
                                        @endphp
                                        <div class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-rounded-ui-xs tw-border tw-px-3 tw-py-2 {{ $qFlagged ? 'tw-border-warning/50 tw-bg-warning/5' : 'tw-border-outline-variant/50 tw-bg-surface' }}">
                                            <dt class="tw-text-on-surface tw-text-pretty">
                                                {{ $index + 1 }}. {{ __('registration.questionnaire.questions.'.$qKey) }}
                                            </dt>
                                            <dd class="tw-m-0 tw-shrink-0 tw-text-end">
                                                <span class="tw-font-semibold tw-text-on-surface">
                                                    {{ $qAnswer === null ? __('registration.questionnaire.not_answered') : __('registration.questionnaire.'.$qAnswer) }}
                                                </span>
                                                @if ($qFlagged)
                                                    <span class="tw-mt-0.5 tw-flex tw-items-center tw-justify-end tw-gap-1 tw-text-[10px] tw-font-semibold tw-text-warning">
                                                        <x-ui.icon name="alert-triangle" size="xs" />
                                                        {{ __('registration.questionnaire.needs_attention') }}
                                                    </span>
                                                @endif
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        @endif

                        {{-- 5. INTEGRITY VERIFICATION & SHA-256 CHECKSUM FOOTER --}}
                        <div class="tw-rounded-ui-xs tw-border tw-border-outline-variant/60 tw-bg-surface-container tw-p-3.5">
                            <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-2">
                                <div class="tw-flex tw-items-center tw-gap-2">
                                    <span class="tw-inline-flex tw-items-center tw-justify-center tw-w-6 tw-h-6 tw-rounded-full {{ $isChecksumValid ? 'tw-bg-success/15 tw-text-success' : 'tw-bg-warning/15 tw-text-warning' }} tw-shrink-0">
                                        <x-ui.icon :name="$isChecksumValid ? 'shield-check' : 'shield-alert'" size="xs" />
                                    </span>
                                    <div>
                                        <span class="tw-text-ui-xs tw-font-bold tw-text-on-surface tw-block">
                                            {{ __('local_procurement.registration.snapshot_checksum') }}
                                        </span>
                                        <span class="tw-text-[11px] tw-text-on-surface-variant">
                                            {{ $isChecksumValid ? __('local_procurement.registration.snapshot_integrity_verified') : 'Checksum Verification Mismatch' }}
                                        </span>
                                    </div>
                                </div>

                                @if ($checksum)
                                    <button
                                        type="button"
                                        @click.stop="copyText('{{ $checksum }}', 'copiedChecksum')"
                                        class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-1 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface tw-text-on-surface hover:tw-bg-surface-container-highest tw-text-[11px] tw-font-semibold tw-transition-all tw-duration-150 active:tw-scale-95 tw-cursor-pointer tw-shrink-0"
                                    >
                                        <template x-if="copiedChecksum">
                                            <span class="tw-inline-flex tw-items-center tw-gap-1 tw-text-success">
                                                <x-ui.icon name="check" size="xs" />
                                                <span>{{ __('local_procurement.registration.snapshot_checksum_copied') }}</span>
                                            </span>
                                        </template>
                                        <template x-if="!copiedChecksum">
                                            <span class="tw-inline-flex tw-items-center tw-gap-1">
                                                <x-ui.icon name="copy" size="xs" />
                                                <span>{{ __('local_procurement.registration.snapshot_copy_checksum') }}</span>
                                            </span>
                                        </template>
                                    </button>
                                @endif
                            </div>

                            @if ($checksum)
                                <div class="tw-mt-2.5">
                                    <code class="tw-block tw-font-mono tw-text-[11px] tw-text-on-surface-variant tw-bg-surface tw-p-2 tw-rounded-ui-xs tw-border tw-border-outline-variant/60 tw-break-all tw-tabular-nums">
                                        {{ $checksum }}
                                    </code>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- RAW JSON VIEW --}}
                    <div class="tw-space-y-2" x-show="showRawJson" style="display: none;">
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-ui-xs tw-text-on-surface-variant tw-px-1">
                            <span class="tw-font-mono tw-text-[11px]">JSON Payload (application/json) &bull; {{ strlen($jsonPretty) }} bytes</span>
                            <button
                                type="button"
                                @click.stop="copyText({{ Js::from($jsonPretty) }}, 'copiedJson')"
                                class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-font-semibold tw-text-primary hover:tw-underline tw-cursor-pointer tw-border-0 tw-bg-transparent tw-p-0"
                            >
                                <x-ui.icon name="copy" size="xs" />
                                <span x-text="copiedJson ? '{{ __('local_procurement.registration.snapshot_json_copied') }}' : '{{ __('local_procurement.registration.snapshot_copy_json') }}'"></span>
                            </button>
                        </div>
                        <div class="tw-relative">
                            <pre x-ref="rawJsonContent" class="tw-bg-slate-900 tw-text-slate-100 dark:tw-bg-surface-container-highest dark:tw-text-on-surface tw-p-4 tw-rounded-ui-xs tw-text-ui-xs tw-overflow-x-auto tw-m-0 tw-font-mono tw-leading-relaxed tw-border tw-border-slate-800 dark:tw-border-outline-variant/60 tw-max-h-[500px] tw-overflow-y-auto">{{ $jsonPretty }}</pre>
                        </div>
                    </div>
                </div>
            </div>

            {{-- AUDIT TRAIL LOG --}}
            <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-5 tw-shadow-xs">
                <div class="tw-flex tw-items-center tw-gap-2 tw-mb-4 tw-text-primary">
                    <x-ui.icon name="shield-check" size="sm" />
                    <h3 class="tw-m-0 tw-text-ui-base tw-font-bold tw-text-on-surface">{{ __('local_procurement.registration.audit_trail') }}</h3>
                </div>

                <div class="tw-space-y-3">
                    @forelse ($audits as $audit)
                        <div class="tw-flex tw-items-start tw-gap-3 tw-text-ui-xs tw-border-b tw-border-outline-variant tw-pb-2.5 last:tw-border-0">
                            <span class="tw-inline-flex tw-items-center tw-justify-center tw-w-6 tw-h-6 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-shrink-0 tw-mt-0.5">
                                <x-ui.icon name="activity" size="xs" />
                            </span>
                            <div class="tw-flex-1">
                                <div class="tw-flex tw-items-center tw-justify-between">
                                    <span class="tw-font-semibold tw-text-on-surface">{{ match($audit->event) {
                                        'registration_submitted' => __('local_procurement.registration.event_submitted'),
                                        'registration_resubmitted' => __('local_procurement.registration.event_resubmitted'),
                                        'revision_requested' => __('local_procurement.registration.event_revision'),
                                        'registration_rejected' => __('local_procurement.registration.event_rejected'),
                                        'registration_approved' => __('local_procurement.registration.event_approved'),
                                        'scope_assigned' => __('local_procurement.registration.event_scope'),
                                        'account_activated' => __('local_procurement.registration.event_activated'),
                                        'status_accessed_credentials' => __('local_procurement.registration.event_status_accessed'),
                                        default => str_replace('_', ' ', strtoupper($audit->event)),
                                    } }}</span>
                                    <span class="tw-text-on-surface-variant">{{ $regionalFormatter->timestamp($audit->created_at, 'datetime_comma') }}</span>
                                </div>
                                <div class="tw-text-on-surface-variant tw-mt-0.5">
                                    {{ __('local_procurement.registration.actor', ['name' => $audit->actor?->name ?? __('local_procurement.registration.system_applicant'), 'role' => $audit->actor_role]) }}
                                </div>
                                @if ($audit->notes)
                                    <div class="tw-mt-1 tw-text-on-surface tw-bg-surface-container tw-p-2 tw-rounded-ui-xs">
                                        {{ $audit->notes }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="tw-text-ui-xs tw-text-on-surface-variant tw-m-0">{{ __('local_procurement.registration.audit_empty') }}</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- RIGHT COLUMN: DOCUMENTS & ATTEMPT HISTORY (1 col) --}}
        <div class="tw-space-y-6">
            {{-- DOCUMENTS CARD --}}
            <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-5 tw-shadow-xs">
                <div class="tw-flex tw-items-center tw-gap-2 tw-mb-4 tw-text-primary">
                    <x-ui.icon name="file-text" size="sm" />
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-on-surface">{{ __('registration.js.step3') }}</h3>
                </div>

                <div class="tw-space-y-2.5">
                    @forelse ($documents as $doc)
                        <div class="tw-p-3 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface-container-lowest">
                            <div class="tw-flex tw-items-center tw-justify-between tw-mb-1">
                                <span class="tw-font-semibold tw-text-ui-xs tw-text-primary">{{ match($doc->document_type) {
                                    'SURAT_PERNYATAAN_REKENING' => __('local_procurement.registration.doc_bank_declaration'),
                                    'SKD' => __('local_procurement.registration.doc_domicile'),
                                    'COMPANY_PROFILE' => __('local_procurement.registration.doc_company_profile'),
                                    'OTHER' => __('local_procurement.registration.doc_other'),
                                    default => $doc->document_type,
                                } }}</span>
                                <span class="tw-text-[11px] tw-text-on-surface-variant">{{ number_format($doc->file_size / 1024, 1) }} KB</span>
                            </div>
                            <p class="tw-m-0 tw-text-[11px] tw-text-on-surface-variant tw-truncate" title="{{ $doc->original_filename }}">{{ $doc->original_filename }}</p>

                            <div class="tw-mt-2 tw-pt-2 tw-border-t tw-border-outline-variant tw-flex tw-justify-end">
                                <a
                                    href="{{ route('supplier-registrations.document', ['attempt' => $attempt->hash, 'document' => $doc->hash]) }}"
                                    class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-underline tw-no-underline"
                                >
                                    <x-ui.icon name="download" size="xs" />
                                    <span>{{ __('local_procurement.registration.download') }}</span>
                                </a>
                            </div>
                        </div>
                    @empty
                        <p class="tw-text-ui-xs tw-text-on-surface-variant tw-m-0">{{ __('local_procurement.registration.documents_empty') }}</p>
                    @endforelse
                </div>
            </div>

            {{-- ATTEMPT HISTORY CARD --}}
            <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-5 tw-shadow-xs">
                <div class="tw-flex tw-items-center tw-gap-2 tw-mb-3 tw-text-primary">
                    <x-ui.icon name="history" size="sm" />
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-on-surface">{{ __('local_procurement.registration.attempts') }}</h3>
                </div>

                <div class="tw-space-y-2">
                    @foreach ($history as $h)
                        <div class="tw-p-2.5 tw-rounded-ui-xs {{ $h->id === $attempt->id ? 'tw-bg-primary/10 tw-border tw-border-primary/30' : 'tw-bg-surface-container tw-border tw-border-outline-variant' }}">
                            <div class="tw-flex tw-items-center tw-justify-between">
                                <span class="tw-font-bold tw-text-ui-xs">{{ __('local_procurement.registration.attempt', ['attempt' => $h->attempt_number]) }}</span>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::registrationTone($h->status)">
                                    {{ \App\Support\StatusHelper::registrationLabel($h->status) }}
                                </x-ui.status-chip>
                            </div>
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1">
                                {{ $h->submitted_at ? $regionalFormatter->timestamp($h->submitted_at, 'datetime_comma') : '-' }}
                            </div>
                            @if ($h->id !== $attempt->id)
                                <div class="tw-mt-2">
                                    <a href="{{ route('supplier-registrations.show', $h->hash) }}" class="tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-underline tw-no-underline">
                                        {{ __('local_procurement.registration.view_attempt') }} &rarr;
                                    </a>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- MODAL: APPROVE & ACTIVATE (SINGLE REVIEWER) --}}
    <div
        x-show="approveModal"
        style="display: none;"
        class="tw-fixed tw-inset-0 tw-z-50 tw-flex tw-items-center tw-justify-center tw-bg-black/60 tw-p-4"
        x-transition:enter="tw-transition tw-ease-out tw-duration-200"
        x-transition:enter-start="tw-opacity-0"
        x-transition:enter-end="tw-opacity-100"
        x-transition:leave="tw-transition tw-ease-in tw-duration-150"
        x-transition:leave-start="tw-opacity-100"
        x-transition:leave-end="tw-opacity-0"
    >
        <div
            class="tw-w-full tw-max-w-lg tw-rounded-ui-sm tw-bg-surface tw-p-6 tw-shadow-xl tw-border tw-border-outline-variant"
            @click.away="approveModal = false"
            x-data="{ scopeImport: true, scopeLocal: false }"
        >
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-4">
                <div class="tw-flex tw-items-center tw-gap-2.5 tw-text-success">
                    <x-ui.icon name="check-circle" size="md" />
                    <h3 class="tw-m-0 tw-text-ui-lg tw-font-bold tw-text-on-surface">{{ __('local_procurement.registration.approve_supplier') }}</h3>
                </div>
                <button type="button" class="tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-text-on-surface" @click="approveModal = false">
                    <x-ui.icon name="x" size="sm" />
                </button>
            </div>

            <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant tw-mb-4">
                {{ __('local_procurement.registration.approve_help', ['attempt' => $attempt->attempt_number, 'email' => $user->email]) }}
            </p>

            <form method="POST" action="{{ route('supplier-registrations.approve', $attempt->hash) }}">
                @csrf

                {{-- SCOPE ASSIGNMENT --}}
                <div class="tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-4 tw-mb-4">
                    <label class="tw-block tw-text-ui-sm tw-font-bold tw-text-on-surface tw-mb-2">
                        {{ __('local_procurement.registration.assign_scopes') }} <span class="tw-text-error">*</span>
                    </label>

                    {{-- PRESET SHORTCUTS --}}
                    <div class="tw-flex tw-gap-2 tw-mb-3">
                        <button type="button" class="ui-focus-ring tw-px-2.5 tw-py-1 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface tw-text-ui-xs tw-font-medium hover:tw-bg-surface-container" @click="scopeImport = true; scopeLocal = false">
                            {{ __('local_procurement.registration.import_only') }}
                        </button>
                        <button type="button" class="ui-focus-ring tw-px-2.5 tw-py-1 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface tw-text-ui-xs tw-font-medium hover:tw-bg-surface-container" @click="scopeImport = false; scopeLocal = true">
                            {{ __('local_procurement.registration.local_only') }}
                        </button>
                        <button type="button" class="ui-focus-ring tw-px-2.5 tw-py-1 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface tw-text-ui-xs tw-font-medium hover:tw-bg-surface-container" @click="scopeImport = true; scopeLocal = true">
                            {{ __('local_procurement.registration.both') }}
                        </button>
                    </div>

                    <div class="tw-space-y-2">
                        <label class="tw-flex tw-items-center tw-gap-2.5 tw-cursor-pointer">
                            <input type="checkbox" name="scopes[]" value="import" x-model="scopeImport" class="form-check-input tw-mt-0">
                            <div>
                                <span class="tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ __('local_procurement.registration.import_scope') }}</span>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('local_procurement.registration.import_scope_help') }}</span>
                            </div>
                        </label>

                        <label class="tw-flex tw-items-center tw-gap-2.5 tw-cursor-pointer">
                            <input type="checkbox" name="scopes[]" value="local" x-model="scopeLocal" class="form-check-input tw-mt-0">
                            <div>
                                <span class="tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ __('local_procurement.registration.local_scope') }}</span>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('local_procurement.registration.local_scope_help') }}</span>
                            </div>
                        </label>
                    </div>
                </div>

                {{-- OPTIONAL APPROVAL NOTES --}}
                <div class="tw-mb-4">
                    <label for="approval_notes" class="tw-block tw-text-ui-sm tw-font-medium tw-text-on-surface tw-mb-1">
                        {{ __('local_procurement.registration.approval_notes') }}
                    </label>
                    <textarea
                        id="approval_notes"
                        name="notes"
                        rows="2"
                        class="ui-motion tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-2.5 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary"
                        placeholder="{{ __('local_procurement.registration.approval_example') }}"
                    ></textarea>
                </div>

                <div class="tw-flex tw-items-center tw-justify-end tw-gap-2.5">
                    <button type="button" class="ui-focus-ring tw-px-4 tw-py-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-text-ui-sm tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container" @click="approveModal = false">
                        {{ __('local_invoice.actions.cancel') }}
                    </button>
                    <button
                        type="submit"
                        :disabled="!scopeImport && !scopeLocal"
                        class="ui-focus-ring tw-px-5 tw-py-2 tw-rounded-ui-sm tw-bg-success tw-text-on-success tw-text-ui-sm tw-font-semibold tw-border-0 hover:tw-brightness-95 disabled:tw-opacity-50"
                    >
                        {{ __('local_procurement.registration.confirm_approval') }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- MODAL: REQUEST REVISION --}}
    <div
        x-show="revisionModal"
        style="display: none;"
        class="tw-fixed tw-inset-0 tw-z-50 tw-flex tw-items-center tw-justify-center tw-bg-black/60 tw-p-4"
        x-transition:enter="tw-transition tw-ease-out tw-duration-200"
        x-transition:enter-start="tw-opacity-0"
        x-transition:enter-end="tw-opacity-100"
        x-transition:leave="tw-transition tw-ease-in tw-duration-150"
        x-transition:leave-start="tw-opacity-100"
        x-transition:leave-end="tw-opacity-0"
    >
        <div class="tw-w-full tw-max-w-lg tw-rounded-ui-sm tw-bg-surface tw-p-6 tw-shadow-xl tw-border tw-border-outline-variant" @click.away="revisionModal = false">
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-4">
                <div class="tw-flex tw-items-center tw-gap-2.5 tw-text-info">
                    <x-ui.icon name="edit" size="md" />
                    <h3 class="tw-m-0 tw-text-ui-lg tw-font-bold tw-text-on-surface">{{ __('local_procurement.registration.revision_title') }}</h3>
                </div>
                <button type="button" class="tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-text-on-surface" @click="revisionModal = false">
                    <x-ui.icon name="x" size="sm" />
                </button>
            </div>

            <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant tw-mb-4">
                {{ __('local_procurement.registration.revision_help') }}
            </p>

            <form method="POST" action="{{ route('supplier-registrations.revision', $attempt->hash) }}">
                @csrf

                <div class="tw-mb-4">
                    <label for="revision_reason" class="tw-block tw-text-ui-sm tw-font-bold tw-text-on-surface tw-mb-1">
                        {{ __('local_procurement.registration.revision_reason') }} <span class="tw-text-error">*</span>
                    </label>
                    <textarea
                        id="revision_reason"
                        name="reason"
                        rows="4"
                        class="ui-motion tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary"
                        placeholder="{{ __('local_procurement.registration.revision_example') }}"
                        required
                    ></textarea>
                </div>

                <div class="tw-flex tw-items-center tw-justify-end tw-gap-2.5">
                    <button type="button" class="ui-focus-ring tw-px-4 tw-py-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-text-ui-sm tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container" @click="revisionModal = false">
                        {{ __('local_invoice.actions.cancel') }}
                    </button>
                    <button type="submit" class="ui-focus-ring tw-px-5 tw-py-2 tw-rounded-ui-sm tw-bg-info tw-text-on-info tw-text-ui-sm tw-font-semibold tw-border-0 hover:tw-brightness-95">
                        {{ __('local_procurement.registration.send_revision') }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- MODAL: REJECT REGISTRATION --}}
    <div
        x-show="rejectModal"
        style="display: none;"
        class="tw-fixed tw-inset-0 tw-z-50 tw-flex tw-items-center tw-justify-center tw-bg-black/60 tw-p-4"
        x-transition:enter="tw-transition tw-ease-out tw-duration-200"
        x-transition:enter-start="tw-opacity-0"
        x-transition:enter-end="tw-opacity-100"
        x-transition:leave="tw-transition tw-ease-in tw-duration-150"
        x-transition:leave-start="tw-opacity-100"
        x-transition:leave-end="tw-opacity-0"
    >
        <div class="tw-w-full tw-max-w-lg tw-rounded-ui-sm tw-bg-surface tw-p-6 tw-shadow-xl tw-border tw-border-outline-variant" @click.away="rejectModal = false">
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-4">
                <div class="tw-flex tw-items-center tw-gap-2.5 tw-text-error">
                    <x-ui.icon name="alert-triangle" size="md" />
                    <h3 class="tw-m-0 tw-text-ui-lg tw-font-bold tw-text-on-surface">{{ __('local_procurement.registration.reject_title') }}</h3>
                </div>
                <button type="button" class="tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-text-on-surface" @click="rejectModal = false">
                    <x-ui.icon name="x" size="sm" />
                </button>
            </div>

            <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant tw-mb-4">
                {{ __('local_procurement.registration.reject_help') }}
            </p>

            <form method="POST" action="{{ route('supplier-registrations.reject', $attempt->hash) }}">
                @csrf

                <div class="tw-mb-4">
                    <label for="reject_reason" class="tw-block tw-text-ui-sm tw-font-bold tw-text-on-surface tw-mb-1">
                        {{ __('local_procurement.registration.reject_reason') }} <span class="tw-text-error">*</span>
                    </label>
                    <textarea
                        id="reject_reason"
                        name="reason"
                        rows="3"
                        class="ui-motion tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary"
                        placeholder="{{ __('local_procurement.registration.reject_example') }}"
                        required
                    ></textarea>
                </div>

                <div class="tw-flex tw-items-center tw-justify-end tw-gap-2.5">
                    <button type="button" class="ui-focus-ring tw-px-4 tw-py-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-text-ui-sm tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container" @click="rejectModal = false">
                        {{ __('local_invoice.actions.cancel') }}
                    </button>
                    <button type="submit" class="ui-focus-ring tw-px-5 tw-py-2 tw-rounded-ui-sm tw-bg-error tw-text-on-error tw-text-ui-sm tw-font-semibold tw-border-0 hover:tw-brightness-95">
                        {{ __('local_procurement.registration.confirm_rejection') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
