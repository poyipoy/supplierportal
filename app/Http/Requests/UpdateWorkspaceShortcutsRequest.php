<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\QuickAccessService;
use App\Services\UserPreferenceService;
use App\Support\PortalContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateWorkspaceShortcutsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function rules(): array
    {
        $max = UserPreferenceService::WORKSPACE_SHORTCUTS_MAX_ITEMS;

        return [
            'scope' => ['sometimes', 'nullable', 'string', Rule::in([PortalContext::SCOPE_IMPORT, PortalContext::SCOPE_LOCAL])],
            'updated_at' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'usage' => ['sometimes', 'array', 'max:'.$max],
            'favorites' => ['sometimes', 'array', 'max:'.$max],
            'favorites.*' => ['string', 'max:255'],
            'favorites_initialized' => ['sometimes', 'boolean'],
            'bookmarks' => ['sometimes', 'array', 'max:'.$max],
            'bookmarks.*' => ['array'],
            'bookmarks.*.url' => ['required', 'string', 'max:500'],
            'bookmarks.*.label' => ['sometimes', 'nullable', 'string', 'max:200'],
            'bookmarks.*.moduleKey' => ['sometimes', 'nullable', 'string', 'max:64'],
            'bookmarks.*.createdAt' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'layout' => ['sometimes', 'nullable', 'array'],
            'layout.weights' => ['sometimes', 'array'],
            'layout.weights.favorites' => ['sometimes', 'numeric', 'gt:0', 'max:1000'],
            'layout.weights.bookmarks' => ['sometimes', 'numeric', 'gt:0', 'max:1000'],
            'layout.weights.frequent' => ['sometimes', 'numeric', 'gt:0', 'max:1000'],
            'layout.minimized' => ['sometimes', 'array'],
            'layout.minimized.favorites' => ['sometimes', 'boolean'],
            'layout.minimized.bookmarks' => ['sometimes', 'boolean'],
            'layout.minimized.frequent' => ['sometimes', 'boolean'],
            'layout.minimizedOrder' => ['sometimes', 'array', 'max:3'],
            'layout.minimizedOrder.*' => ['string', Rule::in(UserPreferenceService::WORKSPACE_SHORTCUTS_PANE_KEYS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();
            if (! $user instanceof User) {
                return;
            }

            if ($user->isSupplier()) {
                $requestedScope = $this->input('scope') ?? app(QuickAccessService::class)->contextFor($user);
                $eligible = match ($requestedScope) {
                    PortalContext::SCOPE_LOCAL => $user->isLocalEligible(),
                    PortalContext::SCOPE_IMPORT => $user->isImportEligible(),
                    default => false,
                };

                if (! $eligible) {
                    $validator->errors()->add('scope', __('customization.supplier_context_changed'));

                    return;
                }
            }

            $usage = $this->input('usage');
            if (is_array($usage)) {
                foreach ($usage as $path => $entry) {
                    if (! is_string($path) || ! UserPreferenceService::isAllowedShortcutPath($path)) {
                        $validator->errors()->add('usage', 'Invalid shortcut usage path.');
                        break;
                    }
                    if (! is_array($entry)) {
                        $validator->errors()->add('usage', 'Invalid shortcut usage entry.');
                        break;
                    }
                    $count = $entry['count'] ?? null;
                    $lastUsedAt = $entry['lastUsedAt'] ?? 0;
                    if (! is_numeric($count) || (int) $count < 1 || ! is_numeric($lastUsedAt) || (int) $lastUsedAt < 0) {
                        $validator->errors()->add('usage', 'Invalid shortcut usage counters.');
                        break;
                    }
                }
            }

            $favorites = $this->input('favorites');
            if (is_array($favorites)) {
                foreach ($favorites as $path) {
                    if (! is_string($path) || ! UserPreferenceService::isAllowedShortcutPath($path)) {
                        $validator->errors()->add('favorites', 'Invalid favorite module path.');
                        break;
                    }
                }
            }

            $bookmarks = $this->input('bookmarks');
            if (is_array($bookmarks)) {
                foreach ($bookmarks as $bookmark) {
                    $url = is_array($bookmark) ? ($bookmark['url'] ?? null) : null;
                    if (! is_string($url) || ! UserPreferenceService::isAllowedBookmarkUrl($url, $this->getHost())) {
                        $validator->errors()->add('bookmarks', 'Invalid bookmark URL.');
                        break;
                    }
                }
            }
        });
    }
}
