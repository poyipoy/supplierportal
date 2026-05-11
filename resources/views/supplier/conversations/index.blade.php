@extends('layouts.app')
@section('title', 'Negosiasi & Chat — ADASI Portal')
@section('page-title', 'Negosiasi dengan Purchasing')

@section('content')
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">Daftar Chat Negosiasi</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle datatable" style="font-size: 0.9rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Konteks Dokumen</th>
                            <th>Purchasing Officer</th>
                            <th>Pesan Terakhir</th>
                            <th>Waktu Terakhir</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($conversations as $conv)
                            <tr>
                                <td>
                                    @if($conv->conversable_type === 'App\Models\PurchaseRequirement')
                                        <span class="badge bg-primary text-uppercase me-2">PR</span>
                                    @else
                                        <span class="badge bg-success text-uppercase me-2">PO</span>
                                    @endif
                                    <span class="fw-medium">{{ $conv->context_label }}</span>
                                </td>
                                <td class="fw-medium">{{ $conv->purchasingUser->name }}</td>
                                <td>
                                    @if($conv->latestMessage)
                                        @if($conv->latestMessage->sender_id === auth()->id())
                                            <i class="bi bi-reply text-muted me-1"></i>
                                        @endif
                                        {{ Str::limit($conv->latestMessage->body, 50) }}
                                    @else
                                        <em class="text-muted">Belum ada pesan</em>
                                    @endif
                                </td>
                                <td>
                                    @if($conv->latestMessage)
                                        <small class="text-muted">{{ $conv->latestMessage->created_at->diffForHumans() }}</small>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-end">
                                    @php $unreadCount = $conv->unreadCountFor(auth()->id()); @endphp
                                    <a href="{{ route('supplier.conversations.show', $conv->id) }}" class="btn btn-sm btn-outline-primary position-relative" data-open-chat-conversation="{{ $conv->id }}">
                                        <i class="bi bi-chat-text"></i> Buka Chat
                                        @if($unreadCount > 0)
                                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                                {{ $unreadCount }}
                                            </span>
                                        @endif
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">Belum ada riwayat percakapan/negosiasi.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
