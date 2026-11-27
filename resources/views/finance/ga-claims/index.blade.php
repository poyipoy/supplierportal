@extends('layouts.app')
@section('title', __('finance.ga.register_title').' - '.__('finance.labels.finance_ap'))
@section('page-title', __('finance.ga.register_title'))

@section('content')
<div class="tw:grid tw:gap-6">
    <x-ui.page-header
        :title="__('finance.ga.register_title')"
        :description="__('finance.ga.register_help')"
        :eyebrow="__('finance.labels.finance_ap')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="outline" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.ga')" variant="outline" size="sm">
                <x-ui.icon name="credit-card" size="sm" />
                <span>{{ __('finance.ga.manage_drp') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('success'))
        <div class="tw:p-4 tw:rounded-lg tw:bg-success/10 tw:border tw:border-success/20 tw:text-success tw:text-ui-sm tw:flex tw:items-center tw:gap-3">
            <x-ui.icon name="check-circle" size="md" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    {{-- Filter Bar --}}
    @php
        $gaClaimsResetUrl = route('finance.ga-claims.index');
        $gaClaimsRemoveUrl = static function (array $dropKeys) use ($gaClaimsResetUrl): string {
            $query = request()->query();
            foreach ($dropKeys as $key) {
                unset($query[$key]);
            }

            return $query === [] ? $gaClaimsResetUrl : $gaClaimsResetUrl.'?'.http_build_query($query);
        };

        $gaClaimsChips = [];
        if (request()->filled('search')) {
            $gaClaimsChips[] = [
                'key' => 'search',
                'label' => __('local_invoice.actions.search'),
                'value' => (string) request('search'),
                'removeUrl' => $gaClaimsRemoveUrl(['search']),
            ];
        }
        if (request()->filled('status')) {
            $gaClaimStatusVal = match (request('status')) {
                'SUBMITTED' => __('local_invoice.labels.submitted'),
                'PAID' => __('local_invoice.labels.paid'),
                default => \App\Support\StatusHelper::gaClaimLabel((string) request('status')),
            };
            $gaClaimsChips[] = [
                'key' => 'status',
                'label' => __('local_invoice.labels.status'),
                'value' => $gaClaimStatusVal,
                'removeUrl' => $gaClaimsRemoveUrl(['status']),
            ];
        }
        if (request()->filled('employee_id')) {
            $selectedGaEmployee = collect($employees)->firstWhere('id', (int) request('employee_id'));
            $gaClaimsChips[] = [
                'key' => 'employee_id',
                'label' => __('ga.labels.employee_name'),
                'value' => $selectedGaEmployee ? ($selectedGaEmployee->name.' ('.$selectedGaEmployee->department.')') : (string) request('employee_id'),
                'removeUrl' => $gaClaimsRemoveUrl(['employee_id']),
            ];
        }
    @endphp

    <x-ui.filter-bar
        id="gaClaimsFilterForm"
        :action="$gaClaimsResetUrl"
        :has-active-filters="count($gaClaimsChips) > 0"
        :reset-url="$gaClaimsResetUrl"
        :reset-label="__('common.actions.reset')"
        :submit-label="__('common.labels_review.filter')"
    >
        <x-slot:search>
            <label for="ga-claim-search" class="tw:sr-only">{{ __('local_invoice.actions.search') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                type="search"
                id="ga-claim-search"
                name="search"
                class="ui-input ui-input--sm ui-filter-bar__search-input"
                placeholder="{{ __('ga.filters.claim_search') }}"
                value="{{ request('search') }}"
                autocomplete="off"
            >
        </x-slot:search>

        <x-slot:filters>
            <label for="ga-claim-status" class="tw:sr-only">{{ __('local_invoice.labels.status') }}</label>
            <select id="ga-claim-status" name="status" class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-44">
                <option value="">{{ __('local_invoice.labels.all_statuses') }}</option>
                <option value="SUBMITTED" @selected(request('status') === 'SUBMITTED')>{{ __('local_invoice.labels.submitted') }}</option>
                <option value="BASIC_VERIFIED" @selected(request('status') === 'BASIC_VERIFIED')>{{ \App\Support\StatusHelper::gaClaimLabel('BASIC_VERIFIED') }}</option>
                <option value="NEED_REVISION" @selected(request('status') === 'NEED_REVISION')>{{ \App\Support\StatusHelper::gaClaimLabel('NEED_REVISION') }}</option>
                <option value="READY_TO_PAY" @selected(request('status') === 'READY_TO_PAY')>{{ \App\Support\StatusHelper::gaClaimLabel('READY_TO_PAY') }}</option>
                <option value="PAID" @selected(request('status') === 'PAID')>{{ __('local_invoice.labels.paid') }}</option>
            </select>

            <label for="ga-claim-employee" class="tw:sr-only">{{ __('ga.labels.employee_name') }}</label>
            <select id="ga-claim-employee" name="employee_id" class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-52">
                <option value="">{{ __('ga.filters.all_employees') }}</option>
                @foreach($employees as $emp)
                    <option value="{{ $emp->id }}" @selected(request('employee_id') == $emp->id)>
                        {{ $emp->name }} ({{ $emp->department }})
                    </option>
                @endforeach
            </select>
        </x-slot:filters>

        <x-slot:chips>
            <x-ui.filter-chips :items="$gaClaimsChips" :clear-url="$gaClaimsResetUrl" />
        </x-slot:chips>
    </x-ui.filter-bar>

    {{-- Data Table --}}
    <x-ui.data-table
        :title="__('ga.list.heading')"
        :description="__('finance.ga.verification_help')"
    >
        <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-xs tw:w-full">
                <thead>
                    <tr>
                        <th scope="col">{{ __('finance.ga.claim_number') }}</th>
                        <th scope="col">{{ __('local_invoice.labels.date') }}</th>
                        <th scope="col">{{ __('ga.labels.employee_department') }}</th>
                        <th scope="col">{{ __('ga.labels.claim_type') }}</th>
                        <th scope="col">{{ __('finance.ga.amount_rupiah') }}</th>
                        <th scope="col">{{ __('finance.drp.transfer_destination') }}</th>
                        <th scope="col">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('common.table.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($claims as $claim)
                        <tr>
                            <td><strong class="ui-tabular-nums tw:text-primary">{{ $claim->claim_number }}</strong></td>
                            <td>{{ $regionalFormatter->date($claim->claim_date, 'dmy') }}</td>
                            <td>
                                <strong class="tw:text-on-surface tw:block">{{ $claim->employee?->name }}</strong>
                                <span class="tw:text-on-surface-variant tw:text-ui-xs">{{ $claim->employee?->department }}</span>
                            </td>
                            <td>
                                <span class="tw:inline-flex tw:px-2 tw:py-0.5 tw:rounded-sm tw:text-ui-xs tw:font-semibold tw:bg-primary/10 tw:text-primary">
                                    {{ \App\Models\GaClaim::claimTypeLabel($claim->claim_type) }}
                                </span>
                            </td>
                            <td><strong class="ui-tabular-nums tw:text-ui-sm">Rp {{ $regionalFormatter->number(number_format($claim->amount, 0, ',', '.'), 'indonesian') }}</strong></td>
                            <td>
                                <span class="tw:font-semibold">{{ $claim->employee?->bank_name }}</span> ·
                                <span class="ui-tabular-nums">{{ $claim->employee?->account_number }}</span>
                                <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant">{{ __('finance.drp_surface.account_holder') }} {{ $claim->employee?->account_holder_name }}</span>
                            </td>
                            <td>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::gaClaimTone($claim->status)">
                                    {{ \App\Support\StatusHelper::gaClaimLabel($claim->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="tw:text-end">
                                <x-ui.row-action
                                    :href="route('finance.ga-claims.show', $claim)"
                                    :context="$claim->claim_number"
                                />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="tw:text-center tw:py-8 tw:text-on-surface-variant">{{ __('finance.copy_review.ga_empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

        @if($claims->hasPages())
            <x-slot:pagination>
                {{ $claims->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
