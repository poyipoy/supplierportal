<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\UserPreferenceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyUserLocale
{
    public function __construct(private readonly UserPreferenceService $preferences) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Establish English before resolving the account, including early errors.
        app()->setLocale('en');
        $user = $request->user();
        if ($user instanceof User) {
            app()->setLocale($this->preferences->for($user)['locale']);
        } elseif ($request->hasSession() && $request->session()->has('locale')) {
            $locale = UserPreferenceService::normalizeLocale($request->session()->get('locale'));
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
