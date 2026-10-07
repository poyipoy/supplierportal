@auth
    @if(auth()->user()->isPurchasing() || \App\Support\PortalContext::isImport(auth()->user()))
        <div class="offcanvas offcanvas-end chat-drawer" tabindex="-1" id="chatDrawer" aria-labelledby="chatDrawerTitle">
            <div class="offcanvas-header tw-flex tw-items-center tw-justify-between tw-gap-3 tw-border-b tw-border-outline-variant tw-bg-surface tw-px-4 tw-py-3">
                <div class="tw-flex tw-flex-1 tw-min-w-0 tw-items-center tw-gap-2.5">
                    <button type="button" class="ui-focus-ring tw-inline-flex tw-h-8 tw-w-8 tw-shrink-0 tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container d-none" id="chatDrawerBack" title="{{ __('common.chat.back') }}" aria-label="{{ __('common.chat.back_lower') }}">
                        <x-ui.icon name="arrow-left" />
                    </button>
                    <div class="tw-flex-1 tw-min-w-0 tw-overflow-hidden">
                        <h6 class="offcanvas-title tw-m-0 tw-truncate tw-text-ui-sm tw-font-semibold" id="chatDrawerTitle">{{ __('common.chat.title') }}</h6>
                        <span class="tw-block tw-truncate tw-text-ui-xs tw-text-on-surface-variant" id="chatDrawerSubtitle">{{ __('common.chat.list') }}</span>
                    </div>
                </div>
                <div class="tw-flex tw-shrink-0 tw-items-center tw-gap-2 ms-auto">
                    <div class="d-none align-items-center tw-shrink-0" id="chatDrawerMuteContainer">
                        <button type="button"
                            class="ui-focus-ring ui-motion tw-inline-flex tw-h-8 tw-w-8 tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container hover:tw-text-on-surface"
                            id="chatDrawerMuteToggle"
                            aria-pressed="false"
                            title="{{ __('common.chat.mute_help') }}"
                            aria-label="{{ __('common.chat.mute') }}">
                            <span class="chat-drawer-mute-icon-unmuted d-inline-flex">
                                <x-ui.icon name="bell" />
                            </span>
                            <span class="chat-drawer-mute-icon-muted d-none">
                                <x-ui.icon name="bell-off" />
                            </span>
                            <span class="tw-sr-only" id="chatDrawerMuteSrLabel">{{ __('common.chat.mute') }}</span>
                        </button>
                    </div>
                    <button type="button"
                        class="ui-focus-ring ui-motion tw-inline-flex tw-h-8 tw-w-8 tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container hover:tw-text-on-surface"
                        data-bs-dismiss="offcanvas"
                        title="{{ __('common.actions.close') }}"
                        aria-label="{{ __('common.actions.close') }}">
                        <x-ui.icon name="x" />
                    </button>
                </div>
            </div>

            <div class="offcanvas-body">
                <div class="chat-drawer-pane" id="chatDrawerListPane">
                    <div class="tw-border-b tw-border-outline-variant tw-bg-surface tw-p-3">
                        <div class="tw-relative">
                            <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-2.5 tw-pointer-events-none tw-text-on-surface-variant"><x-ui.icon name="search" /></div>
                            <input type="search" class="tw-h-9 tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-pl-8 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary" id="chatDrawerSearch" placeholder="{{ __('common.chat.search') }}">
                        </div>
                    </div>
                    <div class="chat-thread-list" id="chatDrawerList">
                        <div class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-py-8 tw-text-on-surface-variant">
                            <span class="ui-spinner" aria-hidden="true"></span>
                            <span class="tw-mt-2 tw-text-ui-xs">{{ __('common.chat.loading') }}</span>
                        </div>
                    </div>
                </div>

                <div class="chat-drawer-pane d-none" id="chatDrawerConversationPane">
                    <div class="chat-context-panel tw-border-b tw-border-outline-variant tw-bg-surface tw-p-3 d-none" id="chatDrawerContext"></div>
                    <div class="chat-action-panel tw-border-b tw-border-outline-variant tw-bg-surface-low tw-p-2 d-none" id="chatDrawerActions"></div>
                    <div class="chat-message-list p-3" id="chatDrawerMessages"></div>
                    <div class="tw-border-t tw-border-outline-variant tw-bg-surface tw-p-3">
                        <form id="chatDrawerForm" data-managed-submit>
                            <div class="chat-composer-tools d-flex align-items-center justify-content-between gap-2 mb-2">
                                <div class="dropdown d-none" id="chatDrawerTemplates">
                                    <button class="ui-focus-ring tw-inline-flex tw-h-7 tw-items-center tw-gap-1 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-px-2.5 tw-text-ui-xs tw-font-medium tw-text-on-surface hover:tw-bg-surface-low" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <x-ui.icon name="zap" /> {{ __('common.chat.template') }}
                                    </button>
                                    <div class="dropdown-menu p-2 chat-template-menu" id="chatDrawerTemplateMenu"></div>
                                </div>
                                <div class="tw-flex-1 tw-text-end tw-text-ui-xs tw-text-on-surface-variant d-none" id="chatDrawerAttachmentList"></div>
                            </div>
                            <div class="tw-flex tw-gap-2 tw-items-end">
                                <textarea class="tw-flex-1 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-px-3 tw-py-2 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary tw-resize-none" id="chatDrawerInput" rows="2" maxlength="2000" placeholder="{{ __('common.chat.type') }}" aria-label="{{ __('common.fields.message') }}"></textarea>
                                <label class="ui-focus-ring tw-inline-flex tw-h-10 tw-w-10 tw-shrink-0 tw-cursor-pointer tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container tw-mb-0" for="chatDrawerAttachments" title="{{ __('common.upload.attach') }}" aria-label="{{ __('common.upload.attach') }}">
                                    <x-ui.icon name="paperclip" />
                                </label>
                                <input type="file" class="d-none" id="chatDrawerAttachments" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.xlsx,.xls,.doc,.docx">
                                <button type="submit" class="ui-focus-ring tw-inline-flex tw-h-10 tw-w-10 tw-shrink-0 tw-items-center tw-justify-center tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-primary-foreground hover:tw-brightness-95" id="chatDrawerSend" aria-label="{{ __('common.chat.send') }}">
                                    <x-ui.icon name="send" />
                                </button>
                            </div>
                            <div class="tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('common.chat.enter_send') }}</div>
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
                        muteUrlTemplate: '{{ route('conversations.mute', ['id' => '__ID__']) }}',
                    };

                    const drawer = new bootstrap.Offcanvas(drawerEl);
                    const backButton = document.getElementById('chatDrawerBack');
                    const titleEl = document.getElementById('chatDrawerTitle');
                    const subtitleEl = document.getElementById('chatDrawerSubtitle');
                    const muteContainer = document.getElementById('chatDrawerMuteContainer');
                    const muteToggle = document.getElementById('chatDrawerMuteToggle');
                    const muteIconUnmuted = muteToggle ? muteToggle.querySelector('.chat-drawer-mute-icon-unmuted') : null;
                    const muteIconMuted = muteToggle ? muteToggle.querySelector('.chat-drawer-mute-icon-muted') : null;
                    const muteSrLabel = document.getElementById('chatDrawerMuteSrLabel');
                    let activeConversationMuted = false;
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
                    const sendButtonDefaultHtml = sendButton.innerHTML;
                    const sendButtonDefaultDisabled = sendButton.disabled;

                    let conversations = [];
                    let activeConversationId = null;
                    let lastMessageId = 0;
                    let pollTimer = null;
                    let searchTimer = null;
                    let isSending = false;
                    let activeOpenerButton = null;

                    const drafts = {};
                    const getDraftKey = (conversationId) => `adasi_chat_draft_${config.myId}_${conversationId}`;

                    const getDraft = (conversationId) => {
                        if (!conversationId) return '';
                        try {
                            const stored = sessionStorage.getItem(getDraftKey(conversationId));
                            return stored !== null ? stored : (drafts[conversationId] || '');
                        } catch {
                            return drafts[conversationId] || '';
                        }
                    };

                    const saveDraft = (conversationId, text) => {
                        if (!conversationId) return;
                        const content = text ?? '';
                        if (content.trim() === '') {
                            clearDraft(conversationId);
                            return;
                        }
                        drafts[conversationId] = content;
                        try {
                            sessionStorage.setItem(getDraftKey(conversationId), content);
                        } catch {}
                    };

                    const clearDraft = (conversationId) => {
                        if (!conversationId) return;
                        delete drafts[conversationId];
                        try {
                            sessionStorage.removeItem(getDraftKey(conversationId));
                        } catch {}
                    };

                    const setSendButtonLoading = (loading) => {
                        sendButton.disabled = loading || sendButtonDefaultDisabled;

                        if (loading) {
                            sendButton.setAttribute('aria-busy', 'true');
                            sendButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span><span class="tw-sr-only">{{ __('common.chat.sending') }}</span>';
                            return;
                        }

                        sendButton.removeAttribute('aria-busy');
                        sendButton.innerHTML = sendButtonDefaultHtml;
                    };

                    const buildUrl = (template, id) => template.replace('__ID__', id);
                    const escapeHtml = (value) => String(value ?? '')
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');

                    const chipClass = (source = '') => {
                        const value = String(source).toLowerCase();
                        const base = 'tw-inline-flex tw-items-center tw-rounded-ui-xs tw-border tw-px-2 tw-py-0.5 tw-text-ui-xs tw-font-semibold';

                        if (value.includes('danger')) return `${base} tw-border-error/40 tw-bg-error-container tw-text-error-container-foreground`;
                        if (value.includes('warning')) return `${base} tw-border-warning/40 tw-bg-warning-container tw-text-warning-container-foreground`;
                        if (value.includes('success')) return `${base} tw-border-success/40 tw-bg-success-container tw-text-success-container-foreground`;
                        if (value.includes('primary')) return `${base} tw-border-primary/40 tw-bg-primary-container tw-text-primary-container-foreground`;

                        return `${base} tw-border-outline-variant tw-bg-surface-container tw-text-on-surface-variant`;
                    };

                    const actionClass = (variant = '') => {
                        const value = String(variant).toLowerCase();
                        const base = 'ui-focus-ring tw-inline-flex tw-min-h-[var(--ui-control-height-sm)] tw-items-center tw-justify-center tw-whitespace-nowrap tw-rounded-ui-sm tw-border tw-px-2.5 tw-py-1 tw-text-ui-xs tw-font-semibold tw-no-underline';

                        if (value.includes('danger')) return `${base} tw-border-error tw-bg-transparent tw-text-error hover:tw-bg-error-container`;
                        if (value.includes('warning')) return `${base} tw-border-warning tw-bg-warning-container tw-text-warning-container-foreground hover:tw-brightness-95`;
                        if (value.includes('success')) return `${base} tw-border-transparent tw-bg-success tw-text-success-foreground hover:tw-brightness-95`;

                        return `${base} tw-border-outline tw-bg-transparent tw-text-on-surface hover:tw-bg-surface-container`;
                    };

                    const normalizeMessage = (message) => {
                        const createdAt = message.created_at ? new Date(message.created_at) : new Date();
                        const senderName = message.sender_name || (message.sender ? message.sender.name : @js(__('common.user')));

                        return {
                            id: Number(message.id),
                            senderId: Number(message.sender_id),
                            senderName,
                            body: message.body || '',
                            isMe: typeof message.is_me === 'boolean'
                                ? message.is_me
                                : Number(message.sender_id) === config.myId,
                            time: message.time || createdAt.toLocaleTimeString(window.AdasiI18n.locale === 'id' ? 'id-ID' : 'en-GB', { hour: '2-digit', minute: '2-digit' })
                        };
                    };

                    const updateMuteUI = (isMuted) => {
                        activeConversationMuted = isMuted;
                        if (!muteToggle) return;
                        muteToggle.setAttribute('aria-pressed', isMuted ? 'true' : 'false');
                        if (isMuted) {
                            muteToggle.setAttribute('aria-label', @js(__('common.chat.unmute')));
                            muteToggle.setAttribute('title', @js(__('common.chat.currently_muted')));
                            if (muteSrLabel) muteSrLabel.textContent = @js(__('common.chat.unmute'));
                            if (muteIconUnmuted) {
                                muteIconUnmuted.classList.add('d-none');
                                muteIconUnmuted.classList.remove('d-inline-flex');
                            }
                            if (muteIconMuted) {
                                muteIconMuted.classList.remove('d-none');
                                muteIconMuted.classList.add('d-inline-flex');
                            }
                            muteToggle.classList.add('tw-bg-surface-container', 'tw-text-on-surface');
                            muteToggle.classList.remove('tw-bg-transparent', 'tw-text-on-surface-variant');
                        } else {
                            muteToggle.setAttribute('aria-label', @js(__('common.chat.mute')));
                            muteToggle.setAttribute('title', @js(__('common.chat.mute_help')));
                            if (muteSrLabel) muteSrLabel.textContent = @js(__('common.chat.mute'));
                            if (muteIconUnmuted) {
                                muteIconUnmuted.classList.remove('d-none');
                                muteIconUnmuted.classList.add('d-inline-flex');
                            }
                            if (muteIconMuted) {
                                muteIconMuted.classList.add('d-none');
                                muteIconMuted.classList.remove('d-inline-flex');
                            }
                            muteToggle.classList.remove('tw-bg-surface-container', 'tw-text-on-surface');
                            muteToggle.classList.add('tw-bg-transparent', 'tw-text-on-surface-variant');
                        }
                    };

                    const setListMode = () => {
                        if (activeConversationId) {
                            saveDraft(activeConversationId, inputEl.value);
                        }
                        activeConversationId = null;
                        lastMessageId = 0;
                        clearInterval(pollTimer);
                        inputEl.value = '';
                        clearAttachments();
                        backButton.classList.add('d-none');
                        titleEl.textContent = @js(__('common.chat.title'));
                        subtitleEl.textContent = @js(__('common.chat.list'));
                        if (muteContainer) {
                            muteContainer.classList.add('d-none');
                            muteContainer.classList.remove('d-flex');
                        }
                        listPane.classList.remove('d-none');
                        conversationPane.classList.add('d-none');
                        contextEl.classList.add('d-none');
                        actionsEl.classList.add('d-none');
                        templatesEl.classList.add('d-none');
                        templateMenuEl.innerHTML = '';
                    };

                    const setConversationMode = (conversation) => {
                        backButton.classList.remove('d-none');
                        titleEl.textContent = conversation.partner_name || @js(__('common.chat.short'));
                        subtitleEl.textContent = `${conversation.context_label || '-'} · ${conversation.partner_role || ''}`;
                        if (muteContainer) {
                            muteContainer.classList.remove('d-none');
                            muteContainer.classList.add('d-flex');
                            updateMuteUI(Boolean(conversation.muted));
                        }
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
                                    <x-ui.icon name="message-square-text" size="lg" />
                                    <div class="fw-medium mt-2">{{ __('common.chat.empty_chats') }}</div>
                                    <div class="small">{{ __('common.chat.empty') }}</div>
                                </div>
                            `;
                            return;
                        }

                        listEl.innerHTML = filtered.map((conversation) => `
                            <button type="button" class="chat-thread-button" data-chat-conversation-id="${conversation.id}">
                                <div class="d-flex justify-content-between gap-2 mb-1">
                                    <div class="fw-semibold text-truncate d-flex align-items-center gap-1.5">
                                        <span>${escapeHtml(conversation.partner_name)}</span>
                                        ${conversation.muted ? `<span class="tw-inline-flex tw-items-center tw-text-on-surface-variant" title="${escapeHtml(window.AdasiI18n.t('js.chat.muted_help'))}"><x-ui.icon name="bell-off" size="xs" /></span>` : ''}
                                    </div>
                                    ${conversation.unread_count > 0 ? `<span class="tw-inline-flex tw-min-w-5 tw-items-center tw-justify-center tw-rounded-full tw-bg-error tw-px-1.5 tw-text-ui-xs tw-font-semibold tw-text-error-foreground">${conversation.unread_count}</span>` : ''}
                                </div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="${chipClass(conversation.context_type === 'PR' ? 'primary' : 'success')}">${escapeHtml(conversation.context_type)}</span>
                                    <small class="text-muted text-truncate">${escapeHtml(conversation.context_label)}</small>
                                </div>
                                <div class="d-flex flex-wrap gap-1 mb-1">
                                    <span class="${chipClass(conversation.status_badge_class)}">${escapeHtml(conversation.status_label || @js(__('common.chat.active')))}</span>
                                    ${conversation.sla ? `<span class="${chipClass(conversation.sla.class)}">${escapeHtml(conversation.sla.label || '')}</span>` : ''}
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
                                <span>{{ __('common.chat.loading') }}</span>
                            </div>
                        `;

                        const url = new URL(config.indexUrl, window.location.origin);
                        if (query) url.searchParams.set('q', query);

                        return fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
                            .then((response) => {
                                if (!response.ok) throw new Error(@js(__('common.chat.load_list_failed')));
                                return response.json();
                            })
                            .then((data) => {
                                conversations = data.conversations || [];
                                renderList();
                            })
                            .catch(() => {
                                listEl.innerHTML = `
                                    <div class="tw-m-3 tw-rounded-ui-sm tw-border-s-4 tw-border-error tw-bg-error-container tw-p-3 tw-text-ui-sm tw-text-error-container-foreground" role="alert">
                                        {{ __('common.chat.failed_list') }}
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
                                <div class="tw-min-w-0">
                                    <div class="d-flex align-items-center gap-2 tw-min-w-0">
                                        <span class="${chipClass(context.type === 'PO' ? 'success' : 'primary')}">${escapeHtml(context.type || 'DOC')}</span>
                                        <div class="fw-bold text-truncate">${escapeHtml(context.title || '-')}</div>
                                    </div>
                                    <div class="small text-muted text-truncate mt-1">${escapeHtml(context.subtitle || '')}</div>
                                </div>
                                <div class="d-flex gap-1 flex-shrink-0">
                                    <button type="button" class="ui-focus-ring tw-inline-flex tw-h-8 tw-w-8 tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-text-on-surface-variant hover:tw-bg-surface-container" data-chat-context-toggle title="{{ __('common.chat.toggle') }}" aria-label="{{ __('common.chat.toggle_context') }}">
                                        <x-ui.icon name="chevron-down" />
                                    </button>
                                    ${context.url ? `<a href="${escapeHtml(context.url)}" class="ui-focus-ring tw-inline-flex tw-h-8 tw-w-8 tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-outline tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container" title="${escapeHtml(@js(__('common.accessibility.open_details')))}" aria-label="${escapeHtml(@js(__('common.accessibility.open_context')))}"><x-ui.icon name="external-link" /></a>` : ''}
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
                                return `<a href="${escapeHtml(action.url)}" class="${actionClass(action.variant)}">
                                    ${escapeHtml(action.label)}
                                </a>`;
                            }

                            return `<button type="button" class="${actionClass(action.variant)}" data-chat-action="${escapeHtml(action.key)}" data-chat-action-label="${escapeHtml(action.label)}" data-chat-action-note="${action.requires_note ? '1' : '0'}" data-chat-action-type="${escapeHtml(action.type || 'prompt')}">
                                ${escapeHtml(action.label)}
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
                            <span class="ui-status-chip ui-status-chip--neutral me-1 mb-1">
                                <x-ui.icon name="paperclip" class="me-1" />${escapeHtml(file.name)}
                            </span>
                        `).join('');
                        attachmentListEl.classList.remove('d-none');
                    };

                    const clearAttachments = () => {
                        attachmentInput.value = '';
                        renderAttachmentList();
                    };

                    const readReceiptHtml = (message) => {
                        if (!message.is_me) return '';

                        const read = Boolean(message.is_read);
                        const title = read
                            ? window.AdasiI18n.t(message.read_at_display ? 'js.chat.read_at' : 'js.chat.read', { time: message.read_at_display || '' })
                            : window.AdasiI18n.t('js.chat.sent_unread');

                        return `<span class="chat-read-receipt ${read ? 'is-read' : ''}" data-read-receipt-id="${message.id}" title="${escapeHtml(title)}">
                            <x-ui.icon name="check-check" />
                        </span>`;
                    };

                    const renderMessageAttachments = (attachments) => {
                        if (!attachments || attachments.length === 0) return '';

                        return `<div class="chat-attachment-stack mt-2">
                            ${attachments.map((attachment) => `
                                <a href="${escapeHtml(attachment.url)}" target="_blank" class="chat-attachment-link">
                                    <x-ui.icon name="paperclip" class="me-1" />
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
                            receiptEl.setAttribute('title', window.AdasiI18n.t(
    receipt.read_at_display ? 'js.chat.read_at' : 'js.chat.read',
    { time: receipt.read_at_display || '' }
));
                        });
                    };

                    const renderMessage = (message) => {
                        const normalized = normalizeMessage(message);
                        const wrapperClass = normalized.isMe ? 'justify-content-end' : 'justify-content-start';
                        const alignClass = normalized.isMe ? 'align-items-end' : 'align-items-start';
                        const bubbleClass = normalized.isMe ? 'is-me' : 'is-partner';
                        const senderLabel = normalized.senderName;
                       
                        messagesEl.insertAdjacentHTML('beforeend', `
                            <div class="chat-message-row ${normalized.isMe ? 'is-me' : 'is-partner'} ${wrapperClass}" data-message-id="${normalized.id}">
                                <div class="chat-message-stack ${alignClass}">
                                    <div class="chat-message-bubble ${bubbleClass}">
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
                        const nextConversationId = String(conversationId);
                        const isSwitching = activeConversationId !== nextConversationId;

                        if (isSwitching) {
                            if (activeConversationId) {
                                saveDraft(activeConversationId, inputEl.value);
                            }
                            activeConversationId = nextConversationId;
                            inputEl.value = getDraft(activeConversationId);
                            clearAttachments();
                        }
                        messagesEl.innerHTML = `
                            <div class="text-center text-muted py-5">
                                <div class="spinner-border spinner-border-sm me-1"></div>
                                {{ __('common.chat.messages_loading') }}
                            </div>
                        `;

                        return fetch(buildUrl(config.showUrlTemplate, conversationId), {
                            headers: { 'Accept': 'application/json' }
                        })
                            .then((response) => {
                                if (!response.ok) throw new Error(@js(__('common.chat.open_error')));
                                return response.json();
                            })
                            .then((data) => {
                                if (activeConversationId !== nextConversationId) {
                                    return;
                                }
                                setConversationMode(data.conversation);
                                renderContext(data.context);
                                renderActions(data.quick_actions);
                                renderTemplates(data.templates);
                                messagesEl.innerHTML = '';
                                lastMessageId = 0;

                                if (!data.messages || data.messages.length === 0) {
                                    messagesEl.innerHTML = `
                                        <div class="text-center text-muted py-5" id="chatDrawerEmpty">
                                            <x-ui.icon name="message-circle-more" size="lg" />
                                            <div class="fw-medium mt-2">{{ __('common.chat.empty_messages') }}</div>
                                            <div class="small">{{ __('common.chat.start') }}</div>
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
                                if (activeConversationId !== nextConversationId) {
                                    return;
                                }
                                messagesEl.innerHTML = `
                                    <div class="tw-m-3 tw-rounded-ui-sm tw-border-s-4 tw-border-error tw-bg-error-container tw-p-3 tw-text-ui-sm tw-text-error-container-foreground" role="alert">
                                        {{ __('common.chat.failed_details') }}
                                    </div>
                                `;
                            });
                    };

                    const appendLatestMessages = () => {
                        if (!activeConversationId || document.hidden) return;
                        const currentPollingId = activeConversationId;

                        fetch(`${buildUrl(config.latestUrlTemplate, activeConversationId)}?after=${lastMessageId}`, {
                            headers: { 'Accept': 'application/json' }
                        })
                            .then((response) => response.ok ? response.json() : null)
                            .then((data) => {
                                if (!data || activeConversationId !== currentPollingId) return;
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
                        const label = button.dataset.chatActionLabel || @js(__('common.chat.action'));
                        const requiresNote = button.dataset.chatActionNote === '1';
                        const actionType = button.dataset.chatActionType || 'prompt';

                        const execute = (note = '') => {
                            button.disabled = true;
                            const originalHtml = button.innerHTML;
                            button.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>${escapeHtml(@js(__('datatables.processing')))}`;

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
                                                    : (payload.message || @js(__('common.chat.action_unavailable')));
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

                                    AdasiToast.show({
                                        type: 'success',
                                        title: @js(__('common.chat.success')),
                                        message: @js(__('common.chat.action_processed')).replace(':label', label),
                                        autoClose: 1400
                                    });
                                })
                                .catch((error) => {
                                    AdasiToast.show({
                                        type: 'error',
                                        title: @js(__('common.chat.action_failed')),
                                        message: error.message || @js(__('common.chat.action_unavailable')),
                                        autoClose: 4000
                                    });
                                })
                                .finally(() => {
                                    button.disabled = false;
                                    button.innerHTML = originalHtml;
                                });
                        };

                        if (requiresNote || actionType === 'prompt') {
                            AdasiAlert.prompt({
                                title: label,
                                inputLabel: requiresNote ? @js(__('common.chat.notes_required')) : @js(__('common.chat.additional_notes')),
                                placeholder: @js(__('common.chat.note_placeholder')),
                                maxLength: 1000,
                                required: requiresNote,
                                requiredMessage: @js(__('common.chat.notes_required_message')),
                                confirmText: window.AdasiI18n.t('js.actions.send'),
                                cancelText: window.AdasiI18n.t('js.actions.cancel')
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    execute(String(result.value || '').trim());
                                }
                            });
                            return;
                        }

                        AdasiAlert.confirm({
                            title: label,
                            text: @js(__('common.chat.continue')),
                            confirmText: window.AdasiI18n.t('js.actions.continue'),
                            cancelText: window.AdasiI18n.t('js.actions.cancel')
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
                                icon.classList.toggle('chevron-down', detail.classList.contains('d-none'));
                                icon.classList.toggle('chevron-up', !detail.classList.contains('d-none'));
                            }
                            return;
                        }

                        const templateButton = event.target.closest('[data-chat-template]');
                        if (templateButton) {
                            const template = templateButton.dataset.chatTemplate || '';
                            inputEl.value = inputEl.value
                                ? `${inputEl.value.trim()}\n${template}`
                                : template;
                            if (activeConversationId) {
                                saveDraft(activeConversationId, inputEl.value);
                            }
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

                        activeOpenerButton = submitButton;
                        if (submitButton) {
                            if (window.AdasiButton && typeof window.AdasiButton.startLoading === 'function') {
                                window.AdasiButton.startLoading(submitButton, { text: @js(__('common.chat.opening')) });
                            } else {
                                submitButton.disabled = true;
                            }
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
                                if (!response.ok) throw new Error(@js(__('common.chat.create_error')));
                                return response.json();
                            })
                            .then((data) => {
                                if (data.success && data.conversation_id) {
                                    openConversation(data.conversation_id);
                                    if (typeof updateBadges === 'function') updateBadges();
                                }
                            })
                            .catch(() => {
                                AdasiToast.show({
                                    type: 'error',
                                    title: @js(__('common.chat.open_failed')),
                                    message: @js(__('common.chat.open_help')),
                                    autoClose: 4000
                                });
                            })
                            .finally(() => {
                                if (submitButton) {
                                    if (window.AdasiButton && typeof window.AdasiButton.stopLoading === 'function') {
                                        window.AdasiButton.stopLoading(submitButton);
                                    } else {
                                        submitButton.disabled = false;
                                    }
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
                        setSendButtonLoading(true);
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
                                if (!response.ok) throw new Error(@js(__('common.chat.send_error')));
                                return response.json();
                            })
                            .then((data) => {
                                const emptyState = document.getElementById('chatDrawerEmpty');
                                if (emptyState) emptyState.remove();

                                clearDraft(activeConversationId);
                                inputEl.value = '';
                                clearAttachments();
                                renderMessage(data.message);
                                scrollMessagesToBottom();
                                loadConversations();
                            })
                            .catch(() => {
                                AdasiToast.show({
                                    type: 'error',
                                    title: @js(__('common.chat.send_failed')),
                                    message: @js(__('common.chat.send_help')),
                                    autoClose: 4000
                                });
                           })
                            .finally(() => {
                                isSending = false;
                                setSendButtonLoading(false);
                                inputEl.focus();
                            });
                    });

                    inputEl.addEventListener('input', () => {
                        if (activeConversationId) {
                            saveDraft(activeConversationId, inputEl.value);
                        }
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
                    if (muteToggle) {
                        muteToggle.addEventListener('click', async () => {
                            if (!activeConversationId) return;
                            const nextMuted = !activeConversationMuted;
                            muteToggle.disabled = true;
                            try {
                                const url = config.muteUrlTemplate.replace('__ID__', activeConversationId);
                                const method = nextMuted ? 'POST' : 'DELETE';
                                const response = await fetch(url, {
                                    method: method,
                                    headers: {
                                        'X-CSRF-TOKEN': config.csrf,
                                        'Accept': 'application/json',
                                        'Content-Type': 'application/json',
                                    },
                                });
                                if (response.ok) {
                                    const data = await response.json();
                                    const isMutedResult = Boolean(data.muted);
                                    updateMuteUI(isMutedResult);
                                    const conv = conversations.find(c => String(c.id) === String(activeConversationId));
                                    if (conv) conv.muted = isMutedResult;
                                }
                            } catch (e) {
                                console.error('Failed to toggle mute state', e);
                            } finally {
                                muteToggle.disabled = false;
                            }
                        });
                    }
                    attachmentInput.addEventListener('change', renderAttachmentList);
                    backButton.addEventListener('click', () => {
                        setListMode();
                        loadConversations();
                    });
                    drawerEl.addEventListener('hidden.bs.offcanvas', () => {
                        clearInterval(pollTimer);
                        if (activeConversationId) {
                            saveDraft(activeConversationId, inputEl.value);
                        }
                        activeConversationId = null;
                        inputEl.value = '';
                        clearAttachments();

                        if (activeOpenerButton) {
                            if (window.AdasiButton && typeof window.AdasiButton.stopLoading === 'function') {
                                window.AdasiButton.stopLoading(activeOpenerButton);
                            } else {
                                activeOpenerButton.disabled = false;
                            }
                            activeOpenerButton = null;
                        }
                    });
                })();
            </script>
        @endpush
    @endif
@endauth
