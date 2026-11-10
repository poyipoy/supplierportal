<?php

namespace App\Http\Middleware;

use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Maintenance\MaintenanceResolver;
use App\Support\BusinessTime;
use App\Support\Maintenance\MaintenanceModuleRegistry;
use App\Support\Maintenance\RouteClassification;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops requests that touch a module under maintenance, on every route: pages,
 * AJAX/JSON, downloads and mutations alike. Every signed-in role is covered
 * except admin, who must stay free to end the maintenance. Guests and background
 * work pass untouched. Runs before role/scope middleware, so a module only blocks
 * roles that work in that module's core (suppliers: the scopes they hold); the
 * role/scope check still answers everyone else.
 */
class EnforceMaintenance
{
    public function __construct(private readonly MaintenanceModuleRegistry $registry) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user instanceof User || $user->isAdmin()) {
            return $next($request);
        }

        $classification = $this->registry->classify($request);
        if ($classification->isAlwaysAllowed()) {
            return $next($request);
        }

        $resolver = new MaintenanceResolver;
        if ($resolver->effectiveRecords()->isEmpty()) {
            return $next($request);
        }

        $scopes = $this->registry->scopesFor($user);
        $moduleKeys = $classification->kind === RouteClassification::MODULE
            ? array_values(array_filter(
                $classification->modules,
                fn (string $key): bool => in_array((string) $this->registry->scopeOf($key), $scopes, true),
            ))
            : [];

        $global = $resolver->globalRecords();
        if ($global->isNotEmpty()) {
            return $this->blocked($request, true, $global->concat($resolver->moduleRecordsFor($moduleKeys)));
        }

        if ($moduleKeys === []) {
            return $next($request);
        }

        $blocking = $resolver->moduleRecordsFor($moduleKeys);

        return $blocking->isEmpty() ? $next($request) : $this->blocked($request, false, $blocking);
    }

    /** @param Collection<int, MaintenanceRecord> $records */
    private function blocked(Request $request, bool $global, Collection $records): Response
    {
        $notices = $records->unique('id')->map(fn (MaintenanceRecord $record): array => [
            'title' => $record->title,
            'description' => $record->description,
            'starts_at' => BusinessTime::format($record->starts_at),
            'estimated_end_at' => BusinessTime::format($record->estimated_end_at),
        ])->values();

        if ($request->expectsJson() || $request->ajax()) {
            $response = response()->json([
                'success' => false,
                'message' => __('maintenance.notice.unavailable'),
                'maintenance' => [
                    'scope' => $global ? MaintenanceRecord::TYPE_GLOBAL : MaintenanceRecord::TYPE_MODULE,
                    'notices' => $notices,
                ],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } else {
            $response = response()->view(
                $global ? 'maintenance.global' : 'maintenance.module',
                ['notices' => $notices],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
