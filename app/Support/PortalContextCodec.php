<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PortalContextCodec
{
    public const PAYLOAD_PREFIX = 'scv2';

    public const APPLICATION = 'erp';

    public const TYPE = 'session';

    public const MAX_RAW_LENGTH = 2048;

    public const MAX_DECODED_BYTES = 1536;

    public const MAX_JSON_DEPTH = 4;

    public const MAX_QUERY_PARAMS = 12;

    public const MAX_ARGUMENT_LENGTH = 1024;

    public const MAX_VALUE_LENGTH = 256;

    /** @var list<string> */
    public const PLAIN_INT_KEYS = ['announcement', 'period_id'];

    /** @var list<string> */
    public const HASHED_QUERY_KEYS = [
        'pr_id',
        'pr_item',
        'supplier_id',
        'supplier',
        'user_id',
        'shipment_id',
        'duplicate_from',
    ];

    /** @var list<string> */
    private const PAYLOAD_KEYS = ['description', 'application', 'type', 'action', 'path', 'argument'];

    public static function encodeScv2Payload(string $description, string $action, string $argument): string
    {
        $payload = [
            'description' => $description,
            'application' => self::APPLICATION,
            'type' => self::TYPE,
            'action' => $action,
            'path' => null,
            'argument' => $argument,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return base64_encode(self::PAYLOAD_PREFIX.$json);
    }

    /**
     * Decode and strictly validate a raw `scv2` Base64 context payload (SEC-03, SEC-06).
     *
     * @return array{description: string, application: string, type: string, action: string, path: null|string, argument: string}
     */
    public static function decodeScv2Payload(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_RAW_LENGTH) {
            throw new HttpException(400, 'Invalid context length.');
        }

        if (strlen($raw) % 4 !== 0 || preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/', $raw) !== 1) {
            throw new HttpException(400, 'Invalid context encoding.');
        }

        $decoded = base64_decode($raw, true);
        if (! is_string($decoded) || $decoded === '' || strlen($decoded) > self::MAX_DECODED_BYTES) {
            throw new HttpException(400, 'Invalid decoded context size.');
        }

        if (! mb_check_encoding($decoded, 'UTF-8') || ! str_starts_with($decoded, self::PAYLOAD_PREFIX)) {
            throw new HttpException(400, 'Invalid context prefix.');
        }

        $jsonSlice = substr($decoded, strlen(self::PAYLOAD_PREFIX));
        try {
            $data = json_decode($jsonSlice, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'Malformed context payload.');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new HttpException(400, 'Context payload must be an object.');
        }

        foreach (array_keys($data) as $key) {
            if (! in_array($key, self::PAYLOAD_KEYS, true)) {
                throw new HttpException(400, 'Unexpected context property.');
            }
        }

        if (($data['application'] ?? null) !== self::APPLICATION || ($data['type'] ?? null) !== self::TYPE) {
            throw new HttpException(400, 'Invalid context application or type.');
        }

        $action = $data['action'] ?? null;
        if (! is_string($action) || preg_match('/\A[a-z0-9._-]{3,64}\z/', $action) !== 1) {
            throw new HttpException(400, 'Invalid session action.');
        }

        $path = $data['path'] ?? null;
        if ($path !== null && $path !== '') {
            throw new HttpException(400, 'Context path injection rejected.');
        }

        $description = $data['description'] ?? '';
        if (! is_string($description) || preg_match('/[\x00-\x1F\x7F]/', $description) === 1) {
            throw new HttpException(400, 'Invalid context description.');
        }

        $argument = $data['argument'] ?? '';
        if (! is_string($argument) || strlen($argument) > self::MAX_ARGUMENT_LENGTH) {
            throw new HttpException(400, 'Invalid context argument.');
        }

        return [
            'description' => $description,
            'application' => self::APPLICATION,
            'type' => self::TYPE,
            'action' => $action,
            'path' => null,
            'argument' => $argument,
        ];
    }

    /**
     * Build normalized session metadata list ordered for deterministic URL matching across PHP and JS.
     *
     * @return list<array{
     *     action: string,
     *     route: string,
     *     logicalId: string,
     *     pathTemplate: string,
     *     params: list<string>,
     *     queryAllowlist: list<string>,
     *     hashedKeys: list<string>,
     *     description: string,
     * }>
     */
    public static function sessionIndex(?User $user = null, ?string $activeSupplierScope = null, ?string $prefixOverride = null): array
    {
        $sessions = config('portal_sessions', []);
        if (! is_array($sessions)) {
            return [];
        }

        $logicalPrefix = (string) config('portal_urls.context_urls.logical_id_prefix', 'lid://adasi.portal.');
        $routeLabels = WorkspaceShell::menuRouteLabelMap();
        $routes = RouteFacade::getRoutes();
        $entries = [];

        foreach ($sessions as $action => $spec) {
            if (! is_string($action) || ! is_array($spec) || ! is_string($spec['route'] ?? null)) {
                continue;
            }

            $routeName = $spec['route'];
            $route = $routes->getByName($routeName);
            if (! $route instanceof Route) {
                continue;
            }

            if ($user instanceof User && ! PortalSessionAuthorizer::canAccess($user, $routeName, $activeSupplierScope)) {
                continue;
            }

            $params = array_values(array_filter($spec['params'] ?? [], 'is_string'));
            $queryAllowlist = array_values(array_filter($spec['query_allowlist'] ?? [], 'is_string'));
            $pathTemplate = self::routePathTemplate($route, $prefixOverride);

            $hashedKeys = [];
            foreach ($params as $param) {
                if (! in_array($param, self::PLAIN_INT_KEYS, true)) {
                    $hashedKeys[] = $param;
                }
            }
            foreach ($queryAllowlist as $qKey) {
                if (in_array($qKey, self::HASHED_QUERY_KEYS, true) && ! in_array($qKey, $hashedKeys, true)) {
                    $hashedKeys[] = $qKey;
                }
            }

            $entries[] = [
                'action' => $action,
                'route' => $routeName,
                'logicalId' => $logicalPrefix.(string) ($spec['logical_id'] ?? 'shared'),
                'pathTemplate' => $pathTemplate,
                'params' => $params,
                'queryAllowlist' => $queryAllowlist,
                'hashedKeys' => $hashedKeys,
                'description' => (string) ($routeLabels[$routeName] ?? $spec['description'] ?? $action),
            ];
        }

        return $entries;
    }

    /**
     * Project an internal shell-eligible Laravel URL into its canonical Infor context URL.
     *
     * @param  list<array<string, mixed>>|null  $sessionIndex
     * @param  array{appUuid?: string, prefix?: string, basePath?: string}|null  $options
     */
    public static function project(
        string $internalUrl,
        ?User $user = null,
        ?array $sessionIndex = null,
        ?array $options = null
    ): ?string {
        if ($internalUrl === '') {
            return null;
        }

        $parsed = parse_url($internalUrl);
        if (! is_array($parsed) || ! isset($parsed['path'])) {
            return null;
        }

        $path = self::normalizePath((string) $parsed['path']);
        $httpBase = array_key_exists('basePath', $options ?? [])
            ? PortalPath::normalizeBasePath((string) $options['basePath'])
            : PortalPath::baseUrl();

        $matchPath = $path;
        if ($httpBase !== '') {
            if ($path === $httpBase) {
                $matchPath = '/';
            } elseif (str_starts_with($path, $httpBase.'/')) {
                $matchPath = substr($path, strlen($httpBase));
            } elseif (isset($parsed['host'])) {
                return null;
            }
        }

        $prefix = array_key_exists('prefix', $options ?? [])
            ? trim((string) $options['prefix'], '/')
            : trim(PortalPath::prefix(), '/');

        $index = $sessionIndex ?? self::sessionIndex($user, null, $prefix);
        $matched = self::matchPathAgainstIndex($matchPath, $index);
        if ($matched === null) {
            return null;
        }

        $entry = $matched['entry'];
        $routeParams = $matched['params'];

        foreach ($routeParams as $paramKey => $paramVal) {
            if (! self::validateTypedValue($paramKey, $paramVal, $entry, decodeHashids: false)) {
                return null;
            }
        }

        $rawQuery = (string) ($parsed['query'] ?? '');
        $filteredQuery = [];
        if ($rawQuery !== '') {
            foreach (explode('&', $rawQuery) as $pair) {
                if ($pair === '' || ! str_contains($pair, '=')) {
                    continue;
                }
                [$kRaw, $vRaw] = explode('=', $pair, 2);
                if (str_contains(urldecode($kRaw), '[')) {
                    continue;
                }
                $k = urldecode($kRaw);
                $v = urldecode($vRaw);
                if ($v === '' || ! in_array($k, $entry['queryAllowlist'], true)) {
                    continue;
                }
                if (array_key_exists($k, $filteredQuery)) {
                    return null;
                }
                if (! self::validateTypedValue($k, $v, $entry, decodeHashids: false)) {
                    return null;
                }
                $filteredQuery[$k] = $v;
            }
        }

        if (count($filteredQuery) > self::MAX_QUERY_PARAMS) {
            return null;
        }

        ksort($filteredQuery, SORT_STRING);

        $argument = self::serializeArgument($routeParams, $filteredQuery, $entry['params']);
        if (strlen($argument) > self::MAX_ARGUMENT_LENGTH) {
            return null;
        }

        $favoriteContext = self::encodeScv2Payload(
            $entry['description'],
            $entry['action'],
            $argument,
        );

        $decodedBytes = strlen('scv2'.json_encode([
            'description' => $entry['description'],
            'application' => self::APPLICATION,
            'type' => self::TYPE,
            'action' => $entry['action'],
            'path' => null,
            'argument' => $argument,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if ($decodedBytes > self::MAX_DECODED_BYTES || strlen($favoriteContext) > self::MAX_RAW_LENGTH) {
            return null;
        }

        $appUuid = strtolower((string) ($options['appUuid'] ?? config('portal_urls.context_urls.app_uuid', '934f47ee-06a4-4f2d-a619-e7cf35e9d91b')));
        $basePath = $httpBase.($prefix !== '' ? '/'.$prefix : '').'/'.$appUuid;

        $originPrefix = '';
        if (isset($parsed['scheme'], $parsed['host'])) {
            $originPrefix = $parsed['scheme'].'://'.$parsed['host'].(isset($parsed['port']) ? ':'.$parsed['port'] : '');
        }

        return $originPrefix.$basePath.'?'.self::buildContextQueryString($favoriteContext, $entry['logicalId']);
    }

    /**
     * Resolve and authorize a raw context request (SEC-02, SEC-03, SEC-04, SEC-05, SEC-06, SEC-08).
     *
     * @return array{
     *     action: string,
     *     routeName: string,
     *     logicalId: string,
     *     params: array<string, string>,
     *     query: array<string, string>,
     *     requiredSupplierScope: null|string,
     *     menuTitle: null|string,
     *     internalPath: string,
     *     internalUrl: string,
     *     internalEmbedUrl: string,
     *     canonicalContextPath: string,
     *     canonicalContextUrl: string,
     * }
     */
    public static function resolve(Request $request, ?User $user = null): array
    {
        $rawFavoriteContext = $request->query('favoriteContext');
        if (! is_string($rawFavoriteContext) || $rawFavoriteContext === '') {
            throw new HttpException(400, 'Missing favoriteContext.');
        }

        $payload = self::decodeScv2Payload($rawFavoriteContext);
        $action = $payload['action'];

        $spec = config('portal_sessions.'.$action);
        if (! is_array($spec) || ! is_string($spec['route'] ?? null)) {
            throw new HttpException(404);
        }

        $routeName = $spec['route'];
        $route = RouteFacade::getRoutes()->getByName($routeName);
        if (! $route instanceof Route || WorkspaceShell::classifyRoute($route) !== WorkspaceShell::CLASS_SHELL_PAGE) {
            throw new HttpException(404);
        }

        $logicalPrefix = (string) config('portal_urls.context_urls.logical_id_prefix', 'lid://adasi.portal.');
        $expectedLogicalId = $logicalPrefix.(string) ($spec['logical_id'] ?? 'shared');

        if ($request->query->has('LogicalId')) {
            $rawLogicalId = $request->query('LogicalId');
            if (! is_string($rawLogicalId) || preg_match('/\Alid:\/\/adasi\.portal\.[a-z0-9_.-]{1,32}\z/', $rawLogicalId) !== 1) {
                throw new HttpException(400, 'Malformed LogicalId.');
            }
        } else {
            $rawLogicalId = null;
        }

        if (! PortalSessionAuthorizer::canAccess($user, $routeName)) {
            throw new HttpException(404);
        }

        if ($rawLogicalId !== null && $rawLogicalId !== $expectedLogicalId) {
            throw new HttpException(400, 'Mismatched LogicalId.');
        }

        $sessionEntry = [
            'params' => array_values(array_filter($spec['params'] ?? [], 'is_string')),
            'queryAllowlist' => array_values(array_filter($spec['query_allowlist'] ?? [], 'is_string')),
            'hashedKeys' => self::computeHashedKeys(
                array_values(array_filter($spec['params'] ?? [], 'is_string')),
                array_values(array_filter($spec['query_allowlist'] ?? [], 'is_string')),
            ),
        ];

        $parsedArgument = self::parseAndValidateArgument($payload['argument'], $sessionEntry, decodeHashids: true);
        $routeParams = $parsedArgument['params'];
        $queryParams = $parsedArgument['query'];

        $routePath = PortalPath::httpPath(route($routeName, $routeParams, absolute: false), $request);
        $queryString = self::encodeQueryPairs($queryParams);
        $internalPath = $routePath.($queryString !== '' ? '?'.$queryString : '');
        $origin = $request->getSchemeAndHttpHost();
        $internalUrl = $origin.$internalPath;
        $internalEmbedUrl = WorkspaceShell::appendEmbedParam($internalUrl);

        $routeLabels = WorkspaceShell::menuRouteLabelMap();
        $description = (string) ($routeLabels[$routeName] ?? $spec['description'] ?? $action);
        $canonicalArgument = self::serializeArgument($routeParams, $queryParams, $sessionEntry['params']);
        $canonicalContextToken = self::encodeScv2Payload($description, $action, $canonicalArgument);

        $appUuid = strtolower((string) config('portal_urls.context_urls.app_uuid', '934f47ee-06a4-4f2d-a619-e7cf35e9d91b'));
        $canonicalContextPath = PortalPath::httpPath(route('portal.page', ['pageUuid' => $appUuid], absolute: false), $request)
            .'?'.self::buildContextQueryString($canonicalContextToken, $expectedLogicalId);

        return [
            'action' => $action,
            'routeName' => $routeName,
            'logicalId' => $expectedLogicalId,
            'params' => $routeParams,
            'query' => $queryParams,
            'requiredSupplierScope' => PortalSessionAuthorizer::requiredSupplierScope($routeName),
            'menuTitle' => $routeLabels[$routeName] ?? ($spec['description'] ?? null),
            'internalPath' => $internalPath,
            'internalUrl' => $internalUrl,
            'internalEmbedUrl' => $internalEmbedUrl,
            'canonicalContextPath' => $canonicalContextPath,
            'canonicalContextUrl' => $origin.$canonicalContextPath,
        ];
    }

    /**
     * Parse and strictly validate `argument` string against session parameter and allow-list policy (SEC-04, SEC-06, SEC-08).
     *
     * @param  array{params: list<string>, queryAllowlist: list<string>, hashedKeys: list<string>}  $sessionEntry
     * @return array{params: array<string, string>, query: array<string, string>}
     */
    public static function parseAndValidateArgument(string $argument, array $sessionEntry, bool $decodeHashids = true): array
    {
        $seen = [];
        $routeParams = [];
        $queryParams = [];

        if ($argument !== '') {
            if (strlen($argument) > self::MAX_ARGUMENT_LENGTH) {
                throw new HttpException(400, 'Argument exceeds max length.');
            }

            foreach (explode('&', $argument) as $pair) {
                if ($pair === '' || ! str_contains($pair, '=')) {
                    throw new HttpException(400, 'Malformed argument segment.');
                }

                [$rawKey, $rawVal] = explode('=', $pair, 2);
                $decodedKey = rawurldecode($rawKey);
                $decodedVal = rawurldecode($rawVal);

                if (
                    $decodedKey === ''
                    || preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $decodedKey) !== 1
                    || array_key_exists($decodedKey, $seen)
                ) {
                    throw new HttpException(400, 'Invalid or duplicate argument key.');
                }

                if (
                    ! mb_check_encoding($decodedVal, 'UTF-8')
                    || preg_match('/[\x00-\x1F\x7F]/', $decodedVal) === 1
                    || strlen($decodedVal) > self::MAX_VALUE_LENGTH
                ) {
                    throw new HttpException(400, 'Invalid argument value.');
                }

                $seen[$decodedKey] = true;

                if (in_array($decodedKey, $sessionEntry['params'], true)) {
                    if ($decodedVal === '' || str_contains($decodedVal, '/')) {
                        throw new HttpException(404);
                    }
                    $canonical = self::canonicalizeTypedValue($decodedKey, $decodedVal, $sessionEntry, $decodeHashids, notFoundOnInvalid: true);
                    $routeParams[$decodedKey] = $canonical;

                    continue;
                }

                if (! in_array($decodedKey, $sessionEntry['queryAllowlist'], true)) {
                    throw new HttpException(400, 'Disallowed query parameter.');
                }

                if ($decodedVal !== '') {
                    $canonical = self::canonicalizeTypedValue($decodedKey, $decodedVal, $sessionEntry, $decodeHashids, notFoundOnInvalid: in_array($decodedKey, self::HASHED_QUERY_KEYS, true));
                    $queryParams[$decodedKey] = $canonical;
                }
            }
        }

        foreach ($sessionEntry['params'] as $requiredParam) {
            if (! array_key_exists($requiredParam, $routeParams)) {
                throw new HttpException(404);
            }
        }

        if (count($queryParams) > self::MAX_QUERY_PARAMS) {
            throw new HttpException(400, 'Too many query parameters.');
        }

        ksort($queryParams, SORT_STRING);

        return [
            'params' => $routeParams,
            'query' => $queryParams,
        ];
    }

    /**
     * @param  list<string>  $params
     * @param  list<string>  $queryAllowlist
     * @return list<string>
     */
    private static function computeHashedKeys(array $params, array $queryAllowlist): array
    {
        $hashed = [];
        foreach ($params as $param) {
            if (! in_array($param, self::PLAIN_INT_KEYS, true)) {
                $hashed[] = $param;
            }
        }
        foreach ($queryAllowlist as $qKey) {
            if (in_array($qKey, self::HASHED_QUERY_KEYS, true) && ! in_array($qKey, $hashed, true)) {
                $hashed[] = $qKey;
            }
        }

        return $hashed;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function validateTypedValue(string $key, string $value, array $entry, bool $decodeHashids): bool
    {
        if (in_array($key, self::PLAIN_INT_KEYS, true)) {
            return preg_match('/\A[1-9]\d{0,17}\z/', $value) === 1;
        }

        $hashedKeys = $entry['hashedKeys'] ?? [];
        if (in_array($key, $hashedKeys, true)) {
            if (preg_match('/\A[A-Za-z0-9]{1,64}\z/', $value) !== 1 || ctype_digit($value)) {
                return false;
            }
            if ($decodeHashids) {
                return HashidCodec::decode($value) !== null;
            }
        }

        return strlen($value) <= self::MAX_VALUE_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function canonicalizeTypedValue(
        string $key,
        string $value,
        array $entry,
        bool $decodeHashids,
        bool $notFoundOnInvalid
    ): string {
        if (in_array($key, self::PLAIN_INT_KEYS, true)) {
            if (preg_match('/\A[1-9]\d{0,17}\z/', $value) !== 1) {
                throw new HttpException($notFoundOnInvalid ? 404 : 400, 'Invalid integer parameter.');
            }

            return $value;
        }

        $hashedKeys = $entry['hashedKeys'] ?? [];
        if (in_array($key, $hashedKeys, true)) {
            if (preg_match('/\A[A-Za-z0-9]{1,64}\z/', $value) !== 1 || ctype_digit($value)) {
                throw new HttpException(404);
            }
            if ($decodeHashids) {
                $decoded = HashidCodec::decode($value);
                if ($decoded === null) {
                    throw new HttpException(404);
                }

                return HashidCodec::encode($decoded);
            }
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $index
     * @return null|array{entry: array<string, mixed>, params: array<string, string>}
     */
    private static function matchPathAgainstIndex(string $path, array $index): ?array
    {
        $pathSegments = $path === '/' ? [] : explode('/', trim($path, '/'));
        $best = null;
        $bestScore = null;

        foreach ($index as $entry) {
            $template = (string) ($entry['pathTemplate'] ?? '');
            $tplSegments = $template === '/' ? [] : explode('/', trim($template, '/'));

            if (count($tplSegments) !== count($pathSegments)) {
                continue;
            }

            $matchedParams = [];
            $staticCount = 0;
            $ok = true;

            foreach ($tplSegments as $i => $tplSeg) {
                $actualSeg = $pathSegments[$i];
                if (str_starts_with($tplSeg, '{') && str_ends_with($tplSeg, '}')) {
                    if ($actualSeg === '') {
                        $ok = false;
                        break;
                    }
                    $paramName = substr($tplSeg, 1, -1);
                    $matchedParams[$paramName] = rawurldecode($actualSeg);
                } elseif ($tplSeg === $actualSeg) {
                    $staticCount++;
                } else {
                    $ok = false;
                    break;
                }
            }

            if (! $ok) {
                continue;
            }

            $paramCount = count($matchedParams);
            $score = ($staticCount * 1000) - ($paramCount * 100) + strlen($template);
            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
                $best = ['entry' => $entry, 'params' => $matchedParams];
            }
        }

        return $best;
    }

    private static function routePathTemplate(Route $route, ?string $prefixOverride = null): string
    {
        $uri = trim($route->uri(), '/');

        if ($prefixOverride !== null) {
            $activePrefix = trim(PortalPath::prefix(), '/');
            $bareUri = $uri;
            if ($activePrefix !== '' && str_starts_with($bareUri, $activePrefix.'/')) {
                $bareUri = substr($bareUri, strlen($activePrefix) + 1);
            } elseif ($activePrefix !== '' && $bareUri === $activePrefix) {
                $bareUri = '';
            }

            $cleanOverride = trim($prefixOverride, '/');
            $combined = trim(($cleanOverride !== '' ? $cleanOverride.'/' : '').$bareUri, '/');

            return $combined === '' ? '/' : '/'.$combined;
        }

        return $uri === '' ? '/' : '/'.$uri;
    }

    /**
     * @param  array<string, string>  $routeParams
     * @param  array<string, string>  $queryParams
     * @param  list<string>  $paramOrder
     */
    private static function serializeArgument(array $routeParams, array $queryParams, array $paramOrder): string
    {
        $ordered = [];
        foreach ($paramOrder as $key) {
            if (array_key_exists($key, $routeParams)) {
                $ordered[$key] = $routeParams[$key];
            }
        }
        foreach ($queryParams as $key => $val) {
            $ordered[$key] = $val;
        }

        return self::encodeQueryPairs($ordered);
    }

    /**
     * @param  array<string, string>  $pairs
     */
    private static function encodeQueryPairs(array $pairs): string
    {
        if ($pairs === []) {
            return '';
        }

        $chunks = [];
        foreach ($pairs as $k => $v) {
            $chunks[] = rawurlencode((string) $k).'='.rawurlencode((string) $v);
        }

        return implode('&', $chunks);
    }

    private static function buildContextQueryString(string $favoriteContext, string $logicalId): string
    {
        return 'favoriteContext='.rawurlencode($favoriteContext).'&LogicalId='.rawurlencode($logicalId);
    }

    private static function normalizePath(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
