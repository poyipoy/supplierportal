@props(['tone' => 'info', 'title' => null, 'dismissible' => false])

@php
    $tones = [
        'info' => ['tw-border-primary tw-bg-primary-container tw-text-primary-container-foreground', 'bi-info-circle-fill'],
        'success' => ['tw-border-success tw-bg-success-container tw-text-success-container-foreground', 'bi-check-circle-fill'],
        'warning' => ['tw-border-warning tw-bg-warning-container tw-text-warning-container-foreground', 'bi-exclamation-triangle-fill'],
        'error' => ['tw-border-error tw-bg-error-container tw-text-error-container-foreground', 'bi-x-circle-fill'],
    ];
    [$toneClasses, $icon] = $tones[$tone] ?? $tones['info'];
@endphp

<div
    @if($dismissible) x-data="{ visible: true }" x-show="visible" @endif
    role="{{ $tone === 'error' ? 'alert' : 'status' }}"
    {{ $attributes->class(['tw-flex tw-items-start tw-gap-3 tw-rounded-ui-sm tw-border-s-4 tw-p-4', $toneClasses]) }}
>
    <i class="bi {{ $icon }} tw-mt-0.5 tw-shrink-0" aria-hidden="true"></i>
    <div class="tw-min-w-0 tw-flex-1">
        @if($title)<div class="tw-font-semibold">{{ $title }}</div>@endif
        <div class="tw-text-ui-sm">{{ $slot }}</div>
    </div>
    @if($dismissible)
        <button type="button" class="ui-focus-ring tw-inline-flex tw-h-8 tw-w-8 tw-shrink-0 tw-items-center tw-justify-center tw-rounded-ui-full hover:tw-bg-surface-container" @click="visible = false" aria-label="Tutup pesan">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    @endif
</div>
