@extends('layouts.app')

@section('title', 'Export History - ADASI Portal')
@section('page-title', 'Export History')

@section('content')
<x-ui.page-header
    title="Export History"
    description="Review generated files and download completed exports within their three-day retention window."
>
    <x-slot:actions>
        @if($hasPending)
            <span class="ui-status-chip ui-status-chip--info" id="exportPollingState"><x-ui.icon name="refresh-cw" size="sm" />Refreshing status</span>
        @else
            <span class="ui-status-chip ui-status-chip--neutral" id="exportPollingState">No active exports</span>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<x-ui.data-table title="Generated Files" description="Status updates automatically while an export is queued or processing." class="tw-mt-5">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Export</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Completed / Expires</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="exportJobsTableBody">
                    @forelse($items as $item)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $item['label'] }}</div>
                                <div class="small text-muted text-break">{{ $item['file_name'] }}</div>
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
                            <td class="small text-muted">{{ $item['created_at'] ? \Carbon\Carbon::parse($item['created_at'])->format('d M Y H:i') : '-' }}</td>
                            <td class="small text-muted">
                                @if($item['completed_at'])
                                    Completed: {{ \Carbon\Carbon::parse($item['completed_at'])->format('d M Y H:i') }}<br>
                                @endif
                                @if($item['expires_at'])
                                    Expires: {{ \Carbon\Carbon::parse($item['expires_at'])->format('d M Y H:i') }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="text-end">
                                @if($item['download_url'])
                                    <a href="{{ $item['download_url'] }}" class="btn btn-sm btn-outline-success">
                                        <x-ui.icon name="download" class="me-1" />Download
                                    </a>
                                @else
                                    <span class="text-muted small">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5">
                                <x-ui.icon name="inbox" size="lg" class="d-block mx-auto mb-2" />No exports have been created.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

    @if($jobs->hasPages())
        <x-slot:pagination>{{ $jobs->links() }}</x-slot:pagination>
    @endif
</x-ui.data-table>
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
            body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-5"><x-ui.icon name="inbox" size="lg" class="d-block mx-auto mb-2" />No exports have been created.</td></tr>';
            return;
        }

        body.innerHTML = items.map((item) => {
            const completed = item.completed_at ? `Completed: ${escapeHtml(formatDate(item.completed_at))}<br>` : '';
            const expiry = item.expires_at ? `Expires: ${escapeHtml(formatDate(item.expires_at))}` : '-';
            const action = item.download_url
                ? `<a href="${escapeHtml(item.download_url)}" class="btn btn-sm btn-outline-success"><x-ui.icon name="download" class="me-1" />Download</a>`
                : '<span class="text-muted small">-</span>';

            return `<tr>
                <td><div class="fw-semibold">${escapeHtml(item.label)}</div><div class="small text-muted text-break">${escapeHtml(item.file_name)}</div></td>
                <td><span class="${badgeClass(item.status)} text-capitalize">${escapeHtml(item.status)}</span></td>
                <td class="small text-muted">${escapeHtml(formatDate(item.created_at))}</td>
                <td class="small text-muted">${completed}${expiry}</td>
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
