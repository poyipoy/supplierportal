@props([
    'name',
    'title' => null,
    'show' => false,
    'side' => 'right',
    'position' => null,
])

@php
    $resolvedSide = $position === 'start' ? 'left' : ($position === 'end' ? 'right' : ($position ?? $side));
@endphp

<div
    x-data="{
        open: @js($show),
        returnFocus: null,
        focusables() {
            return [...this.$refs.panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\'-1\'])')];
        },
        openDrawer() {
            this.returnFocus = document.activeElement;
            this.open = true;
            this.$nextTick(() => (this.focusables()[0] || this.$refs.panel).focus());
        },
        closeDrawer() {
            this.open = false;
            this.$nextTick(() => this.returnFocus?.focus?.());
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
    x-init="$watch('open', value => document.body.classList.toggle('ui-dialog-open', value))"
    x-on:open-ui-drawer.window="if ($event.detail === '{{ $name }}' || $event.detail?.name === '{{ $name }}') openDrawer()"
    x-on:close-ui-drawer.window="if ($event.detail === '{{ $name }}' || $event.detail?.name === '{{ $name }}') closeDrawer()"
    x-on:keydown.escape.window="if (open) closeDrawer()"
    x-show="open"
    x-cloak
    {{ $attributes->class(['tw-fixed tw-inset-0 tw-z-drawer']) }}
>
    <div class="ui-scrim tw-absolute tw-inset-0" @click="closeDrawer()" aria-hidden="true"></div>
    <aside
        x-ref="panel"
        x-on:keydown.tab="trapFocus($event)"
        role="dialog"
        aria-modal="true"
        @if($title) aria-label="{{ $title }}" @endif
        tabindex="-1"
        class="tw-absolute tw-inset-y-0 tw-flex tw-w-[min(100vw,26rem)] tw-flex-col tw-bg-surface tw-border-s tw-border-outline-variant tw-shadow-ui-2 {{ $resolvedSide === 'left' ? 'tw-start-0' : 'tw-end-0' }}"
        x-transition:enter="tw-transition tw-duration-standard tw-ease-emphasized"
        x-transition:enter-start="{{ $resolvedSide === 'left' ? '-tw-translate-x-full' : 'tw-translate-x-full' }}"
        x-transition:enter-end="tw-translate-x-0"
        x-transition:leave="tw-transition tw-duration-fast tw-ease-standard"
        x-transition:leave-start="tw-translate-x-0"
        x-transition:leave-end="{{ $resolvedSide === 'left' ? '-tw-translate-x-full' : 'tw-translate-x-full' }}"
    >
        <header class="tw-flex tw-h-14 tw-items-center tw-justify-between tw-gap-3 tw-border-b tw-border-outline-variant tw-px-4 md:tw-px-5">
            @isset($header)
                {{ $header }}
            @else
                @if($title)
                    <h2 class="tw-m-0 tw-text-sm tw-font-bold tw-text-on-surface">{{ $title }}</h2>
                @endif
            @endisset
            <button type="button" class="ui-focus-ring tw-inline-flex tw-h-8 tw-w-8 tw-items-center tw-justify-center tw-rounded-ui-sm tw-text-on-surface-variant hover:tw-bg-surface-container hover:tw-text-on-surface" @click="closeDrawer()" aria-label="Close panel">
                <x-ui.icon name="x" size="sm" />
            </button>
        </header>
        <div class="tw-min-h-0 tw-flex-1 tw-overflow-y-auto tw-p-4 md:tw-p-5">{{ $slot }}</div>
        @isset($footer)
            <footer class="tw-border-t tw-border-outline-variant tw-p-4 md:tw-p-5 tw-bg-surface-low">{{ $footer }}</footer>
        @endisset
    </aside>
</div>
