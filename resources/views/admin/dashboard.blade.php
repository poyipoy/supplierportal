@extends('layouts.app')
@section('title', 'Admin Dashboard - ADASI Portal')
@section('page-title', 'Dashboard Admin')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Admin Dashboard"
        description="Monitor users, supplier participation, transactions, claims, and exchange-rate administration."
        eyebrow="Admin"
    />

    <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2 xl:tw-grid-cols-4">
        <x-ui.metric-card
            label="Active Users"
            :value="$totalUsersActive"
            icon="bi-people"
            :href="route('admin.users.index')"
            :meta="collect($usersByRole)->map(fn ($count, $role) => ucfirst($role) . ': ' . $count)->implode(' / ')"
        />
        <x-ui.metric-card label="Transactions This Month" :value="$transaksiBulanIni" icon="bi-graph-up" tone="success" />
        <x-ui.metric-card label="Registered Suppliers" :value="$supplierCount" icon="bi-building" tone="info" :href="route('admin.users.index')" />
        <x-ui.metric-card label="Active Claims" :value="$klaimAktif" icon="bi-shield-exclamation" tone="error" />
    </div>

    <div class="tw-grid tw-gap-6 xl:tw-grid-cols-[minmax(0,1.4fr)_minmax(20rem,1fr)] xl:tw-items-start">
        <x-ui.card title="Latest System Activities" padding="none">
            <div class="tw-divide-y tw-divide-outline-variant">
                @forelse($recentActivities as $act)
                    <div class="tw-flex tw-gap-3 tw-p-4 shell:tw-px-5">
                        <span class="tw-inline-flex tw-h-10 tw-w-10 tw-shrink-0 tw-items-center tw-justify-center tw-rounded-ui-full tw-bg-primary-container tw-text-primary-container-foreground">
                            <i class="bi {{ $act->data['icon'] ?? 'bi-bell' }}" aria-hidden="true"></i>
                        </span>
                        <div class="tw-min-w-0 tw-flex-1">
                            <div class="tw-text-ui-sm tw-font-semibold">{{ $act->data['title'] ?? 'Notifications' }}</div>
                            <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ $act->data['message'] ?? '-' }}</p>
                            <time class="tw-mt-1 tw-block tw-text-ui-xs tw-text-on-surface-variant" datetime="{{ $act->created_at->toIso8601String() }}">
                                {{ $act->created_at->diffForHumans() }}
                            </time>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="bi-activity" title="No activity recorded" description="Recent administrative activity will appear here." />
                @endforelse
            </div>
        </x-ui.card>

        <div class="tw-grid tw-gap-6">
            <x-ui.card title="Exchange Rate Management">
                <x-slot:actions>
                    <x-ui.button size="sm" variant="secondary" data-bs-toggle="modal" data-bs-target="#kursModal">
                        <x-slot:leading><i class="bi bi-plus-lg"></i></x-slot:leading>
                        Update Exchange Rate
                    </x-ui.button>
                </x-slot:actions>

                <div class="tw-grid tw-grid-cols-2 tw-gap-3">
                    @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                        @php($rate = $latestRates[$currency] ?? null)
                        <div class="tw-rounded-ui-sm tw-bg-surface-container tw-p-3 tw-text-center">
                            <div class="tw-text-ui-xs tw-text-on-surface-variant">{{ $currency }} to IDR</div>
                            <div class="ui-tabular-nums tw-mt-1 tw-font-semibold">Rp {{ $rate ? number_format($rate->rate_to_idr, 0, ',', '.') : '-' }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="tw-mt-5 tw-flex tw-items-center tw-justify-between tw-gap-3">
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-semibold">Latest Rate History</h3>
                    <a href="{{ route('admin.exchange-rates.index') }}" class="ui-focus-ring tw-rounded-ui-xs tw-text-ui-xs tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">
                        View all {{ $riwayatKursTotal }}
                    </a>
                </div>
                <div class="tw-mt-2 tw-max-h-64 tw-overflow-auto">
                    <table class="table table-sm table-hover align-middle mb-0 tw-text-ui-xs">
                        <thead class="table-light"><tr><th scope="col">Currency</th><th scope="col">Rate</th><th scope="col">Valid From</th></tr></thead>
                        <tbody>
                            @forelse($riwayatKurs as $kurs)
                                <tr>
                                    <td><x-ui.status-chip tone="neutral">{{ $kurs->currency }}</x-ui.status-chip></td>
                                    <td class="ui-tabular-nums fw-medium">Rp {{ number_format($kurs->rate_to_idr, 0, ',', '.') }}</td>
                                    <td>{{ $kurs->valid_from->format('d M Y') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="tw-py-6 tw-text-center tw-text-on-surface-variant">No exchange rates saved.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card title="Quick Links" variant="tonal">
                <x-ui.button :href="route('admin.announcements.index')" variant="ghost" size="sm" class="tw-w-full tw-justify-start">
                    <x-slot:leading><i class="bi bi-megaphone"></i></x-slot:leading>
                    Announcement Management
                </x-ui.button>
            </x-ui.card>
        </div>
    </div>
</div>

<div class="modal fade" id="kursModal" tabindex="-1" aria-labelledby="kursModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form action="{{ route('admin.kurs.update') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title fs-6 fw-bold" id="kursModalTitle">Update Exchange Rate</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body tw-grid tw-gap-4">
                    <x-ui.select name="currency" label="Currency" :options="\App\Models\ExchangeRate::CURRENCY_LABELS" required />
                    <x-ui.input name="rate_to_idr" type="number" label="Rate to IDR" step="0.01" min="0.01" placeholder="16500" required />
                </div>
                <div class="modal-footer">
                    <x-ui.button type="submit" class="tw-w-full">Save</x-ui.button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
