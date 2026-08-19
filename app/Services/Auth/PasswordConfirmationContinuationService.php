<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

class PasswordConfirmationContinuationService
{
    public const SESSION_KEY = 'auth.password_confirmation_action';

    private const METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const MAX_PAYLOAD_BYTES = 8192;

    public function capture(Request $request): bool
    {
        $route = $request->route();
        $routeName = is_object($route) ? $route->getName() : null;
        $method = strtoupper($request->method());

        if (! is_string($routeName) || $routeName === '' || ! in_array($method, self::METHODS, true)) {
            $this->forget($request);

            return false;
        }

        $routeDefinition = app('router')->getRoutes()->getByName($routeName);
        if ($routeDefinition === null || ! in_array($method, $routeDefinition->methods(), true)) {
            $this->forget($request);

            return false;
        }

        if ($request->allFiles() !== []) {
            $this->forget($request);

            return false;
        }

        $inputs = $this->flattenInputs($request->except([
            '_token',
            '_method',
            'password',
            'password_confirmation',
            'current_password',
        ]));

        $payload = [
            'route_name' => $routeName,
            'route_parameters' => $this->routeParameters($route),
            'method' => $method,
            'uri' => $request->getRequestUri(),
            'user_id' => (string) $request->user()->getAuthIdentifier(),
            'session_id' => $request->session()->getId(),
            'issued_at' => now()->timestamp,
            'expires_at' => now()->addSeconds($this->lifetime())->timestamp,
            'nonce' => (string) Str::uuid(),
            'inputs' => $inputs,
        ];

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
                $this->forget($request);

                return false;
            }

            $request->session()->put(self::SESSION_KEY, Crypt::encryptString($encoded));

            return true;
        } catch (Throwable) {
            $this->forget($request);

            return false;
        }
    }

    /**
     * Pull and invalidate the pending action before it can be replayed.
     *
     * @return array{url: string, method: string, inputs: array<int, array{name: string, value: string}>}|null
     */
    public function pull(Request $request): ?array
    {
        $encrypted = $request->session()->pull(self::SESSION_KEY);
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload) || ! $this->isValidPayload($request, $payload)) {
            return null;
        }

        $uri = $payload['uri'];

        return [
            'url' => url()->to($uri),
            'method' => $payload['method'],
            'inputs' => $payload['inputs'],
        ];
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    private function lifetime(): int
    {
        return max(1, (int) config('auth_security.password_confirmation.continuation_lifetime_seconds', 600));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isValidPayload(Request $request, array $payload): bool
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return false;
        }

        $method = $payload['method'] ?? null;
        $routeName = $payload['route_name'] ?? null;
        $routeParameters = $payload['route_parameters'] ?? null;
        $uri = $payload['uri'] ?? null;
        $payloadUserId = $payload['user_id'] ?? null;
        $sessionId = $payload['session_id'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;
        $inputs = $payload['inputs'] ?? null;

        if (! is_string($method) || ! in_array($method, self::METHODS, true)
            || ! is_string($routeName) || $routeName === ''
            || ! is_array($routeParameters)
            || ! is_string($uri) || ! str_starts_with($uri, '/')
            || str_starts_with($uri, '//')
            || str_contains($uri, '://')
            || ! is_string($payloadUserId) || $payloadUserId !== (string) $user->getAuthIdentifier()
            || ! is_string($sessionId) || ! hash_equals($sessionId, $request->session()->getId())
            || ! is_int($expiresAt) || $expiresAt <= now()->timestamp
            || ! is_array($inputs)) {
            return false;
        }

        $route = app('router')->getRoutes()->getByName($routeName);

        return $route !== null
            && in_array($method, $route->methods(), true)
            && $this->validInputFields($inputs);
    }

    /**
     * @return array<string, string|null>
     */
    private function routeParameters(object $route): array
    {
        $parameters = [];

        foreach ($route->parameters() as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $parameters[(string) $name] = $value === null ? null : (string) $value;
            } elseif (is_object($value) && method_exists($value, 'getRouteKey')) {
                $parameters[(string) $name] = (string) $value->getRouteKey();
            }
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<int, array{name: string, value: string}>
     */
    private function flattenInputs(array $inputs, string $prefix = ''): array
    {
        $fields = [];

        foreach ($inputs as $name => $value) {
            $fieldName = $prefix === '' ? (string) $name : $prefix.'['.$name.']';

            if (is_array($value)) {
                $fields = array_merge($fields, $this->flattenInputs($value, $fieldName));

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $fields[] = [
                    'name' => $fieldName,
                    'value' => (string) $value,
                ];
            }
        }

        return $fields;
    }

    /**
     * @param  array<int, mixed>  $inputs
     */
    private function validInputFields(array $inputs): bool
    {
        foreach ($inputs as $input) {
            if (! is_array($input)
                || ! is_string($input['name'] ?? null)
                || ! is_string($input['value'] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
