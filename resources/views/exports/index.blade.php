@extends('layouts.app')

@section('title', __('navigation.export_history').' - ADASI Portal')
@section('page-title', __('navigation.export_history'))

@section('content')
<div class="tw:grid tw:gap-5">
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

    <section class="tw:border tw:border-outline tw:bg-surface" aria-labelledby="export-table-title">
        <header class="tw:flex tw:flex-wrap tw:items-end tw:justify-between tw:gap-3 tw:border-b tw:border-outline-variant tw:bg-surface-container tw:px-5 tw:py-3">
            <div>
                <h2 id="export-table-title" class="tw:m-0 tw:text-ui-sm tw:font-semibold">{{ __('common.export_history.generated') }}</h2>
                <p class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">{{ __('common.export_history.status_help') }}</p>
            </div>
            <form method="GET" action="{{ route('exports.index') }}" class="tw:flex tw:flex-wrap tw:items-end tw:gap-2" aria-label="{{ __('common.filters.controls') }}">
                <div>
                    <label for="export-filter-status" class="tw:block tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">{{ __('common.export_history.filter_status') }}</label>
                    <select id="export-filter-status" name="status" class="ui-select ui-select--sm">
                        <option value="">{{ __('common.export_history.filter_all_statuses') }}</option>
                        @foreach(['queued', 'processing', 'completed', 'failed', 'cancelled'] as $statusOption)
                            <option value="{{ $statusOption }}" @selected(($statusFilter ?? null) === $statusOption)>{{ __('common.export_history.'.$statusOption) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="export-filter-format" class="tw:block tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">{{ __('common.export_history.filter_format') }}</label>
                    <select id="export-filter-format" name="format" class="ui-select ui-select--sm">
                        <option value="">{{ __('common.export_history.filter_all_formats') }}</option>
                        <option value="xlsx" @selected(($formatFilter ?? null) === 'xlsx')>XLSX</option>
                        <option value="csv" @selected(($formatFilter ?? null) === 'csv')>CSV</option>
                    </select>
                </div>
                <div class="tw:flex tw:items-center tw:gap-1.5">
                    <x-ui.button type="submit" size="sm" variant="primary">{{ __('common.filters.apply') }}</x-ui.button>
                    @if(($statusFilter ?? null) !== null || ($formatFilter ?? null) !== null)
                        <a href="{{ route('exports.index') }}" class="ui-focus-ring tw:inline-flex tw:h-8 tw:items-center tw:rounded-ui-sm tw:border tw:border-outline tw:bg-surface tw:px-2.5 tw:text-ui-xs tw:font-medium tw:text-on-surface tw:no-underline tw:hover:bg-surface-container-high">{{ __('common.filters.reset') }}</a>
                    @endif
                </div>
            </form>
        </header>
        <p id="exportCenterLiveStatus" class="tw:sr-only" role="status" aria-live="polite"></p>
        <div class="ui-data-table__scroll tw:overflow-x-auto">
            <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:w-full tw:text-ui-sm">
                <thead>
                    <tr>
                        <th scope="col">{{ __('common.export_history.export') }}</th>
                        <th scope="col">{{ __('common.fields.status') }}</th>
                        <th scope="col">{{ __('common.export_history.created') }}</th>
                        <th scope="col">{{ __('common.export_history.completed_expires') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('common.export_history.actions') }}</th>
                    </tr>
                </thead>
                <tbody id="exportJobsTableBody">
                    @forelse($items as $item)
                        <tr>
                            <td>
                                <div class="tw:font-semibold">{{ $item['label'] }}</div>
                                <span class="tw:text-ui-xs tw:font-semibold">{{ strtoupper($item['format']) }}</span>
                                <div class="tw:text-ui-xs tw:text-on-surface-variant tw:break-all">{{ $item['file_name'] }}</div>
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
                                    $isPendingRow = in_array($item['status'], ['queued', 'processing'], true);
                                    $progressVal = is_numeric($item['progress'] ?? null) ? max(0, min(100, (int) $item['progress'])) : null;
                                    $processedRows = max(0, (int) ($item['processed_rows'] ?? 0));
                                    $totalRows = max(0, (int) ($item['total_rows'] ?? 0));
                                @endphp
                                <x-ui.status-chip :tone="$tone">{{ __('common.export_history.'.$item['status']) }}</x-ui.status-chip>
                                @if($isPendingRow)
                                    <div class="tw:mt-1.5 tw:grid tw:gap-1" data-export-progress-block>
                                        <progress
                                            class="ui-export-progress"
                                            max="100"
                                            @if($progressVal !== null) value="{{ $progressVal }}" @endif
                                            aria-label="{{ __('common.export_history.progress_label') }}"></progress>
                                        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-x-2 tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums">
                                            @if($progressVal !== null)
                                                <span>{{ $progressVal }}%</span>
                                            @endif
                                            @if($totalRows > 0)
                                                <span>{{ __('common.export_history.rows_progress', ['processed' => number_format($processedRows), 'total' => number_format($totalRows)]) }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @elseif($item['status'] === 'failed' && !empty($item['failure_message']))
                                    <p class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-error">{{ $item['failure_message'] }}</p>
                                @endif
                            </td>
                            <td class="tw:text-ui-xs tw:text-on-surface-variant tw:whitespace-nowrap">{{ $item['created_at'] ? $regionalFormatter->timestamp(\Carbon\Carbon::parse($item['created_at']), 'datetime') : '-' }}</td>
                            <td class="tw:text-ui-xs tw:text-on-surface-variant">
                                @if($item['completed_at'])
                                    <span>{{ __('common.export_history.completed_at', ['date' => $regionalFormatter->timestamp(\Carbon\Carbon::parse($item['completed_at']), 'datetime')]) }}</span><br>
                                @endif
                                @if($item['expires_at'])
                                    <span>{{ __('common.export_history.expires_at', ['date' => $regionalFormatter->timestamp(\Carbon\Carbon::parse($item['expires_at']), 'datetime')]) }}</span>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="tw:text-end">
                                <div class="tw:inline-flex tw:flex-wrap tw:items-center tw:justify-end tw:gap-1.5">
                                    @if($item['download_url'])
                                        <a href="{{ $item['download_url'] }}" class="ui-focus-ring tw:inline-flex tw:h-8 tw:items-center tw:gap-1.5 tw:rounded-ui-sm tw:border tw:border-success/60 tw:bg-transparent tw:px-2.5 tw:text-ui-xs tw:font-medium tw:text-success tw:no-underline tw:hover:bg-success/5">
                                            <x-ui.icon name="download" />{{ __('common.actions.download') }}
                                        </a>
                                    @endif
                                    @if(!empty($item['cancel_url']))
                                        <form method="POST" action="{{ $item['cancel_url'] }}" class="tw:inline" data-export-action-form="cancel">
                                            @csrf
                                            <button type="submit" data-export-action="cancel" data-action-url="{{ $item['cancel_url'] }}" class="ui-focus-ring tw:inline-flex tw:h-8 tw:cursor-pointer tw:items-center tw:gap-1.5 tw:rounded-ui-sm tw:border tw:border-error/60 tw:bg-transparent tw:px-2.5 tw:text-ui-xs tw:font-medium tw:text-error tw:hover:bg-error/5 tw:disabled:cursor-not-allowed tw:disabled:opacity-60">
                                                <x-ui.icon name="x" size="sm" />{{ __('common.export_history.cancel') }}
                                            </button>
                                        </form>
                                    @endif
                                    @if(!empty($item['rerun_url']))
                                        <form method="POST" action="{{ $item['rerun_url'] }}" class="tw:inline" data-export-action-form="rerun">
                                            @csrf
                                            <button type="submit" data-export-action="rerun" data-action-url="{{ $item['rerun_url'] }}" class="ui-focus-ring tw:inline-flex tw:h-8 tw:cursor-pointer tw:items-center tw:gap-1.5 tw:rounded-ui-sm tw:border tw:border-outline tw:bg-surface tw:px-2.5 tw:text-ui-xs tw:font-medium tw:text-on-surface tw:hover:bg-surface-container-high tw:disabled:cursor-not-allowed tw:disabled:opacity-60">
                                                <x-ui.icon name="rotate-cw" size="sm" />{{ __('common.export_history.rerun') }}
                                            </button>
                                        </form>
                                    @endif
                                    @if(!$item['download_url'] && empty($item['cancel_url']) && empty($item['rerun_url']))
                                        <span class="tw:text-ui-xs tw:text-on-surface-variant">-</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="tw:py-12 tw:text-center">
                                <x-empty-state icon="inbox" :title="__('common.export_history.empty')" :text="__('common.export_history.empty_help')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($jobs->hasPages())
            <div class="tw:border-t tw:border-outline-variant tw:bg-surface-low tw:px-5 tw:py-3">{{ $jobs->links('pagination::bootstrap-5') }}</div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const body = document.getElementById('exportJobsTableBody');
    const state = document.getElementById('exportPollingState');
    const liveStatus = document.getElementById('exportCenterLiveStatus');
    const statusUrl = @json(\App\Support\PortalPath::routePath('exports.index', request()->query()));
    let hasPending = @json($hasPending);
    let pollTimer = null;
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
        'progress_label' => __('common.export_history.progress_label'),
        'rows_progress' => __('common.export_history.rows_progress'),
        'cancel' => __('common.export_history.cancel'),
        'cancelling' => __('common.export_history.cancelling'),
        'rerun' => __('common.export_history.rerun'),
        'rerunning' => __('common.export_history.rerunning'),
        'cancel_failed' => __('common.export_history.cancel_failed'),
        'rerun_failed' => __('common.export_history.rerun_failed'),
        'cancelled_notice' => __('common.export_history.cancelled_notice'),
        'rerun_notice' => __('common.export_history.rerun_notice'),
    ]);
    const datedLabel = (key, date) => copy[key].replace(':date', regionalTimestamp(date));

    if (!body || !state) {
        return;
    }

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    })[character]);
    const formatDate = (value) => value
        ? new Intl.DateTimeFormat(document.documentElement?.lang === 'id' ? 'id-ID' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : '-';
    const regionalTimestamp = (value) => window.AdasiPreferences.displayTimestamp(value, formatDate);
    const formatCount = (value) => {
        const numeric = Math.max(0, Number(value) || 0);
        return new Intl.NumberFormat(document.documentElement?.lang === 'id' ? 'id-ID' : 'en-US').format(numeric);
    };
    const csrfToken = () => document.querySelector?.('meta[name="csrf-token"]')?.getAttribute('content') || '';
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

    const announce = (message) => {
        if (liveStatus) {
            liveStatus.textContent = message;
        }
    };
    const notify = (type, message) => {
        announce(message);
        if (window.AdasiToast?.show) {
            window.AdasiToast.show({ type, message });
        }
    };

    const renderStatusDetail = (item) => {
        if (item.status === 'queued' || item.status === 'processing') {
            const hasProgress = item.progress !== null && item.progress !== undefined && Number.isFinite(Number(item.progress));
            const progress = hasProgress ? Math.max(0, Math.min(100, Math.round(Number(item.progress)))) : null;
            const totalRows = Math.max(0, Number(item.total_rows) || 0);
            const processedRows = Math.max(0, Number(item.processed_rows) || 0);
            const valueAttr = progress !== null ? ` value="${progress}"` : '';
            const percentHtml = progress !== null ? `<span>${progress}%</span>` : '';
            const rowsHtml = totalRows > 0
                ? `<span>${escapeHtml(copy.rows_progress.replace(':processed', formatCount(processedRows)).replace(':total', formatCount(totalRows)))}</span>`
                : '';

            return `<div class="tw:mt-1.5 tw:grid tw:gap-1" data-export-progress-block><progress class="ui-export-progress" max="100"${valueAttr} aria-label="${escapeHtml(copy.progress_label)}"></progress><div class="tw:flex tw:flex-wrap tw:items-center tw:gap-x-2 tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums">${percentHtml}${rowsHtml}</div></div>`;
        }

        if (item.status === 'failed' && item.failure_message) {
            return `<p class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-error">${escapeHtml(item.failure_message)}</p>`;
        }

        return '';
    };

    const renderActions = (item) => {
        const parts = [];
        if (item.download_url) {
            parts.push(`<a href="${escapeHtml(item.download_url)}" class="ui-focus-ring tw:inline-flex tw:h-8 tw:items-center tw:gap-1.5 tw:rounded-ui-sm tw:border tw:border-success/60 tw:bg-transparent tw:px-2.5 tw:text-ui-xs tw:font-medium tw:text-success tw:no-underline tw:hover:bg-success/5"><x-ui.icon name="download" />${escapeHtml(copy.download)}</a>`);
        }
        if (item.cancel_url) {
            parts.push(`<button type="button" data-export-action="cancel" data-action-url="${escapeHtml(item.cancel_url)}" class="ui-focus-ring tw:inline-flex tw:h-8 tw:cursor-pointer tw:items-center tw:gap-1.5 tw:rounded-ui-sm tw:border tw:border-error/60 tw:bg-transparent tw:px-2.5 tw:text-ui-xs tw:font-medium tw:text-error tw:hover:bg-error/5 tw:disabled:cursor-not-allowed tw:disabled:opacity-60"><x-ui.icon name="x" size="sm" />${escapeHtml(copy.cancel)}</button>`);
        }
        if (item.rerun_url) {
            parts.push(`<button type="button" data-export-action="rerun" data-action-url="${escapeHtml(item.rerun_url)}" class="ui-focus-ring tw:inline-flex tw:h-8 tw:cursor-pointer tw:items-center tw:gap-1.5 tw:rounded-ui-sm tw:border tw:border-outline tw:bg-surface tw:px-2.5 tw:text-ui-xs tw:font-medium tw:text-on-surface tw:hover:bg-surface-container-high tw:disabled:cursor-not-allowed tw:disabled:opacity-60"><x-ui.icon name="rotate-cw" size="sm" />${escapeHtml(copy.rerun)}</button>`);
        }
        if (!parts.length) {
            return '<span class="tw:text-ui-xs tw:text-on-surface-variant">-</span>';
        }

        return `<div class="tw:inline-flex tw:flex-wrap tw:items-center tw:justify-end tw:gap-1.5">${parts.join('')}</div>`;
    };

    const renderRows = (items) => {
        if (!items.length) {
            body.innerHTML = `<tr><td colspan="5" class="tw:py-12 tw:text-center"><div class="tw:text-on-surface-variant"><x-ui.icon name="inbox" class="tw:mb-2" /><div class="tw:text-ui-sm tw:font-medium">${escapeHtml(copy.empty)}</div></div></td></tr>`;
            return;
        }

        body.innerHTML = items.map((item) => {
            const completed = item.completed_at ? `<span>${escapeHtml(datedLabel('completed_at', item.completed_at))}</span><br>` : '';
            const expiry = item.expires_at ? `<span>${escapeHtml(datedLabel('expires_at', item.expires_at))}</span>` : '-';
            const statusDetail = renderStatusDetail(item);
            const action = renderActions(item);

            return `<tr>
                <td><div class="tw:font-semibold">${escapeHtml(item.label)}</div><span class="tw:text-ui-xs tw:font-semibold">${escapeHtml(String(item.format || 'xlsx').toUpperCase())}</span><div class="tw:text-ui-xs tw:text-on-surface-variant tw:break-all">${escapeHtml(item.file_name)}</div></td>
                <td><span class="${badgeClass(item.status)}">${escapeHtml(statusLabel(item.status))}</span>${statusDetail}</td>
                <td class="tw:text-ui-xs tw:text-on-surface-variant tw:whitespace-nowrap">${escapeHtml(regionalTimestamp(item.created_at))}</td>
                <td class="tw:text-ui-xs tw:text-on-surface-variant">${completed}${expiry}</td>
                <td class="tw:text-end">${action}</td>
            </tr>`;
        }).join('');
    };

    const schedulePoll = () => {
        if (pollTimer !== null) {
            window.clearTimeout?.(pollTimer);
            pollTimer = null;
        }
        if (hasPending) {
            pollTimer = window.setTimeout(poll, 5000);
        }
    };

    const refreshTable = async (force = false) => {
        if (!force && !hasPending) {
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

        schedulePoll();
    };

    const poll = () => refreshTable(false);

    const runAction = async (button, actionType, url) => {
        if (!button || button.disabled || !url) {
            return;
        }

        const originalHtml = button.innerHTML;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.innerHTML = `<span class="ui-spinner" aria-hidden="true"></span>${escapeHtml(actionType === 'cancel' ? copy.cancelling : copy.rerunning)}`;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            let payload = null;
            try { payload = await response.json(); } catch {}

            if (!response.ok) {
                const detail = payload && payload.errors && typeof payload.errors === 'object'
                    ? Object.values(payload.errors).flat().join(' ')
                    : (payload?.message || (actionType === 'cancel' ? copy.cancel_failed : copy.rerun_failed));
                throw new Error(detail);
            }

            if (actionType === 'rerun') {
                hasPending = true;
                notify('success', payload?.message || copy.rerun_notice);
            } else {
                notify('info', payload?.message || copy.cancelled_notice);
            }

            await refreshTable(true);
        } catch (error) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.innerHTML = originalHtml;
            notify('error', error instanceof Error ? error.message : (actionType === 'cancel' ? copy.cancel_failed : copy.rerun_failed));
        }
    };

    body.addEventListener?.('submit', (event) => {
        const form = event.target?.closest?.('[data-export-action-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const button = form.querySelector('[data-export-action]');
        runAction(button, form.dataset.exportActionForm, form.action);
    });

    body.addEventListener?.('click', (event) => {
        const button = event.target?.closest?.('button[type="button"][data-export-action]');
        if (!button) {
            return;
        }
        runAction(button, button.dataset.exportAction, button.dataset.actionUrl);
    });

    if (hasPending) {
        pollTimer = window.setTimeout(poll, 5000);
    }
});
</script>
@endpush
