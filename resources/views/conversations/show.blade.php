@extends('layouts.app')
@section('title', 'Chat: ' . $conversation->context_label . ' — ADASI Portal')
@section('page-title', 'Negosiasi: ' . $conversation->context_label)

@section('content')
    <div class="mb-3">
        @php
            $backRoute = auth()->user()->role === 'purchasing' 
                ? \App\Support\PurchasingNavigation::backUrl('purchasing.conversations.index') 
                : route('supplier.conversations.index');
        @endphp
        <a href="{{ $backRoute }}" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Chat
        </a>
    </div>

    @php
        $partner = $conversation->getPartner(auth()->id());
        $partnerName = $partner->role === 'supplier' 
            ? ($partner->supplier->company_name ?? $partner->name) 
            : $partner->name;
    @endphp

    <div class="card border-0 shadow-sm" style="height: calc(100vh - 200px); display: flex; flex-direction: column;">
        {{-- Chat Header --}}
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                    <i class="bi bi-person-fill" style="font-size: 1.5rem;"></i>
                </div>
                <div>
                    <h6 class="mb-0 fw-bold">{{ $partnerName }}</h6>
                    <small class="text-muted">{{ ucfirst($partner->role) }}</small>
                </div>
            </div>
            <div>
                @if($conversation->conversable_type === 'App\Models\PurchaseRequirement')
                    <span class="badge bg-primary text-uppercase px-3 py-2">PR</span>
                @else
                    <span class="badge bg-success text-uppercase px-3 py-2">PO</span>
                @endif
                <span class="fw-bold ms-2">{{ $conversation->context_label }}</span>
            </div>
        </div>

        {{-- Chat Body (Scrollable) --}}
        <div class="card-body bg-light" id="chat-messages" style="flex: 1; overflow-y: auto; padding: 1.5rem;">
            @forelse($conversation->messages as $msg)
                @php $isMe = $msg->sender_id === auth()->id(); @endphp
                <div class="d-flex mb-3 {{ $isMe ? 'justify-content-end' : 'justify-content-start' }}" data-message-id="{{ $msg->id }}">
                    <div class="d-flex flex-column {{ $isMe ? 'align-items-end' : 'align-items-start' }}" style="max-width: 75%;">
                        <div class="small text-muted mb-1 px-1">
                            {{ $isMe ? 'Anda' : $msg->sender->name }} &bull; {{ $msg->created_at->format('H:i') }}
                        </div>
                        <div class="p-3 rounded-3 shadow-sm {{ $isMe ? 'bg-primary text-white' : 'bg-white border' }}" style="white-space: pre-wrap; font-size: 0.95rem;">{{ $msg->body }}</div>
                    </div>
                </div>
            @empty
                <div class="text-center text-muted py-5" id="empty-state">
                    <i class="bi bi-chat-dots" style="font-size: 2.5rem;"></i>
                    <p class="mt-2">Mulai percakapan dengan {{ $partnerName }}</p>
                </div>
            @endforelse
        </div>

        {{-- Chat Form --}}
        <div class="card-footer bg-white py-3 border-top">
            <form id="chat-form" onsubmit="sendMessage(event)">
                <div class="d-flex gap-2 align-items-end">
                    <textarea id="message-body" class="form-control" rows="2" placeholder="Ketik pesan di sini... (Tekan Enter untuk kirim, Shift+Enter untuk baris baru)" required style="resize: none;"></textarea>
                    <button type="submit" class="btn btn-primary px-4 py-2" id="btn-send">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    const conversationId = {{ $conversation->id }};
    const myId = {{ auth()->id() }};
    const chatContainer = document.getElementById('chat-messages');
    const emptyState = document.getElementById('empty-state');
    let lastMessageId = {{ $conversation->messages->last()->id ?? 0 }};
    let isSending = false;

    // Scroll to bottom immediately
    function scrollToBottom() {
        chatContainer.scrollTop = chatContainer.scrollHeight;
    }
    scrollToBottom();

    // Handle form submit
    function sendMessage(e) {
        e.preventDefault();
        if (isSending) return;

        const bodyInput = document.getElementById('message-body');
        const body = bodyInput.value.trim();
        if (!body) return;

        isSending = true;
        document.getElementById('btn-send').disabled = true;

        fetch(`{{ route('conversations.messages.store', $conversation->id) }}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ body: body })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                bodyInput.value = '';
                appendMessage(data.message, true);
                if (emptyState) emptyState.style.display = 'none';
                lastMessageId = Math.max(lastMessageId, data.message.id);
                scrollToBottom();
            }
        })
        .catch(err => console.error(err))
        .finally(() => {
            isSending = false;
            document.getElementById('btn-send').disabled = false;
            bodyInput.focus();
        });
    }

    // Handle Enter key for submit (Shift+Enter for newline)
    document.getElementById('message-body').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(e);
        }
    });

    // Append single message to DOM
    function appendMessage(msg, isMe) {
        const time = new Date(msg.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
        const name = isMe ? 'Anda' : msg.sender.name;
        const alignClass = isMe ? 'justify-content-end' : 'justify-content-start';
        const colAlignClass = isMe ? 'align-items-end' : 'align-items-start';
        const bubbleClass = isMe ? 'bg-primary text-white' : 'bg-white border';

        // Escape HTML
        const safeBody = msg.body.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

        const html = `
            <div class="d-flex mb-3 ${alignClass}" data-message-id="${msg.id}">
                <div class="d-flex flex-column ${colAlignClass}" style="max-width: 75%;">
                    <div class="small text-muted mb-1 px-1">${name} &bull; ${time}</div>
                    <div class="p-3 rounded-3 shadow-sm ${bubbleClass}" style="white-space: pre-wrap; font-size: 0.95rem;">${safeBody}</div>
                </div>
            </div>
        `;
        chatContainer.insertAdjacentHTML('beforeend', html);
    }

    // Polling new messages
    setInterval(() => {
        fetch(`{{ route('conversations.messages.latest', $conversation->id) }}?after=${lastMessageId}`, {
            headers: { 'Accept': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.messages && data.messages.length > 0) {
                if (emptyState) emptyState.style.display = 'none';
                
                let hasNewPartnerMessage = false;
                data.messages.forEach(msg => {
                    appendMessage(msg, msg.sender_id === myId);
                    lastMessageId = Math.max(lastMessageId, msg.id);
                    if (msg.sender_id !== myId) hasNewPartnerMessage = true;
                });
                
                if (hasNewPartnerMessage) {
                    scrollToBottom();
                }
            }
        });
    }, 10000); // 10 seconds polling
</script>
@endpush
