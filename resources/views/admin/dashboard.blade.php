@extends('layouts.app')
@section('title', __('admin.copy.admin_dashboard_adasi_portal'))
@section('page-title', __('admin.copy.admin_dashboard'))

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header :title="__('admin.copy.administration_overview')" :description="__('admin.copy.review_current_administrative_workload_and_open_the_records_that_require_maintenance')" :eyebrow="__('admin.copy.admin')">
        <x-slot:actions>
            <x-ui.button :href="route('admin.users.index')" variant="outline" size="sm"><x-ui.icon name="users" /> {{ __('admin.copy.manage_users') }}</x-ui.button>
            <x-ui.button :href="route('admin.material-hs-code.index')" size="sm"><x-ui.icon name="boxes" /> {{ __('admin.copy.open_master_data') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.dashboard-layout audience="admin">
        <x-slot:summary class="lg:tw-col-span-12">
            <section class="tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface-container tw-overflow-hidden" aria-labelledby="admin-summary-title">
                <h2 id="admin-summary-title" class="tw-sr-only">{{ __('admin.copy.operational_summary') }}</h2>
                <dl class="tw-m-0 tw-grid tw-grid-cols-2 lg:tw-grid-cols-4">
                    <div class="tw-border-b tw-border-r tw-border-outline-variant tw-p-4 lg:tw-border-b-0">
                        <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ __('admin.copy.active_accounts') }}</dt>
                        <dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-xl tw-font-semibold">{{ $regionalFormatter->number(number_format($totalUsersActive), 'international') }}</dd>
                        <div class="tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ collect($usersByRole)->map(fn ($count, $role) => (in_array($role, ['admin', 'purchasing', 'supplier', 'qc', 'finance', 'accounting', 'ga'], true) ? __('navigation.roles.'.$role) : $role) . ' ' . $regionalFormatter->number((string) $count, 'plain'))->implode(' / ') }}</div>
                    </div>
                    <div class="tw-border-b tw-border-outline-variant tw-p-4 lg:tw-border-b-0 lg:tw-border-r">
                        <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ __('admin.copy.registered_suppliers') }}</dt>
                        <dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-xl tw-font-semibold">{{ $regionalFormatter->number(number_format($supplierCount), 'international') }}</dd>
                        <a href="{{ route('admin.users.index') }}" class="ui-focus-ring tw-mt-1 tw-inline-block tw-rounded-ui-xs tw-text-ui-xs tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">{{ __('admin.copy.review_supplier_accounts') }}</a>
                    </div>
                    <div class="tw-border-r tw-border-outline-variant tw-p-4">
                        <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ __('admin.copy.pos_created_this_month') }}</dt>
                        <dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-xl tw-font-semibold">{{ $regionalFormatter->number(number_format($transaksiBulanIni), 'international') }}</dd>
                        <div class="tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('admin.copy.current_calendar_month') }}</div>
                    </div>
                    <div class="tw-p-4">
                        <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ __('admin.copy.active_claims') }}</dt>
                        <dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-xl tw-font-semibold">{{ $regionalFormatter->number(number_format($klaimAktif), 'international') }}</dd>
                        <div class="tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('admin.copy.pending_or_supplier_response_recorded') }}</div>
                    </div>
                </dl>
            </section>
        </x-slot:summary>

        <x-slot:rates class="lg:tw-col-span-8">
            <x-ui.data-table :title="__('admin.copy.administrative_attention')" :description="__('admin.copy.current_exchange_rate_readiness_from_the_configured_currency_set')">
                <x-slot:toolbar>
                    <x-ui.button type="button" size="sm" data-bs-toggle="modal" data-bs-target="#kursModal"><x-ui.icon name="plus" /> {{ __('admin.copy.add_effective_rate') }}</x-ui.button>
                </x-slot:toolbar>
                <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-sm">
                    <thead class="table-light"><tr><th scope="col">{{ __('admin.copy.currency') }}</th><th scope="col" class="text-end">{{ __('admin.copy.current_rate_to_idr') }}</th><th scope="col">{{ __('admin.copy.effective_date') }}</th><th scope="col">{{ __('admin.copy.readiness') }}</th></tr></thead>
                    <tbody>
                        @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                            @php($rate = $latestRates[$currency] ?? null)
                            <tr>
                                <td class="tw-font-mono tw-font-semibold tw-text-primary">{{ $currency }}</td>
                                <td class="ui-tabular-nums text-end tw-font-medium">{{ $rate ? 'Rp ' . $regionalFormatter->number(number_format($rate->rate_to_idr, 2, ',', '.'), 'indonesian') : '-' }}</td>
                                <td>{{ $rate?->valid_from ? $regionalFormatter->date($rate->valid_from, 'human') : '-' }}</td>
                                <td><x-ui.status-chip :tone="$rate ? 'success' : 'warning'">{{ $rate ? __('admin.dashboard.available') : __('admin.copy.rate_required') }}</x-ui.status-chip></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <x-slot:pagination>
                    <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
                        <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('admin.dashboard.rate_count', ['count' => $regionalFormatter->number(number_format($riwayatKursTotal), 'international')]) }}</span>
                        <x-ui.button :href="route('admin.exchange-rates.index')" variant="ghost" size="sm">{{ __('admin.copy.view_rate_history') }} <x-ui.icon name="arrow-right" /></x-ui.button>
                    </div>
                </x-slot:pagination>
            </x-ui.data-table>
        </x-slot:rates>

        <x-slot:shortcuts class="lg:tw-col-span-4">
            <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="admin-shortcuts-title">
                <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-4 tw-py-3">
                    <h2 id="admin-shortcuts-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('admin.copy.administration_shortcuts_220ef8') }}</h2>
                    <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('admin.copy.open_a_maintenance_workspace_directly') }}</p>
                </header>
                <nav class="tw-divide-y tw-divide-outline-variant" aria-label="{{ __('admin.copy.administration_shortcuts') }}">
                    @foreach([
                        ['route' => route('admin.users.index'), 'icon' => 'users', 'label' => __('admin.copy.users'), 'description' => __('admin.dashboard.account_shortcut')],
                        ['route' => route('admin.material-hs-code.index'), 'icon' => 'boxes', 'label' => __('admin.copy.materials_hs_code'), 'description' => __('admin.copy.material_mappings_rules_and_data_quality')],
                        ['route' => route('admin.exchange-rates.index'), 'icon' => 'badge-dollar-sign', 'label' => __('admin.copy.exchange_rates'), 'description' => __('admin.copy.effective_rate_history_by_currency')],
                        ['route' => route('admin.announcements.index'), 'icon' => 'megaphone', 'label' => __('admin.copy.announcements'), 'description' => __('admin.copy.portal_wide_notices_and_publication_state')],
                        ['route' => route('admin.auth-audit-logs.index'), 'icon' => 'shield-check', 'label' => __('admin.copy.authentication_audit'), 'description' => __('admin.copy.account_and_authentication_security_events')],
                    ] as $shortcut)
                        <a href="{{ $shortcut['route'] }}" class="ui-focus-ring tw-flex tw-items-start tw-gap-3 tw-p-4 tw-text-on-surface tw-no-underline hover:tw-bg-surface-low">
                            <x-ui.icon :name="$shortcut['icon']" class="tw-mt-0.5 tw-shrink-0 tw-text-primary" />
                            <span class="tw-min-w-0 tw-flex-1"><span class="tw-block tw-text-ui-sm tw-font-semibold">{{ $shortcut['label'] }}</span><span class="tw-mt-0.5 tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $shortcut['description'] }}</span></span>
                            <x-ui.icon name="chevron-right" class="tw-mt-0.5 tw-shrink-0 tw-text-on-surface-variant" />
                        </a>
                    @endforeach
                </nav>
            </section>
        </x-slot:shortcuts>

        <x-slot:notifications class="lg:tw-col-span-12">
            <x-ui.data-table :title="__('admin.copy.recent_administrative_activity')" :description="__('admin.copy.latest_notifications_available_to_the_signed_in_administrator')">
                <div class="tw-divide-y tw-divide-outline-variant">
                    @forelse($recentActivities as $act)
                        @php($activityUrl = $act->data['url'] ?? null)
                        <div class="tw-grid tw-gap-2 tw-p-4 md:tw-grid-cols-[minmax(0,1fr)_auto] md:tw-items-start shell:tw-px-5">
                            <div class="tw-min-w-0">
                                <div class="tw-text-ui-sm tw-font-semibold">{{ $act->data['title'] ?? __('admin.copy.administrative_notification') }}</div>
                                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ $act->data['message'] ?? __('admin.copy.no_additional_details_were_provided') }}</p>
                            </div>
                            <div class="tw-flex tw-items-center tw-gap-3">
                                <time class="tw-whitespace-nowrap tw-text-ui-xs tw-text-on-surface-variant" datetime="{{ $act->created_at->toIso8601String() }}">{{ $act->created_at->diffForHumans() }}</time>
                                @if($activityUrl)<x-ui.icon-button icon="arrow-right" :label="__('admin.copy.open_activity')" :href="$activityUrl" variant="ghost" size="sm" />@endif
                            </div>
                        </div>
                    @empty
                        <x-ui.empty-state icon="activity" :title="__('admin.copy.no_recent_activity')" :description="__('admin.copy.administrative_notifications_will_appear_here_when_available')" />
                    @endforelse
                </div>
            </x-ui.data-table>
        </x-slot:notifications>
    </x-ui.dashboard-layout>
</div>

<div class="modal fade" id="kursModal" tabindex="-1" aria-labelledby="kursModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <form action="{{ route('admin.kurs.update') }}" method="POST" class="modal-content">
            @csrf
            <div class="modal-header"><div><h2 class="modal-title fs-6 fw-bold" id="kursModalTitle">{{ __('admin.copy.add_effective_rate') }}</h2><p class="mb-0 mt-1 small text-muted">{{ __('admin.copy.the_new_value_is_appended_to_rate_history') }}</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('admin.copy.close') }}"></button></div>
            <div class="modal-body tw-grid tw-gap-4">
                <x-ui.select name="currency" :label="__('admin.copy.currency')" :options="\App\Models\ExchangeRate::currencyOptions()" required />
                <x-ui.input name="rate_to_idr" type="number" :label="__('admin.copy.rate_to_idr')" step="0.01" min="0.01" :placeholder="__('admin.copy.example_16500')" required />
            </div>
            <div class="modal-footer"><x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">{{ __('admin.copy.cancel') }}</x-ui.button><x-ui.button type="submit"><x-ui.icon name="save" /> {{ __('admin.copy.save_rate') }}</x-ui.button></div>
        </form>
    </div>
</div>
@endsection
