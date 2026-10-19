<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use App\Services\Dashboard\DashboardWidgetService;
use Illuminate\Validation\Rule;

/**
 * One definition of the dashboard layout payload, shared by the full Customization form and the
 * layout-only endpoint. Keys come from the submitting user's own audience, so another audience's
 * widgets (or a required widget in `hidden`) are rejected.
 */
trait ValidatesDashboardLayout
{
    protected function dashboardLayoutRules(DashboardWidgetService $dashboardWidgets, mixed $user, bool $required = false): array
    {
        $widgets = $user instanceof User ? $dashboardWidgets->layoutFor($user, []) : [];
        $keys = array_column($widgets, 'key');
        $optionalKeys = array_column(array_filter($widgets, fn (array $widget): bool => ! $widget['required']), 'key');

        return [
            'dashboard' => [$required ? 'required' : 'sometimes', 'array:hidden,order'],
            'dashboard.hidden' => ['sometimes', 'array', 'list', 'max:'.count($optionalKeys)],
            'dashboard.hidden.*' => ['string', 'distinct:strict', Rule::in($optionalKeys)],
            'dashboard.order' => ['sometimes', 'array', 'list', 'max:'.count($keys)],
            'dashboard.order.*' => ['string', 'distinct:strict', Rule::in($keys)],
        ];
    }
}
