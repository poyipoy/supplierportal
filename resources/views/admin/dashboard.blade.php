@extends('layouts.app')
@section('title', 'Admin Dashboard - ADASI Portal')
@section('page-title', 'Admin Dashboard')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Administration Overview" description="Review current administrative workload and open the records that require maintenance." eyebrow="Admin">
        <x-slot:actions>
            <x-ui.button :href="route('admin.users.index')" variant="secondary" size="sm"><x-ui.icon name="users" /> Manage Users</x-ui.button>
            <x-ui.button :href="route('admin.material-hs-code.index')" size="sm"><x-ui.icon name="boxes" /> Open Master Data</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="tw-grid tw-gap-6 xl:tw-grid-cols-[minmax(0,1.45fr)_minmax(19rem,0.75fr)] xl:tw-items-start">
        <div class="tw-grid tw-gap-6">
            <x-ui.data-table title="Administrative Attention" description="Current exchange-rate readiness from the configured currency set.">
                <x-slot:toolbar>
                    <x-ui.button type="button" size="sm" data-bs-toggle="modal" data-bs-target="#kursModal"><x-ui.icon name="plus" /> Add Effective Rate</x-ui.button>
                </x-slot:toolbar>
                <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-sm">
                    <thead class="table-light"><tr><th scope="col">Currency</th><th scope="col" class="text-end">Current Rate to IDR</th><th scope="col">Effective Date</th><th scope="col">Readiness</th></tr></thead>
                    <tbody>
                        @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                            @php($rate = $latestRates[$currency] ?? null)
                            <tr>
                                <td class="tw-font-mono tw-font-semibold tw-text-primary">{{ $currency }}</td>
                                <td class="ui-tabular-nums text-end tw-font-medium">{{ $rate ? 'Rp ' . number_format($rate->rate_to_idr, 2, ',', '.') : '-' }}</td>
                                <td>{{ $rate?->valid_from?->format('d M Y') ?? '-' }}</td>
                                <td><x-ui.status-chip :tone="$rate ? 'success' : 'warning'">{{ $rate ? 'Available' : 'Rate Required' }}</x-ui.status-chip></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <x-slot:pagination>
                    <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
                        <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ number_format($riwayatKursTotal) }} historical rate records retained.</span>
                        <x-ui.button :href="route('admin.exchange-rates.index')" variant="ghost" size="sm">View Rate History <x-ui.icon name="arrow-right" /></x-ui.button>
                    </div>
                </x-slot:pagination>
            </x-ui.data-table>

            <x-ui.data-table title="Recent Administrative Activity" description="Latest notifications available to the signed-in administrator.">
                <div class="tw-divide-y tw-divide-outline-variant">
                    @forelse($recentActivities as $act)
                        @php($activityUrl = $act->data['url'] ?? null)
                        <div class="tw-grid tw-gap-2 tw-p-4 md:tw-grid-cols-[minmax(0,1fr)_auto] md:tw-items-start shell:tw-px-5">
                            <div class="tw-min-w-0">
                                <div class="tw-text-ui-sm tw-font-semibold">{{ $act->data['title'] ?? 'Administrative notification' }}</div>
                                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ $act->data['message'] ?? 'No additional details were provided.' }}</p>
                            </div>
                            <div class="tw-flex tw-items-center tw-gap-3">
                                <time class="tw-whitespace-nowrap tw-text-ui-xs tw-text-on-surface-variant" datetime="{{ $act->created_at->toIso8601String() }}">{{ $act->created_at->diffForHumans() }}</time>
                                @if($activityUrl)<x-ui.icon-button icon="arrow-right" label="Open activity" :href="$activityUrl" variant="ghost" size="sm" />@endif
                            </div>
                        </div>
                    @empty
                        <x-ui.empty-state icon="activity" title="No recent activity" description="Administrative notifications will appear here when available." />
                    @endforelse
                </div>
            </x-ui.data-table>
        </div>

        <section class="tw-border tw-border-outline-variant tw-bg-surface" aria-labelledby="admin-shortcuts-title">
            <header class="tw-border-b tw-border-outline-variant tw-px-4 tw-py-3">
                <h2 id="admin-shortcuts-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Administration Shortcuts</h2>
                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">Open a maintenance workspace directly.</p>
            </header>
            <nav class="tw-divide-y tw-divide-outline-variant" aria-label="Administration shortcuts">
                @foreach([
                    ['route' => route('admin.users.index'), 'icon' => 'users', 'label' => 'Users', 'description' => 'Accounts, roles, status, and MFA access'],
                    ['route' => route('admin.material-hs-code.index'), 'icon' => 'boxes', 'label' => 'Materials & HS Code', 'description' => 'Material mappings, rules, and data quality'],
                    ['route' => route('admin.exchange-rates.index'), 'icon' => 'badge-dollar-sign', 'label' => 'Exchange Rates', 'description' => 'Effective rate history by currency'],
                    ['route' => route('admin.announcements.index'), 'icon' => 'megaphone', 'label' => 'Announcements', 'description' => 'Portal-wide notices and publication state'],
                    ['route' => route('admin.auth-audit-logs.index'), 'icon' => 'shield-check', 'label' => 'Authentication Audit', 'description' => 'Account and authentication security events'],
                ] as $shortcut)
                    <a href="{{ $shortcut['route'] }}" class="ui-focus-ring tw-flex tw-items-start tw-gap-3 tw-p-4 tw-text-on-surface tw-no-underline hover:tw-bg-surface-low">
                        <x-ui.icon :name="$shortcut['icon']" class="tw-mt-0.5 tw-shrink-0 tw-text-primary" />
                        <span class="tw-min-w-0 tw-flex-1"><span class="tw-block tw-text-ui-sm tw-font-semibold">{{ $shortcut['label'] }}</span><span class="tw-mt-0.5 tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $shortcut['description'] }}</span></span>
                        <x-ui.icon name="chevron-right" class="tw-mt-0.5 tw-shrink-0 tw-text-on-surface-variant" />
                    </a>
                @endforeach
            </nav>
        </section>
    </div>

    <section class="tw-border-y tw-border-outline-variant tw-bg-surface" aria-labelledby="admin-summary-title">
        <h2 id="admin-summary-title" class="tw-sr-only">Operational summary</h2>
        <dl class="tw-m-0 tw-grid tw-grid-cols-2 lg:tw-grid-cols-4">
            <div class="tw-border-b tw-border-r tw-border-outline-variant tw-p-4 lg:tw-border-b-0">
                <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">Active Accounts</dt>
                <dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-xl tw-font-semibold">{{ number_format($totalUsersActive) }}</dd>
                <div class="tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ collect($usersByRole)->map(fn ($count, $role) => ucfirst($role) . ' ' . $count)->implode(' / ') }}</div>
            </div>
            <div class="tw-border-b tw-border-outline-variant tw-p-4 lg:tw-border-b-0 lg:tw-border-r">
                <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">Registered Suppliers</dt>
                <dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-xl tw-font-semibold">{{ number_format($supplierCount) }}</dd>
                <a href="{{ route('admin.users.index') }}" class="ui-focus-ring tw-mt-1 tw-inline-block tw-rounded-ui-xs tw-text-ui-xs tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">Review supplier accounts</a>
            </div>
            <div class="tw-border-r tw-border-outline-variant tw-p-4">
                <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">POs Created This Month</dt>
                <dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-xl tw-font-semibold">{{ number_format($transaksiBulanIni) }}</dd>
                <div class="tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">Current calendar month</div>
            </div>
            <div class="tw-p-4">
                <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">Active Claims</dt>
                <dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-text-xl tw-font-semibold">{{ number_format($klaimAktif) }}</dd>
                <div class="tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">Pending or supplier response recorded</div>
            </div>
        </dl>
    </section>
</div>

<div class="modal fade" id="kursModal" tabindex="-1" aria-labelledby="kursModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <form action="{{ route('admin.kurs.update') }}" method="POST" class="modal-content">
            @csrf
            <div class="modal-header"><div><h2 class="modal-title fs-6 fw-bold" id="kursModalTitle">Add Effective Rate</h2><p class="mb-0 mt-1 small text-muted">The new value is appended to rate history.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body tw-grid tw-gap-4">
                <x-ui.select name="currency" label="Currency" :options="\App\Models\ExchangeRate::CURRENCY_LABELS" required />
                <x-ui.input name="rate_to_idr" type="number" label="Rate to IDR" step="0.01" min="0.01" placeholder="Example: 16500" required />
            </div>
            <div class="modal-footer"><x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">Cancel</x-ui.button><x-ui.button type="submit"><x-ui.icon name="save" /> Save Rate</x-ui.button></div>
        </form>
    </div>
</div>
@endsection
