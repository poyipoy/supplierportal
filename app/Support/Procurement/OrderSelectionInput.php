<?php

namespace App\Support\Procurement;

use Illuminate\Http\Request;

/**
 * Reads the Mission 04-W award inputs `order_qty[pr_item_id]` and
 * `accept_spec[pr_item_id]` into the shape PrItemAwardService::applySelection
 * expects. Values are passed through unvalidated: the service validates them
 * under the award locks and keys errors to the line. Only keys the request
 * actually sent are included, so an absent field never clears a selection.
 */
final class OrderSelectionInput
{
    /**
     * @return array<int, array{order_qty?: mixed, accept_spec?: mixed}>
     */
    public static function fromRequest(Request $request): array
    {
        $selections = [];
        foreach (['order_qty', 'accept_spec'] as $field) {
            $values = $request->input($field);
            if (! is_array($values)) {
                continue;
            }
            foreach ($values as $prItemId => $value) {
                $id = filter_var($prItemId, FILTER_VALIDATE_INT);
                if ($id !== false && $id > 0 && is_scalar($value)) {
                    $selections[$id][$field] = $value;
                }
            }
        }

        return $selections;
    }
}
