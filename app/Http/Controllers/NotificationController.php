<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Support\NotificationCategory;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    public function index()
    {
        $dashboardRoute = auth()->user()->role . '.dashboard';

        if (Route::has($dashboardRoute)) {
            return redirect()->route($dashboardRoute);
        }

        return redirect('/');
    }

    public function unreadCount()
    {
        return response()->json(['count' => auth()->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, $id)
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $targetUrl = $this->targetUrlFor($notification);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'redirect' => $targetUrl,
            ]);
        }

        return redirect()->to($targetUrl);
    }

    private function targetUrlFor(DatabaseNotification $notification): string
    {
        $url = trim((string) ($notification->data['url'] ?? ''));

        // If this is a quotation notification, ignore the hardcoded URL and resolve dynamically.
        $category = \App\Support\NotificationCategory::key($notification);
        if ($category === \App\Support\NotificationCategory::QUOTATION) {
            $url = '';
        }

        // Rewrite the URL's host to match the current request so that
        // notifications created on adasi_portal-supplier.test still work
        // when opened from localhost:8000 (and vice versa).
        $url = $this->normalizeNotificationUrl($url);

        if ($this->isUsableNotificationUrl($url, $notification->id)) {
            return $url;
        }

        return $this->fallbackUrlFor($notification) ?? $this->dashboardUrl();
    }

    /**
     * Rewrite the scheme, host, and port of a notification URL to match the
     * current HTTP request, keeping the path and query string intact.
     */
    private function normalizeNotificationUrl(string $url): string
    {
        if ($url === '' || $url === '#') {
            return $url;
        }

        $parsed = parse_url($url);
        if (! isset($parsed['host'])) {
            return $url; // relative URL, nothing to rewrite
        }

        $currentHost = request()->getHost();
        if ($parsed['host'] === $currentHost) {
            return $url; // already correct
        }

        // Rebuild with the current request's scheme, host, and port
        $scheme = request()->getScheme();
        $port = request()->getPort();
        $base = $scheme . '://' . $currentHost;
        if (($scheme === 'http' && $port != 80) || ($scheme === 'https' && $port != 443)) {
            $base .= ':' . $port;
        }

        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return $base . $path . $query . $fragment;
    }

    private function isUsableNotificationUrl(string $url, string $notificationId): bool
    {
        if ($url === '' || $url === '#') {
            return false;
        }

        if (Str::startsWith(Str::lower($url), ['#', 'javascript:', 'mailto:', 'tel:'])) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        if (Str::contains($path, "/notifications/{$notificationId}/read")) {
            return false;
        }

        // Reject URLs whose route prefix belongs to a different role.
        // e.g. a supplier user clicking a URL that starts with /purchasing/...
        $rolePrefix = auth()->user()->role;
        $rolePrefixes = ['admin', 'purchasing', 'supplier', 'qc'];
        foreach ($rolePrefixes as $prefix) {
            if ($prefix === $rolePrefix) {
                continue;
            }
            if (Str::startsWith(ltrim($path, '/'), $prefix . '/')) {
                return false;
            }
        }

        return true;
    }

    private function fallbackUrlFor(DatabaseNotification $notification): ?string
    {
        $data = $notification->data ?? [];
        $text = implode(' ', [
            (string) ($data['title'] ?? ''),
            (string) ($data['message'] ?? ''),
            (string) ($data['po_number'] ?? ''),
            (string) ($data['pr_number'] ?? ''),
        ]);

        if ($conversationUrl = $this->conversationUrl($data['conversation_id'] ?? null)) {
            return $conversationUrl;
        }

        if ($quotationUrl = $this->quotationUrl($data['quotation_id'] ?? null)) {
            return $quotationUrl;
        }

        if ($claimUrl = $this->claimUrl($data['claim_id'] ?? null)) {
            return $claimUrl;
        }

        $po = null;
        if (! empty($data['po_id'])) {
            $po = PurchaseOrder::find($data['po_id']);
        }
        if (! $po && preg_match('/po\/\d{2}\/\d{4}\/\d{3}/i', $text, $matches)) {
            $po = PurchaseOrder::where('po_number', strtoupper($matches[0]))->first();
        }

        if ($po && Str::contains(Str::lower($text), 'claim')) {
            $claim = MaterialClaim::where('po_id', $po->id)->latest()->first();
            if ($claimUrl = $this->claimUrl($claim?->id)) {
                return $claimUrl;
            }
        }

        if ($poUrl = $this->purchaseOrderUrl($po)) {
            return $poUrl;
        }

        $pr = null;
        if (! empty($data['pr_id'])) {
            $pr = PurchaseRequisition::find($data['pr_id']);
        }
        if (! $pr && preg_match('/req\/\d{2}\/\d{4}\/\d{3}/i', $text, $matches)) {
            $pr = PurchaseRequisition::where('pr_number', strtoupper($matches[0]))->first();
        }

        return $this->purchaseRequisitionUrl($pr);
    }

    private function purchaseOrderUrl(?PurchaseOrder $po): ?string
    {
        if (! $po) {
            return null;
        }

        return match (auth()->user()->role) {
            'supplier' => Route::has('supplier.purchase-orders.show')
                ? route('supplier.purchase-orders.show', $po)
                : null,
            'purchasing' => Route::has('purchasing.purchase-orders.show')
                ? route('purchasing.purchase-orders.show', $po)
                : null,
            default => null,
        };
    }

    private function purchaseRequisitionUrl(?PurchaseRequisition $pr): ?string
    {
        if (! $pr) {
            return null;
        }

        if (auth()->user()->role === 'purchasing' && Route::has('purchasing.requisitions.show')) {
            return route('purchasing.requisitions.show', $pr);
        }

        if (auth()->user()->role === 'supplier' && Route::has('supplier.quotations.create')) {
            return route('supplier.quotations.create', $pr);
        }

        return null;
    }

    private function quotationUrl(mixed $quotationId): ?string
    {
        if (! $quotationId || ! Quotation::whereKey($quotationId)->exists()) {
            return null;
        }

        return match (auth()->user()->role) {
            'supplier' => Route::has('supplier.quotations.show')
                ? route('supplier.quotations.show', $quotationId)
                : null,
            'purchasing' => Route::has('purchasing.quotations.show')
                ? route('purchasing.quotations.show', $quotationId)
                : null,
            default => null,
        };
    }

    private function claimUrl(mixed $claimId): ?string
    {
        if (! $claimId || ! MaterialClaim::whereKey($claimId)->exists()) {
            return null;
        }

        return match (auth()->user()->role) {
            'supplier' => Route::has('supplier.claims.show')
                ? route('supplier.claims.show', $claimId)
                : null,
            'purchasing' => Route::has('purchasing.claims.show')
                ? route('purchasing.claims.show', $claimId)
                : null,
            default => null,
        };
    }

    private function conversationUrl(mixed $conversationId): ?string
    {
        if (! $conversationId || ! Conversation::whereKey($conversationId)->exists()) {
            return null;
        }

        return match (auth()->user()->role) {
            'supplier' => Route::has('supplier.conversations.show')
                ? route('supplier.conversations.show', $conversationId)
                : null,
            'purchasing' => Route::has('purchasing.conversations.show')
                ? route('purchasing.conversations.show', $conversationId)
                : null,
            default => null,
        };
    }

    private function dashboardUrl(): string
    {
        $dashboardRoute = auth()->user()->role . '.dashboard';

        return Route::has($dashboardRoute)
            ? route($dashboardRoute)
            : url('/');
    }

    public function markAllRead(Request $request)
    {
        $requestedCategory = $request->input('category', NotificationCategory::ALL);
        $category = is_string($requestedCategory) ? $requestedCategory : NotificationCategory::ALL;
        if (! NotificationCategory::isAllowed($category)) {
            $category = NotificationCategory::ALL;
        }

        $unreadNotifications = auth()->user()
            ->unreadNotifications()
            ->select(['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at'])
            ->get();
        $targetNotifications = $category === NotificationCategory::ALL
            ? $unreadNotifications
            : $unreadNotifications->filter(fn ($notification) => NotificationCategory::key($notification) === $category);

        $markedCount = $targetNotifications->count();
        $targetNotifications->each->markAsRead();

        $categoryLabel = NotificationCategory::options()[$category]['label'] ?? 'Notification';
        $message = $category === NotificationCategory::ALL
            ? 'All notifications have been marked as read.'
            : "{$categoryLabel} notifications have been marked as read.";

        if ($request->expectsJson()) {
            $allNotifications = auth()->user()
                ->notifications()
                ->latest()
                ->take(30)
                ->get();

            $categoryCounts = collect(NotificationCategory::options())->mapWithKeys(function ($option, $key) use ($allNotifications) {
                $items = $key === NotificationCategory::ALL
                    ? $allNotifications
                    : $allNotifications->filter(fn ($notification) => NotificationCategory::key($notification) === $key);

                return [$key => [
                    'total' => $items->count(),
                    'unread' => $items->whereNull('read_at')->count(),
                ]];
            })->all();

            return response()->json([
                'success' => true,
                'category' => $category,
                'marked_count' => $markedCount,
                'message' => $message,
                'unread_count' => auth()->user()->unreadNotifications()->count(),
                'category_counts' => $categoryCounts,
            ]);
        }

        return back()->with('success', $message);
    }
}
