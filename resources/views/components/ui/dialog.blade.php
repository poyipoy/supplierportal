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
        'sm' => 'sm:tw-max-w-sm',
        'md' => 'sm:tw-max-w-md',
        'lg' => 'sm:tw-max-w-lg',
        'xl' => 'sm:tw-max-w-xl',
        '2xl' => 'sm:tw-max-w-2xl',
    ];
    $titleId = $name . '-title';
    $descriptionId = $name . '-description';
@endphp

<div
    x-data="{
        open: @js($show),
        closeOnBackdrop: @js($closeOnBackdrop),
        returnFocus: null,
        focusables() {
            return [...this.$refs.panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\'-1\'])')];
        },
        openDialog() {
            this.returnFocus = document.activeElement;
            this.open = true;
            this.$nextTick(() => (this.focusables()[0] || this.$refs.panel).focus());
        },
        closeDialog() {
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
    x-on:open-ui-dialog.window="if ($event.detail === '{{ $name }}' || $event.detail?.name === '{{ $name }}') openDialog()"
    x-on:close-ui-dialog.window="if ($event.detail === '{{ $name }}' || $event.detail?.name === '{{ $name }}') closeDialog()"
    x-on:keydown.escape.window="if (open) closeDialog()"
    x-show="open"
    x-cloak
    {{ $attributes->class(['tw-fixed tw-inset-0 tw-z-modal tw-overflow-y-auto tw-p-4']) }}
>
    <div class="ui-scrim tw-fixed tw-inset-0" @click="if (closeOnBackdrop) closeDialog()" aria-hidden="true"></div>
    <div class="tw-flex tw-min-h-full tw-items-center tw-justify-center">
        <section
            x-ref="panel"
            x-show="open"
            x-on:keydown.tab="trapFocus($event)"
            role="dialog"
            aria-modal="true"
            @if($title) aria-labelledby="{{ $titleId }}" @endif
            @if($description) aria-describedby="{{ $descriptionId }}" @endif
            tabindex="-1"
            class="tw-relative tw-w-full tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface tw-shadow-ui-2 {{ $widths[$maxWidth] ?? $widths['lg'] }}"
            x-transition:enter="tw-transition tw-duration-standard tw-ease-emphasized"
            x-transition:enter-start="tw-translate-y-1 tw-opacity-0"
            x-transition:enter-end="tw-translate-y-0 tw-opacity-100"
            x-transition:leave="tw-transition tw-duration-fast tw-ease-standard"
            x-transition:leave-start="tw-translate-y-0 tw-opacity-100"
            x-transition:leave-end="tw-translate-y-1 tw-opacity-0"
        >
            @if($title || $description || isset($header))
                <header class="tw-flex tw-items-start tw-justify-between tw-gap-4 tw-border-b tw-border-outline-variant tw-p-5">
                    <div class="tw-min-w-0">
                        @isset($header){{ $header }}@else
                            @if($title)<h2 id="{{ $titleId }}" class="tw-m-0 tw-text-ui-xl tw-font-semibold tw-text-on-surface">{{ $title }}</h2>@endif
                            @if($description)<p id="{{ $descriptionId }}" class="tw-m-0 tw-mt-1 tw-text-ui-sm tw-text-on-surface-variant">{{ $description }}</p>@endif
                        @endisset
                    </div>
                    <button type="button" class="ui-focus-ring tw-inline-flex tw-h-11 tw-w-11 tw-shrink-0 tw-items-center tw-justify-center tw-rounded-ui-full tw-text-on-surface-variant hover:tw-bg-surface-container hover:tw-text-on-surface" @click="closeDialog()" aria-label="Close dialog">
                        <x-ui.icon name="x" size="sm" />
                    </button>
                </header>
            @endif
            <div class="tw-max-h-[min(70vh,42rem)] tw-overflow-y-auto tw-p-5">{{ $slot }}</div>
            @isset($actions)<footer class="tw-flex tw-flex-col-reverse tw-gap-2 tw-border-t tw-border-outline-variant tw-p-4 sm:tw-flex-row sm:tw-justify-end">{{ $actions }}</footer>@endisset
        </section>
    </div>
</div>
