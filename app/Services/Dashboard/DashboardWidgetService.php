<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Support\PortalContext;

class DashboardWidgetService
{
    public const GRID_COLUMNS = 12;

    /**
     * Natural widths a widget may declare. The smallest is 3, so a widened last card in a
     * row (12 minus what precedes it) always lands inside this set too.
     */
    public const SPANS = [3, 4, 5, 6, 7, 8, 9, 12];

    public const KINDS = ['metrics', 'chart', 'table', 'list', 'summary'];

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

    /**
     * Only the user's own audience is editable: an admin viewing the Finance dashboard, or
     * Finance viewing Accounting, may read it but never rearranges it.
     */
    public function ownsAudience(User $user, string $audience): bool
    {
        return $this->audienceFor($user) === $audience;
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
        $rows = [];
        foreach ($layout['order'] as $key) {
            $metadata = $definition['widgets'][$key];
            $rows[] = [
                'key' => $key,
                ...$metadata,
                'label' => __($metadata['label']),
                'span' => self::normalizeSpan($metadata['span'] ?? null),
                'kind' => self::normalizeKind($metadata['kind'] ?? null),
                'visible' => ! in_array($key, $layout['hidden'], true),
            ];
        }

        // A hidden row is absent from the map, so it keeps its natural span for the wireframe.
        $effective = self::computeEffectiveSpans($rows);

        return array_map(fn (array $row): array => [...$row, 'effective_span' => $effective[$row['key']] ?? $row['span']], $rows);
    }

    /**
     * Smart span: lay the visible widgets out in order on a 12-column grid so no row is left short.
     * A full-width widget gets its own row; a partial widget joins the current row while the total
     * stays within 12, otherwise it starts a new one; a row that closes under 12 widens its last
     * widget to fill it. Must stay identical to computeEffectiveSpans() in
     * resources/js/dashboard-layout-core.js (both run the cases in tests/Fixtures/dashboard-smart-span-cases.json).
     *
     * @param  list<array{key: string, span?: mixed, visible?: mixed}>  $items
     * @return array<string, int> effective span per visible key, in order
     */
    public static function computeEffectiveSpans(array $items): array
    {
        $rows = [];
        $current = [];
        $used = 0;

        foreach ($items as $item) {
            if (($item['visible'] ?? false) !== true) {
                continue;
            }

            $span = self::normalizeSpan($item['span'] ?? null);
            if ($used + $span > self::GRID_COLUMNS) {
                $rows[] = $current;
                $current = [];
                $used = 0;
            }

            $current[] = [$item['key'], $span];
            $used += $span;
        }

        if ($current !== []) {
            $rows[] = $current;
        }

        $result = [];
        foreach ($rows as $row) {
            $total = array_sum(array_column($row, 1));
            $lastIndex = array_key_last($row);
            foreach ($row as $index => [$key, $span]) {
                $result[$key] = $index === $lastIndex ? $span + (self::GRID_COLUMNS - $total) : $span;
            }
        }

        return $result;
    }

    public static function normalizeSpan(mixed $span): int
    {
        return is_int($span) && in_array($span, self::SPANS, true) ? $span : self::GRID_COLUMNS;
    }

    public static function normalizeKind(mixed $kind): string
    {
        return is_string($kind) && in_array($kind, self::KINDS, true) ? $kind : 'list';
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
