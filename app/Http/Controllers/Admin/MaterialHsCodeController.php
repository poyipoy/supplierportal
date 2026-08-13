<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MaterialMaster;
use App\Models\PrItem;
use App\Services\Materials\MaterialDataQualityService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class MaterialHsCodeController extends Controller
{
    public function index(): View
    {
        return view('admin.material-hs-code.index', [
            'hsCategories' => MaterialMaster::HS_CATEGORIES,
            'densityProfiles' => MaterialMaster::DENSITY_PROFILES,
            'manufacturerScopes' => MaterialMaster::MANUFACTURER_SCOPES,
            'shapes' => PrItem::SHAPES,
        ]);
    }

    public function quality(MaterialDataQualityService $quality): JsonResponse
    {
        return response()->json($quality->report());
    }
}
