@props([
    'title' => null,
    'description' => null,
])

<section {{ $attributes->class(['ui-form-section tw-mb-6 tw-flex tw-flex-col tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface tw-shadow-none']) }}>
    @if($title || $description || isset($actions))
        <header class="ui-form-section__header tw-flex tw-flex-col tw-gap-1 tw-border-b tw-border-outline-variant tw-bg-surface-container tw-p-4 shell:tw-flex-row shell:tw-items-end shell:tw-justify-between shell:tw-px-5">
            <div class="tw-min-w-0">
                @if($title)
                    <h2 class="ui-form-section__title tw-m-0 tw-text-xs tw-font-bold tw-uppercase tw-tracking-wider tw-text-on-surface">{{ $title }}</h2>
                @endif
                @if($description)
                    <p class="ui-form-section__description tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="tw-flex tw-shrink-0 tw-flex-wrap tw-items-center tw-gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif
    <div class="tw-grid tw-gap-4 tw-p-4 shell:tw-p-5">
        {{ $slot }}
    </div>
</section>
