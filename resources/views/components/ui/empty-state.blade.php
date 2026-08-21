@props([
    'icon' => 'inbox',
    'title' => 'No data available',
    'description' => null,
    'actionUrl' => null,
    'actionText' => null,
    'actionIcon' => 'plus',
])

<div {{ $attributes->class(['tw-flex tw-flex-col tw-items-center tw-justify-center tw-px-4 tw-py-8 tw-text-center']) }}>
    <x-ui.icon :name="$icon" size="lg" class="tw-text-on-surface-variant" />
    <h3 class="tw-m-0 tw-mt-3 tw-text-ui-base tw-font-semibold tw-text-on-surface">{{ $title }}</h3>
    @if($description)<p class="tw-m-0 tw-mt-1 tw-max-w-md tw-text-ui-sm tw-text-on-surface-variant">{{ $description }}</p>@endif
    @if($actionUrl && $actionText)
        <x-ui.button :href="$actionUrl" size="sm" class="tw-mt-5">
            <x-slot:leading><x-ui.icon :name="$actionIcon" size="sm" /></x-slot:leading>
            {{ $actionText }}
        </x-ui.button>
    @endif
    {{ $slot }}
</div>
