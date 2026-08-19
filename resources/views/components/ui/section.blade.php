@props(['title' => null, 'description' => null])

<section {{ $attributes->class(['tw-grid tw-gap-4']) }}>
    @if($title || $description || isset($actions))
        <header class="tw-flex tw-flex-col tw-gap-3 shell:tw-flex-row shell:tw-items-end shell:tw-justify-between">
            <div class="tw-min-w-0">
                @if($title)<h2 class="tw-m-0 tw-text-ui-lg tw-font-semibold tw-text-on-surface">{{ $title }}</h2>@endif
                @if($description)<p class="tw-m-0 tw-mt-1 tw-max-w-3xl tw-text-ui-sm tw-text-on-surface-variant">{{ $description }}</p>@endif
            </div>
            @isset($actions)<div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    {{ $slot }}
</section>
