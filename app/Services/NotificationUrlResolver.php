<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class NotificationUrlResolver
{
    public function resolve(DatabaseNotification $notification, User $user): string
    {
        $storedUrl = $this->normalize((string) ($notification->data['url'] ?? ''));

        if ($storedUrl !== null) {
            $legacyUrl = $this->resolveLegacyRequirementUrl($storedUrl, $user);
            if ($legacyUrl !== null) {
                return $legacyUrl;
            }

            if ($this->isAllowedRoute($storedUrl, $notification->id, $user)) {
                return $storedUrl;
            }
        }

        return $this->fallback($notification, $user) ?? $this->dashboard($user);
    }

    private function normalize(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || $url === '#' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        if (Str::startsWith(Str::lower($url), ['//', 'javascript:', 'data:', 'mailto:', 'tel:'])) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        if (isset($parts['scheme']) && ! in_array(Str::lower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if (! Str::startsWith($path, '/')) {
            return null;
        }

        return $path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private function isAllowedRoute(string $url, string $notificationId, User $user): bool
    {
        if (Str::contains(parse_url($url, PHP_URL_PATH) ?: '', "/notifications/{$notificationId}/read")) {
            return false;
        }

        try {
            $matched = Route::getRoutes()->match(Request::create($url, 'GET'));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return false;
        }

        $name = (string) $matched->getName();
        if ($name === '' || ! Str::startsWith($name, $user->role.'.')) {
            return false;
        }

        if ($user->role !== 'supplier') {
            return true;
        }

        $parameters = $matched->parameters();

        if (Str::startsWith($name, 'supplier.quotations.')) {
            if (isset($parameters['pr_id'])) {
                $pr = $this->resolveModel(PurchaseRequisition::class, $parameters['pr_id']);

                return $pr?->isVisibleToSupplier($user->id) ?? false;
            }

            $quotation = $this->resolveModel(Quotation::class, $parameters['quotation'] ?? $parameters['id'] ?? null);

            return $quotation?->supplier_id === $user->id;
        }

        if (Str::startsWith($name, 'supplier.purchase-orders.')) {
            $po = $this->resolveModel(PurchaseOrder::class, $parameters['id'] ?? null);

            return $po?->supplier_id === $user->id;
        }

        if (Str::startsWith($name, 'supplier.claims.')) {
            $claim = $this->resolveModel(MaterialClaim::class, $parameters['claim'] ?? $parameters['id'] ?? null);

            return $claim?->supplier_id === $user->id;
        }

        if (Str::startsWith($name, 'supplier.conversations.')) {
            $conversation = $this->resolveModel(Conversation::class, $parameters['id'] ?? null);

            return $conversation?->supplier_user_id === $user->id;
        }

        return true;
    }

    private function resolveLegacyRequirementUrl(string $url, User $user): ?string
    {
        if (! preg_match('~^/purchasing/requirements/([^/?#]+)~', $url, $matches)) {
            return null;
        }

        $pr = $this->resolveModel(PurchaseRequisition::class, $matches[1]);

        return $this->purchaseRequisitionUrl($pr, $user);
    }

    private function fallback(DatabaseNotification $notification, User $user): ?string
    {
        $data = $notification->data ?? [];
        $text = implode(' ', [
            (string) ($data['title'] ?? ''),
            (string) ($data['message'] ?? ''),
            (string) ($data['po_number'] ?? ''),
            (string) ($data['pr_number'] ?? ''),
        ]);

        if ($url = $this->conversationUrl($data['conversation_id'] ?? null, $user)) {
            return $url;
        }
        if ($url = $this->quotationUrl($data['quotation_id'] ?? null, $user)) {
            return $url;
        }
        if ($url = $this->claimUrl($data['claim_id'] ?? null, $user)) {
            return $url;
        }

        $po = $this->resolveModel(PurchaseOrder::class, $data['po_id'] ?? null);
        if (! $po && preg_match('/po\/\d{2}\/\d{4}\/\d{3}/i', $text, $matches)) {
            $po = PurchaseOrder::where('po_number', strtoupper($matches[0]))->first();
        }

        if ($po && Str::contains(Str::lower($text), 'claim')) {
            $claim = MaterialClaim::where('po_id', $po->id)->latest()->first();
            if ($url = $this->claimUrl($claim?->id, $user)) {
                return $url;
            }
        }

        if ($url = $this->purchaseOrderUrl($po, $user)) {
            return $url;
        }

        $pr = $this->resolveModel(PurchaseRequisition::class, $data['pr_id'] ?? null);
        if (! $pr && preg_match('/req\/\d{2}\/\d{4}\/\d{3}/i', $text, $matches)) {
            $pr = PurchaseRequisition::where('pr_number', strtoupper($matches[0]))->first();
        }

        return $this->purchaseRequisitionUrl($pr, $user);
    }

    private function purchaseOrderUrl(?PurchaseOrder $po, User $user): ?string
    {
        if (! $po || ($user->role === 'supplier' && $po->supplier_id !== $user->id)) {
            return null;
        }

        return match ($user->role) {
            'supplier' => $this->route('supplier.purchase-orders.show', $po),
            'purchasing' => $this->route('purchasing.purchase-orders.show', $po),
            'qc' => $this->route('qc.inspections.create', $po),
            default => null,
        };
    }

    private function purchaseRequisitionUrl(?PurchaseRequisition $pr, User $user): ?string
    {
        if (! $pr) {
            return null;
        }

        return match ($user->role) {
            'admin' => $this->route('admin.requisitions.show', $pr),
            'purchasing' => $this->route('purchasing.requisitions.show', $pr),
            'supplier' => $pr->isVisibleToSupplier($user->id)
                ? $this->route('supplier.quotations.create', $pr)
                : null,
            default => null,
        };
    }

    private function quotationUrl(mixed $id, User $user): ?string
    {
        $quotation = $this->resolveModel(Quotation::class, $id);
        if (! $quotation || ($user->role === 'supplier' && $quotation->supplier_id !== $user->id)) {
            return null;
        }

        return match ($user->role) {
            'supplier' => $this->route('supplier.quotations.show', $quotation),
            'purchasing' => $this->route('purchasing.quotations.show', $quotation),
            default => null,
        };
    }

    private function claimUrl(mixed $id, User $user): ?string
    {
        $claim = $this->resolveModel(MaterialClaim::class, $id);
        if (! $claim || ($user->role === 'supplier' && $claim->supplier_id !== $user->id)) {
            return null;
        }

        return match ($user->role) {
            'supplier' => $this->route('supplier.claims.show', $claim),
            'purchasing' => $this->route('purchasing.claims.show', $claim),
            default => null,
        };
    }

    private function conversationUrl(mixed $id, User $user): ?string
    {
        $conversation = $this->resolveModel(Conversation::class, $id);
        if (! $conversation || ! $conversation->isMember($user->id)) {
            return null;
        }

        return match ($user->role) {
            'supplier' => $this->route('supplier.conversations.show', $conversation),
            'purchasing' => $this->route('purchasing.conversations.show', $conversation),
            default => null,
        };
    }

    /** @template TModel of Model */
    private function resolveModel(string $modelClass, mixed $value): ?Model
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (ctype_digit((string) $value)) {
                return $modelClass::find((int) $value);
            }

            return (new $modelClass)->resolveRouteBinding((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function route(string $name, Model $model): ?string
    {
        if (! Route::has($name)) {
            return null;
        }

        try {
            return route($name, $model, absolute: false);
        } catch (UrlGenerationException) {
            return null;
        }
    }

    private function dashboard(User $user): string
    {
        $name = $user->role.'.dashboard';

        return Route::has($name) ? route($name, absolute: false) : '/';
    }
}
