<?php
ini_set('display_errors', '0');
ini_set('max_execution_time', '10800');
error_reporting(E_ALL);
set_time_limit(10800);
ignore_user_abort(true);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/ratatoskr_order_store.php';
require_once __DIR__ . '/ratatoskr_orders.php';

/**
 * Constants
 */
const RATATOSKR_NIGHTLY_BATCH_SIZE = 10;

/**
 * Functies
 */
function ratatoskr_nightly_send_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Page load
 */
$startedAt = gmdate('c');

try {
    $companies = ratatoskr_discover_companies();
    $companyResults = [];

    foreach ($companies as $company) {
        $companyResults[] = ratatoskr_sync_company_orders_nightly($company, RATATOSKR_NIGHTLY_BATCH_SIZE);
    }

    $totalFetched = 0;
    $totalReceived = 0;
    $totalSynced = 0;
    $hasErrors = false;

    foreach ($companyResults as $result) {
        if (!is_array($result)) {
            continue;
        }

        $totalFetched += (int) ($result['fetched_count'] ?? 0);
        $totalReceived += (int) ($result['received_count'] ?? 0);
        $totalSynced += (int) ($result['synced_received_count'] ?? 0);
        if (!empty($result['errors'])) {
            $hasErrors = true;
        }
        if (($result['ok'] ?? true) === false) {
            $hasErrors = true;
        }
    }

    ratatoskr_nightly_send_json([
        'ok' => !$hasErrors,
        'started_at' => $startedAt,
        'finished_at' => gmdate('c'),
        'summary' => [
            'companies_processed' => count($companyResults),
            'fetched_count' => $totalFetched,
            'received_count' => $totalReceived,
            'synced_received_count' => $totalSynced,
        ],
        'companies' => $companyResults,
    ]);
} catch (Throwable $error) {
    ratatoskr_nightly_send_json([
        'ok' => false,
        'started_at' => $startedAt,
        'finished_at' => gmdate('c'),
        'error' => 'Nightly sync mislukt.',
        'details' => $error->getMessage(),
    ], 500);
}
