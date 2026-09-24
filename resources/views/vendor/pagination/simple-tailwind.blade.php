@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="tw-flex tw-items-center tw-justify-between tw-w-full">
        {{-- Previous Page Link --}}
        @if ($paginator->onFirstPage())
            <span class="page-item disabled tw-inline-flex tw-items-center tw-gap-1.5 tw-h-8 tw-px-3 tw-rounded-ui-sm tw-border tw-border-outline/40 tw-bg-surface-container-low tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-opacity-50 tw-cursor-not-allowed" aria-disabled="true">
                <x-ui.icon name="chevron-left" size="sm" class="tw-w-3.5 tw-h-3.5" />
                <span>Previous</span>
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="page-link ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-h-8 tw-px-3 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-text-ui-xs tw-font-semibold tw-text-on-surface hover:tw-text-primary hover:tw-bg-surface-container active:tw-bg-surface-high tw-no-underline">
                <x-ui.icon name="chevron-left" size="sm" class="tw-w-3.5 tw-h-3.5" />
                <span>Previous</span>
            </a>
        @endif

        {{-- Next Page Link --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="page-link ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-h-8 tw-px-3 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface tw-text-ui-xs tw-font-semibold tw-text-on-surface hover:tw-text-primary hover:tw-bg-surface-container active:tw-bg-surface-high tw-no-underline">
                <span>Next</span>
                <x-ui.icon name="chevron-right" size="sm" class="tw-w-3.5 tw-h-3.5" />
            </a>
        @else
            <span class="page-item disabled tw-inline-flex tw-items-center tw-gap-1.5 tw-h-8 tw-px-3 tw-rounded-ui-sm tw-border tw-border-outline/40 tw-bg-surface-container-low tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-opacity-50 tw-cursor-not-allowed" aria-disabled="true">
                <span>Next</span>
                <x-ui.icon name="chevron-right" size="sm" class="tw-w-3.5 tw-h-3.5" />
            </span>
        @endif
    </nav>
@endif
