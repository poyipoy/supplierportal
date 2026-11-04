{{-- Prev/Next only (simplePaginate). Same skin and markup as tailwind.blade.php; no total, so no summary. --}}
@if ($paginator->hasPages())
    <nav aria-label="{{ $label ?? __('pagination.navigation') }}" class="ui-pagination">
        <ul class="pagination tw:w-full tw:justify-between">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link">
                        <x-ui.icon name="chevron-left" size="sm" class="tw:w-3.5 tw:h-3.5" />
                        <span>{{ __('pagination.previous') }}</span>
                    </span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">
                        <x-ui.icon name="chevron-left" size="sm" class="tw:w-3.5 tw:h-3.5" />
                        <span>{{ __('pagination.previous') }}</span>
                    </a>
                </li>
            @endif

            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">
                        <span>{{ __('pagination.next') }}</span>
                        <x-ui.icon name="chevron-right" size="sm" class="tw:w-3.5 tw:h-3.5" />
                    </a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link">
                        <span>{{ __('pagination.next') }}</span>
                        <x-ui.icon name="chevron-right" size="sm" class="tw:w-3.5 tw:h-3.5" />
                    </span>
                </li>
            @endif
        </ul>
    </nav>
@endif
