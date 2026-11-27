@props(['tone' => 'info', 'title' => null, 'dismissible' => false])

@php
    $tones = [
        'info' => ['tw:border-primary/40 tw:bg-primary-container tw:text-primary-container-foreground', 'tw:text-primary', 'info'],
        'success' => ['tw:border-success/40 tw:bg-success-container tw:text-success-container-foreground', 'tw:text-success', 'circle-check'],
        'warning' => ['tw:border-warning/40 tw:bg-warning-container tw:text-warning-container-foreground', 'tw:text-warning', 'triangle-alert'],
        'error' => ['tw:border-error/40 tw:bg-error-container tw:text-error-container-foreground', 'tw:text-error', 'circle-x'],
    ];
    [$toneClasses, $iconToneClass, $icon] = $tones[$tone] ?? $tones['info'];
@endphp

<div
    @if($dismissible) x-data="{ visible: true }" x-show="visible" @endif
    role="{{ $tone === 'error' ? 'alert' : 'status' }}"
    {{ $attributes->class(['ui-alert tw:flex tw:items-start tw:gap-3 tw:rounded-ui-md tw:border tw:p-4', $toneClasses]) }}
>
    <x-ui.icon :name="$icon" size="sm" class="tw:mt-0.5 tw:shrink-0 {{ $iconToneClass }}" />
    <div class="tw:min-w-0 tw:flex-1">
        @if($title)<div class="tw:text-ui-sm tw:font-semibold tw:leading-snug tw:text-balance">{{ $title }}</div>@endif
        <div class="tw:text-ui-sm tw:leading-normal tw:text-pretty @if($title) tw:mt-0.5 @endif">{{ $slot }}</div>
    </div>
    @if($dismissible)
        <button type="button" class="ui-focus-ring tw:inline-flex tw:h-11 tw:w-11 tw:-my-2 tw:-me-2 tw:shrink-0 tw:items-center tw:justify-center tw:rounded-ui-sm tw:border-0 tw:bg-transparent tw:text-current tw:transition-colors tw:duration-fast tw:hover:bg-current/10" @click="visible = false" aria-label="{{ __('common.actions.dismiss') }}">
            <x-ui.icon name="x" size="sm" />
        </button>
    @endif
</div>
