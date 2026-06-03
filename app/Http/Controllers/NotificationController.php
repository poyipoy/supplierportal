<?php

namespace App\Http\Controllers;

use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequirement;
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

        if ($this->isUsableNotificationUrl($url, $notification->id)) {
            return $url;
        }

        return $this->fallbackUrlFor($notification) ?? $this->dashboardUrl();
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

        return ! Str::contains($path, "/notifications/{$notificationId}/read");
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
        if (! $po && preg_match('/PO\/\d{2}\/\d{4}\/\d{3}/', $text, $matches)) {
            $po = PurchaseOrder::where('po_number', $matches[0])->first();
        }

        if ($po && Str::contains(Str::lower($text), 'klaim')) {
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
            $pr = PurchaseRequirement::find($data['pr_id']);
        }
        if (! $pr && preg_match('/REQ\/\d{2}\/\d{4}\/\d{3}/', $text, $matches)) {
            $pr = PurchaseRequirement::where('pr_number', $matches[0])->first();
        }

        return $this->purchaseRequirementUrl($pr);
    }

    private function purchaseOrderUrl(?PurchaseOrder $po): ?string
    {
        if (! $po) {
            return null;
        }

        return match (auth()->user()->role) {
            'supplier' => Route::has('supplier.purchase-orders.show')
                ? route('supplier.purchase-orders.show', $po->id)
                : null,
            'purchasing' => Route::has('purchasing.purchase-orders.show')
                ? route('purchasing.purchase-orders.show', $po->id)
                : null,
            default => null,
        };
    }

    private function purchaseRequirementUrl(?PurchaseRequirement $pr): ?string
    {
        if (! $pr) {
            return null;
        }

        if (auth()->user()->role === 'purchasing' && Route::has('purchasing.requirements.show')) {
            return route('purchasing.requirements.show', $pr->id);
        }

        if (auth()->user()->role === 'supplier' && Route::has('supplier.quotations.create')) {
            return route('supplier.quotations.create', $pr->id);
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

        $categoryLabel = NotificationCategory::options()[$category]['label'] ?? 'Notifikasi';
        $message = $category === NotificationCategory::ALL
            ? 'Semua notifikasi telah ditandai dibaca.'
            : "Notifikasi {$categoryLabel} telah ditandai dibaca.";

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
