@props(['name', 'title' => null, 'show' => false, 'side' => 'right'])

<div
    x-data="{ open: @js($show), returnFocus: null, openDrawer() { this.returnFocus = document.activeElement; this.open = true; this.$nextTick(() => this.$refs.panel.focus()); }, closeDrawer() { this.open = false; this.$nextTick(() => this.returnFocus?.focus?.()); } }"
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
        role="dialog"
        aria-modal="true"
        @if($title) aria-label="{{ $title }}" @endif
        tabindex="-1"
        class="tw-absolute tw-inset-y-0 tw-flex tw-w-[min(100vw,28rem)] tw-flex-col tw-bg-surface tw-shadow-ui-3 {{ $side === 'left' ? 'tw-start-0' : 'tw-end-0' }}"
        x-transition:enter="tw-transition tw-duration-standard tw-ease-emphasized"
        x-transition:enter-start="{{ $side === 'left' ? '-tw-translate-x-full' : 'tw-translate-x-full' }}"
        x-transition:enter-end="tw-translate-x-0"
        x-transition:leave="tw-transition tw-duration-fast tw-ease-standard"
        x-transition:leave-start="tw-translate-x-0"
        x-transition:leave-end="{{ $side === 'left' ? '-tw-translate-x-full' : 'tw-translate-x-full' }}"
    >
        <header class="tw-flex tw-min-h-16 tw-items-center tw-justify-between tw-gap-3 tw-border-b tw-border-outline-variant tw-px-4">
            @isset($header){{ $header }}@else@if($title)<h2 class="tw-m-0 tw-text-ui-lg tw-font-semibold">{{ $title }}</h2>@endif@endisset
            <button type="button" class="ui-focus-ring tw-inline-flex tw-h-10 tw-w-10 tw-items-center tw-justify-center tw-rounded-ui-full hover:tw-bg-surface-container" @click="closeDrawer()" aria-label="Tutup panel">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>
        <div class="tw-min-h-0 tw-flex-1 tw-overflow-y-auto">{{ $slot }}</div>
        @isset($footer)<footer class="tw-border-t tw-border-outline-variant tw-p-4">{{ $footer }}</footer>@endisset
    </aside>
</div>
