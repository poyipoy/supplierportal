<?php

namespace App\Http\Controllers;

use App\Services\DocumentChain\DocumentChainResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContextSmartPanelController extends Controller
{
    public function show(
        Request $request,
        string $entityType,
        int|string $hash,
        DocumentChainResolverService $resolver,
    ): JsonResponse {
        $payload = $resolver->resolve($request->user(), $entityType, $hash);

        return response()->json($payload);
    }
}
