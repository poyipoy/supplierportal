@props([
    'title',
    'description' => null,
    'eyebrow' => null,
    'breadcrumbs' => null,
])

<header {{ $attributes->class(['tw-flex tw-flex-col tw-gap-3 tw-mb-5 shell:tw-flex-row shell:tw-items-start shell:tw-justify-between']) }}>
    <div class="tw-min-w-0 tw-flex-1">
        @if($breadcrumbs || isset($breadcrumbSlot))
            <div class="tw-mb-2">
                @if(isset($breadcrumbSlot))
                    {{ $breadcrumbSlot }}
                @elseif(is_array($breadcrumbs))
                    <x-ui.breadcrumb :items="$breadcrumbs" />
                @endif
            </div>
        @endif

        @if($eyebrow)
            <p class="tw-m-0 tw-mb-1 tw-text-[11px] tw-font-bold tw-uppercase tw-tracking-wider tw-text-primary">{{ $eyebrow }}</p>
        @endif

        <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2.5">
            <h1 class="tw-m-0 tw-text-lg shell:tw-text-xl tw-font-bold tw-tracking-tight tw-text-on-surface">{{ $title }}</h1>
            @isset($status)
                <div class="tw-inline-flex tw-items-center">{{ $status }}</div>
            @endisset
        </div>

        @if($description)
            <p class="tw-m-0 tw-mt-1 tw-max-w-3xl tw-text-ui-sm tw-text-on-surface-variant">{{ $description }}</p>
        @endif

        @isset($meta)
            <div class="tw-mt-2.5 tw-flex tw-flex-wrap tw-items-center tw-gap-2">{{ $meta }}</div>
        @endisset
    </div>

    @isset($actions)
        <div class="tw-flex tw-shrink-0 tw-flex-wrap tw-items-center tw-gap-2 shell:tw-pt-0.5">{{ $actions }}</div>
    @endisset
</header>
