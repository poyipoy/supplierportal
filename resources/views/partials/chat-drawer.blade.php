@auth
    @if(in_array(auth()->user()->role, ['purchasing', 'supplier']))
        <div class="offcanvas offcanvas-end chat-drawer" tabindex="-1" id="chatDrawer" aria-labelledby="chatDrawerTitle">
            <div class="offcanvas-header border-bottom bg-white">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <button type="button" class="btn btn-sm btn-light d-none" id="chatDrawerBack" title="Kembali ke Daftar Chat">
                        <i class="bi bi-arrow-left"></i>
                    </button>
                    <div class="min-w-0">
                        <h6 class="offcanvas-title fw-bold mb-0 text-truncate" id="chatDrawerTitle">Negosiasi & Chat</h6>
                        <small class="text-muted text-truncate d-block" id="chatDrawerSubtitle">Daftar percakapan aktif</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
            </div>

            <div class="offcanvas-body">
                <div class="chat-drawer-pane" id="chatDrawerListPane">
                    <div class="p-3 bg-white border-bottom">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="chatDrawerSearch" placeholder="Cari partner, PO, atau PR">
                        </div>
                    </div>
                    <div class="chat-thread-list" id="chatDrawerList">
                        <div class="text-center text-muted py-5">
                            <div class="spinner-border spinner-border-sm me-1"></div>
                            Memuat chat...
                        </div>
                    </div>
                </div>

                <div class="chat-drawer-pane d-none" id="chatDrawerConversationPane">
                    <div class="chat-context-panel border-bottom bg-white p-3 d-none" id="chatDrawerContext"></div>
                    <div class="chat-action-panel border-bottom bg-light p-2 d-none" id="chatDrawerActions"></div>
                    <div class="chat-message-list p-3" id="chatDrawerMessages"></div>
                    <div class="bg-white border-top p-3">
                        <form id="chatDrawerForm">
                            <div class="chat-composer-tools d-flex align-items-center justify-content-between gap-2 mb-2">
                                <div class="dropdown d-none" id="chatDrawerTemplates">
                                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="bi bi-lightning-charge me-1"></i>Template
                                    </button>
                                    <div class="dropdown-menu p-2 chat-template-menu" id="chatDrawerTemplateMenu"></div>
                                </div>
                                <div class="small text-muted flex-grow-1 text-end d-none" id="chatDrawerAttachmentList"></div>
                            </div>
                            <div class="d-flex gap-2 align-items-end">
                                <textarea class="form-control" id="chatDrawerInput" rows="2" maxlength="2000" placeholder="Ketik pesan..." style="resize: none;"></textarea>
                                <label class="btn btn-outline-secondary mb-0" for="chatDrawerAttachments" title="Lampirkan file">
                                    <i class="bi bi-paperclip"></i>
                                </label>
                                <input type="file" class="d-none" id="chatDrawerAttachments" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.xlsx,.xls,.doc,.docx">
                                <button type="submit" class="btn btn-primary" id="chatDrawerSend" style="background-color: var(--adasi-blue);">
                                    <i class="bi bi-send-fill"></i>
                                </button>
                            </div>
                            <div class="form-text small">Enter untuk kirim, Shift+Enter untuk baris baru.</div>
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
                        quickActionUrlTemplate: '{{ route('conversations.quick-action', ['id' => '__ID__']) }}',
                        latestUrlTemplate: '{{ route('conversations.messages.latest', ['id' => '__ID__']) }}',
                    };

                    const drawer = new bootstrap.Offcanvas(drawerEl);
                    const backButton = document.getElementById('chatDrawerBack');
                    const titleEl = document.getElementById('chatDrawerTitle');
                    const subtitleEl = document.getElementById('chatDrawerSubtitle');
                    const listPane = document.getElementById('chatDrawerListPane');
                    const conversationPane = document.getElementById('chatDrawerConversationPane');
                    const listEl = document.getElementById('chatDrawerList');
                    const searchEl = document.getElementById('chatDrawerSearch');
                    const contextEl = document.getElementById('chatDrawerContext');
                    const actionsEl = document.getElementById('chatDrawerActions');
                    const templatesEl = document.getElementById('chatDrawerTemplates');
                    const templateMenuEl = document.getElementById('chatDrawerTemplateMenu');
                    const messagesEl = document.getElementById('chatDrawerMessages');
                    const formEl = document.getElementById('chatDrawerForm');
                    const inputEl = document.getElementById('chatDrawerInput');
                    const attachmentInput = document.getElementById('chatDrawerAttachments');
                    const attachmentListEl = document.getElementById('chatDrawerAttachmentList');
                    const sendButton = document.getElementById('chatDrawerSend');

                    let conversations = [];
                    let activeConversationId = null;
                    let lastMessageId = 0;
                    let pollTimer = null;
                    let searchTimer = null;
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
                        const senderName = message.sender_name || (message.sender ? message.sender.name : 'User');

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
                        titleEl.textContent = 'Negosiasi & Chat';
                        subtitleEl.textContent = 'Daftar percakapan aktif';
                        listPane.classList.remove('d-none');
                        conversationPane.classList.add('d-none');
                        contextEl.classList.add('d-none');
                        actionsEl.classList.add('d-none');
                        templatesEl.classList.add('d-none');
                        templateMenuEl.innerHTML = '';
                        attachmentListEl.classList.add('d-none');
                        attachmentInput.value = '';
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
                                    <div class="fw-medium mt-2">Belum ada chat</div>
                                    <div class="small">Percakapan akan muncul setelah dibuat dari PR atau PO.</div>
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
                                <div class="d-flex flex-wrap gap-1 mb-1">
                                    <span class="badge ${escapeHtml(conversation.status_badge_class || 'bg-secondary')}">${escapeHtml(conversation.status_label || 'Aktif')}</span>
                                    ${conversation.sla ? `<span class="badge ${escapeHtml(conversation.sla.class || 'bg-secondary')}">${escapeHtml(conversation.sla.label || '')}</span>` : ''}
                                </div>
                                <div class="small text-muted text-truncate">${escapeHtml(conversation.latest_preview)}</div>
                                ${conversation.latest_time ? `<div class="small text-muted mt-1">${escapeHtml(conversation.latest_time)}</div>` : ''}
                            </button>
                        `).join('');
                    };

                    const loadConversations = (query = searchEl.value.trim()) => {
                        listEl.innerHTML = `
                            <div class="text-center text-muted py-5">
                                <div class="spinner-border spinner-border-sm me-1"></div>
                                <span>Memuat chat...</span>
                            </div>
                        `;

                        const url = new URL(config.indexUrl, window.location.origin);
                        if (query) url.searchParams.set('q', query);

                        return fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
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
                                        Gagal memuat daftar chat. Coba buka kembali beberapa saat lagi.
                                    </div>
                                `;
                            });
                    };

                    const renderContext = (context) => {
                        if (!context) {
                            contextEl.classList.add('d-none');
                            contextEl.innerHTML = '';
                            return;
                        }

                        const fields = (context.fields || []).map((field) => `
                            <div class="chat-context-field">
                                <div class="text-muted">${escapeHtml(field.label)}</div>
                                <div class="fw-semibold text-truncate">${escapeHtml(field.value)}</div>
                            </div>
                        `).join('');

                        contextEl.innerHTML = `
                            <div class="chat-context-compact d-flex justify-content-between gap-2 align-items-center">
                                <div class="min-w-0">
                                    <div class="d-flex align-items-center gap-2 min-w-0">
                                        <span class="badge ${context.type === 'PO' ? 'bg-success' : 'bg-primary'}">${escapeHtml(context.type || 'DOC')}</span>
                                        <div class="fw-bold text-truncate">${escapeHtml(context.title || '-')}</div>
                                    </div>
                                    <div class="small text-muted text-truncate mt-1">${escapeHtml(context.subtitle || '')}</div>
                                </div>
                                <div class="d-flex gap-1 flex-shrink-0">
                                    <button type="button" class="btn btn-sm btn-light border" data-chat-context-toggle title="Tampilkan detail konteks">
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                    ${context.url ? `<a href="${escapeHtml(context.url)}" class="btn btn-sm btn-outline-primary" title="Buka Detail"><i class="bi bi-box-arrow-up-right"></i></a>` : ''}
                                </div>
                            </div>
                            <div class="chat-context-grid d-none mt-2" id="chatContextDetail">${fields}</div>
                        `;
                        contextEl.classList.remove('d-none');
                    };

                    const renderActions = (actions) => {
                        if (!actions || actions.length === 0) {
                            actionsEl.classList.add('d-none');
                            actionsEl.innerHTML = '';
                            return;
                        }

                        actionsEl.innerHTML = actions.map((action) => {
                            if (action.type === 'link') {
                                return `<a href="${escapeHtml(action.url)}" class="btn btn-sm btn-${escapeHtml(action.variant || 'outline-primary')}">
                                    <i class="bi ${escapeHtml(action.icon || 'bi-arrow-right')} me-1"></i>${escapeHtml(action.label)}
                                </a>`;
                            }

                            return `<button type="button" class="btn btn-sm btn-${escapeHtml(action.variant || 'outline-primary')}" data-chat-action="${escapeHtml(action.key)}" data-chat-action-label="${escapeHtml(action.label)}" data-chat-action-note="${action.requires_note ? '1' : '0'}" data-chat-action-type="${escapeHtml(action.type || 'prompt')}">
                                <i class="bi ${escapeHtml(action.icon || 'bi-lightning-charge')} me-1"></i>${escapeHtml(action.label)}
                            </button>`;
                        }).join('');
                        actionsEl.classList.remove('d-none');
                    };

                    const renderTemplates = (templates) => {
                        if (!templates || templates.length === 0) {
                            templatesEl.classList.add('d-none');
                            templateMenuEl.innerHTML = '';
                            return;
                        }

                        templateMenuEl.innerHTML = templates.map((template) => `
                            <button type="button" class="dropdown-item rounded small text-wrap" data-chat-template="${escapeHtml(template)}">
                                ${escapeHtml(template)}
                            </button>
                        `).join('');
                        templatesEl.classList.remove('d-none');
                    };

                    const renderAttachmentList = () => {
                        const files = Array.from(attachmentInput.files || []);
                        if (files.length === 0) {
                            attachmentListEl.classList.add('d-none');
                            attachmentListEl.innerHTML = '';
                            return;
                        }

                        attachmentListEl.innerHTML = files.map((file) => `
                            <span class="badge bg-light text-dark border me-1 mb-1">
                                <i class="bi bi-paperclip me-1"></i>${escapeHtml(file.name)}
                            </span>
                        `).join('');
                        attachmentListEl.classList.remove('d-none');
                    };

                    const readReceiptHtml = (message) => {
                        if (!message.is_me) return '';

                        const read = Boolean(message.is_read);
                        const title = read
                            ? `Dibaca${message.read_at_display ? ' ' + message.read_at_display : ''}`
                            : 'Terkirim, belum dibaca';

                        return `<span class="chat-read-receipt ${read ? 'is-read' : ''}" data-read-receipt-id="${message.id}" title="${escapeHtml(title)}">
                            <i class="bi bi-check2-all"></i>
                        </span>`;
                    };

                    const renderMessageAttachments = (attachments) => {
                        if (!attachments || attachments.length === 0) return '';

                        return `<div class="chat-attachment-stack mt-2">
                            ${attachments.map((attachment) => `
                                <a href="${escapeHtml(attachment.url)}" target="_blank" class="chat-attachment-link">
                                    <i class="bi bi-paperclip me-1"></i>
                                    <span class="text-truncate">${escapeHtml(attachment.name)}</span>
                                </a>
                            `).join('')}
                        </div>`;
                    };

                    const updateReadReceipts = (receipts) => {
                        (receipts || []).forEach((receipt) => {
                            const receiptEl = messagesEl.querySelector(`[data-read-receipt-id="${receipt.id}"]`);
                            if (!receiptEl) return;

                            receiptEl.classList.add('is-read');
                            receiptEl.setAttribute('title', `Dibaca${receipt.read_at_display ? ' ' + receipt.read_at_display : ''}`);
                        });
                    };

                    const renderMessage = (message) => {
                        const normalized = normalizeMessage(message);
                        const wrapperClass = normalized.isMe ? 'justify-content-end' : 'justify-content-start';
                        const alignClass = normalized.isMe ? 'align-items-end' : 'align-items-start';
                        const bubbleClass = normalized.isMe ? 'is-me' : 'is-partner';
                        const senderLabel = normalized.isMe ? 'Anda' : normalized.senderName;
                       
                        messagesEl.insertAdjacentHTML('beforeend', `
                            <div class="chat-message-row ${normalized.isMe ? 'is-me' : 'is-partner'} ${wrapperClass}" data-message-id="${normalized.id}">
                                <div class="chat-message-stack ${alignClass}">
                                    <div class="chat-message-bubble ${bubbleClass} shadow-sm">
                                        ${normalized.body ? `<div class="chat-message-text">${escapeHtml(normalized.body)}</div>` : ''}
                                        ${renderMessageAttachments(message.attachments)}
                                    </div>
                                    <div class="chat-message-meta ${normalized.isMe ? 'text-end' : 'text-start'}">
                                        ${normalized.isMe ? '' : `${escapeHtml(senderLabel)} · `}
                                        ${escapeHtml(normalized.time)}
                                        ${readReceiptHtml(message)}
                                    </div>
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
                                Memuat pesan...
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
                                renderContext(data.context);
                                renderActions(data.quick_actions);
                                renderTemplates(data.templates);
                                messagesEl.innerHTML = '';
                                lastMessageId = 0;

                                if (!data.messages || data.messages.length === 0) {
                                    messagesEl.innerHTML = `
                                        <div class="text-center text-muted py-5" id="chatDrawerEmpty">
                                            <i class="bi bi-chat-dots" style="font-size:2.2rem;"></i>
                                            <div class="fw-medium mt-2">Belum ada pesan</div>
                                            <div class="small">Mulai percakapan dari kolom di bawah.</div>
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
                                        Gagal memuat detail chat. Coba buka kembali beberapa saat lagi.
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
                                if (!data) return;
                                updateReadReceipts(data.read_receipts);
                                if (!data.messages || data.messages.length === 0) return;

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

                    const runQuickAction = (button) => {
                        if (!activeConversationId || button.disabled) return;

                        const action = button.dataset.chatAction;
                        const label = button.dataset.chatActionLabel || 'Aksi Negosiasi';
                        const requiresNote = button.dataset.chatActionNote === '1';
                        const actionType = button.dataset.chatActionType || 'prompt';

                        const execute = (note = '') => {
                            button.disabled = true;
                            const originalHtml = button.innerHTML;
                            button.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>Memproses`;

                            return fetch(buildUrl(config.quickActionUrlTemplate, activeConversationId), {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': config.csrf
                                },
                                body: JSON.stringify({ action, note })
                            })
                                .then((response) => {
                                    if (!response.ok) {
                                        return response.json()
                                            .then((payload) => {
                                                const messages = payload.errors
                                                    ? Object.values(payload.errors).flat().join('\n')
                                                    : (payload.message || 'Aksi belum bisa diproses.');
                                                throw new Error(messages);
                                            });
                                    }
                                    return response.json();
                                })
                                .then((data) => {
                                    const emptyState = document.getElementById('chatDrawerEmpty');
                                    if (emptyState) emptyState.remove();

                                    if (data.message && !messagesEl.querySelector(`[data-message-id="${data.message.id}"]`)) {
                                        renderMessage(data.message);
                                        scrollMessagesToBottom();
                                    }

                                    renderContext(data.context);
                                    renderActions(data.quick_actions);
                                    loadConversations();
                                    if (typeof updateBadges === 'function') updateBadges();

                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Berhasil',
                                        text: `${label} berhasil diproses.`,
                                        timer: 1400,
                                        showConfirmButton: false
                                    });
                                })
                                .catch((error) => {
                                    Swal.fire('Error', error.message || 'Aksi belum bisa diproses.', 'error');
                                })
                                .finally(() => {
                                    button.disabled = false;
                                    button.innerHTML = originalHtml;
                                });
                        };

                        if (requiresNote || actionType === 'prompt') {
                            Swal.fire({
                                title: label,
                                input: 'textarea',
                                inputLabel: requiresNote ? 'Catatan wajib diisi' : 'Catatan tambahan',
                                inputPlaceholder: 'Tulis catatan untuk supplier...',
                                inputAttributes: { maxlength: 1000 },
                                showCancelButton: true,
                                confirmButtonText: 'Kirim',
                                cancelButtonText: 'Batal',
                                inputValidator: (value) => {
                                    if (requiresNote && !String(value || '').trim()) {
                                        return 'Catatan wajib diisi.';
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
                            text: 'Lanjutkan aksi ini?',
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: 'Ya, lanjut',
                            cancelButtonText: 'Batal'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                execute();
                            }
                        });
                    };

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
                            return;
                        }

                        const contextToggle = event.target.closest('[data-chat-context-toggle]');
                        if (contextToggle) {
                            const detail = document.getElementById('chatContextDetail');
                            if (!detail) return;

                            detail.classList.toggle('d-none');
                            const icon = contextToggle.querySelector('i');
                            if (icon) {
                                icon.classList.toggle('bi-chevron-down', detail.classList.contains('d-none'));
                                icon.classList.toggle('bi-chevron-up', !detail.classList.contains('d-none'));
                            }
                            return;
                        }

                        const templateButton = event.target.closest('[data-chat-template]');
                        if (templateButton) {
                            const template = templateButton.dataset.chatTemplate || '';
                            inputEl.value = inputEl.value
                                ? `${inputEl.value.trim()}\n${template}`
                                : template;
                            inputEl.focus();
                            return;
                        }

                        const actionButton = event.target.closest('[data-chat-action]');
                        if (actionButton) {
                            runQuickAction(actionButton);
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
                            submitButton.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>Membuka...`;
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
                                Swal.fire('Error', 'Chat belum bisa dibuka. Coba beberapa saat lagi.', 'error');
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
                        const files = Array.from(attachmentInput.files || []);
                        if (!body && files.length === 0) return;

                        isSending = true;
                        sendButton.disabled = true;
                        const payload = new FormData();
                        payload.append('body', body);
                        files.forEach((file) => payload.append('attachments[]', file));

                        fetch(buildUrl(config.storeUrlTemplate, activeConversationId), {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': config.csrf
                            },
                            body: payload
                        })
                            .then((response) => {
                                if (!response.ok) throw new Error('Gagal mengirim pesan.');
                                return response.json();
                            })
                            .then((data) => {
                                const emptyState = document.getElementById('chatDrawerEmpty');
                                if (emptyState) emptyState.remove();

                                inputEl.value = '';
                                attachmentInput.value = '';
                                renderAttachmentList();
                                renderMessage(data.message);
                                scrollMessagesToBottom();
                                loadConversations();
                            })
                            .catch(() => {
                                Swal.fire('Error', 'Pesan belum terkirim. Coba lagi.', 'error');
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

                    searchEl.addEventListener('input', () => {
                        clearTimeout(searchTimer);
                        searchTimer = setTimeout(() => loadConversations(searchEl.value.trim()), 300);
                    });
                    attachmentInput.addEventListener('change', renderAttachmentList);
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
