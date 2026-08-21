@props([
    'label',
    'value',
    'icon',
    'href' => null,
    'tone' => 'primary',
    'meta' => null,
    'valueId' => null,
])

@php
    $tones = [
        'primary' => ['tw-text-primary', 'tw-text-primary'],
        'info' => ['tw-text-primary', 'tw-text-primary'],
        'success' => ['tw-text-success', 'tw-text-success'],
        'warning' => ['tw-text-warning-container-foreground', 'tw-text-warning-container-foreground'],
        'error' => ['tw-text-error', 'tw-text-error'],
        'neutral' => ['tw-text-on-surface-variant', 'tw-text-on-surface'],
    ];
    [$iconClasses, $valueClasses] = $tones[$tone] ?? $tones['primary'];
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if($href) href="{{ $href }}" @endif
    {{ $attributes->class([
        'ui-motion ui-focus-ring tw-flex tw-h-full tw-items-start tw-justify-between tw-gap-4 tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface tw-p-4 tw-text-on-surface tw-no-underline',
        'hover:tw-border-primary hover:tw-shadow-ui-1' => $href,
    ]) }}
>
    <span class="tw-min-w-0">
        <span class="tw-block tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">{{ $label }}</span>
        <span @if($valueId) id="{{ $valueId }}" @endif class="ui-tabular-nums tw-mt-1 tw-block tw-text-ui-2xl tw-font-semibold {{ $valueClasses }}">{{ $value }}</span>
        @if($meta)<span class="tw-mt-1 tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $meta }}</span>@endif
    </span>
    <span class="tw-inline-flex tw-shrink-0 tw-items-center tw-gap-1 {{ $iconClasses }}">
        <x-ui.icon :name="$icon" size="lg" />
        @if($href)<x-ui.icon name="arrow-up-right" size="sm" />@endif
    </span>
</{{ $tag }}>
