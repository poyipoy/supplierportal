<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\QuickAccessService;
use App\Support\PortalContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function rules(): array
    {
        return [
            'theme' => ['required', Rule::in(config('user_preferences.themes'))],
            'density' => ['required', Rule::in(config('user_preferences.densities'))],
            'sidebar_state' => ['required', Rule::in(config('user_preferences.sidebar_states'))],
            'page_size' => ['required', 'integer', Rule::in(config('user_preferences.page_sizes'))],
            'quick_access' => ['sometimes', 'array', 'max:'.(int) config('user_preferences.quick_access_limit')],
            'quick_access.*' => ['string'],
            'supplier_context' => ['nullable', Rule::in([PortalContext::SCOPE_LOCAL, PortalContext::SCOPE_IMPORT])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if (! $user instanceof User) {
                return;
            }

            $quickAccess = app(QuickAccessService::class);
            $actualContext = $quickAccess->contextFor($user);

            if ($user->isSupplier() && $this->input('supplier_context') !== $actualContext) {
                $validator->errors()->add('supplier_context', 'The supplier portal changed. Reload customization before saving.');
            }

            $submittedKeys = (array) $this->input('quick_access', []);
            if (count(array_unique($submittedKeys, SORT_REGULAR)) !== count($submittedKeys)) {
                $validator->errors()->add('quick_access', 'Choose each shortcut only once.');
            }

            foreach ((array) $this->input('quick_access', []) as $key) {
                if (! is_string($key) || ! $quickAccess->isAvailableKey($user, $key, $actualContext)) {
                    $validator->errors()->add('quick_access', 'One or more selected shortcuts are not available for your role or active portal.');
                    break;
                }
            }
        });
    }
}
