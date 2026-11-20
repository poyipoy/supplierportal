{{-- Area yang diganti server tabs: pencarian + tabel percobaan registrasi untuk satu status. --}}
@php
    $regResetUrl = route('supplier-registrations.index', ['status' => $statusFilter]);
    $regSearch = (string) request('search', '');
    $regChips = [];
    if ($regSearch !== '') {
        $regChips[] = [
            'key' => 'search',
            'label' => __('local_invoice.actions.search'),
            'value' => $regSearch,
            'removeUrl' => $regResetUrl,
        ];
    }
@endphp

{{-- REGISTRATION ATTEMPTS TABLE --}}
<x-ui.data-table
    :title="__('local_procurement.registration.attempt_count', ['count' => $attempts->total()])"
    :description="__('local_procurement.registration.review_help')"
>
    <x-slot:filters>
        <x-ui.filter-bar
            id="registrationAttemptsFilterForm"
            :action="route('supplier-registrations.index')"
            variant="embedded"
            :server-tabs="true"
            :has-active-filters="$regSearch !== ''"
            :reset-url="$regResetUrl"
            :reset-label="__('common.actions.clear')"
            :submit-label="__('finance.drp_ui.filter')"
        >
            <x-slot:hidden>
                <input type="hidden" name="status" value="{{ $statusFilter }}">
            </x-slot:hidden>

            <x-slot:search>
                <label for="registrationAttemptsSearch" class="tw:sr-only">{{ __('local_procurement.registration.search') }}</label>
                <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
                <input
                    id="registrationAttemptsSearch"
                    type="search"
                    name="search"
                    value="{{ $regSearch }}"
                    placeholder="{{ __('local_procurement.registration.search') }}"
                    class="form-control form-control-sm ui-filter-bar__search-input"
                >
            </x-slot:search>

            <x-slot:chips>
                <x-ui.filter-chips :items="$regChips" :clear-url="$regResetUrl" :server-tabs="true" />
            </x-slot:chips>
        </x-ui.filter-bar>
    </x-slot:filters>
    <div class="ui-data-table__scroll tw:overflow-x-auto">
        <table class="table table-hover align-middle w-100 tw:m-0 tw:text-ui-sm">
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
                            <span class="ui-tabular-nums tw:font-semibold tw:text-ui-xs tw:text-primary">{{ $ref }}</span>
                            <div class="tw:text-[11px] tw:text-on-surface-variant">{{ __('common.final_copy.attempt_number', ['number' => $attempt->attempt_number]) }}</div>
                        </td>
                        <td>
                            <div class="tw:font-semibold tw:text-on-surface">
                                {{ $supplier?->company_title ? $supplier->company_title . ' ' : '' }}{{ $supplier?->company_name ?? $attempt->user->name }}
                            </div>
                            <div class="tw:text-ui-xs tw:text-on-surface-variant">{{ $attempt->user->email }}</div>
                        </td>
                        <td>
                            <div class="ui-tabular-nums tw:text-ui-xs">NIB: {{ $supplier?->nib ?? '-' }}</div>
                            <div class="ui-tabular-nums tw:text-[11px] tw:text-on-surface-variant">NPWP: {{ $supplier?->npwp ?? '-' }}</div>
                        </td>
                        <td>
                            <div class="tw:text-ui-xs tw:font-medium">{{ $supplier?->pic_name ?? '-' }}</div>
                            <div class="tw:text-[11px] tw:text-on-surface-variant">{{ $supplier?->pic_phone ?? '-' }}</div>
                        </td>
                        <td>
                            <span class="tw:text-ui-xs">{{ $attempt->submitted_at ? $regionalFormatter->timestamp($attempt->submitted_at, 'datetime_comma') : '-' }}</span>
                        </td>
                        <td>
                            <x-ui.status-chip :tone="\App\Support\StatusHelper::registrationTone($attempt->status)">
                                {{ \App\Support\StatusHelper::registrationLabel($attempt->status) }}
                            </x-ui.status-chip>
                        </td>
                        <td>
                            <span class="tw:text-ui-xs text-muted">{{ $attempt->reviewer?->name ?? '-' }}</span>
                        </td>
                        <td class="text-end">
                            <a
                                href="{{ route('supplier-registrations.show', $attempt->hash) }}"
                                class="ui-data-action ui-data-action--primary ui-focus-ring tw:no-underline"
                            >
                                {{ __('common.labels_review.review') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center tw:py-8 tw:text-on-surface-variant">
                            <x-ui.icon name="inbox" size="lg" class="tw:mb-2 tw:opacity-50" />
                            <p class="tw:m-0 tw:text-ui-sm">{{ __('finance.copy_review.registration_empty') }}</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($attempts->hasPages())
        <div class="tw:p-4 tw:border-t tw:border-outline-variant">
            {{ $attempts->links() }}
        </div>
    @endif
</x-ui.data-table>
