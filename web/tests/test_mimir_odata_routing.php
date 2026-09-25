<?php

/**
 * OData-routing: Mímir als $mimirApi gezet is, BC als de key ontbreekt.
 * Run: php web/tests/test_mimir_odata_routing.php
 */

/**
 * Includes/requires
 */
require_once dirname(__DIR__) . '/odata.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/ratatoskr_orders.php';

/**
 * Variabelen
 */
$failures = 0;
$mockPort = 18941;
$mockLog = sys_get_temp_dir() . '/ratatoskr-mimir-mock.log';
$mockScript = sys_get_temp_dir() . '/ratatoskr-mimir-mock.php';
$authPath = dirname(__DIR__) . '/auth.php';
$authBackup = null;

/**
 * Functies
 */
function test_assert(string $name, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "OK  {$name}\n";
        return;
    }

    $failures++;
    echo "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function test_reset_discovery_cache(): void
{
    unset(
        $GLOBALS['demeter_company_environment_map'],
        $GLOBALS['demeter_companies_by_environment'],
        $GLOBALS['demeter_active_environments']
    );
}

function test_write_mock(): void
{
    global $mockScript, $mockLog;
    $log = var_export($mockLog, true);
    $php = <<<'PHP'
<?php
$log = LOG_PATH;
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$rawBody = (string) file_get_contents('php://input');
file_put_contents($log, json_encode([
    'uri' => $uri,
    'method' => $method,
    'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'authorization' => $authorization,
    'api_key' => (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''),
    'body' => $rawBody,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');

if (str_contains($uri, '/mimir-redirect/api/')) {
    http_response_code(302);
    header('Location: /evil');
    echo json_encode(['error' => 'redirect']);
    exit;
}

if (str_contains($uri, '/evil')) {
    echo json_encode(['value' => [
        ['name' => 'Evil BV', 'environment' => 'Evil'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir-dup/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Overlap BV', 'environment' => 'Production'],
        ['name' => 'Overlap BV', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Koninklijke van Twist', 'environment' => 'Production'],
        ['name' => "Van Twist's", 'environment' => 'Production'],
        ['name' => 'Hunter van Twist', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/query.php')) {
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        $body = [];
    }
    if (($body['table'] ?? '') === 'OrderByProbe') {
        echo json_encode(['value' => [
            ['Posting_Date' => '2024-01-01', 'No' => '2'],
            ['Posting_Date' => '2024-06-01', 'No' => '1'],
            ['Posting_Date' => '2024-06-01', 'No' => '3'],
        ]]);
        exit;
    }
    echo json_encode(['value' => [[
        'No' => 'PO1',
        'company' => (string) ($body['company'] ?? ''),
        'table' => (string) ($body['table'] ?? ''),
        'select' => $body['select'] ?? [],
        'filter' => (string) ($body['filter'] ?? ''),
        'max_age' => $body['max_age'] ?? null,
        'orderby_sent' => array_key_exists('orderby', $body) || array_key_exists('$orderby', $body),
    ]]]);
    exit;
}

$user = '';
if (str_starts_with($authorization, 'Basic ')) {
    $decoded = base64_decode(substr($authorization, 6), true);
    if (is_string($decoded) && str_contains($decoded, ':')) {
        $user = explode(':', $decoded, 2)[0];
    }
}
echo json_encode(['value' => [[
    'Name' => 'BC Company',
    'via' => 'bc',
    'user' => $user,
]]]);
PHP;
    file_put_contents($mockScript, str_replace('LOG_PATH', $log, $php));
}

/**
 * Zet auth.php terug. Verwijdert het bestand alleen als deze test het zelf heeft aangemaakt.
 */
function test_restore_auth_php(string $path, bool $existedBefore, ?string $backup, bool $written): void
{
    if (!$written) {
        return;
    }

    if ($existedBefore) {
        if (!is_string($backup)) {
            return;
        }
        file_put_contents($path, $backup);
        return;
    }

    @unlink($path);
}

function test_mock_requests(): array
{
    global $mockLog;
    if (!is_file($mockLog)) {
        return [];
    }
    $rows = [];
    foreach (file($mockLog, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return $rows;
}

/**
 * Page load
 */
test_assert('mimir uit zonder key', odata_mimir_enabled() === false);
test_assert('default Mímir-base', odata_mimir_base_url() === 'https://sleutels.kvt.nl/mimir/api');

$restoreProbe = sys_get_temp_dir() . '/ratatoskr-auth-restore-probe.php';
$restoreCreated = sys_get_temp_dir() . '/ratatoskr-auth-restore-created.php';
file_put_contents($restoreProbe, "<?php\n\$marker = 'original';\n");
test_restore_auth_php($restoreProbe, true, "<?php\n\$marker = 'original';\n", false);
test_assert(
    'restore laat bestaand bestand met rust als de test niet schreef',
    is_file($restoreProbe) && str_contains((string) file_get_contents($restoreProbe), 'original')
);
file_put_contents($restoreProbe, "<?php\n\$marker = 'replaced';\n");
test_restore_auth_php($restoreProbe, true, "<?php\n\$marker = 'original';\n", true);
test_assert(
    'restore zet backup terug nadat de test schreef',
    is_file($restoreProbe) && str_contains((string) file_get_contents($restoreProbe), 'original')
);
@unlink($restoreProbe);
file_put_contents($restoreCreated, "<?php\n\$marker = 'created';\n");
test_restore_auth_php($restoreCreated, false, null, true);
test_assert('restore verwijdert alleen een door de test aangemaakt bestand', !is_file($restoreCreated));
file_put_contents($restoreCreated, "<?php\n\$marker = 'keep';\n");
test_restore_auth_php($restoreCreated, true, null, true);
test_assert(
    'restore wist geen bestaand bestand zonder leesbare backup',
    is_file($restoreCreated) && str_contains((string) file_get_contents($restoreCreated), 'keep')
);
@unlink($restoreCreated);

$baseUrl = 'https://bc.example';
$spaceUrl = ratatoskr_company_entity_url_with_query(
    'Koninklijke van Twist',
    'PurchaseOrders',
    [
        '$select' => 'No,Buy_from_Vendor_Name',
        '$filter' => "No eq 'PO1'",
        '$orderby' => 'LVS_Order_Date desc,No desc',
    ],
    'Production'
);
$parsedSpace = odata_mimir_parse_entity_url($spaceUrl);
test_assert(
    'entity-URL met spatie in bedrijfsnaam',
    is_array($parsedSpace)
        && ($parsedSpace['company'] ?? '') === 'Koninklijke van Twist'
        && ($parsedSpace['entity'] ?? '') === 'PurchaseOrders'
        && ($parsedSpace['query']['$select'] ?? '') === 'No,Buy_from_Vendor_Name'
        && ($parsedSpace['query']['$filter'] ?? '') === "No eq 'PO1'"
        && ($parsedSpace['query']['$orderby'] ?? '') === 'LVS_Order_Date desc,No desc',
    json_encode($parsedSpace, JSON_UNESCAPED_UNICODE)
);
test_assert(
    'entity-URL is geen company-discovery',
    odata_mimir_parse_companies_url($spaceUrl) === null
);

$apostropheUrl = ratatoskr_company_entity_url_with_query(
    "Van Twist's",
    'PostedPurchaseReceipt',
    ['$select' => 'Order_No,Posting_Date'],
    'Production'
);
$parsedApostrophe = odata_mimir_parse_entity_url($apostropheUrl);
test_assert(
    'apostrof in bedrijfsnaam',
    is_array($parsedApostrophe)
        && ($parsedApostrophe['company'] ?? '') === "Van Twist's"
        && ($parsedApostrophe['entity'] ?? '') === 'PostedPurchaseReceipt'
        && ($parsedApostrophe['query']['$select'] ?? '') === 'Order_No,Posting_Date',
    json_encode($parsedApostrophe, JSON_UNESCAPED_UNICODE)
);

$companiesUrl = 'https://bc.example/Production/ODataV4/Companies?$select=Name';
$parsedCompanies = odata_mimir_parse_companies_url($companiesUrl);
test_assert(
    'companies-URL levert environment',
    is_array($parsedCompanies) && ($parsedCompanies['environment'] ?? '') === 'Production',
    json_encode($parsedCompanies)
);
test_assert('companies-URL is geen entity', odata_mimir_parse_entity_url($companiesUrl) === null);

$threw = false;
try {
    auth_get_auth_for_environment('Production');
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'Geen auth-configuratie');
}
test_assert('BC-auth ontbreekt blijft exception zonder Mímir', $threw);

$threw = false;
try {
    auth_discover_companies_across_active_environments(30);
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'Geen actieve environments');
}
test_assert('company-discovery faalt snel zonder BC-config en zonder Mímir', $threw);

$savedBaseUrl = $baseUrl;
$baseUrl = '';
$threw = false;
try {
    ratatoskr_company_entity_url_with_query('Koninklijke van Twist', 'PurchaseOrders', [], 'Production');
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'baseUrl ontbreekt');
}
$baseUrl = $savedBaseUrl;
test_assert('lege baseUrl blijft een fout zonder Mímir', $threw);

test_write_mock();
@unlink($mockLog);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, $mockScript],
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    sys_get_temp_dir()
);
test_assert('mock-server start', is_resource($server));
usleep(200000);

$mimirApi = 'mimir_test_key';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';
$baseUrl = '';
unset($GLOBALS['auth_list'], $GLOBALS['environment'], $GLOBALS['auth']);
test_reset_discovery_cache();

$authExistedBefore = is_file($authPath);
$authBackup = null;
if ($authExistedBefore) {
    $authRaw = file_get_contents($authPath);
    $authBackup = is_string($authRaw) ? $authRaw : null;
}
$authWritten = false;
$createdCacheFiles = [];

try {
    $discovered = auth_discover_companies_across_active_environments(30);
    test_assert(
        'Mímir company-discovery zonder BC-creds',
        ($discovered['companies'] ?? []) === ['Hunter van Twist', 'Koninklijke van Twist', "Van Twist's"]
            && ($discovered['map']['Koninklijke van Twist'] ?? '') === 'Production'
            && ($discovered['map']['Hunter van Twist'] ?? '') === 'Sandbox'
            && ($discovered['primary_environment'] ?? '') === 'Production',
        json_encode($discovered, JSON_UNESCAPED_UNICODE)
    );

    $auth = auth_get_auth_for_environment('Production');
    test_assert('lege auth-sentinel in Mímir-modus', $auth === []);

    $context = auth_set_current_company_context('Hunter van Twist', 30);
    test_assert(
        'company-context zonder BC-auth',
        ($context['environment'] ?? '') === 'Sandbox' && ($context['auth'] ?? null) === []
    );

    $active = auth_get_active_environments();
    test_assert(
        'environments uit Mímir als auth_list ontbreekt',
        $active === ['Production', 'Sandbox'],
        json_encode($active)
    );
    unset($GLOBALS['demeter_active_environments']);
    $coldActive = auth_get_active_environments();
    test_assert(
        'koude environment-lijst komt gesorteerd uit discovery',
        $coldActive === ['Production', 'Sandbox'],
        json_encode($coldActive)
    );

    $savedMap = $GLOBALS['demeter_company_environment_map'] ?? null;
    $GLOBALS['demeter_company_environment_map'] = ['Koninklijke van Twist' => ''];
    $emptyEnvironmentThrew = false;
    try {
        ratatoskr_company_entity_url_with_query('Koninklijke van Twist', 'PurchaseOrders', []);
    } catch (RuntimeException $error) {
        $emptyEnvironmentThrew = str_contains($error->getMessage(), 'Geen environment beschikbaar');
    }
    if (is_array($savedMap)) {
        $GLOBALS['demeter_company_environment_map'] = $savedMap;
    } else {
        unset($GLOBALS['demeter_company_environment_map']);
    }
    test_assert('leeg environment blijft een fout in Mímir-modus', $emptyEnvironmentThrew);

    unset($GLOBALS['baseUrl']);
    $emptyBaseUrl = ratatoskr_company_entity_url_with_query(
        'Koninklijke van Twist',
        'PurchaseOrders',
        [
            '$select' => 'No,Buy_from_Vendor_Name',
            '$filter' => "No eq 'PO1'",
        ],
        'Production'
    );
    test_assert(
        'lege baseUrl bouwt een vertaalbaar pad',
        str_contains($emptyBaseUrl, "/Production/ODataV4/Company('Koninklijke%20van%20Twist')/PurchaseOrders"),
        $emptyBaseUrl
    );

    $beforeCache = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    $rows = odata_get_all($emptyBaseUrl, [], 60);
    test_assert(
        'odata_get_all via Mímir',
        is_array($rows[0] ?? null)
            && ($rows[0]['company'] ?? '') === 'Koninklijke van Twist'
            && ($rows[0]['table'] ?? '') === 'PurchaseOrders'
            && ($rows[0]['filter'] ?? '') === "No eq 'PO1'"
            && ($rows[0]['max_age'] ?? null) === 60
            && in_array('No', $rows[0]['select'] ?? [], true),
        json_encode($rows, JSON_UNESCAPED_UNICODE)
    );
    $afterCache = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    test_assert('Mímir slaat Ratatoskr-filecache over', count($afterCache) === count($beforeCache));

    $companyRows = odata_get_all('https://bc.example/Sandbox/ODataV4/Company?$select=Name', [], 30);
    $companyNames = array_map(static function (array $row): string {
        return (string) ($row['Name'] ?? '');
    }, $companyRows);
    test_assert(
        'company-discovery-URL gaat naar Mímir, niet naar BC-host',
        $companyNames === ['Hunter van Twist'],
        json_encode($companyNames, JSON_UNESCAPED_UNICODE)
    );

    $uncachedUrl = ratatoskr_company_entity_url_with_query(
        'Koninklijke van Twist',
        'PostedPurchaseReceipt',
        [
            '$select' => 'Order_No,Posting_Date',
            '$filter' => 'Posting_Date ge 2024-01-01',
        ],
        'Production'
    );
    $uncached = ratatoskr_odata_get_all_uncached($uncachedUrl, []);
    test_assert(
        'uncached read gaat naar Mímir met max_age 0',
        is_array($uncached[0] ?? null)
            && ($uncached[0]['table'] ?? '') === 'PostedPurchaseReceipt'
            && ($uncached[0]['max_age'] ?? null) === 0,
        json_encode($uncached, JSON_UNESCAPED_UNICODE)
    );

    $orderByUrl = ratatoskr_company_entity_url_with_query(
        'Koninklijke van Twist',
        'OrderByProbe',
        ['$orderby' => 'Posting_Date desc,No desc'],
        'Production'
    );
    $ordered = odata_get_all($orderByUrl, [], 30);
    $orderedKeys = array_map(static function (array $row): string {
        return (string) ($row['Posting_Date'] ?? '') . ':' . (string) ($row['No'] ?? '');
    }, $ordered);
    $orderByBody = '';
    foreach (test_mock_requests() as $request) {
        $body = (string) ($request['body'] ?? '');
        if (str_contains($body, 'OrderByProbe')) {
            $orderByBody = $body;
        }
    }
    test_assert(
        '$orderby wordt lokaal toegepast en niet naar Mímir gestuurd',
        $orderedKeys === ['2024-06-01:3', '2024-06-01:1', '2024-01-01:2']
            && $orderByBody !== ''
            && !str_contains($orderByBody, 'orderby'),
        json_encode($ordered, JSON_UNESCAPED_UNICODE) . ' body=' . $orderByBody
    );

    $cachedFlag = ratatoskr_odata_get_all_with_cache_flag($emptyBaseUrl, [], 60);
    test_assert(
        'cache-flag pad gaat naar Mímir zonder lokale miss-markering',
        ($cachedFlag['from_cache'] ?? false) === true
            && (($cachedFlag['rows'][0]['table'] ?? '') === 'PurchaseOrders'),
        json_encode($cachedFlag, JSON_UNESCAPED_UNICODE)
    );

    $requests = test_mock_requests();
    $hitBcHost = false;
    $sawMimirUa = false;
    $sawBearer = false;
    foreach ($requests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), 'bc.example')) {
            $hitBcHost = true;
        }
        if (($request['ua'] ?? '') === 'Ratatoskr-MimirClient/1.0' && str_contains((string) ($request['uri'] ?? ''), '/mimir/api/')) {
            $sawMimirUa = true;
        }
        if (($request['authorization'] ?? '') === 'Bearer mimir_test_key' && ($request['api_key'] ?? '') === 'mimir_test_key') {
            $sawBearer = true;
        }
    }
    test_assert('geen request naar de BC-host', $hitBcHost === false);
    test_assert('Mímir-client user-agent', $sawMimirUa);
    test_assert('Bearer en X-API-Key', $sawBearer);

    test_reset_discovery_cache();
    $mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir-redirect/api';
    $redirectThrew = false;
    try {
        odata_mimir_companies_as_rows(null);
    } catch (Exception $error) {
        $redirectThrew = str_contains($error->getMessage(), 'HTTP 302');
    }
    test_assert('Mímir-request volgt geen redirect', $redirectThrew);

    test_reset_discovery_cache();
    $mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir-dup/api';
    $overlapThrew = false;
    try {
        auth_discover_companies_across_active_environments(30);
    } catch (RuntimeException $error) {
        $overlapThrew = str_contains($error->getMessage(), 'Bedrijfsnaam-overlap');
    }
    test_assert('overlap tussen Mímir-environments blijft een fout', $overlapThrew);

    $mimirApi = '';
    $baseUrl = 'http://127.0.0.1:' . $mockPort;
    $environment = 'Production';
    $auth_list = [
        'Production' => [
            'mode' => 'basic',
            'user' => 'bcuser',
            'pass' => 'bcpass',
        ],
    ];
    $auth = $auth_list['Production'];
    if ($authExistedBefore && !is_string($authBackup)) {
        throw new RuntimeException('Bestaande auth.php kon niet worden gelezen; test wijzigt het bestand niet.');
    }
    $authWritten = true;
    file_put_contents($authPath, "<?php\n\$baseUrl = " . var_export($baseUrl, true) . ";\n\$environment = 'Production';\n\$auth_list = " . var_export($auth_list, true) . ";\n\$mimirApi = '';\n");
    test_reset_discovery_cache();
    @unlink($mockLog);

    test_assert('Mímir uit na lege key', odata_mimir_enabled() === false);
    $bcUrl = $baseUrl . '/Production/ODataV4/Companies?$select=Name';
    $bcRows = odata_get_all($bcUrl, $auth, 30);
    test_assert(
        'zonder Mímir blijft BC-fetch werken',
        is_array($bcRows[0] ?? null) && ($bcRows[0]['via'] ?? '') === 'bc' && ($bcRows[0]['user'] ?? '') === 'bcuser',
        json_encode($bcRows)
    );
    $bcRequests = test_mock_requests();
    $bcHitMimir = false;
    foreach ($bcRequests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), '/mimir/')) {
            $bcHitMimir = true;
        }
    }
    test_assert('BC-fetch raakt Mímir niet', $bcHitMimir === false);

    $uncachedBc = ratatoskr_odata_get_all_uncached($bcUrl, $auth);
    test_assert(
        'uncached zonder Mímir blijft BC',
        is_array($uncachedBc[0] ?? null) && ($uncachedBc[0]['via'] ?? '') === 'bc',
        json_encode($uncachedBc)
    );
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    test_restore_auth_php($authPath, $authExistedBefore, $authBackup, $authWritten);
    @unlink($mockScript);
    @unlink($mockLog);
    $after = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    foreach ($after as $cacheFile) {
        if (!in_array($cacheFile, $beforeCache ?? [], true)) {
            $createdCacheFiles[] = $cacheFile;
            @unlink($cacheFile);
        }
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "all mimir routing tests passed\n";
exit(0);
