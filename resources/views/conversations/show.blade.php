@extends('layouts.app')
@section('title', 'Chat: ' . $conversation->context_label . ' - ADASI Portal')
@section('page-title', 'Negotiation: ' . $conversation->context_label)

@section('content')
    <div class="chat-fullpage-shell">
    <div class="chat-fullpage-back">
        @php
            $backRoute = auth()->user()->role === 'purchasing' 
                ? \App\Support\PurchasingNavigation::backUrl('purchasing.conversations.index') 
                : route('supplier.conversations.index');
        @endphp
        <a href="{{ $backRoute }}" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i> Back to Chat List
        </a>
    </div>

    @isset($chatContext)
        <div class="card border-0 shadow-sm chat-fullpage-context">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge {{ $chatContext['type'] === 'PO' ? 'bg-success' : 'bg-primary' }}">{{ $chatContext['type'] }}</span>
                            <h6 class="mb-0 fw-bold">{{ $chatContext['title'] }}</h6>
                        </div>
                        <div class="small text-muted">{{ $chatContext['subtitle'] }}</div>
                    </div>
                    @if($chatContext['url'])
                        <a href="{{ $chatContext['url'] }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Open Details
                        </a>
                    @endif
                </div>
                @if(!empty($chatContext['fields']))
                    <details class="chat-fullpage-context-details mt-2">
                        <summary class="small fw-semibold text-primary">Context Details</summary>
                        <div class="row g-2 mt-2">
                            @foreach($chatContext['fields'] as $field)
                                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
                                    <div class="border rounded bg-light px-3 py-2 h-100">
                                        <div class="small text-muted">{{ $field['label'] }}</div>
                                        <div class="fw-semibold text-truncate">{{ $field['value'] }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
                @if(!empty($quickActions))
                    <div class="d-flex flex-wrap gap-2 mt-3" id="chatQuickActions">
                        @foreach($quickActions as $action)
                            @if(($action['type'] ?? '') === 'link')
                                <a href="{{ $action['url'] }}" class="btn btn-sm btn-{{ $action['variant'] ?? 'outline-primary' }}">
                                    <i class="bi {{ $action['icon'] ?? 'bi-arrow-right' }} me-1"></i>{{ $action['label'] }}
                                </a>
                            @else
                                <button type="button"
                                    class="btn btn-sm btn-{{ $action['variant'] ?? 'outline-primary' }}"
                                    data-chat-action="{{ $action['key'] }}"
                                    data-chat-action-label="{{ $action['label'] }}"
                                    data-chat-action-note="{{ !empty($action['requires_note']) ? '1' : '0' }}"
                                    data-chat-action-type="{{ $action['type'] ?? 'prompt' }}">
                                    <i class="bi {{ $action['icon'] ?? 'bi-lightning-charge' }} me-1"></i>{{ $action['label'] }}
                                </button>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endisset

    @php
        $partner = $conversation->getPartner(auth()->id());
        $partnerName = $partner->role === 'supplier' 
            ? ($partner->supplier->company_name ?? $partner->name) 
            : $partner->name;
    @endphp

    <div class="card border-0 shadow-sm chat-fullpage-card">
        {{-- Chat Header --}}
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
            <div class="d-flex align-items-center gap-3">
                <div class="chat-fullpage-avatar bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center">
                    <i class="bi bi-person-fill" style="font-size: 1.5rem;"></i>
                </div>
                <div>
                    <h6 class="mb-0 fw-bold">{{ $partnerName }}</h6>
                    <small class="text-muted">{{ ucfirst($partner->role) }}</small>
                </div>
            </div>
            <div>
                @if($conversation->conversable_type === 'App\Models\PurchaseRequisition')
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
                <div class="chat-message-row {{ $isMe ? 'is-me justify-content-end' : 'is-partner justify-content-start' }}" data-message-id="{{ $msg->id }}">
                    <div class="chat-message-stack {{ $isMe ? 'align-items-end' : 'align-items-start' }}">
                        <div class="chat-message-bubble {{ $isMe ? 'is-me' : 'is-partner' }} shadow-sm">
                            @if($msg->body !== '')
                                <div class="chat-message-text">{{ $msg->body }}</div>
                            @endif
                            @if($msg->attachments->isNotEmpty())
                                <div class="d-grid gap-1 mt-2">
                                    @foreach($msg->attachments as $attachment)
                                        <a href="{{ route('attachments.show', $attachment->id) }}" target="_blank" class="btn btn-sm {{ $isMe ? 'btn-light' : 'btn-outline-primary' }} text-start">
                                            <i class="bi bi-paperclip me-1"></i>{{ $attachment->file_name }}
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="chat-message-meta {{ $isMe ? 'text-end' : 'text-start' }}">
                            @unless($isMe)
                                {{ $msg->sender->name }} &bull;
                            @endunless
                            {{ $msg->created_at->format('H:i') }}
                            @if($isMe)
                                <span class="chat-read-receipt {{ $msg->read_at ? 'is-read' : '' }}"
                                      data-read-receipt-id="{{ $msg->id }}"
                                      title="{{ $msg->read_at ? 'Read ' . $msg->read_at->format('H:i') : 'Sent, unread' }}">
                                    <i class="bi bi-check2-all"></i>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center text-muted py-5" id="empty-state">
                    <i class="bi bi-chat-dots" style="font-size: 2.5rem;"></i>
                    <p class="mt-2">Start a conversation with {{ $partnerName }}</p>
                </div>
            @endforelse
        </div>

        {{-- Chat Form --}}
        <div class="card-footer bg-white py-3 border-top">
            <form id="chat-form" onsubmit="sendMessage(event)">
                @if(!empty($messageTemplates))
                    <div class="dropdown mb-2">
                        <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-lightning-charge me-1"></i>Message Template
                        </button>
                        <div class="dropdown-menu p-2 chat-template-menu">
                            @foreach($messageTemplates as $template)
                                <button type="button" class="dropdown-item rounded small text-wrap" data-chat-template="{{ $template }}">
                                    {{ $template }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="small text-muted mb-2 d-none" id="message-attachments-preview"></div>
                <div class="d-flex gap-2 align-items-end">
                    <textarea id="message-body" class="form-control" rows="2" placeholder="Type a message here... (Press Enter to send, Shift+Enter for a new line)" style="resize: none;"></textarea>
                    <label for="message-attachments" class="btn btn-outline-secondary mb-0" title="Attach file">
                        <i class="bi bi-paperclip"></i>
                    </label>
                    <input type="file" id="message-attachments" class="d-none" multiple accept=".jpg,.jpeg,.png,.pdf,.xlsx,.xls,.doc,.docx">
                    <button type="submit" class="btn btn-primary px-4 py-2" id="btn-send">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
    </div>
@endsection

@push('scripts')
<script>
    const conversationId = {{ $conversation->id }};
    const myId = {{ auth()->id() }};
    const chatContainer = document.getElementById('chat-messages');
    const emptyState = document.getElementById('empty-state');
    const attachmentInput = document.getElementById('message-attachments');
    const attachmentPreview = document.getElementById('message-attachments-preview');
    const quickActionUrl = `{{ route('conversations.quick-action', $conversation->id) }}`;
    let lastMessageId = {{ $conversation->messages->last()->id ?? 0 }};
    let isSending = false;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderAttachmentPreview() {
        const files = Array.from(attachmentInput?.files || []);
        if (!attachmentPreview || files.length === 0) {
            if (attachmentPreview) {
                attachmentPreview.classList.add('d-none');
                attachmentPreview.innerHTML = '';
            }
            return;
        }

        attachmentPreview.innerHTML = files.map((file) => `
            <span class="badge bg-light text-dark border me-1 mb-1">
                <i class="bi bi-paperclip me-1"></i>${escapeHtml(file.name)}
            </span>
        `).join('');
        attachmentPreview.classList.remove('d-none');
    }

    function readReceiptHtml(msg) {
        if (Number(msg.sender_id) !== myId) return '';

        const read = Boolean(msg.is_read || msg.read_at);
        const title = read
            ? `Read${msg.read_at_display ? ' ' + msg.read_at_display : ''}`
            : 'Sent, unread';

        return `<span class="chat-read-receipt ${read ? 'is-read' : ''}" data-read-receipt-id="${msg.id}" title="${escapeHtml(title)}">
            <i class="bi bi-check2-all"></i>
        </span>`;
    }

    function attachmentHtml(attachments, isMe) {
        if (!attachments || attachments.length === 0) return '';

        return `<div class="d-grid gap-1 mt-2">
            ${attachments.map((attachment) => `
                <a href="${escapeHtml(attachment.url)}" target="_blank" class="btn btn-sm ${isMe ? 'btn-light' : 'btn-outline-primary'} text-start">
                    <i class="bi bi-paperclip me-1"></i>${escapeHtml(attachment.name)}
                </a>
            `).join('')}
        </div>`;
    }

    function updateReadReceipts(receipts) {
        (receipts || []).forEach((receipt) => {
            const receiptEl = chatContainer.querySelector(`[data-read-receipt-id="${receipt.id}"]`);
            if (!receiptEl) return;

            receiptEl.classList.add('is-read');
            receiptEl.setAttribute('title', `Read${receipt.read_at_display ? ' ' + receipt.read_at_display : ''}`);
        });
    }

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
        const files = Array.from(attachmentInput?.files || []);
        if (!body && files.length === 0) return;

        isSending = true;
        document.getElementById('btn-send').disabled = true;
        const payload = new FormData();
        payload.append('body', body);
        files.forEach((file) => payload.append('attachments[]', file));

        fetch(`{{ route('conversations.messages.store', $conversation->id) }}`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: payload
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                bodyInput.value = '';
                if (attachmentInput) attachmentInput.value = '';
                renderAttachmentPreview();
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
        const name = isMe ? 'You' : (msg.sender_name || msg.sender?.name || 'User');
        const alignClass = isMe ? 'justify-content-end' : 'justify-content-start';
        const colAlignClass = isMe ? 'align-items-end' : 'align-items-start';
        const bubbleClass = isMe ? 'is-me' : 'is-partner';

        const safeBody = escapeHtml(msg.body || '');

        const html = `
            <div class="chat-message-row ${isMe ? 'is-me' : 'is-partner'} ${alignClass}" data-message-id="${msg.id}">
                <div class="chat-message-stack ${colAlignClass}">
                    <div class="chat-message-bubble ${bubbleClass} shadow-sm">
                        ${safeBody ? `<div class="chat-message-text">${safeBody}</div>` : ''}
                        ${attachmentHtml(msg.attachments, isMe)}
                    </div>
                    <div class="chat-message-meta ${isMe ? 'text-end' : 'text-start'}">
                        ${isMe ? '' : `${escapeHtml(name)} &bull; `}
                        ${time}
                        ${readReceiptHtml(msg)}
                    </div>
                </div>
            </div>
        `;
        chatContainer.insertAdjacentHTML('beforeend', html);
    }

    document.querySelectorAll('[data-chat-template]').forEach((button) => {
        button.addEventListener('click', () => {
            const bodyInput = document.getElementById('message-body');
            const template = button.dataset.chatTemplate || '';
            bodyInput.value = bodyInput.value
                ? `${bodyInput.value.trim()}\n${template}`
                : template;
            bodyInput.focus();
        });
    });

    attachmentInput?.addEventListener('change', renderAttachmentPreview);

    document.querySelectorAll('[data-chat-action]').forEach((button) => {
        button.addEventListener('click', () => {
            const action = button.dataset.chatAction;
            const label = button.dataset.chatActionLabel || 'Negotiation Action';
            const requiresNote = button.dataset.chatActionNote === '1';
            const actionType = button.dataset.chatActionType || 'prompt';

            const execute = (note = '') => {
                const originalHtml = button.innerHTML;
                button.disabled = true;
                button.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>Processing`;

                fetch(quickActionUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ action, note })
                })
                    .then((response) => {
                        if (!response.ok) {
                            return response.json().then((payload) => {
                                const messages = payload.errors
                                    ? Object.values(payload.errors).flat().join('\n')
                                    : (payload.message || 'The action cannot be processed yet.');
                                throw new Error(messages);
                            });
                        }
                        return response.json();
                    })
                    .then((data) => {
                        if (data.message && !chatContainer.querySelector(`[data-message-id="${data.message.id}"]`)) {
                            appendMessage(data.message, true);
                            lastMessageId = Math.max(lastMessageId, data.message.id);
                            scrollToBottom();
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: `${label} processed successfully.`,
                            timer: 1400,
                            showConfirmButton: false
                        });
                    })
                    .catch((error) => Swal.fire('Error', error.message || 'The action cannot be processed yet.', 'error'))
                    .finally(() => {
                        button.disabled = false;
                        button.innerHTML = originalHtml;
                    });
            };

            if (requiresNote || actionType === 'prompt') {
                Swal.fire({
                    title: label,
                    input: 'textarea',
                    inputLabel: requiresNote ? 'Notes are required' : 'Additional notes',
                    inputPlaceholder: 'Write a note for the supplier...',
                    inputAttributes: { maxlength: 1000 },
                    showCancelButton: true,
                    confirmButtonText: 'Send',
                    cancelButtonText: 'Cancel',
                    inputValidator: (value) => {
                        if (requiresNote && !String(value || '').trim()) {
                            return 'Notes are required.';
                        }
                        return null;
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        execute(String(result.value || '').trim());
                    }
                });
                return;
            }

            Swal.fire({
                title: label,
                text: 'Continue with this action?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, continue',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) execute();
            });
        });
    });

    // Polling new messages
    setInterval(() => {
        fetch(`{{ route('conversations.messages.latest', $conversation->id) }}?after=${lastMessageId}`, {
            headers: { 'Accept': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            updateReadReceipts(data.read_receipts);
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
