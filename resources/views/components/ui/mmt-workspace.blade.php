@props([
    'id' => 'mmt-workspace',
    'satelliteId' => null,
    'masterLabel' => '',
    'satelliteTitle' => '',
    'satelliteEmptyTitle' => '',
    'satelliteEmpty' => '',
    'masterFrameless' => false,
])

@php
    $resolvedSatelliteId = $satelliteId ?: $id.'-satellite';
    $resolvedEmptyTitle = $satelliteEmptyTitle !== '' ? $satelliteEmptyTitle : ($satelliteTitle !== '' ? $satelliteTitle : __('common.states.empty'));
@endphp

<div
    id="{{ $id }}"
    {{ $attributes->class(['ui-mmt-workspace tw:grid tw:gap-4']) }}
    data-mmt-workspace
    data-mmt-satellite-target="#{{ $resolvedSatelliteId }}"
>
    <section
        class="{{ $masterFrameless ? 'tw:min-w-0' : 'ui-card tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface tw:shadow-ui-1 tw:overflow-hidden tw:min-w-0' }}"
        data-mmt-master-pane
        @if ($masterLabel !== '') aria-label="{{ $masterLabel }}" @endif
    >
        @isset($masterToolbar)
            <div class="tw:border-b tw:border-outline-variant tw:bg-surface-container tw:px-4 tw:py-2.5">
                {{ $masterToolbar }}
            </div>
        @endisset

        <div data-mmt-master-content>
            {{ $header ?? $slot }}
        </div>
    </section>

    <section
        class="ui-card tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface tw:shadow-ui-1 tw:overflow-hidden tw:min-w-0"
        data-mmt-satellite-pane-wrapper
        @if ($satelliteTitle !== '') aria-label="{{ $satelliteTitle }}" @endif
    >
        @if ($satelliteTitle !== '' || isset($satelliteHeader))
            <header class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2 tw:border-b tw:border-outline-variant tw:bg-surface-container tw:px-4 tw:py-2.5">
                <div class="tw:flex tw:min-w-0 tw:items-center tw:gap-2">
                    <x-ui.icon name="layers" size="xs" class="tw:shrink-0 tw:text-primary" aria-hidden="true" />
                    @if ($satelliteTitle !== '')
                        <h2 class="tw:m-0 tw:truncate tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ $satelliteTitle }}</h2>
                    @endif
                    <span
                        class="tw:hidden tw:inline-flex tw:items-center tw:rounded-ui-full tw:border tw:border-outline-variant tw:bg-surface-low tw:px-2.5 tw:py-0.5 tw:text-ui-xs tw:font-semibold tw:text-primary ui-tabular-nums"
                        data-mmt-active-ref
                        hidden
                    ></span>
                </div>
                @isset($satelliteHeader)
                    <div class="tw:flex tw:items-center tw:gap-2">{{ $satelliteHeader }}</div>
                @endisset
            </header>
        @else
            <div class="tw:flex tw:items-center tw:gap-2 tw:border-b tw:border-outline-variant tw:bg-surface-container tw:px-4 tw:py-2" data-mmt-ref-bar hidden>
                <x-ui.icon name="layers" size="xs" class="tw:shrink-0 tw:text-primary" aria-hidden="true" />
                <span
                    class="tw:hidden tw:inline-flex tw:items-center tw:rounded-ui-full tw:border tw:border-outline-variant tw:bg-surface-low tw:px-2.5 tw:py-0.5 tw:text-ui-xs tw:font-semibold tw:text-primary ui-tabular-nums"
                    data-mmt-active-ref
                    hidden
                ></span>
            </div>
        @endif

        <div
            id="{{ $resolvedSatelliteId }}"
            class="tw:p-4"
            data-server-tabs-container
            data-mmt-satellite
            aria-live="polite"
        >
            <div data-server-tabs-nav class="tw:mb-3 tw:empty:hidden">{{ $satelliteNav ?? '' }}</div>

            <div data-server-tabs-content>
                @isset($satellite)
                    {{ $satellite }}
                @elseif ($satelliteEmpty !== '')
                    <x-ui.empty-state
                        icon="mouse-pointer-click"
                        :title="$resolvedEmptyTitle"
                        :description="$satelliteEmpty"
                        class="tw:py-6"
                    />
                @endisset
            </div>
        </div>
    </section>
</div>
