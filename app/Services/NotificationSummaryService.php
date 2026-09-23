<?php

namespace App\Services;

use App\Models\User;
use App\Support\NotificationCategory;
use App\Support\NotificationDomain;
use Illuminate\Support\Collection;

class NotificationSummaryService
{
    public function forUser(User $user, int $limit = 30): array
    {
        $categories = NotificationCategory::optionsForUser($user);
        $allowedDomains = NotificationDomain::allowedDomainsForUser($user);

        $notifications = $this->filterByDomains(
            $user->notifications()->latest()->take($limit)->get(['id', 'data', 'read_at', 'created_at']),
            $allowedDomains,
        );
        $unreadNotifications = $this->filterByDomains(
            $user->unreadNotifications()->get(['id', 'data', 'read_at', 'created_at']),
            $allowedDomains,
        );

        return $this->summary($categories, $notifications, $unreadNotifications);
    }

    public function countsForUser(User $user, int $limit = 30): array
    {
        $categories = NotificationCategory::optionsForUser($user);
        $allowedDomains = NotificationDomain::allowedDomainsForUser($user);

        $notifications = $this->filterByDomains(
            $user->notifications()->latest()->take($limit)->get(['id', 'data', 'read_at']),
            $allowedDomains,
        );
        $unreadNotifications = $this->filterByDomains(
            $user->unreadNotifications()->get(['id', 'data', 'read_at']),
            $allowedDomains,
        );

        $summary = $this->summary($categories, $notifications, $unreadNotifications);

        return [
            'count' => $summary['count'],
            'category_counts' => $summary['category_counts'],
        ];
    }

    private function filterByDomains(Collection $notifications, array $allowedDomains): Collection
    {
        return $notifications->filter(
            fn ($notification) => in_array(NotificationDomain::forNotification($notification), $allowedDomains, true),
        )->values();
    }

    private function summary(array $categories, Collection $notifications, Collection $unreadNotifications): array
    {
        $groups = collect($categories)->mapWithKeys(function ($option, string $key) use ($notifications): array {
            return [$key => $this->filterByCategory($notifications, $key)->values()];
        });

        $counts = collect($categories)->mapWithKeys(function ($option, string $key) use ($notifications, $unreadNotifications): array {
            $unreadCount = $this->filterByCategory($unreadNotifications, $key)->count();
            $loadedTotal = $this->filterByCategory($notifications, $key)->count();

            return [$key => [
                'total' => max($unreadCount, $loadedTotal),
                'unread' => $unreadCount,
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
