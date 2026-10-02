<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Support\PortalContext;

class DashboardWidgetService
{
    public function audienceFor(User $user, ?string $routeName = null): ?string
    {
        if ($routeName !== null) {
            foreach (config('dashboard_widgets.audiences', []) as $audience => $definition) {
                if ($definition['route'] === $routeName) {
                    return $this->eligible($user, $definition)
                        && ($definition['context'] === null || PortalContext::current($user) === $definition['context'])
                        ? $audience : null;
                }
            }

            return null;
        }

        if ($user->isSupplier()) {
            $context = PortalContext::current($user);
            $audience = $context !== null ? 'supplier.'.$context : null;
        } else {
            $audience = $user->role;
        }

        $definition = config('dashboard_widgets.audiences', [])[$audience ?? ''] ?? null;

        return is_array($definition) && $this->eligible($user, $definition) ? $audience : null;
    }

    public function layoutFor(User $user, array $savedLayouts, ?string $audience = null): array
    {
        $audience ??= $this->audienceFor($user);
        $definition = config('dashboard_widgets.audiences', [])[$audience ?? ''] ?? null;

        if (! is_array($definition) || ! $this->eligible($user, $definition)
            || ($definition['context'] !== null && PortalContext::current($user) !== $definition['context'])) {
            return [];
        }

        $layout = $this->normalizeLayout($definition['widgets'], $savedLayouts[$audience] ?? []);
        $result = [];
        foreach ($layout['order'] as $key) {
            $metadata = $definition['widgets'][$key];
            $result[] = ['key' => $key, ...$metadata, 'visible' => ! in_array($key, $layout['hidden'], true)];
        }

        return $result;
    }

    public function normalizeLayouts(User $user, array $savedLayouts): array
    {
        $result = [];
        foreach (config('dashboard_widgets.audiences', []) as $audience => $definition) {
            if (array_key_exists($audience, $savedLayouts) && $this->eligible($user, $definition)) {
                $result[$audience] = $this->normalizeLayout($definition['widgets'], $savedLayouts[$audience]);
            }
        }

        return $result;
    }

    public function mergeLayout(User $user, array $savedLayouts, array $submitted): array
    {
        $layouts = $this->normalizeLayouts($user, $savedLayouts);
        $audience = $this->audienceFor($user);
        if ($audience !== null) {
            $layouts[$audience] = $this->normalizeLayout(config('dashboard_widgets.audiences')[$audience]['widgets'], $submitted);
        }

        return $layouts;
    }

    private function normalizeLayout(array $widgets, mixed $layout): array
    {
        $layout = is_array($layout) ? $layout : [];
        $order = $this->trustedKeys($layout['order'] ?? [], $widgets);
        $hidden = array_values(array_filter($this->trustedKeys($layout['hidden'] ?? [], $widgets),
            fn (string $key): bool => ! $widgets[$key]['required']));

        return ['hidden' => $hidden, 'order' => array_values(array_unique([...$order, ...array_keys($widgets)]))];
    }

    private function trustedKeys(mixed $keys, array $widgets): array
    {
        if (! is_array($keys)) {
            return [];
        }

        // Bound saved-input inspection by the finite registry size.
        $selected = [];
        foreach (array_slice($keys, 0, count($widgets) * 2) as $key) {
            if (is_string($key) && isset($widgets[$key]) && ! in_array($key, $selected, true)) {
                $selected[] = $key;
            }
        }

        return $selected;
    }

    private function eligible(User $user, array $definition): bool
    {
        if (! in_array($user->role, $definition['roles'], true)) {
            return false;
        }

        return match ($definition['context']) {
            PortalContext::SCOPE_IMPORT => $user->isImportEligible(),
            PortalContext::SCOPE_LOCAL => $user->isLocalEligible(),
            default => true,
        };
    }
}
