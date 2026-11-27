import { isSameOriginUrl } from './workspace-shell-core.js';

const PANEL_ID = 'adasi-smart-panel';
const VALID_TONES = new Set(['neutral', 'info', 'success', 'warning', 'error']);
const PREVIEW_LOADER_FALLBACK_MS = 8000;

export function normalizeTone(tone) {
    const raw = String(tone || '').trim().toLowerCase();
    if (raw === 'primary') return 'info';
    if (raw === 'danger') return 'error';
    return VALID_TONES.has(raw) ? raw : 'neutral';
}

export function setElementHidden(el, hidden) {
    if (!el) return;
    el.hidden = Boolean(hidden);
    el.classList?.toggle?.('tw:hidden', Boolean(hidden));
}

export function buildPreviewFrameSrc(doc) {
    if (!doc || !doc.url) return '';

    const rawUrl = String(doc.url);
    const filename = String(doc.filename || '').toLowerCase();
    const looksPdf = filename.endsWith('.pdf') || rawUrl.includes('/pdf/');

    if (looksPdf && !rawUrl.includes('#')) {
        return `${rawUrl}#navpanes=0&view=FitH`;
    }

    return rawUrl;
}

function initSmartPanel() {
    const panel = document.getElementById(PANEL_ID);
    if (!panel) return;

    const dialogEl = document.getElementById('smart-panel-dialog');
    const closeBtn = panel.querySelector('[data-smart-panel-close][aria-label]');
    const tablistEl = panel.querySelector('[role="tablist"]');
    const titleEl = document.getElementById('smart-panel-title');
    const subtitleEl = document.getElementById('smart-panel-subtitle');
    const statusEl = document.getElementById('smart-panel-status');
    const loadingEl = document.getElementById('smart-panel-loading');
    const errorEl = document.getElementById('smart-panel-error');
    const retryBtnEl = document.getElementById('smart-panel-retry');
    const chainPane = document.getElementById('smart-panel-pane-chain');
    const previewPane = document.getElementById('smart-panel-pane-preview');
    const slaPane = document.getElementById('smart-panel-pane-sla');
    const docListEl = document.getElementById('smart-panel-doc-list');
    const expandToggleEl = document.getElementById('smart-panel-expand-toggle');
    const openExternalEl = document.getElementById('smart-panel-open-external');
    const frameWrapEl = document.getElementById('smart-panel-preview-frame-wrap');
    const previewLoadingEl = document.getElementById('smart-panel-preview-loading');
    const iframeEl = document.getElementById('smart-panel-iframe');
    const noDocsEl = document.getElementById('smart-panel-no-docs');
    const tabButtons = Array.from(panel.querySelectorAll('[data-smart-panel-tab]'));

    const labels = {
        defaultTitle: panel.dataset?.defaultTitle || 'Document Chain & Context',
        defaultSubtitle: panel.dataset?.defaultSubtitle || 'Contextual Drillback',
        currentBadge: panel.dataset?.labelCurrent || 'Current',
        readOnlyHint: panel.dataset?.labelReadOnly || 'Summary only',
        openDetailLabel: panel.dataset?.labelOpenDetail || 'Open :label :reference',
        emptyChain: panel.dataset?.labelEmptyChain || 'No linked documents were found in this workflow chain yet.',
        emptySla: panel.dataset?.labelEmptySla || 'No SLA or timeline summary metrics are available for this document yet.',
    };

    let activeTab = 'chain';
    let lastTrigger = null;
    let lastRequestUrl = null;
    let activeController = null;
    let activeRequestId = 0;
    let currentDocs = [];
    let activeDocIndex = 0;
    let loadedIframeUrl = null;
    let previewLoadTimer = null;
    let isExpanded = false;

    function cloneIcon(name) {
        const tpl = panel.querySelector(`template[data-smart-panel-icon="${name}"]`);
        const iconNode = tpl?.content?.firstElementChild;
        return iconNode ? iconNode.cloneNode(true) : null;
    }

    function applyStatusChip(el, text, tone) {
        if (!el) return;
        const normalized = normalizeTone(tone);
        el.className = `ui-status-chip ui-status-chip--${normalized} tw:shrink-0`;
        el.replaceChildren();
        const icon = cloneIcon(normalized);
        if (icon) el.append(icon);
        const span = document.createElement('span');
        span.textContent = text;
        el.append(span);
    }

    function updateTabCount(tabKey, count) {
        const countEl = panel.querySelector(`[data-smart-panel-tab-count="${tabKey}"]`);
        if (!countEl) return;
        if (typeof count === 'number' && count > 0) {
            countEl.textContent = String(count);
            setElementHidden(countEl, false);
        } else {
            countEl.textContent = '';
            setElementHidden(countEl, true);
        }
    }

    function clearTabCounts() {
        ['chain', 'preview', 'sla'].forEach((key) => updateTabCount(key, 0));
    }

    function clearPreviewLoading() {
        if (previewLoadTimer) {
            clearTimeout(previewLoadTimer);
            previewLoadTimer = null;
        }
        setElementHidden(previewLoadingEl, true);
    }

    function startPreviewLoading() {
        if (!previewLoadingEl) return;
        clearPreviewLoading();
        setElementHidden(previewLoadingEl, false);
        previewLoadTimer = setTimeout(clearPreviewLoading, PREVIEW_LOADER_FALLBACK_MS);
    }

    function getFocusables() {
        if (!dialogEl) return [];
        return Array.from(
            dialogEl.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')
        ).filter((el) => el.offsetParent !== null);
    }

    function syncDialogWidth() {
        if (dialogEl) {
            const isPreview = activeTab === 'preview';
            dialogEl.classList.toggle('tw:max-w-xl', !isPreview);
            dialogEl.classList.toggle('tw:max-w-6xl', isPreview && !isExpanded);
            dialogEl.classList.toggle('tw:max-w-full', isPreview && isExpanded);
        }

        if (expandToggleEl) {
            expandToggleEl.setAttribute('aria-pressed', isExpanded ? 'true' : 'false');
            const labelEl = expandToggleEl.querySelector('[data-smart-panel-expand-text]');
            const expandIconEl = expandToggleEl.querySelector('[data-smart-panel-expand-icon]');
            const collapseIconEl = expandToggleEl.querySelector('[data-smart-panel-collapse-icon]');

            if (labelEl) {
                labelEl.textContent = isExpanded
                    ? (expandToggleEl.dataset.collapseLabel || '')
                    : (expandToggleEl.dataset.expandLabel || '');
            }
            setElementHidden(expandIconEl, isExpanded);
            setElementHidden(collapseIconEl, !isExpanded);
        }
    }

    function syncActiveDocument() {
        const doc = currentDocs[activeDocIndex] || null;

        if (docListEl) {
            Array.from(docListEl.children).forEach((btn, idx) => {
                const isSelected = idx === activeDocIndex;
                btn.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
                btn.classList.toggle('tw:border-primary', isSelected);
                btn.classList.toggle('tw:bg-primary-container', isSelected);
                btn.classList.toggle('tw:text-primary-container-foreground', isSelected);
                btn.classList.toggle('tw:border-outline-variant', !isSelected);
                btn.classList.toggle('tw:bg-surface', !isSelected);
                btn.classList.toggle('tw:text-on-surface', !isSelected);
            });
        }

        if (openExternalEl) {
            if (doc) {
                openExternalEl.href = doc.download_url || doc.url;
                setElementHidden(openExternalEl, false);
            } else {
                setElementHidden(openExternalEl, true);
                openExternalEl.removeAttribute('href');
            }
        }

        if (iframeEl && doc && activeTab === 'preview') {
            const nextSrc = buildPreviewFrameSrc(doc);
            if (loadedIframeUrl !== nextSrc) {
                loadedIframeUrl = nextSrc;
                startPreviewLoading();
                iframeEl.setAttribute('src', nextSrc);
            }
            const docTitle = doc.label && doc.filename ? `${doc.label}: ${doc.filename}` : (doc.filename || doc.label || '');
            if (docTitle) {
                iframeEl.setAttribute('title', docTitle);
            }
        }
    }

    function switchTab(tabKey) {
        activeTab = tabKey;
        tabButtons.forEach((btn) => {
            const isSelected = btn.dataset.smartPanelTab === tabKey;
            btn.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            btn.setAttribute('tabindex', isSelected ? '0' : '-1');
            btn.classList.toggle('tw:border-primary', isSelected);
            btn.classList.toggle('tw:text-primary-text', isSelected);
            btn.classList.toggle('tw:border-transparent', !isSelected);
            btn.classList.toggle('tw:text-on-surface-variant', !isSelected);
        });

        syncDialogWidth();

        setElementHidden(chainPane, tabKey !== 'chain');
        if (previewPane) {
            setElementHidden(previewPane, tabKey !== 'preview');
            previewPane.classList.toggle('tw:flex', tabKey === 'preview');
        }
        setElementHidden(slaPane, tabKey !== 'sla');

        if (tabKey === 'preview') {
            syncActiveDocument();
        }
    }

    function closePanel({ restoreFocus = true } = {}) {
        if (panel.classList.contains('tw:hidden')) return;
        if (activeController) {
            activeController.abort();
            activeController = null;
        }
        activeRequestId += 1;
        clearPreviewLoading();
        setElementHidden(panel, true);
        if (dialogEl) dialogEl.setAttribute('aria-busy', 'false');
        if (typeof window !== 'undefined' && window.AdasiOverlayModality) {
            window.AdasiOverlayModality.release(panel, { restoreFocus: false });
        } else {
            document.body.classList.remove('ui-dialog-open');
        }
        loadedIframeUrl = null;
        isExpanded = false;
        if (iframeEl) iframeEl.removeAttribute('src');
        const targetTrigger = lastTrigger;
        lastTrigger = null;
        if (
            restoreFocus
            && targetTrigger
            && !targetTrigger.hidden
            && targetTrigger.isConnected !== false
            && typeof targetTrigger.focus === 'function'
        ) {
            targetTrigger.focus();
        }
    }

    function renderEmptyBox(iconName, text) {
        const box = document.createElement('div');
        box.className = 'tw:flex tw:flex-col tw:items-center tw:justify-center tw:gap-2 tw:py-10 tw:px-4 tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface-low tw:text-center tw:text-ui-xs tw:text-on-surface-variant';
        const icon = cloneIcon(iconName);
        if (icon) box.append(icon);
        const message = document.createElement('p');
        message.className = 'tw:m-0 tw:max-w-sm';
        message.textContent = text;
        box.append(message);
        return box;
    }

    function isNavigableSameOrigin(url) {
        if (typeof url !== 'string' || url.trim() === '') return false;
        const currentOrigin = typeof window !== 'undefined' ? (window.location?.origin || '') : '';
        return currentOrigin
            ? isSameOriginUrl(url, currentOrigin)
            : (url.trim().startsWith('/') && !url.trim().startsWith('//'));
    }

    function renderChain(nodes = []) {
        if (!chainPane) return;
        chainPane.replaceChildren();

        if (!nodes.length) {
            chainPane.append(renderEmptyBox('inbox', labels.emptyChain));
            return;
        }

        const list = document.createElement('ol');
        list.className = 'ui-smart-panel-timeline';

        nodes.forEach((node) => {
            const tone = normalizeTone(node.tone);
            const isCurrent = Boolean(node.current);
            const safeChainUrl = !isCurrent && isNavigableSameOrigin(node.url);

            const item = document.createElement('li');
            item.className = 'ui-smart-panel-timeline__item' + (isCurrent ? ' is-current' : '');
            if (isCurrent) {
                item.setAttribute('aria-current', 'step');
            }

            const marker = document.createElement('span');
            marker.className = `ui-smart-panel-timeline__marker ui-smart-panel-timeline__marker--${tone}`;
            marker.setAttribute('aria-hidden', 'true');

            const card = document.createElement('div');
            card.className = 'ui-smart-panel-node'
                + (isCurrent ? ' is-current' : '')
                + (safeChainUrl ? ' is-interactive' : '');

            const top = document.createElement('div');
            top.className = 'tw:flex tw:items-start tw:justify-between tw:gap-3';

            const left = document.createElement('div');
            left.className = 'tw:min-w-0 tw:flex-1';

            const labelRow = document.createElement('div');
            labelRow.className = 'tw:flex tw:flex-wrap tw:items-center tw:gap-1.5';

            const badge = document.createElement('span');
            badge.className = 'tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant';
            badge.textContent = node.label || node.type || '';
            labelRow.append(badge);

            if (isCurrent) {
                const currentPill = document.createElement('span');
                currentPill.className = 'ui-status-chip ui-status-chip--info';
                currentPill.textContent = labels.currentBadge;
                labelRow.append(currentPill);
            } else if (!safeChainUrl) {
                const roWrap = document.createElement('span');
                roWrap.className = 'tw:inline-flex tw:items-center tw:gap-1 tw:text-ui-xs tw:text-on-surface-variant';
                const lockIcon = cloneIcon('lock');
                if (lockIcon) roWrap.append(lockIcon);
                const roText = document.createElement('span');
                roText.textContent = labels.readOnlyHint;
                roWrap.append(roText);
                labelRow.append(roWrap);
            }

            let refEl;
            if (safeChainUrl) {
                refEl = document.createElement('a');
                refEl.href = node.url;
                refEl.className = 'ui-focus-ring ui-smart-panel-node__link ui-smart-panel-node__ref tw:mt-1 tw:text-ui-sm tw:font-bold ui-tabular-nums';
                const accessibleLabel = labels.openDetailLabel
                    .replace(':label', String(node.label || node.type || ''))
                    .replace(':reference', String(node.reference || ''))
                    .trim();
                if (accessibleLabel) {
                    refEl.setAttribute('aria-label', accessibleLabel);
                }
                refEl.addEventListener('click', (event) => {
                    if (
                        event.defaultPrevented
                        || event.button !== 0
                        || event.metaKey
                        || event.ctrlKey
                        || event.shiftKey
                        || event.altKey
                        || refEl.getAttribute('target') === '_blank'
                    ) {
                        return;
                    }
                    if (typeof window === 'undefined' || !window.AdasiWorkspaceShell || typeof window.AdasiNavigateUrl !== 'function') {
                        return;
                    }
                    const origin = window.location?.origin || '';
                    if (!isSameOriginUrl(node.url, origin)) {
                        return;
                    }
                    event.preventDefault();
                    closePanel({ restoreFocus: false });
                    window.AdasiNavigateUrl(node.url);
                });
            } else {
                refEl = document.createElement('strong');
                refEl.className = 'ui-smart-panel-node__ref tw:mt-1 tw:text-ui-sm tw:font-bold tw:text-on-surface ui-tabular-nums';
            }
            refEl.textContent = node.reference || '-';

            left.append(labelRow, refEl);

            const right = document.createElement('div');
            right.className = 'tw:shrink-0 tw:flex tw:flex-col tw:items-end tw:gap-1';

            const statusBadge = document.createElement('span');
            applyStatusChip(statusBadge, node.status || '-', tone);
            right.append(statusBadge);

            const metaRow = document.createElement('div');
            metaRow.className = 'tw:flex tw:items-center tw:gap-1.5 tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums';

            if (node.date) {
                const dateSpan = document.createElement('span');
                dateSpan.textContent = node.date;
                metaRow.append(dateSpan);
            }

            if (safeChainUrl) {
                const chevron = cloneIcon('chevron');
                if (chevron) {
                    chevron.classList?.add?.('ui-smart-panel-node__chevron');
                    metaRow.append(chevron);
                }
            }

            if (metaRow.children.length > 0) {
                right.append(metaRow);
            }

            top.append(left, right);
            card.append(top);
            item.append(marker, card);
            list.append(item);
        });

        chainPane.append(list);
    }

    function renderDocuments(documents = []) {
        if (!docListEl || !frameWrapEl || !iframeEl || !noDocsEl) return;
        docListEl.replaceChildren();
        currentDocs = documents;
        activeDocIndex = 0;
        loadedIframeUrl = null;
        clearPreviewLoading();
        iframeEl.removeAttribute('src');

        if (!documents.length) {
            setElementHidden(frameWrapEl, true);
            setElementHidden(noDocsEl, false);
            syncActiveDocument();
            return;
        }

        setElementHidden(noDocsEl, true);
        setElementHidden(frameWrapEl, false);

        documents.forEach((doc, idx) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ui-focus-ring tw:inline-flex tw:items-center tw:gap-1.5 tw:min-h-[40px] tw:max-w-full tw:px-3 tw:py-1.5 tw:rounded-ui-sm tw:border tw:text-ui-xs tw:font-semibold';
            const fileIcon = cloneIcon('file');
            if (fileIcon) btn.append(fileIcon);
            const labelSpan = document.createElement('span');
            labelSpan.className = 'tw:truncate';
            labelSpan.textContent = `${doc.label}: ${doc.filename}`;
            btn.append(labelSpan);
            btn.addEventListener('click', () => {
                activeDocIndex = idx;
                syncActiveDocument();
            });
            docListEl.append(btn);
        });

        syncActiveDocument();
    }

    function renderSla(items = []) {
        if (!slaPane) return;
        slaPane.replaceChildren();

        if (!items.length) {
            slaPane.append(renderEmptyBox('clock', labels.emptySla));
            return;
        }

        const list = document.createElement('dl');
        list.className = 'ui-smart-panel-sla-list';

        items.forEach((item) => {
            const tone = normalizeTone(item.tone);
            const row = document.createElement('div');
            row.className = `ui-smart-panel-sla-card ui-smart-panel-sla-card--${tone}`;

            const label = document.createElement('dt');
            label.className = 'tw:m-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant';
            label.textContent = item.label || '';

            const val = document.createElement('dd');
            val.className = 'tw:m-0 tw:shrink-0';
            if (tone === 'neutral') {
                const strong = document.createElement('strong');
                strong.className = 'tw:text-ui-xs tw:font-semibold tw:text-on-surface ui-tabular-nums';
                strong.textContent = item.value || '-';
                val.append(strong);
            } else {
                const chip = document.createElement('span');
                applyStatusChip(chip, item.value || '-', tone);
                chip.classList?.add?.('ui-tabular-nums');
                val.append(chip);
            }

            row.append(label, val);
            list.append(row);
        });

        slaPane.append(list);
    }

    async function openPanel(url, triggerEl) {
        if (activeController) {
            activeController.abort();
            activeController = null;
        }
        const requestId = ++activeRequestId;
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        activeController = controller;

        lastRequestUrl = url;
        lastTrigger = triggerEl || null;
        isExpanded = false;
        currentDocs = [];
        activeDocIndex = 0;
        loadedIframeUrl = null;
        clearPreviewLoading();
        if (iframeEl) iframeEl.removeAttribute('src');
        setElementHidden(openExternalEl, true);
        if (titleEl) titleEl.textContent = labels.defaultTitle;
        if (subtitleEl) subtitleEl.textContent = labels.defaultSubtitle;
        setElementHidden(statusEl, true);
        clearTabCounts();
        chainPane?.replaceChildren?.();
        docListEl?.replaceChildren?.();
        slaPane?.replaceChildren?.();

        setElementHidden(panel, false);
        switchTab('chain');

        if (typeof window !== 'undefined' && window.AdasiOverlayModality) {
            window.AdasiOverlayModality.acquire(panel, { triggerEl: lastTrigger });
        } else {
            document.body.classList.add('ui-dialog-open');
        }
        if (dialogEl) dialogEl.setAttribute('aria-busy', 'true');

        if (closeBtn && typeof closeBtn.focus === 'function') {
            closeBtn.focus();
        } else if (dialogEl && typeof dialogEl.focus === 'function') {
            dialogEl.focus();
        }

        setElementHidden(loadingEl, false);
        setElementHidden(errorEl, true);
        setElementHidden(chainPane, true);
        if (previewPane) {
            setElementHidden(previewPane, true);
            previewPane.classList.remove('tw:flex');
        }
        setElementHidden(slaPane, true);

        try {
            const fetchOptions = {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            };
            if (controller) {
                fetchOptions.signal = controller.signal;
            }
            const response = await fetch(url, fetchOptions);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            if (requestId !== activeRequestId) return;

            if (titleEl) titleEl.textContent = data.title || labels.defaultTitle;
            if (subtitleEl) subtitleEl.textContent = data.subtitle || labels.defaultSubtitle;
            if (statusEl) {
                if (data.status) {
                    applyStatusChip(statusEl, data.status, data.status_tone);
                    setElementHidden(statusEl, false);
                } else {
                    setElementHidden(statusEl, true);
                }
            }

            const nodes = Array.isArray(data.nodes) ? data.nodes : [];
            const documents = Array.isArray(data.documents) ? data.documents : [];
            const slaSummary = Array.isArray(data.sla_summary) ? data.sla_summary : [];

            updateTabCount('chain', nodes.length);
            updateTabCount('preview', documents.length);
            updateTabCount('sla', slaSummary.length);

            renderChain(nodes);
            renderDocuments(documents);
            renderSla(slaSummary);

            setElementHidden(loadingEl, true);
            switchTab(activeTab);
        } catch (err) {
            if (requestId !== activeRequestId || err?.name === 'AbortError') return;
            setElementHidden(loadingEl, true);
            setElementHidden(errorEl, false);
        } finally {
            if (requestId === activeRequestId) {
                activeController = null;
                if (dialogEl) dialogEl.setAttribute('aria-busy', 'false');
            }
        }
    }

    if (iframeEl) {
        iframeEl.addEventListener('load', clearPreviewLoading);
        iframeEl.addEventListener('error', clearPreviewLoading);
    }

    if (retryBtnEl) {
        retryBtnEl.addEventListener('click', () => {
            if (lastRequestUrl) {
                openPanel(lastRequestUrl, lastTrigger);
            }
        });
    }

    tabButtons.forEach((btn) => {
        btn.addEventListener('click', () => switchTab(btn.dataset.smartPanelTab));
    });

    if (tablistEl && tabButtons.length) {
        tablistEl.addEventListener('keydown', (e) => {
            const currentIndex = tabButtons.findIndex((btn) => btn.dataset.smartPanelTab === activeTab);
            if (currentIndex === -1) return;

            const isRtl = document.dir === 'rtl';
            const nextKey = isRtl ? 'ArrowLeft' : 'ArrowRight';
            const prevKey = isRtl ? 'ArrowRight' : 'ArrowLeft';
            let targetIndex = null;

            if (e.key === nextKey) {
                targetIndex = (currentIndex + 1) % tabButtons.length;
            } else if (e.key === prevKey) {
                targetIndex = (currentIndex - 1 + tabButtons.length) % tabButtons.length;
            } else if (e.key === 'Home') {
                targetIndex = 0;
            } else if (e.key === 'End') {
                targetIndex = tabButtons.length - 1;
            }

            if (targetIndex === null) return;
            e.preventDefault();
            const targetBtn = tabButtons[targetIndex];
            switchTab(targetBtn.dataset.smartPanelTab);
            targetBtn.focus();
        });
    }

    if (expandToggleEl) {
        expandToggleEl.addEventListener('click', () => {
            isExpanded = !isExpanded;
            syncDialogWidth();
        });
    }

    panel.querySelectorAll('[data-smart-panel-close]').forEach((el) => {
        el.addEventListener('click', () => closePanel());
    });

    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
        window.addEventListener('pageshow', (event) => {
            if (event?.persisted) {
                closePanel({ restoreFocus: false });
            }
        });
    }

    document.addEventListener('keydown', (e) => {
        if (panel.classList.contains('tw:hidden')) return;

        if (e.key === 'Escape') {
            closePanel();
            return;
        }

        if (e.key === 'Tab') {
            const focusables = getFocusables();
            if (!focusables.length) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    document.addEventListener('click', (e) => {
        const trigger = e.target?.closest?.('[data-smart-panel-trigger]');
        if (!trigger) return;
        const url = trigger.dataset.smartPanelUrl;
        if (!url) return;
        e.preventDefault();
        openPanel(url, trigger);
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSmartPanel);
    } else {
        initSmartPanel();
    }
}

export { initSmartPanel };