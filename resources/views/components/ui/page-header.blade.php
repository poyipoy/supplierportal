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
    $posLabel = ($navPosition !== null && $navTotal > 0)
        ? __('navigation.record_nav.position', ['position' => $navPosition, 'total' => $navTotal])
        : '';
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
                <nav aria-label="{{ __('navigation.record_nav.label') }}" class="ui-record-nav" data-record-nav-bar>
                    @if($prevUrl)
                        <a
                            href="{{ $prevUrl }}"
                            class="ui-record-nav__btn ui-record-nav__btn--prev ui-focus-ring"
                            data-record-nav="prev"
                            data-workspace-detail-switch="true"
                            title="{{ __('navigation.record_nav.previous_hint') }}"
                            aria-label="{{ __('navigation.record_nav.previous_hint') }}"
                        >
                            <x-ui.icon name="chevron-left" size="xs" aria-hidden="true" />
                            <span class="ui-record-nav__label">{{ __('navigation.record_nav.previous') }}</span>
                        </a>
                    @else
                        <button
                            type="button"
                            class="ui-record-nav__btn ui-record-nav__btn--prev"
                            disabled
                            data-record-nav-disabled="prev"
                            title="{{ __('navigation.record_nav.previous') }}"
                            aria-label="{{ __('navigation.record_nav.previous') }}"
                        >
                            <x-ui.icon name="chevron-left" size="xs" aria-hidden="true" />
                            <span class="ui-record-nav__label">{{ __('navigation.record_nav.previous') }}</span>
                        </button>
                    @endif

                    @if($navPosition !== null && $navTotal > 0)
                        <span
                            class="ui-record-nav__counter ui-tabular-nums"
                            title="{{ $posLabel }}"
                            aria-label="{{ $posLabel }}"
                            data-record-nav-position="{{ $navPosition }}/{{ $navTotal }}"
                        >
                            <span class="ui-record-nav__pos">{{ $navPosition }}</span><span class="ui-record-nav__sep" aria-hidden="true">/</span><span class="ui-record-nav__total">{{ $navTotal }}</span>
                        </span>
                    @endif

                    @if($nextUrl)
                        <a
                            href="{{ $nextUrl }}"
                            class="ui-record-nav__btn ui-record-nav__btn--next ui-focus-ring"
                            data-record-nav="next"
                            data-workspace-detail-switch="true"
                            title="{{ __('navigation.record_nav.next_hint') }}"
                            aria-label="{{ __('navigation.record_nav.next_hint') }}"
                        >
                            <span class="ui-record-nav__label">{{ __('navigation.record_nav.next') }}</span>
                            <x-ui.icon name="chevron-right" size="xs" aria-hidden="true" />
                        </a>
                    @else
                        <button
                            type="button"
                            class="ui-record-nav__btn ui-record-nav__btn--next"
                            disabled
                            data-record-nav-disabled="next"
                            title="{{ __('navigation.record_nav.next') }}"
                            aria-label="{{ __('navigation.record_nav.next') }}"
                        >
                            <span class="ui-record-nav__label">{{ __('navigation.record_nav.next') }}</span>
                            <x-ui.icon name="chevron-right" size="xs" aria-hidden="true" />
                        </button>
                    @endif
                </nav>
            @endif

            @isset($actions){{ $actions }}@endisset
        </div>
    @endif
</header>
