@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="tw-flex tw-flex-col sm:tw-flex-row tw-items-center tw-justify-between tw-gap-3.5 tw-w-full">
        {{-- Results Summary --}}
        <div class="tw-text-ui-xs tw-text-on-surface-variant tw-text-center sm:tw-text-left">
            <span>{{ __('Showing') }}</span>
            @if ($paginator->firstItem())
                <span class="tw-font-semibold tw-text-on-surface">{{ $paginator->firstItem() }}</span>
                <span>{{ __('to') }}</span>
                <span class="tw-font-semibold tw-text-on-surface">{{ $paginator->lastItem() }}</span>
            @else
                <span class="tw-font-semibold tw-text-on-surface">{{ $paginator->count() }}</span>
            @endif
            <span>{{ __('of') }}</span>
            <span class="tw-font-semibold tw-text-on-surface">{{ $paginator->total() }}</span>
            <span>{{ __('results') }}</span>
        </div>

        {{-- Page Buttons Container --}}
        <div class="pagination tw-flex tw-items-center tw-gap-1.5 tw-flex-wrap tw-justify-center tw-m-0 tw-p-0">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <span class="page-item disabled tw-inline-flex tw-items-center tw-gap-1.5 tw-h-8 tw-px-3 tw-rounded-ui-sm tw-border tw-border-outline/40 tw-bg-surface-container-low tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-opacity-50 tw-cursor-not-allowed" aria-disabled="true" aria-label="Previous">
                    <x-ui.icon name="chevron-left" size="sm" class="tw-w-3.5 tw-h-3.5" />
                    <span class="tw-hidden sm:tw-inline">Previous</span>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="page-link ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-h-8 tw-px-3 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-text-ui-xs tw-font-semibold tw-text-on-surface hover:tw-text-primary hover:tw-bg-surface-container active:tw-bg-surface-high tw-no-underline" aria-label="Previous">
                    <x-ui.icon name="chevron-left" size="sm" class="tw-w-3.5 tw-h-3.5" />
                    <span class="tw-hidden sm:tw-inline">Previous</span>
                </a>
            @endif

            {{-- Numbered Page Links --}}
            @foreach ($elements as $element)
                {{-- "Three Dots" Separator --}}
                @if (is_string($element))
                    <span class="tw-inline-flex tw-items-center tw-justify-center tw-w-8 tw-h-8 tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant" aria-disabled="true">
                        {{ $element }}
                    </span>
                @endif

                {{-- Array Of Links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="page-item active tw-inline-flex tw-items-center tw-justify-center tw-min-w-[32px] tw-h-8 tw-px-2.5 tw-rounded-ui-sm tw-border tw-border-primary tw-bg-primary tw-text-ui-xs tw-font-bold tw-text-white tw-shadow-sm" aria-current="page">
                                {{ $page }}
                            </span>
                        @else
                            <a href="{{ $url }}" class="page-link ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-justify-center tw-min-w-[32px] tw-h-8 tw-px-2.5 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-text-ui-xs tw-font-semibold tw-text-on-surface hover:tw-text-primary hover:tw-bg-surface-container active:tw-bg-surface-high tw-no-underline" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="page-link ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-h-8 tw-px-3 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-text-ui-xs tw-font-semibold tw-text-on-surface hover:tw-text-primary hover:tw-bg-surface-container active:tw-bg-surface-high tw-no-underline" aria-label="Next">
                    <span class="tw-hidden sm:tw-inline">Next</span>
                    <x-ui.icon name="chevron-right" size="sm" class="tw-w-3.5 tw-h-3.5" />
                </a>
            @else
                <span class="page-item disabled tw-inline-flex tw-items-center tw-gap-1.5 tw-h-8 tw-px-3 tw-rounded-ui-sm tw-border tw-border-outline/40 tw-bg-surface-container-low tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-opacity-50 tw-cursor-not-allowed" aria-disabled="true" aria-label="Next">
                    <span class="tw-hidden sm:tw-inline">Next</span>
                    <x-ui.icon name="chevron-right" size="sm" class="tw-w-3.5 tw-h-3.5" />
                </span>
            @endif
        </div>
    </nav>
@endif
