@props([
    'name',
    'title' => null,
    'description' => null,
    'show' => false,
    'maxWidth' => 'lg',
    'closeOnBackdrop' => true,
])

@php
    $widths = [
        'sm' => 'tw:sm:max-w-sm',
        'md' => 'tw:sm:max-w-md',
        'lg' => 'tw:sm:max-w-lg',
        'xl' => 'tw:sm:max-w-xl',
        '2xl' => 'tw:sm:max-w-2xl',
        '4xl' => 'tw:sm:max-w-4xl',
    ];
    $titleId = $name . '-title';
    $descriptionId = $name . '-description';
@endphp

<div
    data-ui-dialog="{{ $name }}"
    x-data="{
        dialogName: @js((string) $name),
        open: @js($show),
        closeOnBackdrop: @js($closeOnBackdrop),
        actionsHidden: false,
        modalityAcquired: false,
        returnFocus: null,
        focusables() {
            return [...this.$refs.panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\'-1\'])')]
                .filter((el) => el.offsetParent !== null);
        },
        focusInitial(attempt = 0) {
            if (!this.open) return;
            const preferred = this.$refs.panel?.querySelector('[data-dialog-initial-focus]:not([disabled]), [autofocus]:not([disabled])');
            const initialTarget = preferred && preferred.offsetParent !== null
                ? preferred
                : (this.focusables()[0] || this.$refs.panel);
            initialTarget?.focus?.({ preventScroll: true });
            if (!this.$refs.panel?.contains(document.activeElement) && attempt < 3) {
                window.requestAnimationFrame(() => this.focusInitial(attempt + 1));
            }
        },
        openDialog() {
            this.returnFocus = document.activeElement;
            this.actionsHidden = false;
            this.open = true;
            if (window.AdasiOverlayModality) {
                this.modalityAcquired = true;
                window.AdasiOverlayModality.acquire(this.$root, { triggerEl: this.returnFocus });
            } else {
                document.body?.classList?.add('ui-dialog-open');
            }
            this.$nextTick(() => this.focusInitial(0));
        },
        closeDialog(dispatchCloseEvent = true, { restoreFocus = true } = {}) {
            if (!this.open) return;
            this.open = false;
            this.actionsHidden = false;
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
            if (dispatchCloseEvent) {
                window.dispatchEvent(new CustomEvent('close-ui-dialog', { detail: { name: this.dialogName, fromDialog: true } }));
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
            if (!items.length) {
                event.preventDefault();
                this.$refs.panel?.focus?.();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        },
    }"
    x-init="if (open) { if (window.AdasiOverlayModality) { modalityAcquired = true; window.AdasiOverlayModality.acquire($root); } else { document.body?.classList?.add('ui-dialog-open'); } $nextTick(() => focusInitial(0)); }"
    x-on:open-ui-dialog.window="if ($event.detail === dialogName || $event.detail?.name === dialogName) openDialog()"
    x-on:close-ui-dialog.window="if (($event.detail === dialogName || $event.detail?.name === dialogName) && !$event.detail?.fromDialog) closeDialog(false, { restoreFocus: $event.detail?.restoreFocus !== false })"
    x-on:ui-dialog-actions.window="if ($event.detail?.name === dialogName) actionsHidden = Boolean($event.detail?.hidden)"
    x-on:keydown.escape.window="if (open) closeDialog()"
    x-show="open"
    x-cloak
    {{ $attributes->class(['tw:fixed tw:inset-0 tw:z-modal tw:overflow-y-auto tw:overscroll-contain tw:p-4']) }}
>
    <div class="ui-scrim tw:fixed tw:inset-0" @click="if (closeOnBackdrop) closeDialog()" aria-hidden="true"></div>
    <div class="tw:flex tw:min-h-full tw:items-center tw:justify-center">
        <section
            x-ref="panel"
            x-show="open"
            x-on:keydown.tab="trapFocus($event)"
            role="dialog"
            aria-modal="true"
            @if($title && !isset($header)) aria-labelledby="{{ $titleId }}" @elseif($title) aria-label="{{ $title }}" @endif
            @if($description && !isset($header)) aria-describedby="{{ $descriptionId }}" @endif
            tabindex="-1"
            class="tw:relative tw:w-full tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-dialog tw:shadow-ui-2 {{ $widths[$maxWidth] ?? $widths['lg'] }}"
            x-transition:enter="tw:transition tw:duration-standard tw:ease-standard"
            x-transition:enter-start="tw:translate-y-1 tw:opacity-0"
            x-transition:enter-end="tw:translate-y-0 tw:opacity-100"
            x-transition:leave="tw:transition tw:duration-fast tw:ease-standard"
            x-transition:leave-start="tw:translate-y-0 tw:opacity-100"
            x-transition:leave-end="tw:translate-y-1 tw:opacity-0"
        >
            @if($title || $description || isset($header))
                <header class="tw:flex tw:items-start tw:justify-between tw:gap-4 tw:border-b tw:border-outline-variant tw:p-5">
                    <div class="tw:min-w-0">
                        @isset($header){{ $header }}@else
                            @if($title)<h2 id="{{ $titleId }}" class="tw:m-0 tw:text-ui-xl tw:font-semibold tw:text-on-surface">{{ $title }}</h2>@endif
                            @if($description)<p id="{{ $descriptionId }}" class="tw:m-0 tw:mt-1 tw:text-ui-sm tw:text-on-surface-variant">{{ $description }}</p>@endif
                        @endisset
                    </div>
                    <button type="button" class="ui-focus-ring tw:inline-flex tw:h-11 tw:w-11 tw:shrink-0 tw:items-center tw:justify-center tw:rounded-ui-full tw:border-0 tw:bg-transparent tw:text-on-surface-variant tw:hover:bg-surface-container tw:hover:text-on-surface" @click="closeDialog()" aria-label="{{ __('common.dialog.close') }}">
                        <x-ui.icon name="x" size="sm" />
                    </button>
                </header>
            @endif
            <div class="tw:max-h-[min(70vh,42rem)] tw:overflow-y-auto tw:overscroll-contain tw:p-5">{{ $slot }}</div>
            @isset($actions)<footer x-show="!actionsHidden" class="tw:flex tw:flex-col-reverse tw:gap-2 tw:border-t tw:border-outline-variant tw:p-4 tw:sm:flex-row tw:sm:justify-end">{{ $actions }}</footer>@endisset
        </section>
    </div>
</div>
