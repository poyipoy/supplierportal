@extends('layouts.app')

@section('title', 'Export Saya - ADASI Portal')
@section('page-title', 'Export Saya')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div>
            <h5 class="mb-1 fw-semibold"><i class="bi bi-file-earmark-spreadsheet me-2 text-success"></i>Export Saya</h5>
            <div class="small text-muted">File tersedia selama 3 hari setelah selesai diproses.</div>
        </div>
        @if($hasPending)
            <span class="badge bg-info text-dark" id="exportPollingState"><i class="bi bi-arrow-repeat me-1"></i>Memperbarui status</span>
        @else
            <span class="badge bg-secondary" id="exportPollingState">Tidak ada export aktif</span>
        @endif
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Export</th>
                        <th>Status</th>
                        <th>Dibuat</th>
                        <th>Selesai / Kadaluarsa</th>
                        <th class="text-end">Aksi</th>
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
                                    $badge = match($item['status']) {
                                        'queued' => 'bg-secondary',
                                        'processing' => 'bg-info text-dark',
                                        'completed' => 'bg-success',
                                        default => 'bg-danger',
                                    };
                                @endphp
                                <span class="badge {{ $badge }} text-capitalize">{{ $item['status'] }}</span>
                            </td>
                            <td class="small text-muted">{{ $item['created_at'] ? \Carbon\Carbon::parse($item['created_at'])->format('d M Y H:i') : '-' }}</td>
                            <td class="small text-muted">
                                @if($item['completed_at'])
                                    Selesai: {{ \Carbon\Carbon::parse($item['completed_at'])->format('d M Y H:i') }}<br>
                                @endif
                                @if($item['expires_at'])
                                    Kadaluarsa: {{ \Carbon\Carbon::parse($item['expires_at'])->format('d M Y H:i') }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="text-end">
                                @if($item['download_url'])
                                    <a href="{{ $item['download_url'] }}" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-download me-1"></i>Download
                                    </a>
                                @else
                                    <span class="text-muted small">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i>Belum ada export yang dibuat.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($jobs->hasPages())
        <div class="card-footer bg-white py-3">{{ $jobs->links() }}</div>
    @endif
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
        ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : '-';
    const badgeClass = (status) => ({
        queued: 'bg-secondary',
        processing: 'bg-info text-dark',
        completed: 'bg-success',
        failed: 'bg-danger',
    })[status] || 'bg-secondary';

    const renderRows = (items) => {
        if (!items.length) {
            body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Belum ada export yang dibuat.</td></tr>';
            return;
        }

        body.innerHTML = items.map((item) => {
            const completed = item.completed_at ? `Selesai: ${escapeHtml(formatDate(item.completed_at))}<br>` : '';
            const expiry = item.expires_at ? `Kadaluarsa: ${escapeHtml(formatDate(item.expires_at))}` : '-';
            const action = item.download_url
                ? `<a href="${escapeHtml(item.download_url)}" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Download</a>`
                : '<span class="text-muted small">-</span>';

            return `<tr>
                <td><div class="fw-semibold">${escapeHtml(item.label)}</div><div class="small text-muted text-break">${escapeHtml(item.file_name)}</div></td>
                <td><span class="badge ${badgeClass(item.status)} text-capitalize">${escapeHtml(item.status)}</span></td>
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
            state.className = hasPending ? 'badge bg-info text-dark' : 'badge bg-secondary';
            state.innerHTML = hasPending
                ? '<i class="bi bi-arrow-repeat me-1"></i>Memperbarui status'
                : 'Tidak ada export aktif';
        } catch (error) {
            state.className = 'badge bg-warning text-dark';
            state.textContent = 'Status belum dapat diperbarui';
        }

        if (hasPending) {
            window.setTimeout(poll, 5000);
        }
    };

    window.setTimeout(poll, 5000);
});
</script>
@endpush
