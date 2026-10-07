@extends('layouts.app')
@section('title', __('local_procurement.registration.list_title'))
@section('page-title', __('local_procurement.registration.list_heading'))

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        :title="__('navigation.quick.registrations')"
        :description="__('local_procurement.registration.list_description')"
        :eyebrow="__('local_procurement.registration.management')"
    />

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

    {{-- STATUS FILTER TABS --}}
    <div class="tw-flex tw-items-center tw-gap-2 tw-overflow-x-auto tw-pb-1">
        @php
            $tabs = [
                'ALL' => ['label' => __('local_procurement.registration.all'), 'icon' => 'layers'],
                'PENDING' => ['label' => __('local_procurement.registration.pending_review'), 'icon' => 'clock'],
                'REVISION' => ['label' => __('local_procurement.registration.revision_required'), 'icon' => 'alert-circle'],
                'APPROVED' => ['label' => __('local_procurement.registration.approved'), 'icon' => 'check-circle'],
                'REJECTED' => ['label' => __('local_procurement.registration.rejected'), 'icon' => 'x-circle'],
            ];
        @endphp

        @foreach ($tabs as $key => $tab)
            @php $isActive = ($statusFilter === $key); @endphp
            <a
                href="{{ route('supplier-registrations.index', ['status' => $key, 'search' => request('search')]) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-px-3.5 tw-py-2 tw-rounded-ui-sm tw-text-ui-sm tw-font-medium tw-no-underline {{ $isActive ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-bg-surface tw-border tw-border-outline-variant tw-text-on-surface-variant hover:tw-bg-surface-container' }}"
            >
                <x-ui.icon :name="$tab['icon']" size="xs" />
                <span>{{ $tab['label'] }}</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-px-2 tw-py-0.5 tw-rounded-full tw-text-[11px] tw-font-bold {{ $isActive ? 'tw-bg-primary-foreground/20 tw-text-primary-foreground' : 'tw-bg-surface-container-high tw-text-on-surface' }}">
                    {{ $counts[$key] ?? 0 }}
                </span>
            </a>
        @endforeach
    </div>

    {{-- SEARCH & CONTROLS --}}
    <form method="GET" action="{{ route('supplier-registrations.index') }}" class="tw-flex tw-items-center tw-gap-3">
        <input type="hidden" name="status" value="{{ $statusFilter }}">
        <div class="tw-relative tw-flex-1 tw-max-w-md">
            <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                <x-ui.icon name="search" size="sm" />
            </div>
            <input
                type="search"
                name="search"
                value="{{ request('search') }}"
                placeholder="{{ __('local_procurement.registration.search') }}"
                class="ui-motion tw-h-10 tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary"
            >
        </div>
        <button type="submit" class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-h-10 tw-px-4 tw-rounded-ui-sm tw-bg-primary tw-text-primary-foreground tw-text-ui-sm tw-font-semibold tw-border-0 hover:tw-brightness-95">
            {{ __('finance.drp_ui.filter') }}
        </button>
        @if (request('search'))
            <a href="{{ route('supplier-registrations.index', ['status' => $statusFilter]) }}" class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-h-10 tw-px-3 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-text-ui-sm tw-text-on-surface hover:tw-bg-surface-container tw-no-underline">
                <x-ui.icon name="x" size="xs" />
                <span>{{ __('common.actions.clear') }}</span>
            </a>
        @endif
    </form>

    {{-- REGISTRATION ATTEMPTS TABLE --}}
    <x-ui.data-table
        :title="__('local_procurement.registration.attempt_count', ['count' => $attempts->total()])"
        :description="__('local_procurement.registration.review_help')"
    >
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table class="table table-hover align-middle w-100 tw-m-0 tw-text-ui-sm">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 140px;">{{ __('local_procurement.registration.reference_label') }}</th>
                        <th scope="col">{{ __('local_procurement.registration.company_label') }}</th>
                        <th scope="col">{{ __('local_procurement.registration.legal_tax') }}</th>
                        <th scope="col">{{ __('registration.pic_contact') }}</th>
                        <th scope="col">{{ __('local_procurement.registration.submitted_date', ['timezone' => \App\Support\BusinessTime::label()]) }}</th>
                        <th scope="col">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col">{{ __('local_procurement.registration.last_reviewer') }}</th>
                        <th scope="col" class="text-end" style="width: 100px;">{{ __('local_invoice.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($attempts as $attempt)
                        @php
                            $supplier = $attempt->user->supplier;
                            $ref = $attempt->user->registrationAccesses->first()?->registration_reference ?? '-';
                        @endphp
                        <tr>
                            <td>
                                <span class="tw-font-mono tw-font-semibold tw-text-ui-xs tw-text-primary">{{ $ref }}</span>
                                <div class="tw-text-[11px] tw-text-on-surface-variant">{{ __('common.final_copy.attempt_number', ['number' => $attempt->attempt_number]) }}</div>
                            </td>
                            <td>
                                <div class="tw-font-semibold tw-text-on-surface">
                                    {{ $supplier?->company_title ? $supplier->company_title . ' ' : '' }}{{ $supplier?->company_name ?? $attempt->user->name }}
                                </div>
                                <div class="tw-text-ui-xs tw-text-on-surface-variant">{{ $attempt->user->email }}</div>
                            </td>
                            <td>
                                <div class="tw-font-mono tw-text-ui-xs">NIB: {{ $supplier?->nib ?? '-' }}</div>
                                <div class="tw-font-mono tw-text-[11px] tw-text-on-surface-variant">NPWP: {{ $supplier?->npwp ?? '-' }}</div>
                            </td>
                            <td>
                                <div class="tw-text-ui-xs tw-font-medium">{{ $supplier?->pic_name ?? '-' }}</div>
                                <div class="tw-text-[11px] tw-text-on-surface-variant">{{ $supplier?->pic_phone ?? '-' }}</div>
                            </td>
                            <td>
                                <span class="tw-text-ui-xs">{{ $attempt->submitted_at ? $regionalFormatter->timestamp($attempt->submitted_at, 'datetime_comma') : '-' }}</span>
                            </td>
                            <td>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::registrationTone($attempt->status)">
                                    {{ \App\Support\StatusHelper::registrationLabel($attempt->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td>
                                <span class="tw-text-ui-xs text-muted">{{ $attempt->reviewer?->name ?? '-' }}</span>
                            </td>
                            <td class="text-end">
                                <a
                                    href="{{ route('supplier-registrations.show', $attempt->hash) }}"
                                    class="ui-data-action ui-data-action--primary ui-focus-ring tw-no-underline"
                                >
                                    {{ __('common.labels_review.review') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center tw-py-8 tw-text-on-surface-variant">
                                <x-ui.icon name="inbox" size="lg" class="tw-mb-2 tw-opacity-50" />
                                <p class="tw-m-0 tw-text-ui-sm">{{ __('finance.copy_review.registration_empty') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($attempts->hasPages())
            <div class="tw-p-4 tw-border-t tw-border-outline-variant">
                {{ $attempts->links() }}
            </div>
        @endif
    </x-ui.data-table>
</div>
@endsection
