<?php

namespace App\Services;

use App\Models\User;
use App\Support\NotificationCategory;
use Illuminate\Support\Collection;

class NotificationSummaryService
{
    public function forUser(User $user, int $limit = 30): array
    {
        $categories = NotificationCategory::options();
        $notifications = $user->notifications()
            ->latest()
            ->take($limit)
            ->get(['id', 'data', 'read_at', 'created_at']);
        $unreadNotifications = $user->unreadNotifications()
            ->get(['id', 'data', 'read_at', 'created_at']);

        return $this->summary($categories, $notifications, $unreadNotifications);
    }

    public function countsForUser(User $user, int $limit = 30): array
    {
        $categories = NotificationCategory::options();
        $notifications = $user->notifications()
            ->latest()
            ->take($limit)
            ->get(['id', 'data', 'read_at']);
        $unreadNotifications = $user->unreadNotifications()
            ->get(['id', 'data', 'read_at']);

        $summary = $this->summary($categories, $notifications, $unreadNotifications);

        return [
            'count' => $summary['count'],
            'category_counts' => $summary['category_counts'],
        ];
    }

    private function summary(array $categories, Collection $notifications, Collection $unreadNotifications): array
    {
        $groups = collect($categories)->mapWithKeys(function ($option, string $key) use ($notifications): array {
            return [$key => $this->filterByCategory($notifications, $key)->values()];
        });

        $counts = collect($categories)->mapWithKeys(function ($option, string $key) use ($notifications, $unreadNotifications): array {
            return [$key => [
                'total' => $this->filterByCategory($notifications, $key)->count(),
                'unread' => $this->filterByCategory($unreadNotifications, $key)->count(),
            ]];
        })->all();

        return [
            'categories' => $categories,
            'notifications' => $notifications,
            'groups' => $groups,
            'count' => $unreadNotifications->count(),
            'category_counts' => $counts,
        ];
    }

    private function filterByCategory(Collection $notifications, string $category): Collection
    {
        if ($category === NotificationCategory::ALL) {
            return $notifications;
        }

        return $notifications->filter(
            fn ($notification) => NotificationCategory::key($notification) === $category,
        );
    }
}
