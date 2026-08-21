@props([
    'icon',
    'label',
    'href' => null,
    'variant' => 'ghost',
    'size' => 'md',
    'disabled' => false,
])

@php
    $variants = [
        'ghost' => 'tw-border-transparent tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container hover:tw-text-on-surface',
        'secondary' => 'tw-border-transparent tw-bg-secondary-container tw-text-secondary-container-foreground hover:tw-brightness-95',
        'primary' => 'tw-border-transparent tw-bg-primary tw-text-primary-foreground hover:tw-brightness-95',
        'danger' => 'tw-border-transparent tw-bg-error-container tw-text-error-container-foreground hover:tw-brightness-95',
    ];

    $sizes = [
        'sm' => 'tw-h-8 tw-w-8',
        'md' => 'tw-h-9 tw-w-9',
        'lg' => 'tw-h-11 tw-w-11',
    ];

    $classes = implode(' ', [
        'ui-motion ui-focus-ring tw-relative tw-inline-flex tw-shrink-0 tw-items-center tw-justify-center tw-rounded-ui-sm tw-border disabled:tw-cursor-not-allowed disabled:tw-opacity-50',
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
        <x-ui.icon :name="$icon" :size="$size === 'sm' ? 'sm' : 'md'" />
        @isset($badge){{ $badge }}@endisset
        <span class="tw-sr-only">{{ $label }}</span>
    </a>
@else
    <button
        type="button"
        aria-label="{{ $label }}"
        title="{{ $label }}"
        @disabled($disabled)
        {{ $attributes->class([$classes]) }}
    >
        <x-ui.icon :name="$icon" :size="$size === 'sm' ? 'sm' : 'md'" />
        @isset($badge){{ $badge }}@endisset
        <span class="tw-sr-only">{{ $label }}</span>
    </button>
@endif
