{{--
    Mission 04-W Order Qty + specification acceptance for one award line (Q2,
    decision 04). Rendered only while the ordered-quantity write path is on;
    the server re-validates every value under the award locks.

    @param int         $prItemId
    @param string      $material
    @param int         $requested     PR quantity (never changed)
    @param int         $offered       Supplier offered quantity (input max)
    @param int|null    $value         Persisted ordered_qty; default min(requested, offered)
    @param bool        $disabled      Inactive offer cell (not submitted)
    @param bool        $specDifferent Offer specification differs from the PR
    @param bool        $specAccepted  A current acceptance exists
    @param string|null $requestedSpec
    @param string|null $offeredSpec
    @param string      $idSuffix      Unique per rendered control
--}}
@php
    $default = old("order_qty.{$prItemId}", $value ?? min($requested, $offered));
    $inputId = 'orderQty'.$idSuffix;
    $hintId = 'orderQtyHint'.$idSuffix;
    $errorKey = "order_qty.{$prItemId}";
    $specErrorKey = "accept_spec.{$prItemId}";
    $delta = is_numeric($default) ? (int) $default - $requested : null;
@endphp
<div class="tw:mt-2 tw:grid tw:gap-1 text-start" data-order-qty-control data-pr-item-id="{{ $prItemId }}" data-requested="{{ $requested }}" @if($disabled) hidden @endif>
    <label for="{{ $inputId }}" class="tw:text-ui-xs tw:font-semibold tw:text-on-surface">{{ __('procurement.ordered_quantity.order_qty') }}</label>
    <input
        type="number"
        id="{{ $inputId }}"
        name="order_qty[{{ $prItemId }}]"
        class="form-control form-control-sm text-end ui-tabular-nums @error($errorKey) is-invalid @enderror"
        min="1"
        max="{{ $offered }}"
        step="1"
        value="{{ $default }}"
        required
        @if($disabled) disabled @endif
        aria-label="{{ __('procurement.ordered_quantity.order_qty_for', ['material' => $material]) }}"
        aria-describedby="{{ $hintId }}"
        data-order-qty-input
    >
    <div id="{{ $hintId }}" class="tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums" data-order-qty-hint
         data-template="{{ __('procurement.ordered_quantity.order_qty_hint', ['requested' => $requested, 'offered' => $offered, 'delta' => '__DELTA__']) }}">
        {{ __('procurement.ordered_quantity.order_qty_hint', ['requested' => $requested, 'offered' => $offered, 'delta' => $delta === null ? '—' : ($delta > 0 ? '+'.$delta : (string) $delta)]) }}
    </div>
    @error($errorKey)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

    @if($specDifferent)
        <input type="hidden" name="accept_spec[{{ $prItemId }}]" value="0" @if($disabled) disabled @endif>
        <div class="form-check tw:mt-1">
            <input type="checkbox" class="form-check-input @error($specErrorKey) is-invalid @enderror" id="acceptSpec{{ $idSuffix }}"
                   name="accept_spec[{{ $prItemId }}]" value="1" @checked(old("accept_spec.{$prItemId}", $specAccepted)) @if($disabled) disabled @endif data-accept-spec-input>
            <label class="form-check-label tw:text-ui-xs" for="acceptSpec{{ $idSuffix }}">
                {{ __('procurement.ordered_quantity.accept_spec') }}
                <span class="d-block tw:text-on-surface-variant">{{ __('procurement.ordered_quantity.accept_spec_detail', ['requested' => $requestedSpec ?? '—', 'offered' => $offeredSpec ?? '—']) }}</span>
            </label>
            @error($specErrorKey)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
    @endif
</div>
