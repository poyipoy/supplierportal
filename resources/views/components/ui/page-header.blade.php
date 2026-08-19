@props(['title', 'description' => null, 'eyebrow' => null])

<header {{ $attributes->class(['tw-flex tw-flex-col tw-gap-4 shell:tw-flex-row shell:tw-items-start shell:tw-justify-between']) }}>
    <div class="tw-min-w-0">
        @if($eyebrow)<p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">{{ $eyebrow }}</p>@endif
        <h1 class="tw-m-0 tw-text-ui-2xl tw-font-semibold tw-tracking-tight tw-text-on-surface">{{ $title }}</h1>
        @if($description)<p class="tw-m-0 tw-mt-1 tw-max-w-3xl tw-text-ui-sm tw-text-on-surface-variant">{{ $description }}</p>@endif
        @isset($meta)<div class="tw-mt-3 tw-flex tw-flex-wrap tw-items-center tw-gap-2">{{ $meta }}</div>@endisset
    </div>
    @isset($actions)<div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">{{ $actions }}</div>@endisset
</header>
