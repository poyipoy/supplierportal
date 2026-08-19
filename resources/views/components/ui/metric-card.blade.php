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
        'primary' => ['tw-bg-primary-container tw-text-primary-container-foreground', 'tw-text-primary'],
        'info' => ['tw-bg-primary-container tw-text-primary-container-foreground', 'tw-text-primary'],
        'success' => ['tw-bg-success-container tw-text-success-container-foreground', 'tw-text-success'],
        'warning' => ['tw-bg-warning-container tw-text-warning-container-foreground', 'tw-text-warning'],
        'error' => ['tw-bg-error-container tw-text-error-container-foreground', 'tw-text-error'],
        'neutral' => ['tw-bg-surface-container tw-text-on-surface', 'tw-text-on-surface-variant'],
    ];
    [$iconClasses, $valueClasses] = $tones[$tone] ?? $tones['primary'];
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if($href) href="{{ $href }}" @endif
    {{ $attributes->class([
        'ui-motion ui-focus-ring tw-flex tw-h-full tw-items-center tw-justify-between tw-gap-4 tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface tw-p-5 tw-text-on-surface tw-no-underline tw-shadow-ui-1',
        'hover:tw-border-primary hover:tw-shadow-ui-2' => $href,
    ]) }}
>
    <span class="tw-min-w-0">
        <span class="tw-block tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">{{ $label }}</span>
        <span @if($valueId) id="{{ $valueId }}" @endif class="ui-tabular-nums tw-mt-1 tw-block tw-text-ui-2xl tw-font-semibold {{ $valueClasses }}">{{ $value }}</span>
        @if($meta)<span class="tw-mt-1 tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $meta }}</span>@endif
    </span>
    <span class="tw-relative tw-inline-flex tw-h-12 tw-w-12 tw-shrink-0 tw-items-center tw-justify-center tw-rounded-ui-full {{ $iconClasses }}">
        <i class="bi {{ $icon }} tw-text-xl" aria-hidden="true"></i>
        @if($href)<i class="bi bi-arrow-up-right tw-absolute -tw-bottom-0.5 -tw-end-0.5 tw-text-ui-xs" aria-hidden="true"></i>@endif
    </span>
</{{ $tag }}>
