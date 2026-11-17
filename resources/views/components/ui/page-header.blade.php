@props([
    'title',
    'description' => null,
    'eyebrow' => null,
    'breadcrumbs' => null,
    'recordNav' => null,
])

@php
    $prevUrl = is_array($recordNav) ? ($recordNav['prev'] ?? null) : null;
    $nextUrl = is_array($recordNav) ? ($recordNav['next'] ?? null) : null;
    $navPosition = is_array($recordNav) ? ($recordNav['position'] ?? null) : null;
    $navTotal = is_array($recordNav) ? (int) ($recordNav['total'] ?? 0) : 0;
    $hasRecordNav = is_array($recordNav) && ($prevUrl !== null || $nextUrl !== null || ($navTotal > 1 && $navPosition !== null));
    $navBtnBase = 'ui-motion ui-focus-ring tw:inline-flex tw:min-h-9 tw:min-w-9 tw:pointer-coarse:min-h-11 tw:pointer-coarse:min-w-11 tw:items-center tw:justify-center tw:gap-1 tw:px-2.5 tw:text-ui-xs tw:font-semibold tw:no-underline';
@endphp

<header {{ $attributes->class(['ui-page-header tw:flex tw:flex-col tw:gap-3 tw:shell:flex-row tw:shell:items-start tw:shell:justify-between']) }}>
    <div class="tw:min-w-0 tw:flex-1">
        @if($breadcrumbs || isset($breadcrumbSlot))
            <div class="tw:mb-2">
                @if(isset($breadcrumbSlot))
                    {{ $breadcrumbSlot }}
                @elseif(is_array($breadcrumbs))
                    <x-ui.breadcrumb :items="$breadcrumbs" />
                @endif
            </div>
        @endif

        @if($eyebrow)
            <p class="tw:m-0 tw:mb-1 tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-primary">{{ $eyebrow }}</p>
        @endif

        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2.5">
            <h1 class="tw:m-0 tw:text-lg tw:shell:text-xl tw:font-bold tw:tracking-tight tw:text-on-surface">{{ $title }}</h1>
            @isset($status)
                <div class="tw:inline-flex tw:items-center">{{ $status }}</div>
            @endisset
        </div>

        @if($description)
            <p class="tw:m-0 tw:mt-1 tw:max-w-3xl tw:text-ui-sm tw:text-on-surface-variant">{{ $description }}</p>
        @endif

        @isset($meta)
            <div class="tw:mt-2.5 tw:flex tw:flex-wrap tw:items-center tw:gap-2">{{ $meta }}</div>
        @endisset
    </div>

    @if($hasRecordNav || isset($actions))
        <div class="tw:flex tw:shrink-0 tw:flex-wrap tw:items-center tw:gap-2 tw:shell:pt-0.5">
            @if($hasRecordNav)
                <nav aria-label="{{ __('navigation.record_nav.label') }}" class="tw:inline-flex tw:items-center tw:overflow-hidden tw:rounded-ui-sm tw:border tw:border-outline-strong tw:bg-surface tw:divide-x tw:divide-outline-variant" data-record-nav-bar>
                    @if($prevUrl)
                        <a
                            href="{{ $prevUrl }}"
                            class="{{ $navBtnBase }} tw:text-on-surface tw:hover:bg-surface-container"
                            data-record-nav="prev"
                            title="{{ __('navigation.record_nav.previous_hint') }}"
                            aria-label="{{ __('navigation.record_nav.previous_hint') }}"
                        >
                            <x-ui.icon name="chevron-left" size="xs" aria-hidden="true" />
                            <span class="tw:hidden tw:sm:inline">{{ __('navigation.record_nav.previous') }}</span>
                        </a>
                    @else
                        <button
                            type="button"
                            class="{{ $navBtnBase }} tw:cursor-not-allowed tw:border-0 tw:bg-surface-container tw:text-on-surface-variant"
                            disabled
                            data-record-nav-disabled="prev"
                            aria-label="{{ __('navigation.record_nav.previous') }}"
                        >
                            <x-ui.icon name="chevron-left" size="xs" aria-hidden="true" />
                            <span class="tw:hidden tw:sm:inline">{{ __('navigation.record_nav.previous') }}</span>
                        </button>
                    @endif

                    @if($navPosition !== null && $navTotal > 0)
                        <span
                            class="ui-tabular-nums tw:inline-flex tw:min-h-9 tw:items-center tw:bg-surface-low tw:px-2.5 tw:text-ui-xs tw:font-medium tw:text-on-surface-variant"
                            aria-label="{{ __('navigation.record_nav.position', ['position' => $navPosition, 'total' => $navTotal]) }}"
                            data-record-nav-position
                        >
                            {{ $navPosition }} / {{ $navTotal }}
                        </span>
                    @endif

                    @if($nextUrl)
                        <a
                            href="{{ $nextUrl }}"
                            class="{{ $navBtnBase }} tw:text-on-surface tw:hover:bg-surface-container"
                            data-record-nav="next"
                            title="{{ __('navigation.record_nav.next_hint') }}"
                            aria-label="{{ __('navigation.record_nav.next_hint') }}"
                        >
                            <span class="tw:hidden tw:sm:inline">{{ __('navigation.record_nav.next') }}</span>
                            <x-ui.icon name="chevron-right" size="xs" aria-hidden="true" />
                        </a>
                    @else
                        <button
                            type="button"
                            class="{{ $navBtnBase }} tw:cursor-not-allowed tw:border-0 tw:bg-surface-container tw:text-on-surface-variant"
                            disabled
                            data-record-nav-disabled="next"
                            aria-label="{{ __('navigation.record_nav.next') }}"
                        >
                            <span class="tw:hidden tw:sm:inline">{{ __('navigation.record_nav.next') }}</span>
                            <x-ui.icon name="chevron-right" size="xs" aria-hidden="true" />
                        </button>
                    @endif
                </nav>
            @endif

            @isset($actions){{ $actions }}@endisset
        </div>
    @endif
</header>
