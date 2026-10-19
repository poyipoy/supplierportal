<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierContact\UpdateImportSupplierContactRequest;
use App\Models\Supplier;
use App\Models\SupplierChangeRequest;
use App\Models\User;
use App\Services\VendorMaster\VendorChangeRequestService;
use App\Support\ServerTabsResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;

class ImportSupplierController extends Controller
{
    /** Work queue first: the review decision is what Purchasing opens this page for. */
    public const QUEUES = ['pending', 'missing', 'all'];

    public function index(Request $request)
    {
        Gate::authorize('viewImportDirectory', Supplier::class);

        $filters = $request->validate([
            'contact' => ['nullable', 'string', 'in:'.implode(',', self::QUEUES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $queue = $filters['contact'] ?? 'pending';
        $search = trim((string) ($filters['q'] ?? ''));

        // Search applies to the table and to every tab count.
        $scoped = fn () => User::importEligible()->when($search !== '', function (Builder $query) use ($search) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhereHas('supplier', fn (Builder $profile) => $profile
                    ->where('company_name', 'like', $like)
                    ->orWhere('pic_name', 'like', $like)
                    ->orWhere('pic_email', 'like', $like)));
        });

        $suppliers = $this->applyQueue($scoped(), $queue)
            ->with('supplier')
            ->withExists(['supplierChangeRequests as has_pending_contact' => fn (Builder $query) => $query->contact()->pending()])
            ->orderByDesc('has_pending_contact')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $viewData = [
            'suppliers' => $suppliers,
            'search' => $search,
            'queue' => $queue,
            'queueCounts' => collect(self::QUEUES)->mapWithKeys(fn (string $key) => [$key => $this->applyQueue($scoped(), $key)->count()])->all(),
        ];

        // Tabs, search and pagination swap without a full reload; the nav is re-rendered so counts and hrefs stay fresh.
        if (ServerTabsResponse::wants($request)) {
            return ServerTabsResponse::make(
                view('purchasing.import-suppliers._queue_content', $viewData)->render(),
                $queue,
                $request,
                ['nav' => view('purchasing.import-suppliers._queue_nav', $viewData)->render()],
            );
        }

        return view('purchasing.import-suppliers.index', $viewData);
    }

    public function show(User $vendor)
    {
        Gate::authorize('viewImportDirectory', Supplier::class);
        abort_unless($vendor->isImportEligible(), 404);

        $vendor->load('supplier');
        $contactRequests = $vendor->supplierChangeRequests()
            ->contact()
            ->with(['reviewer:id,name'])
            ->latest('id')
            ->limit(20)
            ->get();

        return view('purchasing.import-suppliers.show', [
            'vendor' => $vendor,
            'profile' => $vendor->supplier,
            'contactRequests' => $contactRequests,
            'pendingRequest' => $contactRequests->first(fn (SupplierChangeRequest $item) => $item->isPending()),
        ]);
    }

    public function updateContact(UpdateImportSupplierContactRequest $request, User $vendor, VendorChangeRequestService $service)
    {
        Gate::authorize('manageImportContact', Supplier::class);
        abort_unless($vendor->isImportEligible(), 404);

        try {
            $service->updateContact($vendor, $request->validated(), $request->user(), $request->validated('reason'));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('purchasing.import-suppliers.show', $vendor)
            ->with('success', __('supplier_contact.directory.updated'));
    }

    public function approve(Request $request, SupplierChangeRequest $changeRequest, VendorChangeRequestService $service)
    {
        Gate::authorize('manageImportContact', Supplier::class);
        abort_unless($changeRequest->isContactChange(), 404);

        try {
            $service->approve($changeRequest, $request->user());
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('purchasing.import-suppliers.show', $changeRequest->supplier)
            ->with('success', __('supplier_contact.directory.approved'));
    }

    public function reject(Request $request, SupplierChangeRequest $changeRequest, VendorChangeRequestService $service)
    {
        Gate::authorize('manageImportContact', Supplier::class);
        abort_unless($changeRequest->isContactChange(), 404);
        $validated = $request->validate(['notes' => ['required', 'string', 'max:1000']], [], [
            'notes' => __('supplier_contact.fields.reject_notes'),
        ]);

        try {
            $service->reject($changeRequest, $request->user(), $validated['notes']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('purchasing.import-suppliers.show', $changeRequest->supplier)
            ->with('success', __('supplier_contact.directory.rejected'));
    }

    private function applyQueue(Builder $query, string $queue): Builder
    {
        return match ($queue) {
            'pending' => $query->whereHas('supplierChangeRequests', fn (Builder $requests) => $requests->contact()->pending()),
            'missing' => $this->withoutContact($query),
            default => $query,
        };
    }

    private function withoutContact(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereDoesntHave('supplier')
            ->orWhereHas('supplier', fn (Builder $profile) => $profile->whereNull('pic_name')->orWhere('pic_name', '')));
    }
}
