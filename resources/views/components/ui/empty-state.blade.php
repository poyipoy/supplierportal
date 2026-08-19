@props([
    'icon' => 'bi-inbox',
    'title' => 'Belum ada data',
    'description' => null,
    'actionUrl' => null,
    'actionText' => null,
    'actionIcon' => 'bi-plus-lg',
])

<div {{ $attributes->class(['tw-flex tw-flex-col tw-items-center tw-justify-center tw-px-4 tw-py-12 tw-text-center']) }}>
    <span class="tw-inline-flex tw-h-12 tw-w-12 tw-items-center tw-justify-center tw-rounded-ui-full tw-bg-secondary-container tw-text-xl tw-text-secondary-container-foreground" aria-hidden="true">
        <i class="bi {{ $icon }}"></i>
    </span>
    <h3 class="tw-m-0 tw-mt-4 tw-text-ui-base tw-font-semibold tw-text-on-surface">{{ $title }}</h3>
    @if($description)<p class="tw-m-0 tw-mt-1 tw-max-w-md tw-text-ui-sm tw-text-on-surface-variant">{{ $description }}</p>@endif
    @if($actionUrl && $actionText)
        <x-ui.button :href="$actionUrl" size="sm" class="tw-mt-5">
            <x-slot:leading><i class="bi {{ $actionIcon }}"></i></x-slot:leading>
            {{ $actionText }}
        </x-ui.button>
    @endif
    {{ $slot }}
</div>
