<?php

namespace App\Http\Controllers;

use App\Services\NotificationSummaryService;
use App\Services\NotificationUrlResolver;
use App\Support\NotificationCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationUrlResolver $urlResolver,
        private readonly NotificationSummaryService $summaryService,
    ) {}

    public function index(Request $request)
    {
        return redirect()->to($this->dashboardUrl($request));
    }

    public function unreadCount(Request $request)
    {
        return response()->json($this->summaryService->countsForUser($request->user()));
    }

    public function summary(Request $request)
    {
        $summary = $this->summaryService->forUser($request->user());
        $counts = collect($summary['category_counts']);

        return view('partials.notification-panel', [
            'notificationCategories' => $summary['categories'],
            'navbarNotifications' => $summary['notifications'],
            'navbarNotificationGroups' => $summary['groups'],
            'navbarNotificationCounts' => $counts->map(fn ($count) => $count['unread']),
            'navbarNotificationTotals' => $counts->map(fn ($count) => $count['total']),
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $targetUrl = $this->urlResolver->resolve($notification, $request->user());
        $notification->markAsRead();

        if ($request->expectsJson()) {
            return response()->json(array_merge([
                'success' => true,
                'redirect' => $targetUrl,
            ], $this->summaryService->countsForUser($request->user())));
        }

        return redirect()->to($targetUrl);
    }

    public function markAllRead(Request $request)
    {
        $requestedCategory = $request->input('category');
        $category = $requestedCategory === null || $requestedCategory === ''
            ? NotificationCategory::ALL
            : $requestedCategory;

        $request->merge(['category' => $category]);
        $validated = $request->validate([
            'category' => ['required', 'string', Rule::in(array_keys(NotificationCategory::options()))],
        ]);
        $category = $validated['category'];

        $unreadNotifications = $request->user()
            ->unreadNotifications()
            ->select(['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at'])
            ->get();

        $ids = $category === NotificationCategory::ALL
            ? $unreadNotifications->pluck('id')
            : $unreadNotifications
                ->filter(fn ($notification) => NotificationCategory::key($notification) === $category)
                ->pluck('id');

        $markedCount = $ids->count();
        if ($markedCount > 0) {
            $request->user()->notifications()
                ->whereIn('id', $ids->all())
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        $categoryLabel = NotificationCategory::options()[$category]['label'] ?? 'Notification';
        $message = $category === NotificationCategory::ALL
            ? 'All notifications have been marked as read.'
            : "{$categoryLabel} notifications have been marked as read.";

        if ($request->expectsJson()) {
            $summary = $this->summaryService->countsForUser($request->user());

            return response()->json(array_merge([
                'success' => true,
                'category' => $category,
                'marked_count' => $markedCount,
                'message' => $message,
                'unread_count' => $summary['count'],
            ], [
                'category_counts' => $summary['category_counts'],
            ]));
        }

        return back()->with('success', $message);
    }

    private function dashboardUrl(Request $request): string
    {
        $dashboardRoute = $request->user()->role.'.dashboard';

        return Route::has($dashboardRoute)
            ? route($dashboardRoute, absolute: false)
            : '/';
    }
}
