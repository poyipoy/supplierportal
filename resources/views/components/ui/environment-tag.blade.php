@props(['placement' => 'navbar'])

@php
    $envTag = \App\Support\EnvironmentTag::resolve();
    $toneClasses = match ($envTag['tone'] ?? 'neutral') {
        'warning' => 'tw:bg-warning-container tw:text-warning-container-foreground tw:border-warning/40',
        'info' => 'tw:bg-primary-container tw:text-primary-container-foreground tw:border-primary/35',
        default => 'tw:bg-surface-container-high tw:text-on-surface tw:border-outline',
    };
@endphp

@if($envTag !== null)
    <span
        {{ $attributes->class([
            'ui-environment-tag',
            'ui-environment-tag--'.$envTag['tone'],
            'ui-environment-tag--'.$placement,
            'tw:inline-flex tw:items-center tw:gap-1 tw:px-2 tw:py-0.5 tw:rounded-ui-xs tw:border tw:text-ui-xs tw:font-bold tw:tracking-wider tw:uppercase tw:select-none tw:shrink-0',
            $toneClasses,
        ]) }}
        role="note"
        aria-label="{{ $envTag['tooltip'] }}"
        title="{{ $envTag['tooltip'] }}"
        data-environment-tag="{{ $envTag['code'] }}"
        data-environment-tone="{{ $envTag['tone'] }}"
        data-locale-ignore
    >
        <span class="tw:h-1.5 tw:w-1.5 tw:rounded-full tw:bg-current" aria-hidden="true"></span>
        <span>{{ $envTag['code'] }}</span>
    </span>
@endif
