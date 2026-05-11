@auth
    @if(in_array(auth()->user()->role, ['purchasing', 'supplier']))
        <div class="offcanvas offcanvas-end chat-drawer" tabindex="-1" id="chatDrawer" aria-labelledby="chatDrawerTitle">
            <div class="offcanvas-header border-bottom bg-white">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <button type="button" class="btn btn-sm btn-light d-none" id="chatDrawerBack" title="{{ __('Kembali ke Daftar Chat') }}">
                        <i class="bi bi-arrow-left"></i>
                    </button>
                    <div class="min-w-0">
                        <h6 class="offcanvas-title fw-bold mb-0 text-truncate" id="chatDrawerTitle">{{ __('Negosiasi & Chat') }}</h6>
                        <small class="text-muted text-truncate d-block" id="chatDrawerSubtitle">{{ __('Daftar percakapan aktif') }}</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
            </div>

            <div class="offcanvas-body">
                <div class="chat-drawer-pane" id="chatDrawerListPane">
                    <div class="p-3 bg-white border-bottom">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="chatDrawerSearch" placeholder="{{ __('Cari partner, PO, atau PR') }}">
                        </div>
                    </div>
                    <div class="chat-thread-list" id="chatDrawerList">
                        <div class="text-center text-muted py-5">
                            <div class="spinner-border spinner-border-sm me-1"></div>
                            {{ __('Memuat chat...') }}
                        </div>
                    </div>
                </div>

                <div class="chat-drawer-pane d-none" id="chatDrawerConversationPane">
                    <div class="chat-message-list p-3" id="chatDrawerMessages"></div>
                    <div class="bg-white border-top p-3">
                        <form id="chatDrawerForm">
                            <div class="d-flex gap-2 align-items-end">
                                <textarea class="form-control" id="chatDrawerInput" rows="2" maxlength="2000" placeholder="{{ __('Ketik pesan...') }}" required style="resize: none;"></textarea>
                                <button type="submit" class="btn btn-primary" id="chatDrawerSend" style="background-color: var(--adasi-blue);">
                                    <i class="bi bi-send-fill"></i>
                                </button>
                            </div>
                            <div class="form-text small">{{ __('Enter untuk kirim, Shift+Enter untuk baris baru.') }}</div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        @push('scripts')
            <script>
                (() => {
                    const drawerEl = document.getElementById('chatDrawer');
                    if (!drawerEl) return;

                    const config = {
                        myId: {{ auth()->id() }},
                        csrf: '{{ csrf_token() }}',
                        indexUrl: '{{ route('conversations.drawer.index') }}',
                        showUrlTemplate: '{{ route('conversations.drawer.show', ['id' => '__ID__']) }}',
                        storeUrlTemplate: '{{ route('conversations.messages.store', ['id' => '__ID__']) }}',
                        latestUrlTemplate: '{{ route('conversations.messages.latest', ['id' => '__ID__']) }}',
                        text: {
                            title: @json(__('Negosiasi & Chat')),
                            subtitle: @json(__('Daftar percakapan aktif')),
                            loadingChat: @json(__('Memuat chat...')),
                            loadingMessages: @json(__('Memuat pesan...')),
                            noChats: @json(__('Belum ada chat')),
                            noChatsHelp: @json(__('Percakapan akan muncul setelah dibuat dari PR atau PO.')),
                            noMessages: @json(__('Belum ada pesan')),
                            noMessagesHelp: @json(__('Mulai percakapan dari kolom di bawah.')),
                            you: @json(__('Anda')),
                            user: @json(__('User')),
                            opening: @json(__('Membuka...')),
                            error: @json(__('Error')),
                            openChatError: @json(__('Chat belum bisa dibuka. Coba beberapa saat lagi.')),
                            sendError: @json(__('Pesan belum terkirim. Coba lagi.')),
                            listError: @json(__('Gagal memuat daftar chat. Coba buka kembali beberapa saat lagi.')),
                            detailError: @json(__('Gagal membuka chat. Pastikan Anda masih memiliki akses ke percakapan ini.'))
                        }
                    };

                    const drawer = new bootstrap.Offcanvas(drawerEl);
                    const backButton = document.getElementById('chatDrawerBack');
                    const titleEl = document.getElementById('chatDrawerTitle');
                    const subtitleEl = document.getElementById('chatDrawerSubtitle');
                    const listPane = document.getElementById('chatDrawerListPane');
                    const conversationPane = document.getElementById('chatDrawerConversationPane');
                    const listEl = document.getElementById('chatDrawerList');
                    const searchEl = document.getElementById('chatDrawerSearch');
                    const messagesEl = document.getElementById('chatDrawerMessages');
                    const formEl = document.getElementById('chatDrawerForm');
                    const inputEl = document.getElementById('chatDrawerInput');
                    const sendButton = document.getElementById('chatDrawerSend');

                    let conversations = [];
                    let activeConversationId = null;
                    let lastMessageId = 0;
                    let pollTimer = null;
                    let isSending = false;

                    const buildUrl = (template, id) => template.replace('__ID__', id);
                    const escapeHtml = (value) => String(value ?? '')
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');

                    const normalizeMessage = (message) => {
                        const createdAt = message.created_at ? new Date(message.created_at) : new Date();
                        const senderName = message.sender_name || (message.sender ? message.sender.name : config.text.user);

                        return {
                            id: Number(message.id),
                            senderId: Number(message.sender_id),
                            senderName,
                            body: message.body || '',
                            isMe: typeof message.is_me === 'boolean'
                                ? message.is_me
                                : Number(message.sender_id) === config.myId,
                            time: message.time || createdAt.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
                        };
                    };

                    const setListMode = () => {
                        activeConversationId = null;
                        lastMessageId = 0;
                        clearInterval(pollTimer);
                        backButton.classList.add('d-none');
                        titleEl.textContent = config.text.title;
                        subtitleEl.textContent = config.text.subtitle;
                        listPane.classList.remove('d-none');
                        conversationPane.classList.add('d-none');
                    };

                    const setConversationMode = (conversation) => {
                        backButton.classList.remove('d-none');
                        titleEl.textContent = conversation.partner_name || 'Chat';
                        subtitleEl.textContent = `${conversation.context_label || '-'} · ${conversation.partner_role || ''}`;
                        listPane.classList.add('d-none');
                        conversationPane.classList.remove('d-none');
                    };

                    const renderList = () => {
                        const keyword = searchEl.value.trim().toLowerCase();
                        const filtered = conversations.filter((conversation) => {
                            const haystack = [
                                conversation.partner_name,
                                conversation.context_label,
                                conversation.latest_preview
                            ].join(' ').toLowerCase();
                            return haystack.includes(keyword);
                        });

                        if (filtered.length === 0) {
                            listEl.innerHTML = `
                                <div class="text-center text-muted py-5 px-4">
                                    <i class="bi bi-chat-square-text" style="font-size:2.2rem;"></i>
                                    <div class="fw-medium mt-2">${escapeHtml(config.text.noChats)}</div>
                                    <div class="small">${escapeHtml(config.text.noChatsHelp)}</div>
                                </div>
                            `;
                            return;
                        }

                        listEl.innerHTML = filtered.map((conversation) => `
                            <button type="button" class="chat-thread-button" data-chat-conversation-id="${conversation.id}">
                                <div class="d-flex justify-content-between gap-2 mb-1">
                                    <div class="fw-semibold text-truncate">${escapeHtml(conversation.partner_name)}</div>
                                    ${conversation.unread_count > 0 ? `<span class="badge bg-danger rounded-pill">${conversation.unread_count}</span>` : ''}
                                </div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="badge ${conversation.context_type === 'PR' ? 'bg-primary' : 'bg-success'}">${escapeHtml(conversation.context_type)}</span>
                                    <small class="text-muted text-truncate">${escapeHtml(conversation.context_label)}</small>
                                </div>
                                <div class="small text-muted text-truncate">${escapeHtml(conversation.latest_preview)}</div>
                                ${conversation.latest_time ? `<div class="small text-muted mt-1">${escapeHtml(conversation.latest_time)}</div>` : ''}
                            </button>
                        `).join('');
                    };

                    const loadConversations = () => {
                        listEl.innerHTML = `
                            <div class="text-center text-muted py-5">
                                <div class="spinner-border spinner-border-sm me-1"></div>
                                ${escapeHtml(config.text.loadingChat)}
                            </div>
                        `;

                        return fetch(config.indexUrl, { headers: { 'Accept': 'application/json' } })
                            .then((response) => {
                                if (!response.ok) throw new Error('Gagal memuat daftar chat.');
                                return response.json();
                            })
                            .then((data) => {
                                conversations = data.conversations || [];
                                renderList();
                            })
                            .catch(() => {
                                listEl.innerHTML = `
                                    <div class="alert alert-danger m-3 small">
                                        ${escapeHtml(config.text.listError)}
                                    </div>
                                `;
                            });
                    };

                    const renderMessage = (message) => {
                        const normalized = normalizeMessage(message);
                        const wrapperClass = normalized.isMe ? 'justify-content-end' : 'justify-content-start';
                        const alignClass = normalized.isMe ? 'align-items-end' : 'align-items-start';
                        const bubbleClass = normalized.isMe ? 'is-me' : 'is-partner';
                        const senderLabel = normalized.isMe ? config.text.you : normalized.senderName;

                        messagesEl.insertAdjacentHTML('beforeend', `
                            <div class="d-flex ${wrapperClass} mb-3" data-message-id="${normalized.id}">
                                <div class="d-flex flex-column ${alignClass}" style="max-width: 100%;">
                                    <div class="small text-muted mb-1 px-1">${escapeHtml(senderLabel)} · ${escapeHtml(normalized.time)}</div>
                                    <div class="chat-message-bubble ${bubbleClass} shadow-sm">${escapeHtml(normalized.body)}</div>
                                </div>
                            </div>
                        `);
                        lastMessageId = Math.max(lastMessageId, normalized.id);
                    };

                    const scrollMessagesToBottom = () => {
                        messagesEl.scrollTop = messagesEl.scrollHeight;
                    };

                    const loadConversation = (conversationId) => {
                        activeConversationId = Number(conversationId);
                        messagesEl.innerHTML = `
                            <div class="text-center text-muted py-5">
                                <div class="spinner-border spinner-border-sm me-1"></div>
                                ${escapeHtml(config.text.loadingMessages)}
                            </div>
                        `;

                        return fetch(buildUrl(config.showUrlTemplate, conversationId), {
                            headers: { 'Accept': 'application/json' }
                        })
                            .then((response) => {
                                if (!response.ok) throw new Error('Gagal membuka chat.');
                                return response.json();
                            })
                            .then((data) => {
                                setConversationMode(data.conversation);
                                messagesEl.innerHTML = '';
                                lastMessageId = 0;

                                if (!data.messages || data.messages.length === 0) {
                                    messagesEl.innerHTML = `
                                        <div class="text-center text-muted py-5" id="chatDrawerEmpty">
                                            <i class="bi bi-chat-dots" style="font-size:2.2rem;"></i>
                                            <div class="fw-medium mt-2">${escapeHtml(config.text.noMessages)}</div>
                                            <div class="small">${escapeHtml(config.text.noMessagesHelp)}</div>
                                        </div>
                                    `;
                                } else {
                                    data.messages.forEach(renderMessage);
                                    scrollMessagesToBottom();
                                }

                                inputEl.focus();
                                startPolling();
                                if (typeof updateBadges === 'function') updateBadges();
                            })
                            .catch(() => {
                                messagesEl.innerHTML = `
                                    <div class="alert alert-danger m-3 small">
                                        ${escapeHtml(config.text.detailError)}
                                    </div>
                                `;
                            });
                    };

                    const appendLatestMessages = () => {
                        if (!activeConversationId || document.hidden) return;

                        fetch(`${buildUrl(config.latestUrlTemplate, activeConversationId)}?after=${lastMessageId}`, {
                            headers: { 'Accept': 'application/json' }
                        })
                            .then((response) => response.ok ? response.json() : null)
                            .then((data) => {
                                if (!data || !data.messages || data.messages.length === 0) return;

                                const emptyState = document.getElementById('chatDrawerEmpty');
                                if (emptyState) emptyState.remove();

                                data.messages.forEach((message) => {
                                    if (!messagesEl.querySelector(`[data-message-id="${message.id}"]`)) {
                                        renderMessage(message);
                                    }
                                });
                                scrollMessagesToBottom();
                                if (typeof updateBadges === 'function') updateBadges();
                            });
                    };

                    const startPolling = () => {
                        clearInterval(pollTimer);
                        pollTimer = setInterval(appendLatestMessages, 8000);
                    };

                    const openList = () => {
                        setListMode();
                        drawer.show();
                        loadConversations();
                    };

                    const openConversation = (conversationId) => {
                        drawer.show();
                        loadConversation(conversationId);
                    };

                    window.openChatDrawer = openList;
                    window.openChatConversation = openConversation;

                    document.addEventListener('click', (event) => {
                        const openListButton = event.target.closest('[data-chat-drawer]');
                        if (openListButton) {
                            event.preventDefault();
                            openList();
                            return;
                        }

                        const openConversationButton = event.target.closest('[data-open-chat-conversation]');
                        if (openConversationButton) {
                            event.preventDefault();
                            openConversation(openConversationButton.dataset.openChatConversation);
                            return;
                        }

                        const threadButton = event.target.closest('[data-chat-conversation-id]');
                        if (threadButton) {
                            openConversation(threadButton.dataset.chatConversationId);
                        }
                    });

                    document.addEventListener('submit', (event) => {
                        const form = event.target.closest('[data-chat-start-form]');
                        if (!form) return;

                        event.preventDefault();
                        const submitButton = form.querySelector('button[type="submit"]');
                        const originalHtml = submitButton ? submitButton.innerHTML : '';

                        if (submitButton) {
                            submitButton.disabled = true;
                            submitButton.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>${escapeHtml(config.text.opening)}`;
                        }

                        fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': config.csrf,
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: new FormData(form)
                        })
                            .then((response) => {
                                if (!response.ok) throw new Error('Gagal membuat chat.');
                                return response.json();
                            })
                            .then((data) => {
                                if (data.success && data.conversation_id) {
                                    openConversation(data.conversation_id);
                                    if (typeof updateBadges === 'function') updateBadges();
                                }
                            })
                            .catch(() => {
                                Swal.fire(config.text.error, config.text.openChatError, 'error');
                            })
                            .finally(() => {
                                if (submitButton) {
                                    submitButton.disabled = false;
                                    submitButton.innerHTML = originalHtml;
                                }
                            });
                    });

                    formEl.addEventListener('submit', (event) => {
                        event.preventDefault();
                        if (!activeConversationId || isSending) return;

                        const body = inputEl.value.trim();
                        if (!body) return;

                        isSending = true;
                        sendButton.disabled = true;

                        fetch(buildUrl(config.storeUrlTemplate, activeConversationId), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': config.csrf
                            },
                            body: JSON.stringify({ body })
                        })
                            .then((response) => {
                                if (!response.ok) throw new Error('Gagal mengirim pesan.');
                                return response.json();
                            })
                            .then((data) => {
                                const emptyState = document.getElementById('chatDrawerEmpty');
                                if (emptyState) emptyState.remove();

                                inputEl.value = '';
                                renderMessage(data.message);
                                scrollMessagesToBottom();
                                loadConversations();
                            })
                            .catch(() => {
                                Swal.fire(config.text.error, config.text.sendError, 'error');
                            })
                            .finally(() => {
                                isSending = false;
                                sendButton.disabled = false;
                                inputEl.focus();
                            });
                    });

                    inputEl.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter' && !event.shiftKey) {
                            event.preventDefault();
                            formEl.requestSubmit();
                        }
                    });

                    searchEl.addEventListener('input', renderList);
                    backButton.addEventListener('click', () => {
                        setListMode();
                        loadConversations();
                    });
                    drawerEl.addEventListener('hidden.bs.offcanvas', () => {
                        clearInterval(pollTimer);
                        activeConversationId = null;
                    });
                })();
            </script>
        @endpush
    @endif
@endauth
