{{--
    The one pagination view: every ->links() call and pagination::bootstrap-5 land here.
    Markup mirrors Bootstrap (ul.pagination > li.page-item > .page-link) so the single skin in
    resources/css/app.css also styles DataTables' own pager. The page window (first, last,
    current ±1) is computed here and ignores $elements, so onEachSide() has no effect.
    Pass ['label' => ...] when a page renders more than one pager so each landmark is unique.
--}}
@if ($paginator->total() > 0)
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $window = collect([1, $last, $current - 1, $current, $current + 1])
            ->filter(fn ($page) => $page >= 1 && $page <= $last)
            ->unique()
            ->sort()
            ->values();

        $items = [];
        $previous = 0;
        foreach ($window as $page) {
            if ($previous && $page - $previous === 2) {
                $items[] = $previous + 1;
            } elseif ($previous && $page - $previous > 2) {
                $items[] = null;
            }
            $items[] = $page;
            $previous = $page;
        }
    @endphp
    <nav aria-label="{{ $label ?? __('pagination.navigation') }}" class="ui-pagination">
        <p class="ui-pagination__summary">
            {{ __('pagination.summary', ['first' => $paginator->firstItem() ?? 0, 'last' => $paginator->lastItem() ?? 0, 'total' => $paginator->total()]) }}
        </p>

        @if ($paginator->hasPages())
            <ul class="pagination">
                @if ($paginator->onFirstPage())
                    <li class="page-item previous disabled" aria-disabled="true">
                        <span class="page-link">
                            <x-ui.icon name="chevron-left" size="sm" class="tw:w-3.5 tw:h-3.5" />
                            <span class="tw:sr-only tw:sm:not-sr-only">{{ __('pagination.previous') }}</span>
                        </span>
                    </li>
                @else
                    <li class="page-item previous">
                        <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">
                            <x-ui.icon name="chevron-left" size="sm" class="tw:w-3.5 tw:h-3.5" />
                            <span class="tw:sr-only tw:sm:not-sr-only">{{ __('pagination.previous') }}</span>
                        </a>
                    </li>
                @endif

                @foreach ($items as $page)
                    @if ($page === null)
                        <li class="page-item disabled" aria-hidden="true"><span class="page-link">…</span></li>
                    @elseif ($page === $current)
                        <li class="page-item active" aria-current="page"><span class="page-link">{{ $page }}</span></li>
                    @else
                        <li class="page-item">
                            <a class="page-link" href="{{ $paginator->url($page) }}" aria-label="{{ __('pagination.page', ['page' => $page]) }}">{{ $page }}</a>
                        </li>
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <li class="page-item next">
                        <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">
                            <span class="tw:sr-only tw:sm:not-sr-only">{{ __('pagination.next') }}</span>
                            <x-ui.icon name="chevron-right" size="sm" class="tw:w-3.5 tw:h-3.5" />
                        </a>
                    </li>
                @else
                    <li class="page-item next disabled" aria-disabled="true">
                        <span class="page-link">
                            <span class="tw:sr-only tw:sm:not-sr-only">{{ __('pagination.next') }}</span>
                            <x-ui.icon name="chevron-right" size="sm" class="tw:w-3.5 tw:h-3.5" />
                        </span>
                    </li>
                @endif
            </ul>
        @endif
    </nav>
@endif
