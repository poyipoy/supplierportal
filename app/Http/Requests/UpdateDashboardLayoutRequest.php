<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDashboardLayout;
use App\Models\User;
use App\Services\Dashboard\DashboardWidgetService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saves only the signed-in user's own dashboard layout (order and hidden panels), without the
 * theme/density/sidebar fields the full Customization form requires.
 */
class UpdateDashboardLayoutRequest extends FormRequest
{
    use ValidatesDashboardLayout;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && app(DashboardWidgetService::class)->audienceFor($user) !== null;
    }

    public function rules(DashboardWidgetService $dashboardWidgets): array
    {
        return $this->dashboardLayoutRules($dashboardWidgets, $this->user(), required: true);
    }
}
