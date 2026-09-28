<?php
/**
 * Mímir-fallback voor Ratatoskr.
 *
 * odata.php laadt dit bestand als enige hook (goedgekeurd door Tim, 2026-09-28).
 * Faalt Mímir (cURL/timeout, non-2xx, ongeldige JSON of een foutpayload), dan
 * geldt de directe BC-route van vóór de migratie: $baseUrl, $auth / $auth_list,
 * $environment en de lokale odata-filecache. Na die fout slaat dit PHP-proces
 * Mímir over. Zonder BC-credentials komt de oorspronkelijke Mímir-fout terug.
 */

/**
 * @return array{open: bool, error: ?Throwable}
 */
function &odata_mimir_circuit_state(): array
{
    static $state = [
        'open' => false,
        'error' => null,
    ];
    return $state;
}

function odata_mimir_circuit_open(): bool
{
    $state = &odata_mimir_circuit_state();
    return $state['open'] === true;
}

function odata_mimir_last_error(): ?Throwable
{
    $state = &odata_mimir_circuit_state();
    return $state['error'] instanceof Throwable ? $state['error'] : null;
}

function odata_mimir_trip(Throwable $exception): void
{
    $state = &odata_mimir_circuit_state();
    if ($state['open'] === true) {
        return;
    }
    $state['open'] = true;
    $state['error'] = $exception;
}

function odata_mimir_circuit_reset(): void
{
    $state = &odata_mimir_circuit_state();
    $state['open'] = false;
    $state['error'] = null;
}

function odata_mimir_connect_timeout_seconds(): int
{
    return 10;
}

function odata_mimir_timeout_seconds_for_sapi(string $sapi): int
{
    return strtolower($sapi) === 'cli' ? 600 : 90;
}

function odata_mimir_timeout_seconds(): int
{
    return odata_mimir_timeout_seconds_for_sapi(PHP_SAPI);
}

function odata_mimir_fail(Exception $exception): void
{
    odata_mimir_trip($exception);
    throw $exception;
}

function odata_mimir_failure_is_transport(Throwable $exception): bool
{
    return odata_mimir_circuit_open() && odata_mimir_last_error() === $exception;
}

/**
 * auth.php dat binnen een functie wordt geladen blijft anders lokaal.
 * Kopieer de BC-variabelen daarom naar $GLOBALS.
 */
function odata_fallback_import_auth(): void
{
    $path = __DIR__ . '/auth.php';
    if (!is_file($path)) {
        return;
    }

    require $path;

    $names = ['baseUrl', 'environment', 'auth', 'auth_list', 'mimirApi', 'mimirBase', 'allowedUsers'];
    foreach ($names as $name) {
        if (isset($$name)) {
            $GLOBALS[$name] = $$name;
        }
    }
}

function odata_auth_is_usable($auth): bool
{
    if (!is_array($auth)) {
        return false;
    }
    $user = trim((string) ($auth['user'] ?? ''));
    if ($user === '') {
        return false;
    }
    $mode = (string) ($auth['mode'] ?? '');
    if ($mode !== 'basic' && $mode !== 'ntlm') {
        return false;
    }
    return array_key_exists('pass', $auth);
}

function odata_bc_base_url(): ?string
{
    odata_fallback_import_auth();
    global $baseUrl;
    if (!isset($baseUrl) || !is_string($baseUrl)) {
        return null;
    }
    $base = trim($baseUrl);
    if ($base === '' || stripos($base, 'mimir.invalid') !== false) {
        return null;
    }
    return $base;
}

function odata_bc_environment(): ?string
{
    odata_fallback_import_auth();
    global $environment, $auth_list;
    if (isset($environment) && is_string($environment)) {
        $env = trim($environment);
        if ($env !== '' && strcasecmp($env, 'mimir') !== 0) {
            return $env;
        }
    }
    if (isset($environment) && is_array($environment)) {
        foreach ($environment as $item) {
            $env = trim((string) $item);
            if ($env !== '' && strcasecmp($env, 'mimir') !== 0) {
                return $env;
            }
        }
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $key => $entry) {
            $env = trim((string) $key);
            if ($env === '' || strcasecmp($env, 'mimir') === 0) {
                continue;
            }
            if (odata_auth_is_usable($entry)) {
                return $env;
            }
        }
    }
    return null;
}

function odata_bc_auth_for_fallback(array $passed): ?array
{
    odata_fallback_import_auth();
    if (odata_auth_is_usable($passed)) {
        return $passed;
    }
    global $auth, $auth_list, $environment;
    if (isset($auth) && odata_auth_is_usable($auth)) {
        return $auth;
    }
    if (!isset($auth_list) || !is_array($auth_list)) {
        return null;
    }

    $preferred = [];
    if (isset($environment) && is_string($environment)) {
        $preferred[] = trim($environment);
    } elseif (isset($environment) && is_array($environment)) {
        foreach ($environment as $item) {
            $preferred[] = trim((string) $item);
        }
    }
    foreach ($preferred as $key) {
        if ($key === '' || strcasecmp($key, 'mimir') === 0) {
            continue;
        }
        if (isset($auth_list[$key]) && odata_auth_is_usable($auth_list[$key])) {
            return $auth_list[$key];
        }
    }
    foreach ($auth_list as $entry) {
        if (odata_auth_is_usable($entry)) {
            return $entry;
        }
    }
    return null;
}

function odata_bc_credentials_configured(): bool
{
    if (odata_bc_base_url() === null || odata_bc_environment() === null) {
        return false;
    }
    return odata_bc_auth_for_fallback([]) !== null;
}

function odata_fallback_environment_from_url(string $url): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }
    $path = (string) ($parts['path'] ?? '');
    if (preg_match('#^/([^/]+)/ODataV4/#', $path, $match) !== 1) {
        return null;
    }
    $env = trim(rawurldecode($match[1]));
    if ($env === '' || strcasecmp($env, 'mimir') === 0) {
        return null;
    }
    return $env;
}

function odata_fallback_environment_for_company(string $company): ?string
{
    $company = trim($company);
    if ($company === '') {
        return null;
    }
    $map = $GLOBALS['demeter_company_environment_map'] ?? null;
    if (!is_array($map)) {
        return null;
    }
    if (isset($map[$company])) {
        $env = trim((string) $map[$company]);
        if ($env !== '' && strcasecmp($env, 'mimir') !== 0) {
            return $env;
        }
    }
    foreach ($map as $name => $env) {
        $envName = trim((string) $env);
        if (strcasecmp((string) $name, $company) === 0 && $envName !== '' && strcasecmp($envName, 'mimir') !== 0) {
            return $envName;
        }
    }
    return null;
}

function odata_fallback_resolve_environment(string $url, string $company = ''): ?string
{
    $fromUrl = odata_fallback_environment_from_url($url);
    if ($fromUrl !== null) {
        return $fromUrl;
    }
    $companyName = trim($company);
    if ($companyName === '' && function_exists('odata_mimir_parse_entity_url')) {
        $parsed = odata_mimir_parse_entity_url($url);
        if (is_array($parsed)) {
            $companyName = trim((string) ($parsed['company'] ?? ''));
        }
    }
    if ($companyName !== '') {
        $fromCompany = odata_fallback_environment_for_company($companyName);
        if ($fromCompany !== null) {
            return $fromCompany;
        }
    }
    return odata_bc_environment();
}

function odata_fallback_auth_for_environment(?string $env, array $passed): ?array
{
    global $auth_list;
    if ($env !== null && $env !== '' && isset($auth_list) && is_array($auth_list) && isset($auth_list[$env]) && odata_auth_is_usable($auth_list[$env])) {
        return $auth_list[$env];
    }
    if (odata_auth_is_usable($passed)) {
        return $passed;
    }
    return odata_bc_auth_for_fallback([]);
}

function odata_fallback_auth_for_url(string $url, array $passed, string $company = ''): ?array
{
    return odata_fallback_auth_for_environment(odata_fallback_resolve_environment($url, $company), $passed);
}

function odata_mimir_log_fallback(Throwable $exception): void
{
    $message = $exception->getMessage();
    $redactions = [];
    $apiKey = function_exists('odata_mimir_api_key') ? odata_mimir_api_key() : '';
    if ($apiKey !== '') {
        $redactions[] = $apiKey;
    }
    global $auth, $auth_list;
    if (isset($auth) && is_array($auth) && isset($auth['pass']) && is_string($auth['pass']) && $auth['pass'] !== '') {
        $redactions[] = $auth['pass'];
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $entry) {
            if (is_array($entry) && isset($entry['pass']) && is_string($entry['pass']) && $entry['pass'] !== '') {
                $redactions[] = $entry['pass'];
            }
        }
    }
    foreach ($redactions as $secret) {
        $message = str_replace($secret, '[redacted]', $message);
    }
    $sanitized = preg_replace('/(Bearer\s+)\S+/i', '$1[redacted]', $message);
    if (is_string($sanitized)) {
        $message = $sanitized;
    }
    error_log('[Ratatoskr] Mímir failed, falling back to direct OData: ' . $message);
}

/**
 * @param callable $viaMimir
 * @param callable $viaDirect
 * @return mixed
 */
function odata_mimir_or_direct(callable $viaMimir, callable $viaDirect)
{
    if (odata_mimir_circuit_open()) {
        $original = odata_mimir_last_error();
        if (!odata_bc_credentials_configured()) {
            if ($original instanceof Throwable) {
                throw $original;
            }
            throw new Exception('Mímir eerder mislukt.');
        }
        odata_mimir_log_fallback($original instanceof Throwable ? $original : new Exception('Mímir overgeslagen na eerdere fout.'));
        return $viaDirect();
    }

    try {
        return $viaMimir();
    } catch (Throwable $exception) {
        // Alleen transport/protocol-fouten uit odata_mimir_fail openen het circuit.
        if (!odata_mimir_failure_is_transport($exception) || !odata_bc_credentials_configured()) {
            throw $exception;
        }
        odata_mimir_log_fallback($exception);
        return $viaDirect();
    }
}

function odata_bc_url_from_odata_url(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    $relative = $host === '';
    if ($host !== 'mimir.invalid' && !$relative) {
        return $url;
    }
    if ($relative && strpos($path, '/ODataV4/') === false) {
        return $url;
    }
    $base = odata_bc_base_url();
    if ($base === null) {
        return $url;
    }
    if (preg_match('#^/([^/]+)(/.+)$#', $path, $match) !== 1) {
        return $url;
    }
    $urlEnv = odata_fallback_resolve_environment($url);
    if ($urlEnv === null) {
        return $url;
    }
    $rebuilt = rtrim($base, '/') . '/' . $urlEnv . $match[2];
    if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
        $rebuilt .= '?' . $parts['query'];
    }
    return $rebuilt;
}

function odata_fallback_mimir_request(string $method, string $path, ?array $jsonBody = null): array
{
    $apiKey = odata_mimir_api_key();
    if ($apiKey === '') {
        throw new Exception('Mímir API-sleutel ontbreekt ($mimirApi).');
    }

    if (odata_mimir_circuit_open()) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir overgeslagen na eerdere fout in dit verzoek.');
    }

    $url = odata_mimir_base_url() . '/' . ltrim($path, '/');
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiKey,
        'X-API-Key: ' . $apiKey,
    ];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => odata_mimir_connect_timeout_seconds(),
        CURLOPT_TIMEOUT => odata_mimir_timeout_seconds(),
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Ratatoskr-MimirClient/1.0',
    ];
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            curl_close($ch);
            throw new Exception('Mímir request JSON encode mislukt.');
        }
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        odata_mimir_fail(new Exception('Mímir cURL error: ' . $err));
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $message = is_array($decoded) ? (string) ($decoded['error'] ?? $raw) : $raw;
        odata_mimir_fail(new Exception('Mímir HTTP ' . $code . ': ' . $message));
    }
    if (!is_array($decoded)) {
        odata_mimir_fail(new Exception('Mímir gaf ongeldige JSON terug.'));
    }
    $errorField = $decoded['error'] ?? null;
    if ($errorField !== null && $errorField !== '' && $errorField !== false) {
        $message = is_string($errorField) ? $errorField : (string) json_encode($errorField, JSON_UNESCAPED_UNICODE);
        odata_mimir_fail(new Exception('Mímir error: ' . $message));
    }
    return $decoded;
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_companies_as_rows_impl(?string $environment = null): array
{
    $response = odata_fallback_mimir_request('GET', 'companies.php');
    $items = $response['value'] ?? null;
    if (!is_array($items)) {
        throw new Exception("Mímir companies-antwoord mist 'value'.");
    }
    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string) ($item['name'] ?? $item['Name'] ?? ''));
        $env = trim((string) ($item['environment'] ?? ''));
        if ($name === '') {
            continue;
        }
        if ($environment !== null && $environment !== '' && $env !== '' && strcasecmp($env, $environment) !== 0) {
            continue;
        }
        $rows[] = ['Name' => $name, 'environment' => $env];
    }
    return $rows;
}

/**
 * @return list<string>
 */
function odata_fallback_usable_environments(): array
{
    global $auth_list;
    $names = [];
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $key => $entry) {
            $env = trim((string) $key);
            if ($env === '' || strcasecmp($env, 'mimir') === 0) {
                continue;
            }
            if (!odata_auth_is_usable($entry)) {
                continue;
            }
            $names[] = $env;
        }
    }
    if ($names === []) {
        $env = odata_bc_environment();
        if ($env !== null) {
            $names[] = $env;
        }
    }
    return $names;
}

/**
 * @return list<array<string, mixed>>
 */
function odata_direct_companies_as_rows(?string $environmentFilter = null): array
{
    $filter = $environmentFilter !== null ? trim($environmentFilter) : '';
    if ($filter !== '' && strcasecmp($filter, 'mimir') !== 0) {
        $envs = [$filter];
    } else {
        $envs = odata_fallback_usable_environments();
    }
    $base = odata_bc_base_url();
    if ($base === null || $envs === []) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir mislukt.');
    }

    $out = [];
    foreach ($envs as $env) {
        $auth = odata_fallback_auth_for_environment($env, []);
        if ($auth === null) {
            continue;
        }
        $rows = odata_get_all_direct(rtrim($base, '/') . '/' . rawurlencode($env) . '/ODataV4/Company', $auth, 300);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['Name'] ?? $row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = ['Name' => $name, 'environment' => $env];
        }
    }
    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function odata_fallback_companies_as_rows(?string $environment = null): array
{
    $fromMimir = static function () use ($environment): array {
        return odata_mimir_companies_as_rows_impl($environment);
    };
    if (!odata_mimir_enabled()) {
        return $fromMimir();
    }
    return odata_mimir_or_direct(
        $fromMimir,
        static function () use ($environment): array {
            return odata_direct_companies_as_rows($environment);
        }
    );
}

/**
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_mimir_query_impl(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    consolelog("Mímir query company=$company table=$table\n");

    $body = [
        'company' => $company,
        'table' => $table,
        'max_age' => max(0, $ttlSeconds),
        'top' => 0,
    ];

    $select = trim((string) ($odataQuery['$select'] ?? $odataQuery['select'] ?? ''));
    if ($select !== '') {
        $cols = [];
        foreach (explode(',', $select) as $col) {
            $col = trim($col);
            if ($col !== '') {
                $cols[] = $col;
            }
        }
        if ($cols !== []) {
            $body['select'] = $cols;
        }
    }

    $filter = trim((string) ($odataQuery['$filter'] ?? $odataQuery['filter'] ?? ''));
    if ($filter !== '') {
        $body['filter'] = $filter;
    }

    $response = odata_fallback_mimir_request('POST', 'query.php', $body);
    if (!isset($response['value']) || !is_array($response['value'])) {
        throw new Exception("Mímir query-antwoord mist 'value'.");
    }
    /** @var list<array<string, mixed>> $value */
    $value = $response['value'];

    $orderby = trim((string) ($odataQuery['$orderby'] ?? $odataQuery['orderby'] ?? ''));
    if ($orderby !== '') {
        $value = odata_mimir_apply_orderby($value, $orderby);
    }

    return $value;
}

/**
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_direct_query(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    $env = odata_fallback_environment_for_company($company);
    if ($env === null) {
        $env = odata_bc_environment();
    }
    $base = odata_bc_base_url();
    $auth = odata_fallback_auth_for_environment($env, []);
    if ($env === null || $base === null || $auth === null) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir mislukt.');
    }

    $params = [];
    foreach (['$select', '$filter', '$orderby', '$expand', '$top', '$skip', 'select', 'filter', 'orderby'] as $key) {
        if (!array_key_exists($key, $odataQuery)) {
            continue;
        }
        $value = trim((string) $odataQuery[$key]);
        if ($value === '') {
            continue;
        }
        $odataKey = ($key === 'select' || $key === 'filter' || $key === 'orderby') ? ('$' . $key) : $key;
        $params[$odataKey] = $value;
    }

    $safeCompany = str_replace("'", "''", $company);
    $url = rtrim($base, '/') . '/' . rawurlencode($env) . "/ODataV4/Company('" . rawurlencode($safeCompany) . "')/" . rawurlencode($table);
    if ($params !== []) {
        $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    return odata_get_all_direct($url, $auth, $ttlSeconds);
}

/**
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_fallback_query(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    $fromMimir = static function () use ($company, $table, $odataQuery, $ttlSeconds): array {
        return odata_mimir_query_impl($company, $table, $odataQuery, $ttlSeconds);
    };
    if (!odata_mimir_enabled()) {
        return $fromMimir();
    }
    return odata_mimir_or_direct(
        $fromMimir,
        static function () use ($company, $table, $odataQuery, $ttlSeconds): array {
            return odata_direct_query($company, $table, $odataQuery, $ttlSeconds);
        }
    );
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_fetch_all_impl(string $url, int $ttlSeconds): array
{
    consolelog("Mímir fetch $url\n");

    $companies = odata_mimir_parse_companies_url($url);
    if ($companies !== null) {
        return odata_mimir_companies_as_rows_impl($companies['environment']);
    }

    $parsed = odata_mimir_parse_entity_url($url);
    if ($parsed === null) {
        throw new Exception('Mímir: OData-URL kon niet worden vertaald naar company/table: ' . $url);
    }

    return odata_mimir_query_impl($parsed['company'], $parsed['entity'], $parsed['query'], $ttlSeconds);
}

/**
 * @return list<array<string, mixed>>
 */
function odata_fallback_fetch_all(string $url, int $ttlSeconds): array
{
    $fromMimir = static function () use ($url, $ttlSeconds): array {
        return odata_mimir_fetch_all_impl($url, $ttlSeconds);
    };
    if (!odata_mimir_enabled()) {
        return $fromMimir();
    }
    return odata_mimir_or_direct(
        $fromMimir,
        static function () use ($url, $ttlSeconds): array {
            $directUrl = odata_bc_url_from_odata_url($url);
            $auth = odata_fallback_auth_for_url($directUrl, []);
            if ($auth === null) {
                $previous = odata_mimir_last_error();
                if ($previous instanceof Throwable) {
                    throw $previous;
                }
                throw new Exception('Mímir mislukt.');
            }
            return odata_get_all_direct($directUrl, $auth, $ttlSeconds);
        }
    );
}

/**
 * @return list<array<string, mixed>>
 */
function odata_fallback_get_all(string $url, array $auth, $ttlSeconds = 300): array
{
    consolelog("Fetching $url\n");
    $ttlSeconds = max(0, (int) $ttlSeconds);

    if (odata_mimir_enabled()) {
        return odata_mimir_or_direct(
            static function () use ($url, $ttlSeconds): array {
                return odata_mimir_fetch_all_impl($url, $ttlSeconds === 0 ? 3600 : $ttlSeconds);
            },
            static function () use ($url, $auth, $ttlSeconds): array {
                $directUrl = odata_bc_url_from_odata_url($url);
                $directAuth = odata_fallback_auth_for_url($directUrl, $auth);
                if ($directAuth === null) {
                    $previous = odata_mimir_last_error();
                    if ($previous instanceof Throwable) {
                        throw $previous;
                    }
                    throw new Exception('Mímir mislukt.');
                }
                return odata_get_all_direct($directUrl, $directAuth, $ttlSeconds);
            }
        );
    }

    return odata_get_all_direct($url, $auth, $ttlSeconds);
}

/**
 * @return list<array<string, mixed>>
 */
function odata_get_all_direct(string $url, array $auth, $ttlSeconds = 300): array
{
    $ttlSeconds = max(1, (int) $ttlSeconds);
    if (isset($GLOBALS['RATATOSKR_ODATA_BC_FETCH']) && is_callable($GLOBALS['RATATOSKR_ODATA_BC_FETCH'])) {
        return $GLOBALS['RATATOSKR_ODATA_BC_FETCH']($url, $auth, $ttlSeconds);
    }

    maybe_cleanup_expired_cache_files();

    $cacheKey = build_cache_key($url, $auth);
    $cachePath = cache_path_for_key($cacheKey);

    if (is_file($cachePath)) {
        consolelog("Found in cache.\n");
        $cached = read_cache_payload($cachePath, $ttlSeconds);
        if ($cached['valid']) {
            consolelog("Returning data.\n");
            return $cached['data'];
        }

        if ($cached['delete']) {
            consolelog("Cache expired.\n");
            @unlink($cachePath);
        }
    }

    $all = [];
    $next = $url;

    while ($next) {
        $resp = odata_get_json($next, $auth);

        if (!isset($resp['value']) || !is_array($resp['value'])) {
            throw new Exception("OData response missing 'value' array");
        }

        $all = array_merge($all, $resp['value']);
        $next = $resp['@odata.nextLink'] ?? null;
        consolelog("Reading next chunk...\n");
    }

    consolelog("Fetched. Now caching...\n");
    write_cache_json($cachePath, $all, $ttlSeconds, $url);
    consolelog("Done, returning data.\n");
    return $all;
}
