<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuthAuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AuthAuditLogController extends Controller
{
    public function index(): View
    {
        return view('admin.auth-audit-logs.index', [
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'events' => AuthAuditLog::EVENTS,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $userFilter = $this->resolveUserFilter($request->query('user_id'));

        $validated = $request->validate([
            'user_id' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'event' => ['nullable', 'string', 'in:'.implode(',', AuthAuditLog::EVENTS)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $query = AuthAuditLog::query()
            ->with('user:id,name,email')
            ->when($userFilter, fn ($query, User $user) => $query->where('user_id', $user->getKey()))
            ->when($validated['email'] ?? null, fn ($query, $email) => $query->where('email_attempted', 'like', '%'.trim($email).'%'))
            ->when($validated['event'] ?? null, fn ($query, $event) => $query->where('event', $event))
            ->when($validated['date_from'] ?? null, fn ($query, $date) => $query->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($validated['date_to'] ?? null, fn ($query, $date) => $query->where('created_at', '<=', Carbon::parse($date)->endOfDay()))
            ->orderByDesc('created_at');

        return DataTables::eloquent($query)
            ->addColumn('user_display', fn (AuthAuditLog $log): string => $log->user?->name ?? 'Unknown / deleted user')
            ->editColumn('email_attempted', fn (AuthAuditLog $log): string => $log->email_attempted ?? '—')
            ->editColumn('ip_address', fn (AuthAuditLog $log): string => $log->ip_address ?? '—')
            ->editColumn('metadata', fn (AuthAuditLog $log): string => $log->metadata ? json_encode($log->metadata, JSON_UNESCAPED_SLASHES) : '—')
            ->editColumn('created_at', fn (AuthAuditLog $log): string => $log->created_at?->format('d M Y H:i:s') ?? '—')
            ->toJson();
    }

    private function resolveUserFilter(mixed $value): ?User
    {
        if ($value === null || $value === '') {
            return null;
        }

        abort_unless(is_string($value) && ! ctype_digit($value), 404);

        $user = (new User)->resolveRouteBinding($value);
        abort_unless($user instanceof User, 404);

        return $user;
    }
}
