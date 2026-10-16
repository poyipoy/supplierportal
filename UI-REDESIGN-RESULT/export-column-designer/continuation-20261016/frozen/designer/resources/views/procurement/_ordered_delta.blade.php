{{-- Decision 02: ordered vs requested, display only (the PR quantity never changes). --}}
@php($orderedDelta = $ordered !== null && $requested !== null ? (int) $ordered - (int) $requested : 0)
@if($orderedDelta !== 0)
    <div class="tw-whitespace-nowrap tw-text-ui-xs tw-font-normal tw-text-on-surface-variant" data-ordered-delta>
        {{ __('procurement.ordered_quantity.order_qty_hint_short', ['requested' => $requested, 'delta' => $orderedDelta > 0 ? '+'.$orderedDelta : (string) $orderedDelta]) }}
    </div>
@endif
