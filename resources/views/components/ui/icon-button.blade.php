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
        'ghost' => 'tw:border-transparent tw:bg-transparent tw:text-on-surface-variant tw:hover:bg-surface-container tw:hover:text-on-surface',
        'outline' => 'tw:border-outline tw:bg-transparent tw:text-on-surface tw:hover:bg-surface-container tw:hover:text-on-surface',
        'secondary' => 'tw:border-outline-variant tw:bg-secondary-container tw:text-secondary-container-foreground tw:hover:brightness-95',
        'secondary-outline' => 'tw:border-slate-300 tw:bg-white tw:text-slate-700 tw:hover:bg-slate-100 tw:hover:border-slate-400 tw:focus-visible:ring-2 tw:focus-visible:ring-slate-400 tw:shadow-2xs',
        'primary' => 'tw:border-transparent tw:bg-primary tw:text-primary-foreground tw:hover:brightness-95',
        'danger' => 'tw:border-transparent tw:bg-error-container tw:text-error-container-foreground tw:hover:brightness-95',
        'warning-outline' => 'tw:border-amber-300 tw:bg-amber-50 tw:text-amber-800 tw:hover:bg-amber-100 tw:hover:border-amber-400 tw:focus-visible:ring-2 tw:focus-visible:ring-amber-500 tw:shadow-2xs',
        'success-outline' => 'tw:border-emerald-300 tw:bg-emerald-50 tw:text-emerald-800 tw:hover:bg-emerald-100 tw:hover:border-emerald-400 tw:focus-visible:ring-2 tw:focus-visible:ring-emerald-500 tw:shadow-2xs',
        'danger-outline' => 'tw:border-red-300 tw:bg-red-50 tw:text-red-700 tw:hover:bg-red-100 tw:hover:border-red-400 tw:focus-visible:ring-2 tw:focus-visible:ring-red-500 tw:shadow-2xs',
    ];

    $sizes = [
        'xs' => 'tw:h-7 tw:w-7',
        'sm' => 'tw:h-8 tw:w-8',
        'md' => 'tw:h-9 tw:w-9',
        'lg' => 'tw:h-11 tw:w-11',
    ];

    $classes = implode(' ', [
        'ui-motion ui-focus-ring tw:relative tw:inline-flex tw:shrink-0 tw:items-center tw:justify-center tw:rounded-ui-sm tw:border tw:disabled:cursor-not-allowed tw:disabled:opacity-50',
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
