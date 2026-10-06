@extends('layouts.app')
@section('title', __('ga.dashboard.page_title'))
@section('page-title', __('ga.dashboard.title'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('ga.dashboard.title')"
        :description="__('ga.review.help')"
        :eyebrow="__('ga.review.internal')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.claims.create')" variant="primary">
                <x-ui.icon name="plus" size="sm" />
                <span>{{ __('ga.review.new_claim') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('ga.drp-draft')" variant="outline">
                <x-ui.icon name="wallet" size="sm" />
                <span>{{ __('finance.drp.ga_draft') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.dashboard-layout audience="ga">
        <x-slot:statuses>
{{-- KPI Cards --}}
    <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-5 tw-gap-4">
        <x-ui.metric-card
            :label="__('local_invoice.labels.submitted')"
            :value="$regionalFormatter->number((string) ($kpis['submitted'] ?? 0), 'plain')"
            icon="file-text"
            tone="primary"
            :href="route('ga.claims.index', ['status' => 'SUBMITTED'])"
        />
        <x-ui.metric-card
            :label="__('ga.verification.basic_label')"
            :value="$regionalFormatter->number((string) ($kpis['basic_verified'] ?? 0), 'plain')"
            icon="clipboard-check"
            tone="info"
            :href="route('ga.claims.index', ['status' => 'BASIC_VERIFIED'])"
        />
        <x-ui.metric-card
            :label="__('local_invoice.labels.ready_to_pay')"
            :value="$regionalFormatter->number((string) ($kpis['ready_to_pay'] ?? 0), 'plain')"
            icon="badge-check"
            tone="success"
            :href="route('ga.claims.index', ['status' => 'READY_TO_PAY'])"
        />
        <x-ui.metric-card
            :label="__('local_invoice.dashboard.revision')"
            :value="$regionalFormatter->number((string) ($kpis['need_revision'] ?? 0), 'plain')"
            icon="file-edit"
            tone="error"
            :href="route('ga.claims.index', ['status' => 'NEED_REVISION'])"
        />
        <x-ui.metric-card
            :label="__('ga.review.paid')"
            :value="$regionalFormatter->number((string) ($kpis['paid'] ?? 0), 'plain')"
            icon="check-circle-2"
            tone="neutral"
            :href="route('ga.claims.index', ['status' => 'PAID'])"
        />
    </div>
        </x-slot:statuses>

        <x-slot:claims>
{{-- Recent Claims Table --}}
    <x-ui.data-table
        :title="__('ga.list.recent')"
        :description="__('ga.list.recent_help')"
    >
        <x-slot:toolbar>
            <x-ui.button :href="route('ga.claims.index')" variant="ghost" size="sm">
                <span>{{ __('ga.review.all_claims') }}</span>
                <x-ui.icon name="arrow-right" size="sm" />
            </x-ui.button>
        </x-slot:toolbar>

        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('ga.detail.number') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('ga.labels.employee') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('ga.labels.claim_type') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.date') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('ga.labels.claim_amount') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_invoice.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentClaims as $c)
                        <tr>
                            <td>
                                <strong class="tw-font-mono tw-text-on-surface">{{ $c->claim_number }}</strong>
                            </td>
                            <td>
                                <strong class="tw-text-on-surface">{{ $c->employee?->name }}</strong>
                                <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $c->employee?->department }}</span>
                            </td>
                            <td>
                                <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                                    {{ \App\Models\GaClaim::claimTypeLabel($c->claim_type) }}
                                </span>
                            </td>
                            <td>{{ $c->claim_date ? $regionalFormatter->date($c->claim_date, 'human') : '' }}</td>
                            <td class="text-end tw-font-mono tw-font-bold tw-text-on-surface">
                                Rp {{ $regionalFormatter->number(number_format($c->amount, 0, ',', '.'), 'indonesian') }}
                            </td>
                            <td>
                                <x-ui.status-chip :tone="match($c->status) { 'PAID' => 'success', 'READY_TO_PAY' => 'success', 'NEED_REVISION' => 'error', 'BASIC_VERIFIED' => 'info', default => 'warning' }">
                                    {{ \App\Support\StatusHelper::gaClaimLabel($c->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('ga.claims.show', $c)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>{{ __('local_invoice.actions.detail') }}</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                {{ __('ga.list.empty_recent') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.data-table>
        </x-slot:claims>
    </x-ui.dashboard-layout>
</div>
@endsection
