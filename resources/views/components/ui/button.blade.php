@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'loading' => false,
    'disabled' => false,
    'iconOnly' => false,
    'label' => null,
])

@php
    $variants = [
        'primary' => 'tw-border-transparent tw-bg-primary tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90',
        'secondary' => 'tw-border-transparent tw-bg-secondary-container tw-text-secondary-container-foreground hover:tw-brightness-95 active:tw-brightness-90',
        'outline' => 'tw-border-outline tw-bg-transparent tw-text-on-surface hover:tw-bg-surface-container active:tw-bg-surface-high',
        'ghost' => 'tw-border-transparent tw-bg-transparent tw-text-on-surface hover:tw-bg-surface-container active:tw-bg-surface-high',
        'danger' => 'tw-border-transparent tw-bg-error tw-text-error-foreground hover:tw-brightness-95 active:tw-brightness-90',
    ];

    $sizes = [
        'sm' => 'tw-min-h-[var(--ui-control-height-sm)] tw-gap-1.5 tw-px-2.5 tw-py-1 tw-text-ui-xs',
        'md' => 'tw-min-h-[var(--ui-control-height-md)] tw-gap-2 tw-px-3.5 tw-py-1.5 tw-text-ui-sm',
    ];

    $isDisabled = (bool) $disabled || (bool) $loading;
    $classes = implode(' ', [
        'ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-justify-center tw-whitespace-nowrap tw-rounded-ui-sm tw-border tw-font-semibold disabled:tw-cursor-not-allowed disabled:tw-opacity-50',
        $variants[$variant] ?? $variants['primary'],
        $sizes[$size] ?? $sizes['md'],
        $iconOnly ? 'tw-aspect-square tw-px-0' : '',
    ]);
@endphp

@if($href)
    <a
        @if(!$isDisabled) href="{{ $href }}" @endif
        @if($isDisabled) aria-disabled="true" tabindex="-1" @endif
        @if($iconOnly && $label) aria-label="{{ $label }}" @endif
        {{ $attributes->class([$classes]) }}
    >
        @if($loading)
            <span class="ui-spinner" aria-hidden="true"></span>
            <span class="tw-sr-only">Processing</span>
        @elseif(isset($leading))
            <span class="tw-inline-flex tw-shrink-0" aria-hidden="true">{{ $leading }}</span>
        @endif

        @if(!$iconOnly)
            <span>{{ $slot }}</span>
        @else
            {{ $slot }}
            @if($label)<span class="tw-sr-only">{{ $label }}</span>@endif
        @endif

        @if(!$loading && isset($trailing))
            <span class="tw-inline-flex tw-shrink-0" aria-hidden="true">{{ $trailing }}</span>
        @endif
    </a>
@else
    <button
        type="{{ $type }}"
        @disabled($isDisabled)
        @if($loading) aria-busy="true" @endif
        @if($iconOnly && $label) aria-label="{{ $label }}" @endif
        {{ $attributes->class([$classes]) }}
    >
        @if($loading)
            <span class="ui-spinner" aria-hidden="true"></span>
            <span class="tw-sr-only">Processing</span>
        @elseif(isset($leading))
            <span class="tw-inline-flex tw-shrink-0" aria-hidden="true">{{ $leading }}</span>
        @endif

        @if(!$iconOnly)
            <span>{{ $slot }}</span>
        @else
            {{ $slot }}
            @if($label)<span class="tw-sr-only">{{ $label }}</span>@endif
        @endif

        @if(!$loading && isset($trailing))
            <span class="tw-inline-flex tw-shrink-0" aria-hidden="true">{{ $trailing }}</span>
        @endif
    </button>
@endif
