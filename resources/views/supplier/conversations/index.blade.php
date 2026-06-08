@extends('layouts.app')
@section('title', 'Negotiation & Chat - ADASI Portal')
@section('page-title', 'Negotiation with Purchasing')

@section('content')
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">Negotiation Chat List</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle datatable" style="font-size: 0.9rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Document Context</th>
                            <th>Purchasing Officer</th>
                            <th>Latest Message</th>
                            <th>Last Time</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($conversations as $conv)
                            @php $sla = \App\Support\ConversationPresenter::slaMeta($conv, auth()->user()); @endphp
                            <tr>
                                <td>
                                    @if($conv->conversable_type === 'App\Models\PurchaseRequisition')
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
                                        <em class="text-muted">No messages yet</em>
                                    @endif
                                </td>
                                <td>
                                    @if($conv->latestMessage)
                                        <small class="text-muted">{{ $conv->latestMessage->created_at->diffForHumans() }}</small>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $conv->statusBadgeClassFor(auth()->user()) }}">{{ $conv->statusLabelFor(auth()->user()) }}</span>
                                    <span class="badge {{ $sla['class'] }} mt-1">{{ $sla['label'] }}</span>
                                </td>
                                <td class="text-end">
                                    @php $unreadCount = $conv->unreadCountFor(auth()->id()); @endphp
                                    <a href="{{ route('supplier.conversations.show', $conv->id) }}" class="btn btn-sm btn-outline-primary position-relative">
                                        <i class="bi bi-chat-text"></i> Open Chat
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
                                <td colspan="6" class="text-center py-4 text-muted">No conversation or negotiation history.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($conversations instanceof \Illuminate\Contracts\Pagination\Paginator && $conversations->hasPages())
                <div class="mt-3">
                    {{ $conversations->links('pagination::bootstrap-5') }}
                </div>
            @endif
        </div>
    </div>
@endsection
