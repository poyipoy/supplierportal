@extends('layouts.app')

@section('title', 'Export History - ADASI Portal')
@section('page-title', 'Export History')

@section('content')
<div class="tw-grid tw-gap-5">
    <x-ui.page-header
        title="Export History"
        description="Review generated files and download completed exports within their retention window."
        eyebrow="Data Exports"
    >
        <x-slot:meta>
            @if($hasPending)
                <x-ui.status-chip tone="info" id="exportPollingState"><x-ui.icon name="refresh-cw" size="sm" />Refreshing status</x-ui.status-chip>
            @else
                <x-ui.status-chip tone="neutral" id="exportPollingState">No active exports</x-ui.status-chip>
            @endif
        </x-slot:meta>
    </x-ui.page-header>

    <section class="tw-border tw-border-outline-variant tw-bg-surface" aria-labelledby="export-table-title">
        <header class="tw-border-b tw-border-outline-variant tw-px-5 tw-py-3">
            <h2 id="export-table-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Generated Files</h2>
            <p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">Status updates automatically while an export is queued or processing.</p>
        </header>
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-sm">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Export</th>
                        <th scope="col">Status</th>
                        <th scope="col">Created</th>
                        <th scope="col">Completed / Expires</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="exportJobsTableBody">
                    @forelse($items as $item)
                        <tr>
                            <td>
                                <div class="tw-font-semibold">{{ $item['label'] }}</div>
                                <div class="tw-text-ui-xs tw-text-on-surface-variant tw-break-all">{{ $item['file_name'] }}</div>
                            </td>
                            <td>
                                @php
                                    $tone = match($item['status']) {
                                        'queued' => 'neutral',
                                        'processing' => 'info',
                                        'completed' => 'success',
                                        default => 'error',
                                    };
                                @endphp
                                <x-ui.status-chip :tone="$tone">{{ ucfirst($item['status']) }}</x-ui.status-chip>
                            </td>
                            <td class="tw-text-ui-xs tw-text-on-surface-variant tw-whitespace-nowrap">{{ $item['created_at'] ? \Carbon\Carbon::parse($item['created_at'])->format('d M Y H:i') : '-' }}</td>
                            <td class="tw-text-ui-xs tw-text-on-surface-variant">
                                @if($item['completed_at'])
                                    <span>Completed: {{ \Carbon\Carbon::parse($item['completed_at'])->format('d M Y H:i') }}</span><br>
                                @endif
                                @if($item['expires_at'])
                                    <span>Expires: {{ \Carbon\Carbon::parse($item['expires_at'])->format('d M Y H:i') }}</span>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="text-end">
                                @if($item['download_url'])
                                    <a href="{{ $item['download_url'] }}" class="ui-focus-ring tw-inline-flex tw-h-8 tw-items-center tw-gap-1.5 tw-rounded-ui-sm tw-border tw-border-success/60 tw-bg-transparent tw-px-2.5 tw-text-ui-xs tw-font-medium tw-text-success tw-no-underline hover:tw-bg-success/5">
                                        <x-ui.icon name="download" />Download
                                    </a>
                                @else
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="tw-py-12 tw-text-center">
                                <x-empty-state icon="inbox" title="No exports have been created." text="Generated exports will appear here." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($jobs->hasPages())
            <div class="tw-border-t tw-border-outline-variant tw-px-5 tw-py-3">{{ $jobs->links() }}</div>
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

    if (!body || !state || !hasPending) {
        return;
    }

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    })[character]);
    const formatDate = (value) => value
        ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : '-';
    const badgeClass = (status) => ({
        queued: 'ui-status-chip ui-status-chip--neutral',
        processing: 'ui-status-chip ui-status-chip--info',
        completed: 'ui-status-chip ui-status-chip--success',
        failed: 'ui-status-chip ui-status-chip--error',
    })[status] || 'ui-status-chip ui-status-chip--neutral';

    const renderRows = (items) => {
        if (!items.length) {
            body.innerHTML = '<tr><td colspan="5" class="tw-py-12 tw-text-center"><div class="tw-text-on-surface-variant"><x-ui.icon name="inbox" class="tw-mb-2" /><div class="tw-text-ui-sm tw-font-medium">No exports have been created.</div></div></td></tr>';
            return;
        }

        body.innerHTML = items.map((item) => {
            const completed = item.completed_at ? `<span>Completed: ${escapeHtml(formatDate(item.completed_at))}</span><br>` : '';
            const expiry = item.expires_at ? `<span>Expires: ${escapeHtml(formatDate(item.expires_at))}</span>` : '-';
            const action = item.download_url
                ? `<a href="${escapeHtml(item.download_url)}" class="ui-focus-ring tw-inline-flex tw-h-8 tw-items-center tw-gap-1.5 tw-rounded-ui-sm tw-border tw-border-success/60 tw-bg-transparent tw-px-2.5 tw-text-ui-xs tw-font-medium tw-text-success tw-no-underline hover:tw-bg-success/5"><x-ui.icon name="download" />Download</a>`
                : '<span class="tw-text-ui-xs tw-text-on-surface-variant">-</span>';

            return `<tr>
                <td><div class="tw-font-semibold">${escapeHtml(item.label)}</div><div class="tw-text-ui-xs tw-text-on-surface-variant tw-break-all">${escapeHtml(item.file_name)}</div></td>
                <td><span class="${badgeClass(item.status)} text-capitalize">${escapeHtml(item.status)}</span></td>
                <td class="tw-text-ui-xs tw-text-on-surface-variant tw-whitespace-nowrap">${escapeHtml(formatDate(item.created_at))}</td>
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
                throw new Error('Unable to refresh export status.');
            }

            const payload = await response.json();
            renderRows(payload.data || []);
            hasPending = Boolean(payload.has_pending);
            state.className = hasPending ? 'ui-status-chip ui-status-chip--info' : 'ui-status-chip ui-status-chip--neutral';
            state.innerHTML = hasPending
                ? '<x-ui.icon name="refresh-cw" size="sm" />Refreshing status'
                : 'No active exports';
        } catch (error) {
            state.className = 'ui-status-chip ui-status-chip--warning';
            state.textContent = 'Status could not be refreshed';
        }

        if (hasPending) {
            window.setTimeout(poll, 5000);
        }
    };

    window.setTimeout(poll, 5000);
});
</script>
@endpush
