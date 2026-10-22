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
        $unreadNotifications = $this->excludeSilent($this->filterByDomains(
            $user->unreadNotifications()->get(['id', 'data', 'read_at', 'created_at']),
            $allowedDomains,
        ));

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
        $unreadNotifications = $this->excludeSilent($this->filterByDomains(
            $user->unreadNotifications()->get(['id', 'data', 'read_at']),
            $allowedDomains,
        ));

        $summary = $this->summary($categories, $notifications, $unreadNotifications);

        return [
            'count' => $summary['count'],
            'category_counts' => $summary['category_counts'],
        ];
    }

    private function excludeSilent(Collection $notifications): Collection
    {
        return $notifications->reject(
            fn ($notification) => ($notification->data['silent'] ?? false) === true
        )->values();
    }

    private function filterByDomains(Collection $notifications, array $allowedDomains): Collection
    {
        return $notifications->filter(
            fn ($notification) => in_array(NotificationDomain::forNotification($notification), $allowedDomains, true),
        )->values();
    }

    private function summary(array $categories, Collection $notifications, Collection $unreadNotifications): array
    {
        // Classify each notification once; classification decodes its JSON data,
        // and repeating it per category made the unread count O(categories × unread).
        $loadedKeys = $notifications->map(fn ($notification) => NotificationCategory::key($notification))->all();
        $unreadTally = $unreadNotifications->map(fn ($notification) => NotificationCategory::key($notification))->countBy()->all();
        $loadedTally = array_count_values($loadedKeys);

        $groups = collect($categories)->mapWithKeys(function ($option, string $key) use ($notifications, $loadedKeys): array {
            return [$key => $key === NotificationCategory::ALL
                ? $notifications->values()
                : $notifications->filter(fn ($notification, $index) => $loadedKeys[$index] === $key)->values()];
        });

        $counts = collect($categories)->mapWithKeys(function ($option, string $key) use ($notifications, $unreadNotifications, $unreadTally, $loadedTally): array {
            $unreadCount = $key === NotificationCategory::ALL ? $unreadNotifications->count() : ($unreadTally[$key] ?? 0);
            $loadedTotal = $key === NotificationCategory::ALL ? $notifications->count() : ($loadedTally[$key] ?? 0);

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
}
