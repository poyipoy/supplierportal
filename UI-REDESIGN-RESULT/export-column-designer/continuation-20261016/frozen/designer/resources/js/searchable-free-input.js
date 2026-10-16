// Opt-in editable combobox. Typed text is always the submitted value.
export function searchableFreeInput(config, dependencies = {}) {
    const request = dependencies.fetch ?? ((...args) => fetch(...args));
    const schedule = dependencies.schedule ?? setTimeout;
    const cancel = dependencies.cancel ?? clearTimeout;
    return {
        value: String(config.value ?? ''), options: [], open: false, loading: false,
        failed: false, highlighted: -1, generation: 0, timer: null, controller: null,
        get status() {
            if (!this.open) return '';
            if (this.loading) return config.messages.loading;
            if (this.failed) return config.messages.failed;
            if (this.value.trim().length < 2) return config.messages.minimum;
            return this.options.length ? config.messages.results.replace(':count', String(this.options.length)) : config.messages.empty;
        },
        input(value) {
            this.value = value;
            this.invalidate();
            this.options = []; this.failed = false; this.highlighted = -1; this.open = true;
            if (value.trim().length >= 2) {
                const generation = this.generation;
                this.timer = schedule(() => this.search(generation), 300);
            }
        },
        async search(generation) {
            if (generation !== this.generation) return;
            this.loading = true;
            const controller = new AbortController();
            this.controller = controller;
            try {
                const url = new URL(config.url, globalThis.location?.href ?? 'http://localhost');
                url.searchParams.set('q', this.value.trim());
                const response = await request(url.toString(), {signal: controller.signal, credentials: 'same-origin', headers: {Accept: 'application/json'}});
                if (!response.ok) throw new Error('Suggestion request failed');
                const body = await response.json();
                if (generation !== this.generation) return;
                if (!Array.isArray(body.data)) throw new Error('Invalid suggestion response');
                const seen = new Set();
                this.options = body.data.filter(entry => {
                    const number = entry?.pr_number;
                    if (typeof number !== 'string' || Array.from(number).length > 100 || !number.trim()) return false;
                    const key = number.trim().toLocaleLowerCase();
                    if (seen.has(key)) return false;
                    seen.add(key); return true;
                }).slice(0, 20).map(entry => entry.pr_number);
                this.failed = false;
            } catch (error) {
                if (generation === this.generation && error.name !== 'AbortError') {
                    this.options = []; this.failed = true;
                }
            } finally {
                if (generation === this.generation) this.loading = false;
            }
        },
        invalidate() {
            this.generation++;
            cancel(this.timer); this.timer = null;
            this.controller?.abort(); this.controller = null; this.loading = false;
        },
        close() { this.invalidate(); this.open = false; this.highlighted = -1; },
        focus() { if (!this.open) this.input(this.value); },
        navigate(direction) {
            this.open = true;
            if (!this.options.length) return;
            this.highlighted = this.highlighted < 0 ? (direction > 0 ? 0 : this.options.length - 1)
                : (this.highlighted + direction + this.options.length) % this.options.length;
            this.$nextTick?.(() => this.$refs?.list?.querySelectorAll('[role=option]')[this.highlighted]?.scrollIntoView({block: 'nearest'}));
        },
        choose(number) {
            this.value = number; this.close();
            this.$refs?.input?.dispatchEvent(new Event('change', {bubbles: true}));
        },
        enter() {
            if (this.highlighted >= 0 && this.highlighted < this.options.length) this.choose(this.options[this.highlighted]);
            else this.close();
        },
        destroy() { this.invalidate(); },
    };
}
