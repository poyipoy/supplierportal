@props([
    'tone' => 'info',
    'title' => null,
    'duration' => 5000,
    'show' => true,
])

@php
    $tones = [
        'info' => ['tw-bg-primary-container tw-text-primary-container-foreground', 'bi-info-circle-fill'],
        'success' => ['tw-bg-success-container tw-text-success-container-foreground', 'bi-check-circle-fill'],
        'warning' => ['tw-bg-warning-container tw-text-warning-container-foreground', 'bi-exclamation-triangle-fill'],
        'error' => ['tw-bg-error-container tw-text-error-container-foreground', 'bi-x-circle-fill'],
    ];
    [$toneClasses, $icon] = $tones[$tone] ?? $tones['info'];
@endphp

<div
    x-data="{ visible: @js($show), timer: null, close() { this.visible = false } }"
    x-init="if (visible && {{ (int) $duration }} > 0) timer = setTimeout(() => close(), {{ (int) $duration }})"
    x-show="visible"
    x-on:mouseenter="if (timer) clearTimeout(timer)"
    x-on:mouseleave="if (visible && {{ (int) $duration }} > 0) timer = setTimeout(() => close(), {{ (int) $duration }})"
    x-transition:enter="tw-transition tw-duration-standard tw-ease-emphasized"
    x-transition:enter-start="tw-translate-y-2 tw-opacity-0"
    x-transition:enter-end="tw-translate-y-0 tw-opacity-100"
    x-transition:leave="tw-transition tw-duration-fast tw-ease-standard"
    x-transition:leave-start="tw-translate-y-0 tw-opacity-100"
    x-transition:leave-end="tw-translate-y-2 tw-opacity-0"
    role="{{ $tone === 'error' ? 'alert' : 'status' }}"
    {{ $attributes->class(['tw-flex tw-w-full tw-max-w-sm tw-items-start tw-gap-3 tw-rounded-ui-md tw-border tw-border-outline-variant tw-p-4 tw-shadow-ui-3', $toneClasses]) }}
>
    <i class="bi {{ $icon }} tw-mt-0.5 tw-shrink-0" aria-hidden="true"></i>
    <div class="tw-min-w-0 tw-flex-1">
        @if($title)<div class="tw-font-semibold">{{ $title }}</div>@endif
        <div class="tw-text-ui-sm">{{ $slot }}</div>
    </div>
    <button type="button" class="ui-focus-ring tw-inline-flex tw-h-8 tw-w-8 tw-shrink-0 tw-items-center tw-justify-center tw-rounded-ui-full hover:tw-bg-surface" @click="close()" aria-label="Tutup notifikasi">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
</div>
