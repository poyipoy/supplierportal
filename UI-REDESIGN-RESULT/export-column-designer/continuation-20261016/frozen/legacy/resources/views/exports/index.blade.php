@extends('layouts.app')

@section('title', __('navigation.export_history').' - ADASI Portal')
@section('page-title', __('navigation.export_history'))

@section('content')
<div class="tw-grid tw-gap-5">
    <x-ui.page-header
        :title="__('navigation.quick.exports')"
        :description="__('common.export_history.description')"
        :eyebrow="__('common.export_history.eyebrow')">
        <x-slot:meta>
            @if($hasPending)
                <x-ui.status-chip tone="info" id="exportPollingState"><x-ui.icon name="refresh-cw" size="sm" />{{ __('common.export_history.refreshing') }}</x-ui.status-chip>
            @else
                <x-ui.status-chip tone="neutral" id="exportPollingState">{{ __('common.export_history.none_active') }}</x-ui.status-chip>
            @endif
        </x-slot:meta>
    </x-ui.page-header>

    <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="export-table-title">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-3">
            <h2 id="export-table-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('common.export_history.generated') }}</h2>
            <p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">{{ __('common.export_history.status_help') }}</p>
        </header>
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-sm">
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('common.export_history.export') }}</th>
                        <th scope="col">{{ __('common.fields.status') }}</th>
                        <th scope="col">{{ __('common.export_history.created') }}</th>
                        <th scope="col">{{ __('common.export_history.completed_expires') }}</th>
                        <th scope="col" class="text-end">{{ __('common.export_history.actions') }}</th>
                    </tr>
                </thead>
                <tbody id="exportJobsTableBody">
                    @forelse($items as $item)
                        <tr>
                            <td>
                                <div class="tw-font-semibold">{{ $item['label'] }}</div>
                                <span class="tw-text-ui-xs tw-font-semibold">{{ strtoupper($item['format']) }}</span>
                                <div class="tw-text-ui-xs tw-text-on-surface-variant tw-break-all">{{ $item['file_name'] }}</div>
                            </td>
                            <td>
                                @php
                                    $tone = match($item['status']) {
                                        'queued' => 'neutral',
                                        'processing' => 'info',
                                        'completed' => 'success',
                                        'cancelled' => 'warning',
                                        default => 'error',
                                    };
                                @endphp
                                <x-ui.status-chip :tone="$tone">{{ __('common.export_history.'.$item['status']) }}</x-ui.status-chip>
                            </td>
                            <td class="tw-text-ui-xs tw-text-on-surface-variant tw-whitespace-nowrap">{{ $item['created_at'] ? $regionalFormatter->timestamp(\Carbon\Carbon::parse($item['created_at']), 'datetime') : '-' }}</td>
                            <td class="tw-text-ui-xs tw-text-on-surface-variant">
                                @if($item['completed_at'])
                                    <span>{{ __('common.export_history.completed_at', ['date' => $regionalFormatter->timestamp(\Carbon\Carbon::parse($item['completed_at']), 'datetime')]) }}</span><br>
                                @endif
                                @if($item['expires_at'])
                                    <span>{{ __('common.export_history.expires_at', ['date' => $regionalFormatter->timestamp(\Carbon\Carbon::parse($item['expires_at']), 'datetime')]) }}</span>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="text-end">
                                @if($item['download_url'])
                                    <a href="{{ $item['download_url'] }}" class="ui-focus-ring tw-inline-flex tw-h-8 tw-items-center tw-gap-1.5 tw-rounded-ui-sm tw-border tw-border-success/60 tw-bg-transparent tw-px-2.5 tw-text-ui-xs tw-font-medium tw-text-success tw-no-underline hover:tw-bg-success/5">
                                        <x-ui.icon name="download" />{{ __('common.actions.download') }}
                                    </a>
                                @else
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="tw-py-12 tw-text-center">
                                <x-empty-state icon="inbox" :title="__('common.export_history.empty')" :text="__('common.export_history.empty_help')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($jobs->hasPages())
            <div class="tw-border-t tw-border-outline-variant tw-bg-surface-low tw-px-5 tw-py-3">{{ $jobs->links('pagination::bootstrap-5') }}</div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const body = document.getElementById('exportJobsTableBody');
    const state = document.getElementById('exportPollingState');
    const statusUrl = @json(route('exports.index', request()->query(), absolute: false));
    let hasPending = @json($hasPending);
    const copy = @js([
        'queued' => __('common.export_history.queued'),
        'processing' => __('common.export_history.processing'),
        'completed' => __('common.export_history.completed'),
        'cancelled' => __('common.export_history.cancelled'),
        'failed' => __('common.export_history.failed'),
        'unknown' => __('common.export_history.unknown'),
        'empty' => __('common.export_history.empty'),
        'refreshing' => __('common.export_history.refreshing'),
        'none_active' => __('common.export_history.none_active'),
        'refresh_error' => __('common.export_history.refresh_error'),
        'refresh_failed' => __('common.export_history.refresh_failed'),
        'download' => __('common.actions.download'),
        'completed_at' => __('common.export_history.completed_at'),
        'expires_at' => __('common.export_history.expires_at'),
    ]);
    const datedLabel = (key, date) => copy[key].replace(':date', regionalTimestamp(date));

    if (!body || !state || !hasPending) {
        return;
    }

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    })[character]);
    const formatDate = (value) => value
        ? new Intl.DateTimeFormat(document.documentElement?.lang === 'id' ? 'id-ID' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : '-';
    const regionalTimestamp = (value) => window.AdasiPreferences.displayTimestamp(value, formatDate);
    const badgeClass = (status) => ({
        queued: 'ui-status-chip ui-status-chip--neutral',
        processing: 'ui-status-chip ui-status-chip--info',
        completed: 'ui-status-chip ui-status-chip--success',
        cancelled: 'ui-status-chip ui-status-chip--warning',
        failed: 'ui-status-chip ui-status-chip--error',
    })[status] || 'ui-status-chip ui-status-chip--neutral';
    const statusLabel = (status) => ({
        queued: copy.queued,
        processing: copy.processing,
        completed: copy.completed,
        cancelled: copy.cancelled,
        failed: copy.failed,
    })[status] || String(status || copy.unknown);

    const renderRows = (items) => {
        if (!items.length) {
            body.innerHTML = `<tr><td colspan="5" class="tw-py-12 tw-text-center"><div class="tw-text-on-surface-variant"><x-ui.icon name="inbox" class="tw-mb-2" /><div class="tw-text-ui-sm tw-font-medium">${escapeHtml(copy.empty)}</div></div></td></tr>`;
            return;
        }

        body.innerHTML = items.map((item) => {
            const completed = item.completed_at ? `<span>${escapeHtml(datedLabel('completed_at', item.completed_at))}</span><br>` : '';
            const expiry = item.expires_at ? `<span>${escapeHtml(datedLabel('expires_at', item.expires_at))}</span>` : '-';
            const action = item.download_url
                ? `<a href="${escapeHtml(item.download_url)}" class="ui-focus-ring tw-inline-flex tw-h-8 tw-items-center tw-gap-1.5 tw-rounded-ui-sm tw-border tw-border-success/60 tw-bg-transparent tw-px-2.5 tw-text-ui-xs tw-font-medium tw-text-success tw-no-underline hover:tw-bg-success/5"><x-ui.icon name="download" />${escapeHtml(copy.download)}</a>`
                : '<span class="tw-text-ui-xs tw-text-on-surface-variant">-</span>';

            return `<tr>
                <td><div class="tw-font-semibold">${escapeHtml(item.label)}</div><span class="tw-text-ui-xs tw-font-semibold">${escapeHtml(String(item.format || 'xlsx').toUpperCase())}</span><div class="tw-text-ui-xs tw-text-on-surface-variant tw-break-all">${escapeHtml(item.file_name)}</div></td>
                <td><span class="${badgeClass(item.status)}">${escapeHtml(statusLabel(item.status))}</span></td>
                <td class="tw-text-ui-xs tw-text-on-surface-variant tw-whitespace-nowrap">${escapeHtml(regionalTimestamp(item.created_at))}</td>
                <td class="tw-text-ui-xs tw-text-on-surface-variant">${completed}${expiry}</td>
                <td class="text-end">${action}</td>
            </tr>`;
        }).join('');
    };

    const poll = async () => {
        if (!hasPending) {
            return;
        }

        try {
            const response = await fetch(statusUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(copy.refresh_error);
            }

            const payload = await response.json();
            renderRows(payload.data || []);
            hasPending = Boolean(payload.has_pending);
            state.className = hasPending ? 'ui-status-chip ui-status-chip--info' : 'ui-status-chip ui-status-chip--neutral';
            state.innerHTML = hasPending
                ? `<x-ui.icon name="refresh-cw" size="sm" />${escapeHtml(copy.refreshing)}`
                : copy.none_active;
        } catch (error) {
            state.className = 'ui-status-chip ui-status-chip--warning';
            state.textContent = copy.refresh_failed;
        }

        if (hasPending) {
            window.setTimeout(poll, 5000);
        }
    };

    window.setTimeout(poll, 5000);
});
</script>
@endpush
