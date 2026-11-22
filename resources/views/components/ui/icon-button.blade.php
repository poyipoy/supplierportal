@props([
    'icon',
    'label',
    'href' => null,
    'variant' => 'ghost',
    'size' => 'md',
    'disabled' => false,
    'type' => 'button',
])

@php
    $variants = [
        'ghost' => 'tw:border-transparent tw:bg-transparent tw:text-on-surface-variant tw:hover:bg-surface-container tw:hover:text-on-surface tw:active:bg-surface-high',
        'outline' => 'tw:border-outline-strong tw:bg-surface tw:text-on-surface tw:hover:bg-surface-container tw:hover:text-on-surface tw:active:bg-surface-high',
        'outline-primary' => 'tw:border-primary-text tw:bg-surface tw:text-primary-text tw:hover:bg-primary-container tw:hover:text-primary-container-foreground tw:active:bg-primary-container tw:active:brightness-95',
        'secondary' => 'tw:border-outline-variant tw:bg-secondary-container tw:text-secondary-container-foreground tw:hover:brightness-95 tw:active:brightness-90',
        'secondary-outline' => 'tw:border-outline-strong tw:bg-surface tw:text-on-surface tw:hover:bg-surface-container tw:hover:text-on-surface tw:active:bg-surface-high',
        'primary' => 'tw:border-transparent tw:bg-primary tw:text-primary-foreground tw:hover:brightness-95 tw:active:brightness-90',
        'danger' => 'tw:border-transparent tw:bg-error-container tw:text-error-container-foreground tw:hover:brightness-95 tw:active:brightness-90',
        'warning-outline' => 'tw:border-warning-container-foreground tw:bg-surface tw:text-warning-container-foreground tw:hover:bg-warning-container tw:active:bg-warning-container tw:active:brightness-95',
        'success-outline' => 'tw:border-success-container-foreground tw:bg-surface tw:text-success-container-foreground tw:hover:bg-success-container tw:active:bg-success-container tw:active:brightness-95',
        'danger-outline' => 'tw:border-error tw:bg-surface tw:text-error tw:hover:bg-error-container tw:hover:text-error-container-foreground tw:active:bg-error-container tw:active:brightness-95',
    ];

    $sizes = [
        'xs' => 'tw:h-7 tw:w-7',
        'sm' => 'tw:h-8 tw:w-8',
        'md' => 'tw:h-9 tw:w-9',
        'lg' => 'tw:h-11 tw:w-11',
    ];

    $classes = implode(' ', [
        'ui-icon-button ui-motion ui-focus-ring tw:relative tw:inline-flex tw:shrink-0 tw:items-center tw:justify-center tw:rounded-ui-sm tw:border tw:no-underline tw:disabled:cursor-not-allowed tw:disabled:opacity-50',
        $variants[$variant] ?? $variants['ghost'],
        $sizes[$size] ?? $sizes['md'],
    ]);
@endphp

@if($href)
    <a
        @if(!$disabled) href="{{ $href }}" @endif
        aria-label="{{ $label }}"
        title="{{ $label }}"
        @if($disabled) aria-disabled="true" tabindex="-1" @endif
        {{ $attributes->class([$classes]) }}
    >
        @isset($visual)
            {{ $visual }}
        @else
            <x-ui.icon :name="$icon" :size="in_array($size, ['xs', 'sm'], true) ? $size : 'md'" />
        @endisset
        @isset($badge){{ $badge }}@endisset
        <span class="tw:sr-only">{{ $label }}</span>
    </a>
@else
    <button
        type="{{ $type }}"
        aria-label="{{ $label }}"
        title="{{ $label }}"
        @disabled($disabled)
        {{ $attributes->class([$classes]) }}
    >
        @isset($visual)
            {{ $visual }}
        @else
            <x-ui.icon :name="$icon" :size="in_array($size, ['xs', 'sm'], true) ? $size : 'md'" />
        @endisset
        @isset($badge){{ $badge }}@endisset
        <span class="tw:sr-only">{{ $label }}</span>
    </button>
@endif
