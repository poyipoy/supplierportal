<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\SessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records verified interactive activity from the top shell or embedded
 * Workspace Shell iframes so real work extends the idle session window without
 * ever reviving an expired session or bypassing the absolute session cap.
 */
class SessionHeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            ...SessionLifecycle::remainingTimings($request),
        ]);
    }
}
