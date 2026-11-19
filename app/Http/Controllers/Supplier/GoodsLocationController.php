<?php

namespace App\Http\Controllers\Supplier;

use App\Exceptions\BusinessRuleArgumentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\StoreGoodsLocationRequest;
use App\Models\SupplierGoodsLocation;
use App\Services\Procurement\PreshipmentInspectionService;
use App\Support\CountryCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class GoodsLocationController extends Controller
{
    public function __construct(
        protected PreshipmentInspectionService $preshipmentService
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SupplierGoodsLocation::class);
        $supplierId = (int) auth()->id();

        $search = trim((string) $request->query('search', ''));
        $query = SupplierGoodsLocation::query()
            ->forSupplier($supplierId)
            ->orderByDesc('is_default')
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('contact', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $locations = $query->paginate(20)->withQueryString();
        $activeCount = SupplierGoodsLocation::query()->forSupplier($supplierId)->count();
        $countries = CountryCatalog::options();
        $maxLocations = SupplierGoodsLocation::MAX_ACTIVE_PER_SUPPLIER;

        return view('supplier.goods-locations.index', compact(
            'locations',
            'activeCount',
            'countries',
            'maxLocations',
            'search',
        ));
    }

    public function options(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SupplierGoodsLocation::class);

        return response()->json($this->preshipmentService->locationOptionsFor($request->user()));
    }

    public function store(StoreGoodsLocationRequest $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('create', SupplierGoodsLocation::class);

        try {
            $location = $this->preshipmentService->createLocation($request->user(), $request->validated());
            $message = __('shipments.preshipment.location_created');

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => $message,
                    'created_hash' => $location->getRouteKey(),
                    ...$this->preshipmentService->locationOptionsFor($request->user()),
                ], 201);
            }

            return redirect()->route('supplier.goods-locations.index')
                ->with('success', $message);
        } catch (BusinessRuleArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => [
                        'limit' => [$exception->getMessage()],
                    ],
                ], 422);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        }
    }

    public function update(StoreGoodsLocationRequest $request, SupplierGoodsLocation $goodsLocation): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $goodsLocation);

        $updated = $this->preshipmentService->updateLocation($goodsLocation, $request->user(), $request->validated());
        $message = __('shipments.preshipment.location_updated');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'updated_hash' => $updated->getRouteKey(),
                ...$this->preshipmentService->locationOptionsFor($request->user()),
            ]);
        }

        return redirect()->route('supplier.goods-locations.index')
            ->with('success', $message);
    }

    public function setDefault(Request $request, SupplierGoodsLocation $goodsLocation): RedirectResponse|JsonResponse
    {
        Gate::authorize('setDefault', $goodsLocation);

        $updated = $this->preshipmentService->setDefaultLocation($goodsLocation, $request->user());
        $message = __('shipments.preshipment.location_default_updated');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'default_hash' => $updated->getRouteKey(),
                ...$this->preshipmentService->locationOptionsFor($request->user()),
            ]);
        }

        return redirect()->route('supplier.goods-locations.index')
            ->with('success', $message);
    }

    public function destroy(Request $request, SupplierGoodsLocation $goodsLocation): RedirectResponse|JsonResponse
    {
        Gate::authorize('delete', $goodsLocation);

        $deletedHash = $goodsLocation->getRouteKey();
        $this->preshipmentService->deleteLocation($goodsLocation, $request->user());
        $message = __('shipments.preshipment.location_deleted');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'deleted_hash' => $deletedHash,
                ...$this->preshipmentService->locationOptionsFor($request->user()),
            ]);
        }

        return redirect()->route('supplier.goods-locations.index')
            ->with('success', $message);
    }
}
