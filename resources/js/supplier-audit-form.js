/**
 * Supplier Audit — form pengisian satu halaman (U1–U9).
 *
 * - Autosave: perubahan diantrekan per kriteria, dikirim (debounce) ke endpoint PATCH sebagai draft.
 *   Hanya satu request berjalan; perubahan baru selama request digabung ke kiriman berikutnya.
 * - Submit tetap lewat form (data-async-submit) dengan seluruh jawaban, setelah antrean kosong.
 * - Navigasi bagian dengan scroll-spy, pintasan keyboard per baris, lompat ke yang belum diisi.
 */
const DEBOUNCE_MS = 800;
const RETRY_DELAYS = [2000, 5000, 15000];

export function supplierAuditForm(config) {
    return {
        answers: config.answers,
        sections: config.sections,
        labels: config.labels,
        autosaveUrl: config.autosaveUrl,
        pending: {},
        inflight: false,
        timer: null,
        retryIndex: 0,
        saveState: 'idle', // idle | saving | saved | failed | expired
        savedLabel: config.savedLabel || '',
        serverErrors: {},
        showIncomplete: false,
        activeSection: config.sections[0]?.code ?? null,
        submitting: false,

        init() {
            this.observeSections();
            this.$watch('activeSection', (code) => this.revealNavItem(code));
            window.addEventListener('beforeunload', (event) => {
                if (!this.submitting && this.hasUnsaved()) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
        },

        // ─── Status & hitungan ───
        isComplete(id) {
            const row = this.answers[id];
            return Boolean(row) && (row.answer === 'NO' || (row.answer === 'YES' && row.score !== null && row.score !== '' && row.score !== undefined));
        },
        get ids() { return Object.keys(this.answers); },
        get total() { return this.ids.length; },
        get filled() { return this.ids.filter((id) => this.isComplete(id)).length; },
        get percent() { return this.total ? Math.round((this.filled / this.total) * 100) : 0; },
        get yesCount() { return this.ids.filter((id) => this.answers[id].answer === 'YES').length; },
        get noCount() { return this.ids.filter((id) => this.answers[id].answer === 'NO').length; },
        get progressLabel() { return this.labels.progress.replace(':filled', this.filled).replace(':total', this.total); },
        sectionFilled(section) { return section.ids.filter((id) => this.isComplete(id)).length; },
        sectionState(section) {
            if (section.ids.some((id) => this.rowFlagged(id))) return 'error';
            const filled = this.sectionFilled(section);
            if (filled === section.ids.length) return 'done';
            return filled > 0 ? 'partial' : 'empty';
        },
        sectionStateLabel(section) { return this.labels.sectionStates[this.sectionState(section)]; },
        get activeSectionIndex() { return Math.max(0, this.sections.findIndex((s) => s.code === this.activeSection)); },
        get sectionPickerLabel() {
            const section = this.sections[this.activeSectionIndex];
            return this.labels.sectionPicker.replace(':current', this.activeSectionIndex + 1).replace(':total', this.sections.length)
                + (section ? ` · ${section.title}` : '');
        },
        rowError(id) { return this.serverErrors[id] || ''; },
        rowFlagged(id) { return this.rowError(id) !== '' || (this.showIncomplete && !this.isComplete(id)); },

        // ─── Perubahan jawaban ───
        setAnswer(id, value) {
            if (this.answers[id].answer === value) return;
            this.answers[id].answer = value;
            if (value !== 'YES') this.answers[id].score = null;
            this.touch(id);
        },
        setScore(id, value) {
            if (this.answers[id].answer !== 'YES') return;
            this.answers[id].score = value;
            this.touch(id);
        },
        touch(id) {
            delete this.serverErrors[id];
            this.pending[id] = { answer: this.answers[id].answer, score: this.answers[id].score };
            if (this.saveState === 'expired') return;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.flush(), DEBOUNCE_MS);
        },
        hasUnsaved() { return this.inflight || Object.keys(this.pending).length > 0; },

        // ─── Autosave ───
        get saveStatusText() {
            switch (this.saveState) {
                case 'saving': return this.labels.saving;
                case 'saved': return this.labels.saved.replace(':time', this.savedLabel);
                case 'failed': return this.labels.failed;
                case 'expired': return this.labels.expired;
                default: return this.savedLabel ? this.labels.saved.replace(':time', this.savedLabel) : this.labels.idle;
            }
        },
        async flush() {
            clearTimeout(this.timer);
            if (this.inflight || this.saveState === 'expired') return;
            const batch = { ...this.pending };
            if (Object.keys(batch).length === 0) return;

            this.inflight = true;
            this.saveState = 'saving';
            let retry = false;
            try {
                const response = await fetch(this.autosaveUrl, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ action: 'draft', answers: batch }),
                });

                if (response.ok) {
                    const data = await response.json();
                    // Hapus hanya entri yang tidak berubah lagi sejak dikirim.
                    Object.entries(batch).forEach(([id, sent]) => {
                        const now = this.pending[id];
                        if (now && now.answer === sent.answer && now.score === sent.score) delete this.pending[id];
                    });
                    this.savedLabel = data.saved_label || this.savedLabel;
                    this.saveState = 'saved';
                    this.retryIndex = 0;
                } else if (response.status === 422) {
                    const data = await response.json().catch(() => ({}));
                    const mapped = { ...this.serverErrors };
                    Object.entries(data.errors || {}).forEach(([key, messages]) => {
                        const match = /^answers\.(\d+)\./.exec(key);
                        if (match) {
                            mapped[match[1]] = [].concat(messages)[0];
                            delete this.pending[match[1]];
                        }
                    });
                    this.serverErrors = mapped;
                    // Baris lain dikirim ulang; baris bermasalah menunggu diperbaiki.
                    this.saveState = 'failed';
                    retry = Object.keys(this.pending).length > 0;
                } else if (response.status === 419 || response.status === 401) {
                    this.saveState = 'expired';
                } else {
                    this.saveState = 'failed';
                    retry = true;
                }
            } catch (error) {
                this.saveState = 'failed';
                retry = true;
            } finally {
                this.inflight = false;
            }

            if (retry) {
                const delay = RETRY_DELAYS[Math.min(this.retryIndex, RETRY_DELAYS.length - 1)];
                this.retryIndex += 1;
                this.timer = setTimeout(() => this.flush(), delay);
            } else if (this.saveState === 'saved' && Object.keys(this.pending).length > 0) {
                this.flush();
            }
        },
        retryNow() {
            this.retryIndex = 0;
            this.flush();
        },
        async waitForSaves() {
            for (let attempt = 0; attempt < 20 && this.hasUnsaved() && this.saveState !== 'expired'; attempt += 1) {
                if (!this.inflight) await this.flush();
                if (this.hasUnsaved()) await new Promise((resolve) => setTimeout(resolve, 250));
            }
        },

        // ─── Navigasi ───
        observeSections() {
            if (!('IntersectionObserver' in window)) return;
            const observer = new IntersectionObserver((entries) => {
                const visible = entries.filter((entry) => entry.isIntersecting)
                    .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
                if (visible[0]) this.activeSection = visible[0].target.dataset.sectionCode;
            }, { rootMargin: '-96px 0px -60% 0px' });
            this.$root.querySelectorAll('[data-section-code]').forEach((el) => observer.observe(el));
        },
        // Keeps the active entry visible inside the nav list without moving the page itself.
        revealNavItem(code) {
            const list = this.$root.querySelector('[data-section-nav-list]');
            const item = list?.querySelector(`[data-nav-code="${CSS.escape(String(code))}"]`);
            if (!list || !item || list.clientHeight === 0) return;
            const top = item.offsetTop - list.offsetTop;
            const bottom = top + item.offsetHeight;
            const margin = item.offsetHeight;
            if (top - margin < list.scrollTop) {
                list.scrollTo({ top: Math.max(0, top - margin), behavior: this.motionBehavior() });
            } else if (bottom + margin > list.scrollTop + list.clientHeight) {
                list.scrollTo({ top: bottom + margin - list.clientHeight, behavior: this.motionBehavior() });
            }
        },
        goToSection(code) {
            this.$dispatch('close-ui-dialog', 'supplier-audit-sections');
            const target = document.getElementById(`bagian-${code.replace(/\./g, '-')}`);
            if (!target) return;
            this.activeSection = code;
            target.scrollIntoView({ block: 'start', behavior: this.motionBehavior() });
            target.querySelector('h2')?.focus({ preventScroll: true });
        },
        focusRow(id) {
            const row = this.$root.querySelector(`[data-criterion-row="${id}"]`);
            if (!row) return;
            row.scrollIntoView({ block: 'center', behavior: this.motionBehavior() });
            row.focus({ preventScroll: true });
        },
        nextIncomplete(afterId = null) {
            const order = this.sections.flatMap((section) => section.ids);
            const start = afterId ? order.indexOf(String(afterId)) + 1 : 0;
            return [...order.slice(start), ...order.slice(0, start)].find((id) => !this.isComplete(id)) ?? null;
        },
        jumpToUnanswered() {
            const current = document.activeElement?.closest?.('[data-criterion-row]')?.dataset.criterionRow ?? null;
            const next = this.nextIncomplete(current);
            if (next) this.focusRow(next);
        },
        motionBehavior() {
            return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
        },

        // ─── Pintasan keyboard (U9) ───
        onRowKeydown(event, id) {
            if (event.ctrlKey || event.altKey || event.metaKey) return;
            const target = event.target;
            if (target instanceof HTMLTextAreaElement || (target instanceof HTMLInputElement && !['radio', 'checkbox'].includes(target.type))) return;
            const key = event.key.toLowerCase();

            if (key === 'y') { this.setAnswer(id, 'YES'); event.preventDefault(); return; }
            if (key === 't' || key === 'n') { this.setAnswer(id, 'NO'); event.preventDefault(); return; }
            if (/^[1-5]$/.test(key)) {
                if (this.answers[id].answer === 'YES') {
                    this.setScore(id, Number(key));
                    event.preventDefault();
                }
                return;
            }
            // Panah bawaan radio group tetap jalan; ArrowDown hanya dari container baris.
            if (key === 'enter' || (key === 'arrowdown' && target.hasAttribute('data-criterion-row'))) {
                const next = this.nextIncomplete(id);
                if (next && next !== String(id)) {
                    event.preventDefault();
                    this.focusRow(next);
                }
            }
        },

        // ─── Submit ───
        async submit() {
            const incomplete = this.ids.filter((id) => !this.isComplete(id));
            if (incomplete.length > 0) {
                this.showIncomplete = true;
                const template = incomplete.length === 1 ? this.labels.incompleteOne : this.labels.incompleteMany;
                window.AdasiToast?.warning(template.replace(':count', incomplete.length));
                this.focusRow(this.nextIncomplete());
                return;
            }

            await this.waitForSaves();
            if (this.saveState === 'expired') return;

            const proceed = () => {
                this.submitting = true;
                this.$refs.form.requestSubmit();
            };
            if (!window.AdasiAlert?.confirm) { proceed(); return; }
            const result = await window.AdasiAlert.confirm({
                title: this.labels.confirmTitle,
                text: this.labels.confirmText.replace(':yes', this.yesCount).replace(':no', this.noCount),
                confirmText: this.labels.confirmYes,
                cancelText: this.labels.cancel,
                confirmTone: 'primary',
            });
            if (result?.isConfirmed) proceed();
        },
        onFormErrors(event) {
            this.submitting = false;
            const mapped = {};
            Object.entries(event.detail?.errors || {}).forEach(([key, messages]) => {
                const match = /^answers\.(\d+)\./.exec(key);
                if (match) mapped[match[1]] = [].concat(messages)[0];
            });
            this.serverErrors = mapped;
            if (Object.keys(mapped).length) this.focusRow(Object.keys(mapped)[0]);
        },
    };
}
