<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesMasterDataRoutes;
use App\Http\Controllers\Controller;
use App\Models\MaterialMaster;
use App\Models\PrItem;
use App\Services\Materials\MaterialDataQualityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MaterialHsCodeController extends Controller
{
    use ResolvesMasterDataRoutes;

    public function index(): View
    {
        Gate::authorize('viewAny', MaterialMaster::class);

        return view('admin.material-hs-code.index', [
            'masterRoutePrefix' => $this->masterRoutePrefix(),
            'hsCategories' => MaterialMaster::HS_CATEGORIES,
            'densityProfiles' => MaterialMaster::DENSITY_PROFILES,
            'manufacturerScopes' => MaterialMaster::MANUFACTURER_SCOPES,
            'shapes' => PrItem::SHAPES,
        ]);
    }

    public function quality(MaterialDataQualityService $quality): JsonResponse
    {
        Gate::authorize('viewAny', MaterialMaster::class);

        return response()->json($quality->report());
    }
}
