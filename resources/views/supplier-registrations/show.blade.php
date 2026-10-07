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

            {{-- SUBMISSION SNAPSHOT CARD --}}
            <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-p-5 tw-shadow-xs" x-data="{ expanded: false }">
                <div class="tw-flex tw-items-center tw-justify-between tw-cursor-pointer" @click="expanded = !expanded">
                    <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary">
                        <x-ui.icon name="archive" size="sm" />
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-on-surface">{{ __('local_procurement.registration.snapshot', ['attempt' => $attempt->attempt_number]) }}</h3>
                    </div>
                    <x-ui.icon name="chevron-down" size="sm" x-show="!expanded" />
                    <x-ui.icon name="chevron-up" size="sm" x-show="expanded" />
                </div>

                <div class="tw-mt-3" x-show="expanded" style="display: none;">
                    <pre class="tw-bg-surface-container tw-p-3 tw-rounded-ui-xs tw-text-ui-xs tw-overflow-x-auto tw-m-0 tw-font-mono">{{ json_encode($attempt->snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
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
