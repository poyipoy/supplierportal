@props(['tone' => 'neutral', 'size' => 'sm', 'icon' => null])

@php
    $tones = [
        'neutral' => ['tw-bg-surface-container tw-text-on-surface', 'circle'],
        'info' => ['tw-bg-primary-container tw-text-primary-container-foreground', 'info'],
        'success' => ['tw-bg-success-container tw-text-success-container-foreground', 'circle-check'],
        'warning' => ['tw-bg-warning-container tw-text-warning-container-foreground', 'triangle-alert'],
        'error' => ['tw-bg-error-container tw-text-error-container-foreground', 'circle-x'],
    ];
    $sizes = ['sm' => 'tw-min-h-6 tw-gap-1.5 tw-px-2 tw-text-ui-xs', 'md' => 'tw-min-h-7 tw-gap-2 tw-px-2.5 tw-text-ui-sm'];
    [$toneClasses, $defaultIcon] = $tones[$tone] ?? $tones['neutral'];
@endphp

<span {{ $attributes->class(['ui-status-chip', 'ui-status-chip--'.$tone, 'tw-inline-flex tw-max-w-full tw-items-center tw-rounded-ui-full tw-font-semibold', $toneClasses, $sizes[$size] ?? $sizes['sm']]) }}>
    <x-ui.icon :name="$icon ?: $defaultIcon" size="sm" class="tw-shrink-0" />
    <span class="tw-truncate">{{ $slot }}</span>
</span>
