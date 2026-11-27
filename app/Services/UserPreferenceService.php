<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPreference;
use App\Services\Dashboard\DashboardWidgetService;
use App\Support\PortalContext;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserPreferenceService
{
    public const CACHE_TTL_SECONDS = 300;

    public const WORKSPACE_SHORTCUTS_STORAGE_KEY = '_workspace_shortcuts';

    public const WORKSPACE_SHORTCUTS_MAX_ITEMS = 50;

    /** @var list<string> */
    public const WORKSPACE_SHORTCUTS_PANE_KEYS = ['favorites', 'bookmarks', 'frequent'];

    private const FORBIDDEN_SHORTCUT_PATH_PATTERN = '#(?:^|/)(?:login|logout|confirm-password|forgot-password|two-factor-challenge|oauth|sso|profile/security|profile/two-factor|supplier-context|verify-receipt|supplier/register|supplier/registration|locale|exports/[^/]+/download|shared/pdf|attachments|local-invoice-documents|supplier-master-documents|ga-claim-documents|export-presets)(?:/|$)#i';

    public static function cacheKey(int $userId): string
    {
        return "user_preferences:{$userId}";
    }

    public static function forgetForUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        if (app()->bound('request')) {
            request()->attributes->remove(self::class.'.'.$userId);
        }

        $cacheKey = self::cacheKey($userId);
        Cache::forget($cacheKey);
        DB::afterCommit(static fn () => Cache::forget($cacheKey));
    }

    public static function normalizeLocale(mixed $locale): string
    {
        return is_string($locale) && in_array($locale, ['en', 'id'], true) ? $locale : 'en';
    }

    public static function normalizeDensity(mixed $density): string
    {
        return is_string($density) && in_array($density, config('user_preferences.densities'), true)
            ? $density : config('user_preferences.defaults.density');
    }

    /** Allow-listed ISO 3166-1 alpha-2 work-location country, or null. */
    public static function normalizeCountry(mixed $country): ?string
    {
        return is_string($country) && isset(config('country_timezones')[$country]) ? $country : null;
    }

    /** Supplier Import display zone: null = follow the country, 'system', or a canonical IANA identifier. */
    public static function normalizeImportTimezone(mixed $timezone): ?string
    {
        if (! is_string($timezone)) {
            return null;
        }

        return $timezone === 'system' || in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : null;
    }

    /**
     * Timestamp display zone for the current request. Only the Supplier Import context uses the
     * country/import preference; every other audience keeps the global regional timezone.
     */
    public static function resolveDisplayTimezone(bool $importContext, array $preferences): string
    {
        $global = (string) ($preferences['timezone'] ?? config('user_preferences.defaults.timezone'));
        if (! $importContext) {
            return $global;
        }

        $explicit = self::normalizeImportTimezone($preferences['import_timezone'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $country = self::normalizeCountry($preferences['country_code'] ?? null);

        return $country !== null ? config('country_timezones')[$country] : $global;
    }

    public function displayTimezone(User $user, array $preferences): string
    {
        return self::resolveDisplayTimezone($this->quickAccess->contextFor($user) === PortalContext::SCOPE_IMPORT, $preferences);
    }

    public function __construct(
        private readonly QuickAccessService $quickAccess,
        private readonly DashboardWidgetService $dashboardWidgets,
    ) {}

    public function for(User $user): array
    {
        $request = request();
        $requestKey = $this->requestCacheKey($user);

        if ($request->attributes->has($requestKey)) {
            return $request->attributes->get($requestKey)['preferences'];
        }

        $userId = $user->getKey();
        $preferences = is_numeric($userId) && (int) $userId > 0
            ? Cache::remember(
                self::cacheKey((int) $userId),
                self::CACHE_TTL_SECONDS,
                fn (): array => $this->loadPreferences($user),
            )
            : $this->loadPreferences($user);

        $request->attributes->set($requestKey, [
            'preferences' => $preferences,
        ]);

        return $preferences;
    }

    /** @return array<string, mixed> */
    private function loadPreferences(User $user): array
    {
        $stored = UserPreference::query()->where('user_id', $user->getKey())->first();

        return $this->buildPreferences($user, $stored);
    }

    /** @return array<string, mixed> */
    private function buildPreferences(User $user, ?UserPreference $stored): array
    {
        $defaults = config('user_preferences.defaults');
        $rawDashboard = is_array($stored?->dashboard_preferences) ? $stored->dashboard_preferences : [];

        return $stored instanceof UserPreference
            ? [
                ...$defaults,
                ...$this->regionalValues($stored),
                'theme' => $stored->theme,
                'density' => self::normalizeDensity($stored->density),
                'sidebar_state' => $stored->sidebar_state,
                'quick_flow' => (bool) ($stored->quick_flow ?? $defaults['quick_flow']),
                'page_size' => $stored->page_size,
                'quick_access' => is_array($stored->quick_access) ? $stored->quick_access : [],
                'revision' => $stored->revision,
                'accent' => isset(config('user_preferences.accents')[$stored->accent]) ? $stored->accent : $defaults['accent'],
                'shell_style' => is_string($stored->shell_style) && isset(config('user_preferences.shell_styles')[$stored->shell_style])
                    ? $stored->shell_style : $defaults['shell_style'],
                'dashboard_preferences' => $this->dashboardWidgets->normalizeLayouts($user, $rawDashboard),
                'workspace_shortcuts' => $this->normalizeAllWorkspaceShortcuts($user, $rawDashboard[self::WORKSPACE_SHORTCUTS_STORAGE_KEY] ?? []),
                'sidebar_revision' => max(1, (int) $stored->sidebar_revision),
                'notification_preferences' => is_array($stored->notification_preferences) ? $stored->notification_preferences : [],
                'locale' => self::normalizeLocale($stored->locale),
                'country_code' => self::normalizeCountry($stored->country_code),
                'import_timezone' => self::normalizeImportTimezone($stored->import_timezone),
            ]
            : [...$defaults, 'revision' => 0, 'notification_preferences' => [], 'workspace_shortcuts' => []];
    }

    /** @param  array<string, mixed>  $preferences */
    private function rememberPreferences(User $user, array $preferences): void
    {
        if (app()->bound('request')) {
            request()->attributes->set($this->requestCacheKey($user), [
                'preferences' => $preferences,
            ]);
        }

        $userId = $user->getKey();
        if (! is_numeric($userId) || (int) $userId <= 0) {
            return;
        }

        $cacheKey = self::cacheKey((int) $userId);
        if (DB::transactionLevel() === 0) {
            Cache::put($cacheKey, $preferences, self::CACHE_TTL_SECONDS);

            return;
        }

        DB::afterCommit(static fn () => Cache::put($cacheKey, $preferences, self::CACHE_TTL_SECONDS));
    }

    public function save(User $user, array $values): array
    {
        return DB::transaction(function () use ($user, $values): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $stored = $lockedUser->preference()->first();
            $submittedKeys = array_values($values['quick_access'] ?? []);

            if ($lockedUser->isSupplier()) {
                $activeContext = $this->quickAccess->contextFor($lockedUser);
                $otherContextKeys = $this->quickAccess->availableInOtherSupplierContext(
                    $lockedUser,
                    $stored?->quick_access ?? [],
                    $activeContext,
                );
                $quickAccess = array_values(array_unique([...$submittedKeys, ...$otherContextKeys]));
            } else {
                $quickAccess = array_values(array_unique($submittedKeys));
            }

            if (count($quickAccess) > (int) config('user_preferences.quick_access_limit')) {
                throw ValidationException::withMessages([
                    'quick_access' => __('customization.shortcuts.limit', ['count' => (int) config('user_preferences.quick_access_limit')]),
                ]);
            }

            $savedAccent = $stored?->accent;
            if (! is_string($savedAccent) || ! isset(config('user_preferences.accents')[$savedAccent])) {
                $savedAccent = config('user_preferences.defaults.accent');
            }
            $savedShellStyle = $stored?->shell_style;
            if (! is_string($savedShellStyle) || ! isset(config('user_preferences.shell_styles')[$savedShellStyle])) {
                $savedShellStyle = config('user_preferences.defaults.shell_style');
            }
            $savedLayouts = is_array($stored?->dashboard_preferences) ? $stored->dashboard_preferences : [];
            $layouts = array_key_exists('dashboard', $values)
                ? $this->dashboardWidgets->mergeLayout($lockedUser, $savedLayouts, $values['dashboard'])
                : $this->dashboardWidgets->normalizeLayouts($lockedUser, $savedLayouts);
            $layouts = $this->withPreservedWorkspaceShortcuts($lockedUser, $layouts, $savedLayouts);
            $previousSidebar = $stored?->sidebar_state ?? config('user_preferences.defaults.sidebar_state');
            $preference = $stored ?? new UserPreference;
            $preference->fill([
                'theme' => $values['theme'],
                'density' => $values['density'],
                'sidebar_state' => $values['sidebar_state'],
                'quick_flow' => array_key_exists('quick_flow', $values)
                    ? (bool) $values['quick_flow']
                    : (bool) ($stored?->quick_flow ?? config('user_preferences.defaults.quick_flow')),
                'page_size' => $values['page_size'],
                'quick_access' => $quickAccess,
                'accent' => $values['accent'] ?? $savedAccent,
                'shell_style' => isset($values['shell_style']) && isset(config('user_preferences.shell_styles')[$values['shell_style']])
                    ? $values['shell_style'] : $savedShellStyle,
                'locale' => self::normalizeLocale($values['locale'] ?? $stored?->locale),
                'dashboard_preferences' => $layouts,
                ...$this->regionalValues($stored, $values),
                // Absent keys keep the stored value, so saves from non-Import contexts never clear it.
                'country_code' => self::normalizeCountry(array_key_exists('country_code', $values) ? $values['country_code'] : $stored?->country_code),
                'import_timezone' => self::normalizeImportTimezone(array_key_exists('import_timezone', $values) ? $values['import_timezone'] : $stored?->import_timezone),
            ]);
            $preference->revision = ($stored?->revision ?? 0) + 1;
            $preference->sidebar_revision = max(1, (int) ($stored?->sidebar_revision ?? 1))
                + ($previousSidebar !== $values['sidebar_state'] ? 1 : 0);
            $lockedUser->preference()->save($preference);
            self::forgetForUser((int) $lockedUser->getKey());
            app(NotificationPreferenceService::class)->forget($lockedUser);

            return $this->for($lockedUser->refresh());
        }, 3);
    }

    public function reset(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $stored = $lockedUser->preference()->first();
            $savedLayouts = is_array($stored?->dashboard_preferences) ? $stored->dashboard_preferences : [];
            $preference = $stored ?? new UserPreference;
            $defaults = config('user_preferences.defaults');
            unset($defaults['sidebar_revision']);
            $preference->fill($defaults);
            $preference->dashboard_preferences = $this->withPreservedWorkspaceShortcuts($lockedUser, [], $savedLayouts);
            $preference->sidebar_revision = max(1, (int) ($stored?->sidebar_revision ?? 1)) + 1;
            $preference->revision = ($stored?->revision ?? 0) + 1;
            $lockedUser->preference()->save($preference);
            self::forgetForUser((int) $lockedUser->getKey());
            app(NotificationPreferenceService::class)->forget($lockedUser);

            return $this->for($lockedUser->refresh());
        }, 3);
    }

    public function saveLocale(User $user, mixed $locale): array
    {
        $locale = self::normalizeLocale($locale);
        $current = $this->for($user);

        if ($current['locale'] === $locale && (int) ($current['revision'] ?? 0) > 0) {
            return $current;
        }

        $saved = DB::transaction(function () use ($user, $locale): UserPreference {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $stored = $lockedUser->preference()->first();
            $preference = $stored ?? new UserPreference;

            if ($stored === null) {
                $preference->fill(config('user_preferences.defaults'));
                $preference->sidebar_revision = 1;
            } elseif (self::normalizeLocale($stored->locale) === $locale) {
                return $stored;
            }

            $preference->locale = $locale;
            $preference->revision = ($stored?->revision ?? 0) + 1;
            $lockedUser->preference()->save($preference);
            self::forgetForUser((int) $lockedUser->getKey());
            app(NotificationPreferenceService::class)->forget($lockedUser);

            return $preference;
        }, 3);

        $preferences = $this->buildPreferences($user, $saved);
        $this->rememberPreferences($user, $preferences);

        return $preferences;
    }

    /**
     * Persists only the user's own audience layout; every other preference is left untouched.
     * Mirrors saveLocale(): row lock, defaults for a first row, revision bump, cache invalidation.
     */
    public function saveDashboardLayout(User $user, array $dashboard): array
    {
        return DB::transaction(function () use ($user, $dashboard): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $stored = $lockedUser->preference()->first();
            $preference = $stored ?? new UserPreference;

            if ($stored === null) {
                $preference->fill(config('user_preferences.defaults'));
                $preference->sidebar_revision = 1;
            }

            $savedLayouts = is_array($stored?->dashboard_preferences) ? $stored->dashboard_preferences : [];
            $mergedLayouts = $this->dashboardWidgets->mergeLayout($lockedUser, $savedLayouts, $dashboard);
            $preference->dashboard_preferences = $this->withPreservedWorkspaceShortcuts($lockedUser, $mergedLayouts, $savedLayouts);
            $preference->revision = ($stored?->revision ?? 0) + 1;
            $lockedUser->preference()->save($preference);
            self::forgetForUser((int) $lockedUser->getKey());
            app(NotificationPreferenceService::class)->forget($lockedUser);

            return $this->for($lockedUser->refresh());
        }, 3);
    }

    public function saveNotificationPreferences(User $user, array $preferences, array $delivery = []): array
    {
        return DB::transaction(function () use ($user, $preferences, $delivery): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $stored = $lockedUser->preference()->first();
            $notifications = app(NotificationPreferenceService::class);
            $notifications->forget($lockedUser);
            $overrides = $notifications->mergeOverrides(
                $lockedUser,
                is_array($stored?->notification_preferences) ? $stored->notification_preferences : [],
                $preferences,
                $delivery,
            );
            $preference = $stored ?? new UserPreference;
            if ($stored === null) {
                $preference->fill(config('user_preferences.defaults'));
                $preference->sidebar_revision = 1;
            }
            $preference->notification_preferences = $overrides === [] ? null : $overrides;
            $preference->revision = ($stored?->revision ?? 0) + 1;
            $lockedUser->preference()->save($preference);
            self::forgetForUser((int) $lockedUser->getKey());
            $notifications->forget($lockedUser);

            return $this->for($lockedUser->refresh());
        }, 3);
    }

    public function resetNotificationPreferences(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $stored = $lockedUser->preference()->first();
            $preference = $stored ?? new UserPreference;
            if ($stored === null) {
                $preference->fill(config('user_preferences.defaults'));
                $preference->sidebar_revision = 1;
            }
            $preference->notification_preferences = null;
            $preference->revision = ($stored?->revision ?? 0) + 1;
            $lockedUser->preference()->save($preference);
            self::forgetForUser((int) $lockedUser->getKey());
            app(NotificationPreferenceService::class)->forget($lockedUser);

            return $this->for($lockedUser->refresh());
        }, 3);
    }

    private function regionalValues(?UserPreference $stored, array $values = []): array
    {
        $regional = [];
        foreach (['timezone' => 'timezones', 'date_format' => 'date_formats', 'time_format' => 'time_formats', 'number_format' => 'number_formats'] as $field => $registry) {
            $value = array_key_exists($field, $values) ? $values[$field] : $stored?->{$field};
            $regional[$field] = is_string($value) && isset(config('regional_display.'.$registry)[$value])
                ? $value : config('user_preferences.defaults.'.$field);
        }

        return $regional;
    }

    public function sidebarCacheVersion(User $user, array $preferences): string
    {
        return 'sidebar-v2:'.(int) $preferences['sidebar_revision'];
    }

    private function requestCacheKey(User $user): string
    {
        return self::class.'.'.$user->getKey();
    }

    public function frontendPayload(User $user, array $preferences): array
    {
        return [
            'theme' => $preferences['theme'],
            'density' => $preferences['density'],
            'densityKeys' => config('user_preferences.densities'),
            'accent' => $preferences['accent'],
            'accentKeys' => array_keys(config('user_preferences.accents')),
            'shellStyle' => $preferences['shell_style'],
            'shellStyleKeys' => array_keys(config('user_preferences.shell_styles')),
            'sidebarState' => $preferences['sidebar_state'],
            'quickFlow' => (bool) ($preferences['quick_flow'] ?? false),
            'pageSize' => $preferences['page_size'],
            'sidebarRevision' => $this->sidebarCacheVersion($user, $preferences),
            'accountId' => (string) $user->getKey(),
            'regional' => [
                ...array_intersect_key($preferences, array_flip(['timezone', 'date_format', 'time_format', 'number_format'])),
                'timezone' => $this->displayTimezone($user, $preferences),
            ],
            'regionalRegistry' => [
                'number_profiles' => config('regional_display.number_profiles'),
                'date_formats' => array_keys(config('regional_display.date_formats')),
                'months' => config('regional_display.months'),
            ],
        ];
    }

    public static function isAllowedShortcutPath(string $rawPath): bool
    {
        $trimmed = trim($rawPath);
        if ($trimmed === '' || strlen($trimmed) > 255 || ! str_starts_with($trimmed, '/') || str_starts_with($trimmed, '//')) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F?#"<>]/', $trimmed)) {
            return false;
        }
        $normalized = rtrim($trimmed, '/') ?: '/';
        if ($normalized === '/' || $normalized === '/dashboard') {
            return false;
        }

        return ! (bool) preg_match(self::FORBIDDEN_SHORTCUT_PATH_PATTERN, $normalized);
    }

    public static function isAllowedBookmarkUrl(string $rawUrl, ?string $allowedHost = null): bool
    {
        $trimmed = trim($rawUrl);
        if ($trimmed === '' || strlen($trimmed) > 500 || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F"<>]/', $trimmed) || preg_match('/^(?:javascript|data|mailto|tel|blob|vbscript):/i', $trimmed)) {
            return false;
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts)) {
            return false;
        }

        if (isset($parts['scheme'])) {
            $scheme = strtolower((string) $parts['scheme']);
            if (! in_array($scheme, ['http', 'https'], true)) {
                return false;
            }
            $host = (string) ($parts['host'] ?? '');
            if ($host === '' || ($allowedHost !== null && $allowedHost !== '' && strcasecmp($host, $allowedHost) !== 0)) {
                return false;
            }
        } elseif (isset($parts['host'])) {
            return false;
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/') ?: '/';
        if (! str_starts_with($path, '/') || $path === '/' || $path === '/dashboard') {
            return false;
        }
        if (preg_match(self::FORBIDDEN_SHORTCUT_PATH_PATTERN, $path)) {
            return false;
        }

        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $queryParams);
            foreach (['signature', 'token', '_embed'] as $sensitiveKey) {
                if (array_key_exists($sensitiveKey, $queryParams)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function resolveWorkspaceShortcutsScope(User $user, ?string $scope = null): string
    {
        if (! $user->isSupplier()) {
            return PortalContext::SCOPE_IMPORT;
        }

        if ($scope === PortalContext::SCOPE_LOCAL && $user->isLocalEligible()) {
            return PortalContext::SCOPE_LOCAL;
        }

        if ($scope === PortalContext::SCOPE_IMPORT && $user->isImportEligible()) {
            return PortalContext::SCOPE_IMPORT;
        }

        $activeContext = $this->quickAccess->contextFor($user);
        if ($activeContext !== null) {
            return $activeContext;
        }

        return $user->isLocalEligible() && ! $user->isImportEligible()
            ? PortalContext::SCOPE_LOCAL
            : PortalContext::SCOPE_IMPORT;
    }

    /**
     * @param  array<string, mixed>  $preferences
     * @return array<string, mixed>
     */
    public function workspaceShortcutsForScope(User $user, array $preferences, ?string $scope = null): array
    {
        $resolvedScope = $this->resolveWorkspaceShortcutsScope($user, $scope);
        $all = is_array($preferences['workspace_shortcuts'] ?? null) ? $preferences['workspace_shortcuts'] : [];

        return is_array($all[$resolvedScope] ?? null) ? $all[$resolvedScope] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function saveWorkspaceShortcuts(User $user, array $payload, ?string $scope = null): array
    {
        $resolvedScope = $this->resolveWorkspaceShortcutsScope($user, $scope);

        return DB::transaction(function () use ($user, $payload, $resolvedScope): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $stored = $lockedUser->preference()->first();
            $preference = $stored ?? new UserPreference;

            if ($stored === null) {
                $preference->fill(config('user_preferences.defaults'));
                $preference->sidebar_revision = 1;
            }

            $savedLayouts = is_array($stored?->dashboard_preferences) ? $stored->dashboard_preferences : [];
            $normalizedDashboard = $this->dashboardWidgets->normalizeLayouts($lockedUser, $savedLayouts);
            $existingAll = $this->normalizeAllWorkspaceShortcuts(
                $lockedUser,
                $savedLayouts[self::WORKSPACE_SHORTCUTS_STORAGE_KEY] ?? [],
            );

            $currentScopeState = is_array($existingAll[$resolvedScope] ?? null) ? $existingAll[$resolvedScope] : [];
            $mergedScopeState = $this->mergeAndSanitizeScopeShortcuts($currentScopeState, $payload);

            if ($this->isScopeShortcutsEmpty($mergedScopeState)) {
                unset($existingAll[$resolvedScope]);
            } else {
                $existingAll[$resolvedScope] = $mergedScopeState;
            }

            if ($existingAll !== []) {
                $normalizedDashboard[self::WORKSPACE_SHORTCUTS_STORAGE_KEY] = $existingAll;
            }

            $preference->dashboard_preferences = $normalizedDashboard;
            $preference->revision = ($stored?->revision ?? 0) + 1;
            $lockedUser->preference()->save($preference);
            self::forgetForUser((int) $lockedUser->getKey());

            $freshUser = $lockedUser->refresh();

            return $this->workspaceShortcutsForScope($freshUser, $this->for($freshUser), $resolvedScope);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $normalizedDashboardLayouts
     * @param  array<string, mixed>  $rawStoredDashboard
     * @return array<string, mixed>
     */
    private function withPreservedWorkspaceShortcuts(User $user, array $normalizedDashboardLayouts, array $rawStoredDashboard): array
    {
        $shortcuts = $this->normalizeAllWorkspaceShortcuts(
            $user,
            $rawStoredDashboard[self::WORKSPACE_SHORTCUTS_STORAGE_KEY] ?? [],
        );

        if ($shortcuts !== []) {
            $normalizedDashboardLayouts[self::WORKSPACE_SHORTCUTS_STORAGE_KEY] = $shortcuts;
        }

        return $normalizedDashboardLayouts;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function normalizeAllWorkspaceShortcuts(User $user, mixed $rawAll): array
    {
        if (! is_array($rawAll)) {
            return [];
        }

        $allowedScopes = $user->isSupplier()
            ? array_values(array_filter([
                $user->isImportEligible() ? PortalContext::SCOPE_IMPORT : null,
                $user->isLocalEligible() ? PortalContext::SCOPE_LOCAL : null,
            ]))
            : [PortalContext::SCOPE_IMPORT];

        $result = [];
        foreach ($allowedScopes as $scopeKey) {
            if (! is_array($rawAll[$scopeKey] ?? null)) {
                continue;
            }
            $normalized = $this->mergeAndSanitizeScopeShortcuts([], $rawAll[$scopeKey]);
            if (! $this->isScopeShortcutsEmpty($normalized)) {
                $result[$scopeKey] = $normalized;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeAndSanitizeScopeShortcuts(array $existing, array $incoming): array
    {
        $usageSource = array_key_exists('usage', $incoming) ? $incoming['usage'] : ($existing['usage'] ?? []);
        $favoritesSource = array_key_exists('favorites', $incoming) ? $incoming['favorites'] : ($existing['favorites'] ?? []);
        $favoritesInitialized = array_key_exists('favorites_initialized', $incoming)
            ? (bool) $incoming['favorites_initialized']
            : (array_key_exists('favoritesInitialized', $incoming)
                ? (bool) $incoming['favoritesInitialized']
                : (array_key_exists('favorites', $incoming) || (bool) ($existing['favoritesInitialized'] ?? false)));
        $bookmarksSource = array_key_exists('bookmarks', $incoming) ? $incoming['bookmarks'] : ($existing['bookmarks'] ?? []);
        $layoutSource = array_key_exists('layout', $incoming) ? $incoming['layout'] : ($existing['layout'] ?? null);
        $updatedAtRaw = $incoming['updated_at'] ?? $incoming['updatedAt'] ?? $existing['updatedAt'] ?? 0;

        return [
            'updatedAt' => is_numeric($updatedAtRaw) && (int) $updatedAtRaw > 0 ? (int) $updatedAtRaw : 0,
            'usage' => $this->sanitizeShortcutsUsage($usageSource),
            'favorites' => $this->sanitizeShortcutsFavorites($favoritesSource),
            'favoritesInitialized' => $favoritesInitialized,
            'bookmarks' => $this->sanitizeShortcutsBookmarks($bookmarksSource),
            'layout' => $this->sanitizeShortcutsLayout($layoutSource),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function isScopeShortcutsEmpty(array $state): bool
    {
        return ($state['usage'] ?? []) === []
            && ($state['favorites'] ?? []) === []
            && ! (bool) ($state['favoritesInitialized'] ?? false)
            && ($state['bookmarks'] ?? []) === []
            && ($state['layout'] ?? null) === null;
    }

    /**
     * @return array<string, array{count: int, lastUsedAt: int}>
     */
    private function sanitizeShortcutsUsage(mixed $rawUsage): array
    {
        if (! is_array($rawUsage)) {
            return [];
        }

        $clean = [];
        foreach ($rawUsage as $rawPath => $entry) {
            if (! is_string($rawPath) || ! is_array($entry) || ! self::isAllowedShortcutPath($rawPath)) {
                continue;
            }
            $normalizedPath = rtrim(trim($rawPath), '/') ?: '/';
            $count = isset($entry['count']) && is_numeric($entry['count']) ? (int) $entry['count'] : 0;
            $lastUsedAt = isset($entry['lastUsedAt']) && is_numeric($entry['lastUsedAt']) ? max(0, (int) $entry['lastUsedAt']) : 0;
            if ($count < 1) {
                continue;
            }
            $clean[$normalizedPath] = [
                'count' => min(100_000, $count),
                'lastUsedAt' => $lastUsedAt,
            ];
            if (count($clean) >= self::WORKSPACE_SHORTCUTS_MAX_ITEMS) {
                break;
            }
        }

        return $clean;
    }

    /**
     * @return list<string>
     */
    private function sanitizeShortcutsFavorites(mixed $rawFavorites): array
    {
        if (! is_array($rawFavorites)) {
            return [];
        }

        $clean = [];
        foreach ($rawFavorites as $rawPath) {
            if (! is_string($rawPath) || ! self::isAllowedShortcutPath($rawPath)) {
                continue;
            }
            $normalizedPath = rtrim(trim($rawPath), '/') ?: '/';
            if (! in_array($normalizedPath, $clean, true)) {
                $clean[] = $normalizedPath;
            }
            if (count($clean) >= self::WORKSPACE_SHORTCUTS_MAX_ITEMS) {
                break;
            }
        }

        return $clean;
    }

    /**
     * @return list<array{url: string, normalizedPath: string, label: string, moduleKey: ?string, createdAt: int}>
     */
    private function sanitizeShortcutsBookmarks(mixed $rawBookmarks): array
    {
        if (! is_array($rawBookmarks)) {
            return [];
        }

        $host = app()->bound('request') ? request()->getHost() : null;
        $seen = [];
        $clean = [];

        foreach ($rawBookmarks as $entry) {
            if (! is_array($entry) || ! is_string($entry['url'] ?? null)) {
                continue;
            }
            $rawUrl = trim($entry['url']);
            if (! self::isAllowedBookmarkUrl($rawUrl, $host)) {
                continue;
            }
            $path = rtrim((string) parse_url($rawUrl, PHP_URL_PATH), '/') ?: '/';
            if (isset($seen[$rawUrl])) {
                continue;
            }
            $seen[$rawUrl] = true;

            $label = isset($entry['label']) && is_string($entry['label'])
                ? trim(strip_tags($entry['label']))
                : '';
            $moduleKey = isset($entry['moduleKey']) && is_string($entry['moduleKey']) && preg_match('/^[a-z0-9_-]{1,64}$/i', trim($entry['moduleKey']))
                ? trim($entry['moduleKey'])
                : null;
            $createdAt = isset($entry['createdAt']) && is_numeric($entry['createdAt']) ? max(0, (int) $entry['createdAt']) : 0;

            $clean[] = [
                'url' => $rawUrl,
                'normalizedPath' => $path,
                'label' => $label !== '' ? mb_substr($label, 0, 200) : $path,
                'moduleKey' => $moduleKey,
                'createdAt' => $createdAt,
            ];

            if (count($clean) >= self::WORKSPACE_SHORTCUTS_MAX_ITEMS) {
                break;
            }
        }

        return $clean;
    }

    /**
     * @return ?array{weights: array{favorites: float, bookmarks: float, frequent: float}, minimized: array{favorites: bool, bookmarks: bool, frequent: bool}, minimizedOrder: list<string>}
     */
    private function sanitizeShortcutsLayout(mixed $rawLayout): ?array
    {
        if (! is_array($rawLayout)) {
            return null;
        }

        $rawMin = is_array($rawLayout['minimized'] ?? null) ? $rawLayout['minimized'] : [];
        $minimized = [
            'favorites' => (bool) ($rawMin['favorites'] ?? false),
            'bookmarks' => (bool) ($rawMin['bookmarks'] ?? false),
            'frequent' => (bool) ($rawMin['frequent'] ?? false),
        ];

        $rawOrder = is_array($rawLayout['minimizedOrder'] ?? null) ? $rawLayout['minimizedOrder'] : [];
        $minimizedOrder = [];
        foreach ($rawOrder as $key) {
            if (is_string($key) && in_array($key, self::WORKSPACE_SHORTCUTS_PANE_KEYS, true) && $minimized[$key] && ! in_array($key, $minimizedOrder, true)) {
                $minimizedOrder[] = $key;
            }
        }
        foreach (self::WORKSPACE_SHORTCUTS_PANE_KEYS as $key) {
            if ($minimized[$key] && ! in_array($key, $minimizedOrder, true)) {
                $minimizedOrder[] = $key;
            }
        }

        $rawWeights = is_array($rawLayout['weights'] ?? null) ? $rawLayout['weights'] : null;
        $validWeights = is_array($rawWeights)
            && isset($rawWeights['favorites'], $rawWeights['bookmarks'], $rawWeights['frequent'])
            && is_numeric($rawWeights['favorites']) && (float) $rawWeights['favorites'] > 0
            && is_numeric($rawWeights['bookmarks']) && (float) $rawWeights['bookmarks'] > 0
            && is_numeric($rawWeights['frequent']) && (float) $rawWeights['frequent'] > 0;

        $source = $validWeights
            ? [
                'favorites' => (float) $rawWeights['favorites'],
                'bookmarks' => (float) $rawWeights['bookmarks'],
                'frequent' => (float) $rawWeights['frequent'],
            ]
            : ['favorites' => 32.0, 'bookmarks' => 32.0, 'frequent' => 36.0];

        $total = ($source['favorites'] + $source['bookmarks'] + $source['frequent']) ?: 100.0;

        return [
            'weights' => [
                'favorites' => round(max(0.01, ($source['favorites'] / $total) * 100.0), 4),
                'bookmarks' => round(max(0.01, ($source['bookmarks'] / $total) * 100.0), 4),
                'frequent' => round(max(0.01, ($source['frequent'] / $total) * 100.0), 4),
            ],
            'minimized' => $minimized,
            'minimizedOrder' => $minimizedOrder,
        ];
    }
}
