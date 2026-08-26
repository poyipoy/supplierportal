@extends('layouts.app')
@section('title', 'Chat: ' . $conversation->context_label . ' - ADASI Portal')
@section('page-title', 'Negotiation: ' . $conversation->context_label)

@section('content')
    <div class="chat-fullpage-shell">
    <div class="tw-mb-2">
        @php
            $backRoute = auth()->user()->role === 'purchasing'
                ? \App\Support\PurchasingNavigation::backUrl('purchasing.conversations.index')
                : route('supplier.conversations.index');
        @endphp
        <a href="{{ $backRoute }}" class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-ui-xs tw-text-ui-sm tw-font-medium tw-text-on-surface-variant tw-no-underline hover:tw-text-on-surface">
            <x-ui.icon name="arrow-left" /> Back to Chat List
        </a>
    </div>

    @isset($chatContext)
        <div class="tw-border tw-border-outline tw-bg-surface-container tw-mb-2">
            <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-px-4 tw-py-3">
                <div class="tw-min-w-0">
                    <div class="tw-flex tw-items-center tw-gap-2 tw-mb-0.5">
                        <x-ui.status-chip :tone="$chatContext['type'] === 'PO' ? 'success' : 'info'" size="sm">{{ $chatContext['type'] }}</x-ui.status-chip>
                        <span class="tw-text-ui-sm tw-font-semibold tw-truncate">{{ $chatContext['title'] }}</span>
                    </div>
                    <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ $chatContext['subtitle'] }}</span>
                </div>
                @if($chatContext['url'])
                    <a href="{{ $chatContext['url'] }}" class="ui-focus-ring tw-inline-flex tw-h-8 tw-items-center tw-gap-1.5 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-px-2.5 tw-text-ui-xs tw-font-medium tw-text-primary tw-no-underline hover:tw-bg-surface-low">
                        <x-ui.icon name="external-link" /> Open Details
                    </a>
                @endif
            </div>
            @if(!empty($chatContext['fields']))
                <details class="tw-border-t tw-border-outline-variant">
                    <summary class="tw-cursor-pointer tw-px-4 tw-py-2 tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-bg-surface-low">Context Details</summary>
                    <div class="chat-context-grid tw-px-4 tw-pb-3 tw-pt-1">
                        @foreach($chatContext['fields'] as $field)
                            <div class="chat-context-field">
                                <div class="tw-text-ui-xs tw-text-on-surface-variant">{{ $field['label'] }}</div>
                                <div class="tw-font-semibold tw-truncate">{{ $field['value'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
            @if(!empty($quickActions))
                <div class="chat-action-panel tw-border-t tw-border-outline-variant tw-px-4 tw-py-2 tw-flex tw-flex-nowrap tw-gap-2 tw-overflow-x-auto" id="chatQuickActions">
                    @foreach($quickActions as $action)
                        @if(($action['type'] ?? '') === 'link')
                            <a href="{{ $action['url'] }}" class="ui-focus-ring tw-inline-flex tw-h-7 tw-shrink-0 tw-items-center tw-gap-1 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-px-2.5 tw-text-ui-xs tw-font-medium tw-text-on-surface tw-no-underline hover:tw-bg-surface-low">
                                <x-ui.icon :name="$action['icon'] ?? 'arrow-right'" />{{ $action['label'] }}
                            </a>
                        @else
                            <button type="button"
                                class="ui-focus-ring tw-inline-flex tw-h-7 tw-shrink-0 tw-items-center tw-gap-1 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-px-2.5 tw-text-ui-xs tw-font-medium tw-text-on-surface hover:tw-bg-surface-low"
                                data-chat-action="{{ $action['key'] }}"
                                data-chat-action-label="{{ $action['label'] }}"
                                data-chat-action-note="{{ !empty($action['requires_note']) ? '1' : '0' }}"
                                data-chat-action-type="{{ $action['type'] ?? 'prompt' }}">
                                <x-ui.icon :name="$action['icon'] ?? 'zap'" />{{ $action['label'] }}
                            </button>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    @endisset

    @php
        $partner = $conversation->getPartner(auth()->id());
        $partnerName = $partner->role === 'supplier'
            ? ($partner->supplier->company_name ?? $partner->name)
            : $partner->name;
    @endphp

    <div class="chat-fullpage-card">
        {{-- Chat Header --}}
        <div class="tw-flex tw-items-center tw-justify-between tw-gap-3 tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-4 tw-py-2.5">
            <div class="tw-flex tw-items-center tw-gap-3 tw-min-w-0">
                <x-ui.avatar :name="$partnerName" size="sm" />
                <div class="tw-min-w-0">
                    <div class="tw-text-ui-sm tw-font-semibold tw-truncate">{{ $partnerName }}</div>
                    <div class="tw-text-ui-xs tw-text-on-surface-variant">{{ ucfirst($partner->role) }}</div>
                </div>
            </div>
            <div class="tw-flex tw-items-center tw-gap-2 tw-shrink-0">
                @if($conversation->conversable_type === 'App\Models\PurchaseRequisition')
                    <x-ui.status-chip tone="info" size="sm">PR</x-ui.status-chip>
                @else
                    <x-ui.status-chip tone="success" size="sm">PO</x-ui.status-chip>
                @endif
                <span class="tw-text-ui-sm tw-font-semibold">{{ $conversation->context_label }}</span>
            </div>
        </div>

        {{-- Chat Body (Scrollable) --}}
        <div id="chat-messages" style="flex: 1; overflow-y: auto; padding: 1rem 1.25rem;">
            @forelse($conversation->messages as $msg)
                @php $isMe = $msg->sender_id === auth()->id(); @endphp
                <div class="chat-message-row {{ $isMe ? 'is-me justify-content-end' : 'is-partner justify-content-start' }}" data-message-id="{{ $msg->id }}">
                    <div class="chat-message-stack {{ $isMe ? 'align-items-end' : 'align-items-start' }}">
                        <div class="chat-message-bubble {{ $isMe ? 'is-me' : 'is-partner' }}">
                            @if($msg->body !== '')
                                <div class="chat-message-text">{{ $msg->body }}</div>
                            @endif
                            @if($msg->attachments->isNotEmpty())
                                <div class="chat-attachment-stack tw-mt-1.5">
                                    @foreach($msg->attachments as $attachment)
                                        <a href="{{ route('attachments.show', $attachment->id) }}" target="_blank" class="chat-attachment-link">
                                            <x-ui.icon name="paperclip" class="tw-mr-1 tw-shrink-0" />
                                            <span class="tw-truncate">{{ $attachment->file_name }}</span>
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
                                    <x-ui.icon name="check-check" />
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-py-12 tw-text-center" id="empty-state">
                    <x-ui.icon name="message-circle-more" class="tw-text-on-surface-variant tw-mb-2" />
                    <p class="tw-m-0 tw-text-ui-sm tw-text-on-surface-variant">Start a conversation with {{ $partnerName }}</p>
                </div>
            @endforelse
        </div>

        {{-- Chat Form --}}
        <div class="tw-border-t tw-border-outline-variant tw-px-4 tw-py-2.5">
            <form id="chat-form" data-managed-submit onsubmit="sendMessage(event)">
                @if(!empty($messageTemplates))
                    <div class="dropdown tw-mb-2">
                        <button class="ui-focus-ring tw-inline-flex tw-h-7 tw-items-center tw-gap-1 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-px-2.5 tw-text-ui-xs tw-font-medium tw-text-on-surface hover:tw-bg-surface-low" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <x-ui.icon name="zap" />Template
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
                <div class="tw-text-ui-xs tw-text-on-surface-variant tw-mb-1.5 d-none" id="message-attachments-preview"></div>
                <div class="tw-flex tw-gap-2 tw-items-end">
                    <textarea id="message-body" class="tw-flex-1 tw-rounded-ui-sm tw-border tw-border-outline-strong tw-bg-surface tw-px-3 tw-py-2 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary tw-resize-none" rows="2" placeholder="Type a message... (Enter to send, Shift+Enter for new line)" aria-label="Message"></textarea>
                    <label for="message-attachments" class="ui-focus-ring tw-inline-flex tw-h-10 tw-w-10 tw-shrink-0 tw-cursor-pointer tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container" title="Attach files" aria-label="Attach files">
                        <x-ui.icon name="paperclip" />
                    </label>
                    <input type="file" id="message-attachments" class="d-none" multiple accept=".jpg,.jpeg,.png,.pdf,.xlsx,.xls,.doc,.docx">
                    <button type="submit" class="ui-focus-ring tw-inline-flex tw-h-10 tw-w-10 tw-shrink-0 tw-items-center tw-justify-center tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-primary-foreground hover:tw-brightness-95" id="btn-send" aria-label="Send message">
                        <x-ui.icon name="send" />
                    </button>
                </div>
            </form>
        </div>
    </div>
    </div>
@endsection

@push('scripts')
<script>
    const conversationId = @json($conversation->getRouteKey());
    const myId = {{ auth()->id() }};
    const chatContainer = document.getElementById('chat-messages');
    const emptyState = document.getElementById('empty-state');
    const attachmentInput = document.getElementById('message-attachments');
    const attachmentPreview = document.getElementById('message-attachments-preview');
    const sendButton = document.getElementById('btn-send');
    const sendButtonDefaultHtml = sendButton.innerHTML;
    const sendButtonDefaultDisabled = sendButton.disabled;
    const quickActionUrl = `{{ route('conversations.quick-action', $conversation) }}`;
    let lastMessageId = {{ $conversation->messages->last()->id ?? 0 }};
    let isSending = false;

    function setSendButtonLoading(loading) {
        sendButton.disabled = loading || sendButtonDefaultDisabled;

        if (loading) {
            sendButton.setAttribute('aria-busy', 'true');
            sendButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span><span class="tw-sr-only">Sending message</span>';
            return;
        }

        sendButton.removeAttribute('aria-busy');
        sendButton.innerHTML = sendButtonDefaultHtml;
    }

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
            <span class="tw-inline-flex tw-items-center tw-gap-1 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface-low tw-px-2 tw-py-0.5 tw-text-ui-xs tw-mr-1 tw-mb-1">
                <x-ui.icon name="paperclip" />${escapeHtml(file.name)}
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
            <x-ui.icon name="check-check" />
        </span>`;
    }

    function attachmentHtml(attachments, isMe) {
        if (!attachments || attachments.length === 0) return '';

        return `<div class="chat-attachment-stack tw-mt-1.5">
            ${attachments.map((attachment) => `
                <a href="${escapeHtml(attachment.url)}" target="_blank" class="chat-attachment-link">
                    <x-ui.icon name="paperclip" class="tw-mr-1 tw-shrink-0" /><span class="tw-truncate">${escapeHtml(attachment.name)}</span>
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
        setSendButtonLoading(true);
        const payload = new FormData();
        payload.append('body', body);
        files.forEach((file) => payload.append('attachments[]', file));

        fetch(`{{ route('conversations.messages.store', $conversation) }}`, {
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
            setSendButtonLoading(false);
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
                    <div class="chat-message-bubble ${bubbleClass}">
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

                        AdasiToast.show({
                            type: 'success',
                            title: 'Success',
                            message: `${label} processed successfully.`,
                            autoClose: 1400
                        });
                    })
                    .catch((error) => AdasiToast.show({
                        type: 'error',
                        title: 'Action Failed',
                        message: error.message || 'The action cannot be processed yet.',
                        autoClose: 4000
                    }))
                    .finally(() => {
                        button.disabled = false;
                        button.innerHTML = originalHtml;
                    });
            };

            if (requiresNote || actionType === 'prompt') {
                AdasiAlert.prompt({
                    title: label,
                    inputLabel: requiresNote ? 'Notes are required' : 'Additional notes',
                    placeholder: 'Write a note for the supplier...',
                    maxLength: 1000,
                    required: requiresNote,
                    requiredMessage: 'Notes are required.',
                    confirmText: 'Send',
                    cancelText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        execute(String(result.value || '').trim());
                    }
                });
                return;
            }

            AdasiAlert.confirm({
                title: label,
                text: 'Continue with this action?',
                confirmText: 'Yes, continue',
                cancelText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) execute();
            });
        });
    });

    // Polling new messages
    setInterval(() => {
        fetch(`{{ route('conversations.messages.latest', $conversation) }}?after=${lastMessageId}`, {
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
