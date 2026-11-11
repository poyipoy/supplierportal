const PANEL_ID = 'adasi-smart-panel';

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
    const chainPane = document.getElementById('smart-panel-pane-chain');
    const previewPane = document.getElementById('smart-panel-pane-preview');
    const slaPane = document.getElementById('smart-panel-pane-sla');
    const docListEl = document.getElementById('smart-panel-doc-list');
    const expandToggleEl = document.getElementById('smart-panel-expand-toggle');
    const openExternalEl = document.getElementById('smart-panel-open-external');
    const frameWrapEl = document.getElementById('smart-panel-preview-frame-wrap');
    const iframeEl = document.getElementById('smart-panel-iframe');
    const noDocsEl = document.getElementById('smart-panel-no-docs');
    const tabButtons = Array.from(panel.querySelectorAll('[data-smart-panel-tab]'));

    let activeTab = 'chain';
    let lastTrigger = null;
    let currentDocs = [];
    let activeDocIndex = 0;
    let loadedIframeUrl = null;
    let isExpanded = false;

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
            if (expandIconEl) expandIconEl.classList.toggle('tw:hidden', isExpanded);
            if (collapseIconEl) collapseIconEl.classList.toggle('tw:hidden', !isExpanded);
        }
    }

    function syncActiveDocument() {
        const doc = currentDocs[activeDocIndex] || null;

        if (docListEl) {
            Array.from(docListEl.children).forEach((btn, idx) => {
                const isSelected = idx === activeDocIndex;
                btn.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
                btn.classList.toggle('tw:border-primary', isSelected);
                btn.classList.toggle('tw:bg-surface-container', isSelected);
                btn.classList.toggle('tw:text-primary', isSelected);
                btn.classList.toggle('tw:border-outline-variant', !isSelected);
                btn.classList.toggle('tw:bg-surface', !isSelected);
                btn.classList.toggle('tw:text-on-surface', !isSelected);
            });
        }

        if (openExternalEl) {
            if (doc) {
                openExternalEl.href = doc.download_url || doc.url;
                openExternalEl.classList.remove('tw:hidden');
            } else {
                openExternalEl.classList.add('tw:hidden');
                openExternalEl.removeAttribute('href');
            }
        }

        if (iframeEl && doc && activeTab === 'preview') {
            const nextSrc = buildPreviewFrameSrc(doc);
            if (loadedIframeUrl !== nextSrc) {
                loadedIframeUrl = nextSrc;
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
            btn.classList.toggle('tw:text-primary', isSelected);
            btn.classList.toggle('tw:border-transparent', !isSelected);
            btn.classList.toggle('tw:text-on-surface-variant', !isSelected);
        });

        syncDialogWidth();

        if (chainPane) chainPane.classList.toggle('tw:hidden', tabKey !== 'chain');
        if (previewPane) {
            previewPane.classList.toggle('tw:hidden', tabKey !== 'preview');
            previewPane.classList.toggle('tw:flex', tabKey === 'preview');
        }
        if (slaPane) slaPane.classList.toggle('tw:hidden', tabKey !== 'sla');

        if (tabKey === 'preview') {
            syncActiveDocument();
        }
    }

    function closePanel() {
        panel.classList.add('tw:hidden');
        document.body.classList.remove('ui-dialog-open');
        loadedIframeUrl = null;
        isExpanded = false;
        if (iframeEl) iframeEl.removeAttribute('src');
        if (lastTrigger && typeof lastTrigger.focus === 'function') {
            lastTrigger.focus();
        }
    }

    function renderChain(nodes = []) {
        if (!chainPane) return;
        chainPane.replaceChildren();

        nodes.forEach((node) => {
            const card = document.createElement('div');
            card.className = 'tw:p-3 tw:rounded-ui-sm tw:border ' + (node.current
                ? 'tw:border-primary tw:bg-surface-container'
                : 'tw:border-outline-variant tw:bg-surface');

            const top = document.createElement('div');
            top.className = 'tw:flex tw:items-center tw:justify-between tw:gap-2';

            const left = document.createElement('div');
            const badge = document.createElement('span');
            badge.className = 'tw:block tw:text-[11px] tw:font-semibold tw:uppercase tw:text-on-surface-variant';
            badge.textContent = node.label || node.type || '';

            let refEl;
            if (node.url) {
                refEl = document.createElement('a');
                refEl.href = node.url;
                refEl.className = 'ui-focus-ring tw:text-ui-sm tw:font-bold tw:text-primary tw:hover:underline ui-tabular-nums';
            } else {
                refEl = document.createElement('strong');
                refEl.className = 'tw:text-ui-sm tw:font-bold tw:text-on-surface ui-tabular-nums';
            }
            refEl.textContent = node.reference || '—';

            left.append(badge, refEl);

            const right = document.createElement('div');
            right.className = 'tw:text-end';
            const statusSpan = document.createElement('span');
            statusSpan.className = 'tw:inline-flex tw:px-2 tw:py-0.5 tw:rounded-sm tw:text-[11px] tw:font-semibold tw:bg-surface-high tw:text-on-surface';
            statusSpan.textContent = node.status || '—';
            right.append(statusSpan);

            if (node.date) {
                const dateSpan = document.createElement('span');
                dateSpan.className = 'tw:block tw:mt-1 tw:text-[11px] tw:text-on-surface-variant ui-tabular-nums';
                dateSpan.textContent = node.date;
                right.append(dateSpan);
            }

            top.append(left, right);
            card.append(top);
            chainPane.append(card);
        });
    }

    function renderDocuments(documents = []) {
        if (!docListEl || !frameWrapEl || !iframeEl || !noDocsEl) return;
        docListEl.replaceChildren();
        currentDocs = documents;
        activeDocIndex = 0;
        loadedIframeUrl = null;
        iframeEl.removeAttribute('src');

        if (!documents.length) {
            frameWrapEl.classList.add('tw:hidden');
            noDocsEl.classList.remove('tw:hidden');
            syncActiveDocument();
            return;
        }

        noDocsEl.classList.add('tw:hidden');
        frameWrapEl.classList.remove('tw:hidden');

        documents.forEach((doc, idx) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ui-focus-ring tw:min-h-[36px] tw:px-2.5 tw:py-1.5 tw:rounded-ui-sm tw:border tw:text-ui-xs tw:font-medium';
            btn.textContent = `${doc.label}: ${doc.filename}`;
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

        items.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'tw:flex tw:items-center tw:justify-between tw:p-3 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:text-ui-xs';
            const label = document.createElement('span');
            label.className = 'tw:text-on-surface-variant';
            label.textContent = item.label || '';
            const val = document.createElement('strong');
            val.className = 'tw:font-semibold tw:text-on-surface ui-tabular-nums';
            val.textContent = item.value || '—';
            row.append(label, val);
            slaPane.append(row);
        });
    }

    async function openPanel(url, triggerEl) {
        lastTrigger = triggerEl || null;
        activeTab = 'chain';
        isExpanded = false;
        currentDocs = [];
        activeDocIndex = 0;
        loadedIframeUrl = null;
        if (iframeEl) iframeEl.removeAttribute('src');
        if (openExternalEl) openExternalEl.classList.add('tw:hidden');
        panel.classList.remove('tw:hidden');
        document.body.classList.add('ui-dialog-open');
        if (dialogEl) dialogEl.setAttribute('aria-busy', 'true');
        syncDialogWidth();
        if (closeBtn && typeof closeBtn.focus === 'function') {
            closeBtn.focus();
        } else if (dialogEl && typeof dialogEl.focus === 'function') {
            dialogEl.focus();
        }
        if (loadingEl) loadingEl.classList.remove('tw:hidden');
        if (errorEl) errorEl.classList.add('tw:hidden');
        if (chainPane) chainPane.classList.add('tw:hidden');
        if (previewPane) {
            previewPane.classList.add('tw:hidden');
            previewPane.classList.remove('tw:flex');
        }
        if (slaPane) slaPane.classList.add('tw:hidden');

        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            if (titleEl) titleEl.textContent = data.title || '';
            if (subtitleEl) subtitleEl.textContent = data.subtitle || '';
            if (statusEl) statusEl.textContent = data.status || '';

            renderChain(data.nodes || []);
            renderDocuments(data.documents || []);
            renderSla(data.sla_summary || []);

            if (loadingEl) loadingEl.classList.add('tw:hidden');
            switchTab(activeTab);
        } catch {
            if (loadingEl) loadingEl.classList.add('tw:hidden');
            if (errorEl) errorEl.classList.remove('tw:hidden');
        } finally {
            if (dialogEl) dialogEl.setAttribute('aria-busy', 'false');
        }
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
        el.addEventListener('click', closePanel);
    });

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
