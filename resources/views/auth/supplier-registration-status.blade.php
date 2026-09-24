@extends('layouts.auth')

@section('title', 'Registration Status - ADASI Supplier Portal')

@section('content')
<style>
    .auth-form-surface { max-width: 44rem !important; }
</style>

<div class="tw-mb-4 tw-flex tw-items-center tw-justify-between">
    <div>
        <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Registration Status</p>
        <h2 class="tw-m-0 tw-mt-0.5 tw-text-ui-xl tw-font-bold tw-text-on-surface">{{ $supplier?->company_name ?? $user->name }}</h2>
        <p class="tw-m-0 tw-text-ui-xs tw-font-mono tw-text-on-surface-variant">Reference: {{ $access->registration_reference }}</p>
    </div>

    <form method="POST" action="{{ route('supplier.registration.logout') }}">
        @csrf
        <button type="submit" class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-px-3 tw-py-1.5 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface tw-text-ui-xs tw-font-medium tw-text-on-surface hover:tw-bg-surface-container">
            <x-ui.icon name="log-out" size="xs" />
            <span>Exit</span>
        </button>
    </form>
</div>

@if (session('success'))
    <div class="tw-rounded-ui-sm tw-bg-success/15 tw-p-3.5 tw-text-on-surface tw-mb-4 tw-flex tw-items-center tw-gap-2.5 tw-text-ui-sm tw-font-medium" role="alert">
        <x-ui.icon name="check-circle" size="sm" class="tw-text-success tw-shrink-0" />
        <span>{{ session('success') }}</span>
    </div>
@endif

{{-- LIFECYCLE STATUS BANNER --}}
@php
    $status = $latestAttempt?->status ?? $user->account_status;
@endphp

@if ($status === \App\Models\SupplierRegistrationAttempt::STATUS_PENDING)
    <div class="tw-rounded-ui-sm tw-border tw-border-warning/30 tw-bg-warning/10 tw-p-4 tw-mb-5">
        <div class="tw-flex tw-items-start tw-gap-3">
            <div class="tw-p-2 tw-rounded-full tw-bg-warning/20 tw-text-warning-dim tw-shrink-0">
                <x-ui.icon name="clock" size="md" />
            </div>
            <div>
                <div class="tw-flex tw-items-center tw-gap-2">
                    <h3 class="tw-m-0 tw-text-ui-base tw-font-bold tw-text-on-surface">Application Under Review</h3>
                    <span class="ui-status-chip ui-status-chip--warning">PENDING</span>
                </div>
                <p class="tw-m-0 tw-mt-1 tw-text-ui-sm tw-text-on-surface-variant">
                    Submitted on <strong>{{ $latestAttempt?->submitted_at?->format('d M Y, H:i') ?? '-' }}</strong>.
                    Our Procurement and Finance reviewers are currently verifying your company information and legal documents. You will be notified once a determination is made.
                </p>
            </div>
        </div>
    </div>
@elseif ($status === \App\Models\SupplierRegistrationAttempt::STATUS_REVISION)
    <div class="tw-rounded-ui-sm tw-border tw-border-info/30 tw-bg-info/10 tw-p-4 tw-mb-5">
        <div class="tw-flex tw-items-start tw-gap-3">
            <div class="tw-p-2 tw-rounded-full tw-bg-info/20 tw-text-info tw-shrink-0">
                <x-ui.icon name="alert-circle" size="md" />
            </div>
            <div class="tw-flex-1">
                <div class="tw-flex tw-items-center tw-gap-2">
                    <h3 class="tw-m-0 tw-text-ui-base tw-font-bold tw-text-on-surface">Revision Requested</h3>
                    <span class="ui-status-chip ui-status-chip--info">REVISION REQUIRED</span>
                </div>
                <div class="tw-mt-2 tw-rounded-ui-xs tw-bg-surface tw-border tw-border-outline-variant tw-p-3">
                    <span class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-uppercase tw-tracking-wider">Reviewer Notes:</span>
                    <p class="tw-m-0 tw-mt-1 tw-text-ui-sm tw-text-on-surface tw-whitespace-pre-line">{{ $latestAttempt?->reviewer_notes ?: 'Please review and update your submitted details.' }}</p>
                </div>
                <div class="tw-mt-3">
                    <a href="{{ route('supplier.registration.edit') }}" class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-px-4 tw-py-2 tw-rounded-ui-sm tw-bg-primary tw-text-primary-foreground tw-text-ui-sm tw-font-semibold tw-no-underline hover:tw-brightness-95">
                        <x-ui.icon name="edit" size="sm" />
                        <span>Update & Resubmit Registration</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
@elseif ($status === \App\Models\SupplierRegistrationAttempt::STATUS_APPROVED)
    <div class="tw-rounded-ui-sm tw-border tw-border-success/30 tw-bg-success/10 tw-p-4 tw-mb-5">
        <div class="tw-flex tw-items-start tw-gap-3">
            <div class="tw-p-2 tw-rounded-full tw-bg-success/20 tw-text-success tw-shrink-0">
                <x-ui.icon name="check-circle" size="md" />
            </div>
            <div class="tw-flex-1">
                <div class="tw-flex tw-items-center tw-gap-2">
                    <h3 class="tw-m-0 tw-text-ui-base tw-font-bold tw-text-on-surface">Registration Approved & Activated!</h3>
                    <span class="ui-status-chip ui-status-chip--success">ACTIVE</span>
                </div>
                <p class="tw-m-0 tw-mt-1 tw-text-ui-sm tw-text-on-surface-variant">
                    Congratulations! Your supplier account has been approved by ADASI. You can now sign in with your email address (<strong class="tw-text-on-surface">{{ $user->email }}</strong>) and the password created during registration.
                </p>
                <div class="tw-mt-3">
                    <a href="{{ route('login') }}" class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-px-4 tw-py-2 tw-rounded-ui-sm tw-bg-primary tw-text-primary-foreground tw-text-ui-sm tw-font-semibold tw-no-underline hover:tw-brightness-95">
                        <x-ui.icon name="log-in" size="sm" />
                        <span>Proceed to Portal Sign In</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
@elseif ($status === \App\Models\SupplierRegistrationAttempt::STATUS_REJECTED)
    <div class="tw-rounded-ui-sm tw-border tw-border-error/30 tw-bg-error/10 tw-p-4 tw-mb-5">
        <div class="tw-flex tw-items-start tw-gap-3">
            <div class="tw-p-2 tw-rounded-full tw-bg-error/20 tw-text-error tw-shrink-0">
                <x-ui.icon name="x-circle" size="md" />
            </div>
            <div class="tw-flex-1">
                <div class="tw-flex tw-items-center tw-gap-2">
                    <h3 class="tw-m-0 tw-text-ui-base tw-font-bold tw-text-on-surface">Registration Not Approved</h3>
                    <span class="ui-status-chip ui-status-chip--danger">REJECTED</span>
                </div>
                <div class="tw-mt-2 tw-rounded-ui-xs tw-bg-surface tw-border tw-border-outline-variant tw-p-3">
                    <span class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-uppercase tw-tracking-wider">Reason for Rejection:</span>
                    <p class="tw-m-0 tw-mt-1 tw-text-ui-sm tw-text-on-surface tw-whitespace-pre-line">{{ $latestAttempt?->reviewer_notes ?: 'Registration requirements not met.' }}</p>
                </div>
                <p class="tw-m-0 tw-mt-2 tw-text-ui-xs tw-text-on-surface-variant">
                    Your identification numbers (NIB, NPWP) have been released. You may start a fresh registration when eligible.
                </p>
                <div class="tw-mt-3">
                    <a href="{{ route('supplier.register') }}" class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-px-4 tw-py-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-text-on-surface tw-text-ui-sm tw-font-semibold tw-no-underline hover:tw-bg-surface-container">
                        <x-ui.icon name="refresh-cw" size="sm" />
                        <span>Register Again</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- SUBMITTED INFORMATION SUMMARY --}}
<div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-4 tw-mb-4">
    <h4 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-primary tw-uppercase tw-tracking-wider tw-mb-3">Submitted Company Details</h4>

    <dl class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-x-4 tw-gap-y-2.5 tw-text-ui-sm tw-m-0">
        <div>
            <dt class="tw-text-ui-xs tw-text-on-surface-variant">Legal Entity & Name</dt>
            <dd class="tw-font-medium tw-text-on-surface tw-m-0">{{ $supplier?->company_title ? $supplier->company_title . ' ' : '' }}{{ $supplier?->company_name ?? '-' }}</dd>
        </div>
        <div>
            <dt class="tw-text-ui-xs tw-text-on-surface-variant">Registered Email</dt>
            <dd class="tw-font-medium tw-text-on-surface tw-m-0">{{ $user->email }}</dd>
        </div>
        <div>
            <dt class="tw-text-ui-xs tw-text-on-surface-variant">Nomor Induk Berusaha (NIB)</dt>
            <dd class="tw-font-medium tw-font-mono tw-text-on-surface tw-m-0">{{ $supplier?->nib ?? '-' }}</dd>
        </div>
        <div>
            <dt class="tw-text-ui-xs tw-text-on-surface-variant">NPWP / NIK</dt>
            <dd class="tw-font-medium tw-font-mono tw-text-on-surface tw-m-0">{{ $supplier?->npwp ?? '-' }}</dd>
        </div>
        <div>
            <dt class="tw-text-ui-xs tw-text-on-surface-variant">PIC Contact</dt>
            <dd class="tw-font-medium tw-text-on-surface tw-m-0">{{ $supplier?->pic_name }} ({{ $supplier?->pic_phone }})</dd>
        </div>
        <div>
            <dt class="tw-text-ui-xs tw-text-on-surface-variant">Bank Account</dt>
            <dd class="tw-font-medium tw-text-on-surface tw-m-0">
                @php $bank = $user->supplierBankAccounts->last(); @endphp
                {{ $bank?->bank_name }} - {{ $bank?->account_number }} (a.n. {{ $bank?->account_holder_name }})
            </dd>
        </div>
        <div class="md:tw-col-span-2">
            <dt class="tw-text-ui-xs tw-text-on-surface-variant">Official Address</dt>
            <dd class="tw-font-medium tw-text-on-surface tw-m-0">{{ $supplier?->address ?? '-' }}</dd>
        </div>
    </dl>
</div>

{{-- VERIFICATION DOCUMENTS --}}
<div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-4 tw-mb-4">
    <h4 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-primary tw-uppercase tw-tracking-wider tw-mb-3">Verification Documents</h4>

    <div class="tw-space-y-2">
        @forelse ($user->supplierMasterDocuments as $doc)
            <div class="tw-flex tw-items-center tw-justify-between tw-bg-surface tw-border tw-border-outline-variant tw-rounded-ui-xs tw-p-2.5">
                <div class="tw-flex tw-items-center tw-gap-2.5">
                    <x-ui.icon name="file-text" size="sm" class="tw-text-primary" />
                    <div>
                        <span class="tw-text-ui-xs tw-font-semibold tw-text-on-surface">{{ $doc->document_type }}</span>
                        <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">{{ $doc->original_filename }} ({{ number_format($doc->file_size / 1024, 1) }} KB)</span>
                    </div>
                </div>

                <a
                    href="{{ route('supplier.registration.document.download', $doc->hash) }}"
                    class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-underline tw-no-underline"
                >
                    <x-ui.icon name="download" size="xs" />
                    <span>Download</span>
                </a>
            </div>
        @empty
            <p class="tw-text-ui-xs tw-text-on-surface-variant tw-m-0">No documents uploaded.</p>
        @endforelse
    </div>
</div>

{{-- ATTEMPT HISTORY --}}
@if ($attempts->count() > 1)
    <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-4">
        <h4 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-on-surface-variant tw-uppercase tw-tracking-wider tw-mb-3">Attempt History</h4>
        <div class="tw-space-y-2">
            @foreach ($attempts as $item)
                <div class="tw-flex tw-items-center tw-justify-between tw-text-ui-xs tw-p-2 tw-rounded-ui-xs {{ $item->id === $latestAttempt?->id ? 'tw-bg-primary/5 tw-border tw-border-primary/20' : 'tw-bg-surface' }}">
                    <div class="tw-flex tw-items-center tw-gap-2">
                        <span class="tw-font-semibold">Attempt #{{ $item->attempt_number }}</span>
                        <span class="tw-text-on-surface-variant">{{ $item->submitted_at?->format('d M Y, H:i') }}</span>
                    </div>
                    <div>
                        <span class="ui-status-chip {{ match($item->status) {
                            'PENDING' => 'ui-status-chip--warning',
                            'REVISION' => 'ui-status-chip--info',
                            'APPROVED' => 'ui-status-chip--success',
                            'REJECTED' => 'ui-status-chip--danger',
                            default => 'ui-status-chip--neutral',
                        } }}">{{ $item->status }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
@endsection
