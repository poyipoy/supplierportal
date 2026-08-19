@props([
    'name',
    'meta' => null,
    'src' => null,
    'size' => 'sm',
])

<span {{ $attributes->class(['tw-inline-flex tw-min-w-0 tw-items-center tw-gap-2 tw-rounded-ui-full tw-bg-surface-container-low tw-p-1 tw-pe-3 tw-text-on-surface']) }}>
    <x-ui.avatar :name="$name" :src="$src" :size="$size" />
    <span class="tw-min-w-0 tw-text-start">
        <span class="tw-block tw-truncate tw-text-ui-sm tw-font-semibold">{{ $name }}</span>
        @if($meta)<span class="tw-block tw-truncate tw-text-ui-xs tw-text-on-surface-variant">{{ $meta }}</span>@endif
    </span>
    @isset($trailing)<span class="tw-ms-auto tw-inline-flex tw-shrink-0">{{ $trailing }}</span>@endisset
</span>
