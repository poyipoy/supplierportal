@props([
    'name',
    'title' => null,
    'show' => false,
    'side' => 'right',
    'position' => null,
])

@php
    $resolvedSide = $position === 'start' ? 'left' : ($position === 'end' ? 'right' : ($position ?? $side));
    $titleId = $name . '-title';
@endphp

<div
    data-ui-drawer="{{ $name }}"
    x-data="{
        drawerName: @js((string) $name),
        open: @js($show),
        modalityAcquired: false,
        returnFocus: null,
        focusables() {
            return [...this.$refs.panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\'-1\'])')]
                .filter((el) => el.offsetParent !== null);
        },
        focusInitial(attempt = 0) {
            if (!this.open) return;
            (this.focusables()[0] || this.$refs.panel)?.focus?.({ preventScroll: true });
            if (!this.$refs.panel?.contains(document.activeElement) && attempt < 3) {
                window.requestAnimationFrame(() => this.focusInitial(attempt + 1));
            }
        },
        openDrawer() {
            this.returnFocus = document.activeElement;
            this.open = true;
            if (window.AdasiOverlayModality) {
                this.modalityAcquired = true;
                window.AdasiOverlayModality.acquire(this.$root, { triggerEl: this.returnFocus });
            } else {
                document.body?.classList?.add('ui-dialog-open');
            }
            this.$nextTick(() => this.focusInitial(0));
        },
        closeDrawer({ restoreFocus = true } = {}) {
            if (!this.open) return;
            this.open = false;
            this.modalityAcquired = false;
            const targetFocus = this.returnFocus;
            this.returnFocus = null;
            if (window.AdasiOverlayModality) {
                window.AdasiOverlayModality.release(this.$root, { restoreFocus });
            } else {
                document.body?.classList?.remove('ui-dialog-open');
                if (restoreFocus && targetFocus && targetFocus.isConnected !== false) {
                    targetFocus.focus?.({ preventScroll: true });
                }
            }
        },
        destroy() {
            const shouldRelease = this.modalityAcquired || this.open;
            this.open = false;
            this.modalityAcquired = false;
            this.returnFocus = null;
            if (shouldRelease) {
                window.AdasiOverlayModality?.release(this.$root, { restoreFocus: false });
            }
        },
        trapFocus(event) {
            const items = this.focusables();
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        },
    }"
    x-init="if (open) { if (window.AdasiOverlayModality) { modalityAcquired = true; window.AdasiOverlayModality.acquire($root); } else { document.body?.classList?.add('ui-dialog-open'); } $nextTick(() => focusInitial(0)); }"
    x-on:open-ui-drawer.window="if ($event.detail === drawerName || $event.detail?.name === drawerName) openDrawer()"
    x-on:close-ui-drawer.window="if ($event.detail === drawerName || $event.detail?.name === drawerName) closeDrawer({ restoreFocus: $event.detail?.restoreFocus !== false })"
    x-on:keydown.escape.window="if (open) closeDrawer()"
    x-show="open"
    x-cloak
    {{ $attributes->class(['tw:fixed tw:inset-0 tw:z-drawer tw:overscroll-contain']) }}
>
    <div class="ui-scrim tw:absolute tw:inset-0" @click="closeDrawer()" aria-hidden="true"></div>
    <aside
        x-ref="panel"
        x-show="open"
        x-on:keydown.tab="trapFocus($event)"
        role="dialog"
        aria-modal="true"
        @if($title && !isset($header)) aria-labelledby="{{ $titleId }}" @elseif($title) aria-label="{{ $title }}" @endif
        tabindex="-1"
        class="tw:absolute tw:inset-y-0 tw:flex tw:w-[min(100vw,26rem)] tw:flex-col tw:bg-dialog tw:border-outline tw:shadow-ui-2 {{ $resolvedSide === 'left' ? 'tw:start-0 tw:border-e' : 'tw:end-0 tw:border-s' }}"
        x-transition:enter="tw:transition tw:duration-standard tw:ease-standard"
        x-transition:enter-start="{{ $resolvedSide === 'left' ? 'tw:-translate-x-full' : 'tw:translate-x-full' }}"
        x-transition:enter-end="tw:translate-x-0"
        x-transition:leave="tw:transition tw:duration-fast tw:ease-standard"
        x-transition:leave-start="tw:translate-x-0"
        x-transition:leave-end="{{ $resolvedSide === 'left' ? 'tw:-translate-x-full' : 'tw:translate-x-full' }}"
    >
        <header class="tw:flex tw:min-h-14 tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-outline-variant tw:px-4 tw:py-2 tw:md:px-5">
            @isset($header)
                {{ $header }}
            @else
                @if($title)
                    <h2 id="{{ $titleId }}" class="tw:m-0 tw:min-w-0 tw:text-ui-lg tw:font-bold tw:text-on-surface">{{ $title }}</h2>
                @endif
            @endisset
            <button type="button" class="ui-focus-ring tw:inline-flex tw:h-9 tw:w-9 tw:pointer-coarse:h-11 tw:pointer-coarse:w-11 tw:items-center tw:justify-center tw:rounded-ui-sm tw:border-0 tw:bg-transparent tw:text-on-surface-variant tw:hover:bg-surface-container tw:hover:text-on-surface" @click="closeDrawer()" aria-label="{{ __('common.panel.close') }}">
                <x-ui.icon name="x" size="sm" />
            </button>
        </header>
        <div class="tw:min-h-0 tw:flex-1 tw:overflow-y-auto tw:overscroll-contain tw:p-4 tw:md:p-5">{{ $slot }}</div>
        @isset($footer)
            <footer class="tw:border-t tw:border-outline-variant tw:p-4 tw:md:p-5 tw:bg-surface-low">{{ $footer }}</footer>
        @endisset
    </aside>
</div>
