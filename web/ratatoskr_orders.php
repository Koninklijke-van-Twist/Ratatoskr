<?php

/**
 * Constants
 */
require_once __DIR__ . '/ratatoskr_order_store.php';

const RATATOSKR_ORDER_LIST_TTL = 18000;
const RATATOSKR_ORDER_TTL_NOT_RECEIVED = 18000;
const RATATOSKR_ORDER_TTL_RECEIVED = 315360000;
const RATATOSKR_ORDER_TTL_OLDER_THAN_WEEK = 259200;
const RATATOSKR_ORDER_TTL_OLDER_THAN_MONTH = 604800;
const RATATOSKR_ORDER_TTL_OLDER_THAN_THREE_MONTHS = 1209600;
const RATATOSKR_ORDER_TTL_OLDER_THAN_HALF_YEAR = 2592000;
const RATATOSKR_ORDER_TTL_OLDER_THAN_ONE_YEAR = 7776000;

/**
 * Functies
 */
function ratatoskr_discover_companies(): array
{
    try {
        $result = auth_discover_companies_across_active_environments(300);
        $companies = is_array($result['companies'] ?? null) ? $result['companies'] : [];
    } catch (Throwable $error) {
        $companies = [];
    }

    if ($companies === []) {
        $companies = [
            'Koninklijke van Twist',
            'Hunter van Twist',
            'KVT Gas',
        ];
    }

    return $companies;
}

function ratatoskr_company_entity_url_with_query(string $company, string $entitySet, array $query, ?string $environment = null): string
{
    global $baseUrl;

    $companyName = trim($company);
    if ($companyName === '') {
        throw new RuntimeException('Geen bedrijf geselecteerd.');
    }

    $targetEnvironment = trim((string) ($environment ?? ''));
    if ($targetEnvironment === '') {
        $targetEnvironment = auth_get_environment_for_company($companyName, 300);
    }

    if ($targetEnvironment === '') {
        throw new RuntimeException('Geen environment beschikbaar.');
    }

    $base = trim((string) ($baseUrl ?? ''));
    if ($base === '') {
        throw new RuntimeException('baseUrl ontbreekt in auth.php.');
    }

    $safeCompany = str_replace("'", "''", $companyName);
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($base, '/') . '/' . rawurlencode($targetEnvironment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function ratatoskr_pick_date(array $row, array $fields): string
{
    foreach ($fields as $field) {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value !== '' && !preg_match('/^0001-01-01(?:[T\s].*)?$/', $value)) {
            return $value;
        }
    }

    return '';
}

function ratatoskr_normalize_date_only(string $value): string
{
    $text = trim($value);
    if ($text === '') {
        return '';
    }

    $parts = preg_split('/[T\s]/', $text, 2);
    $dateOnly = trim((string) ($parts[0] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOnly)) {
        return '';
    }

    return $dateOnly;
}

function ratatoskr_days_since_date(string $dateOnly): ?int
{
    $normalized = ratatoskr_normalize_date_only($dateOnly);
    if ($normalized === '') {
        return null;
    }

    $timestamp = strtotime($normalized . ' 00:00:00 UTC');
    if ($timestamp === false) {
        return null;
    }

    $ageSeconds = time() - $timestamp;
    if ($ageSeconds < 0) {
        return 0;
    }

    return (int) floor($ageSeconds / 86400);
}

function ratatoskr_date_years_ago(int $years): string
{
    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $now->sub(new DateInterval('P' . max(0, $years) . 'Y'))->format('Y-m-d');
    } catch (Throwable $ignoredError) {
        return gmdate('Y-m-d');
    }
}

function ratatoskr_floor_to_monday(DateTimeImmutable $date): DateTimeImmutable
{
    return $date->modify('monday this week')->setTime(0, 0, 0);
}

function ratatoskr_floor_to_month_step(DateTimeImmutable $date, int $months): DateTimeImmutable
{
    $safeMonths = max(1, $months);
    $year = (int) $date->format('Y');
    $month = (int) $date->format('n');
    $stepIndex = (int) floor(($month - 1) / $safeMonths);
    $startMonth = ($stepIndex * $safeMonths) + 1;

    return $date->setDate($year, $startMonth, 1)->setTime(0, 0, 0);
}

function ratatoskr_round_open_order_lower_bound(string $dateOnly): string
{
    $normalized = ratatoskr_normalize_date_only($dateOnly);
    if ($normalized === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($normalized, new DateTimeZone('UTC'));
    } catch (Throwable $ignoredError) {
        return $normalized;
    }

    $ageDays = ratatoskr_days_since_date($normalized);
    if (!is_int($ageDays)) {
        return $normalized;
    }

    if ($ageDays > 365) {
        return ratatoskr_floor_to_month_step($date, 12)->format('Y-m-d');
    }
    if ($ageDays > 182) {
        return ratatoskr_floor_to_month_step($date, 6)->format('Y-m-d');
    }
    if ($ageDays > 90) {
        return ratatoskr_floor_to_month_step($date, 3)->format('Y-m-d');
    }
    if ($ageDays > 30) {
        return ratatoskr_floor_to_month_step($date, 1)->format('Y-m-d');
    }
    if ($ageDays > 7) {
        return ratatoskr_floor_to_monday($date)->format('Y-m-d');
    }

    return $normalized;
}

function ratatoskr_two_year_cutoff_date(): string
{
    $twoYearsAgo = ratatoskr_date_years_ago(2);
    $normalized = ratatoskr_normalize_date_only($twoYearsAgo);
    if ($normalized === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($normalized, new DateTimeZone('UTC'));
    } catch (Throwable $ignoredError) {
        return $normalized;
    }

    // Stabiliseer de cutoff op maandgrenzen zodat de OData filter-key niet dagelijks verschuift.
    return ratatoskr_floor_to_month_step($date, 1)->format('Y-m-d');
}

function ratatoskr_open_order_lower_bound(string $company): string
{
    $latestNotReceivedOrderDate = ratatoskr_normalize_date_only(ratatoskr_store_get_latest_not_received_order_date($company));
    if ($latestNotReceivedOrderDate === '') {
        // Geen SQLite-state (bijv. verse server): gebruik de vaste 2-jaars cutoff als veilige ondergrens.
        return ratatoskr_two_year_cutoff_date();
    }

    $roundedLowerBound = ratatoskr_round_open_order_lower_bound($latestNotReceivedOrderDate);
    $twoYearCutoff = ratatoskr_two_year_cutoff_date();

    if ($twoYearCutoff === '') {
        return $roundedLowerBound;
    }

    return strcmp($roundedLowerBound, $twoYearCutoff) >= 0 ? $roundedLowerBound : $twoYearCutoff;
}

function ratatoskr_build_open_orders_filter(string $lowerBoundOrderDate): string
{
    $clauses = ['LVS_Completely_Received eq false'];
    $lower = ratatoskr_normalize_date_only($lowerBoundOrderDate);

    if ($lower !== '') {
        $clauses[] = 'LVS_Order_Date ge ' . $lower;
    }

    return implode(' and ', $clauses);
}

function ratatoskr_ttl_for_open_order_age(?int $ageDays): int
{
    if (!is_int($ageDays)) {
        return RATATOSKR_ORDER_TTL_NOT_RECEIVED;
    }

    if ($ageDays > 365) {
        return RATATOSKR_ORDER_TTL_OLDER_THAN_ONE_YEAR;
    }
    if ($ageDays > 182) {
        return RATATOSKR_ORDER_TTL_OLDER_THAN_HALF_YEAR;
    }
    if ($ageDays > 90) {
        return RATATOSKR_ORDER_TTL_OLDER_THAN_THREE_MONTHS;
    }
    if ($ageDays > 30) {
        return RATATOSKR_ORDER_TTL_OLDER_THAN_MONTH;
    }
    if ($ageDays > 7) {
        return RATATOSKR_ORDER_TTL_OLDER_THAN_WEEK;
    }

    return RATATOSKR_ORDER_TTL_NOT_RECEIVED;
}

function ratatoskr_odata_get_all_uncached(string $url, array $auth): array
{
    $all = [];
    $next = $url;

    while ($next) {
        $resp = odata_get_json($next, $auth);
        if (!isset($resp['value']) || !is_array($resp['value'])) {
            throw new Exception("OData response missing 'value' array");
        }

        $all = array_merge($all, $resp['value']);
        $next = $resp['@odata.nextLink'] ?? null;
    }

    return $all;
}

function ratatoskr_odata_is_valid_cache_entry(string $url, array $auth, int $ttlSeconds): bool
{
    $safeTtl = max(1, $ttlSeconds);
    $cacheKey = build_cache_key($url, $auth);
    $cachePath = cache_path_for_key($cacheKey);

    if (!is_file($cachePath)) {
        return false;
    }

    $cached = read_cache_payload($cachePath, $safeTtl);
    return (bool) ($cached['valid'] ?? false);
}

function ratatoskr_odata_get_all_with_cache_flag(string $url, array $auth, int $ttlSeconds): array
{
    $safeTtl = max(1, $ttlSeconds);
    $cacheKey = build_cache_key($url, $auth);
    $cachePath = cache_path_for_key($cacheKey);

    if (is_file($cachePath)) {
        $cached = read_cache_payload($cachePath, $safeTtl);
        if ((bool) ($cached['valid'] ?? false)) {
            return [
                'rows' => is_array($cached['data'] ?? null) ? $cached['data'] : [],
                'from_cache' => true,
            ];
        }
    }

    $rows = odata_get_all($url, $auth, $safeTtl);

    return [
        'rows' => $rows,
        'from_cache' => false,
    ];
}

function ratatoskr_fetch_open_order_candidates(string $company, int $ttl = RATATOSKR_ORDER_LIST_TTL): array
{
    $environment = auth_get_environment_for_company($company, $ttl);
    $auth = auth_get_auth_for_environment($environment);
    $openOrderLowerBound = ratatoskr_open_order_lower_bound($company);
    $openOrderFilter = ratatoskr_build_open_orders_filter($openOrderLowerBound);
    $url = ratatoskr_company_entity_url_with_query($company, 'PurchaseOrders', [
        '$select' => 'No,LVS_Order_Date,Document_Date,Posting_Date,Buy_from_Vendor_No,Buy_from_Vendor_Name,LVS_Ex_Factory_Date,LVS_Date_on_Board,LVS_Expected_Receipt_Date,LVS_Completely_Received,Status,Vendor_Order_No',
        '$filter' => $openOrderFilter,
        '$orderby' => 'LVS_Order_Date desc,No desc',
    ], $environment);

    $rows = odata_get_all($url, $auth, $ttl);
    $orders = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $orderNo = trim((string) ($row['No'] ?? ''));
        if ($orderNo === '') {
            continue;
        }

        $orders[] = [
            'order_no' => $orderNo,
            'order_date' => ratatoskr_pick_date($row, ['LVS_Order_Date', 'Document_Date', 'Posting_Date']),
            'vendor_no' => trim((string) ($row['Buy_from_Vendor_No'] ?? '')),
            'vendor_name' => trim((string) ($row['Buy_from_Vendor_Name'] ?? '')),
            'shipment_date' => '',
            'receipt_date' => '',
            'received' => false,
            'status' => trim((string) ($row['Status'] ?? '')),
            'vendor_order_no' => trim((string) ($row['Vendor_Order_No'] ?? '')),
            'needs_detail' => true,
            'source' => 'open',
        ];
    }

    return $orders;
}

function ratatoskr_fetch_recent_received_candidates(string $company, string $sinceDate, int $ttl = RATATOSKR_ORDER_LIST_TTL): array
{
    $sinceDate = trim($sinceDate);
    if ($sinceDate === '') {
        // Geen SQLite-state (bijv. verse server): kijk maximaal 2 jaar terug.
        // '1900-01-01' zou alle bonnen ooit ophalen en de pagina laten bevriezen.
        $sinceDate = ratatoskr_two_year_cutoff_date();
        if ($sinceDate === '') {
            $sinceDate = ratatoskr_date_years_ago(2);
        }
    }

    $environment = auth_get_environment_for_company($company, $ttl);
    $auth = auth_get_auth_for_environment($environment);
    $url = ratatoskr_company_entity_url_with_query($company, 'PostedPurchaseReceipt', [
        '$select' => 'Order_No,Posting_Date,Buy_from_Vendor_No,Buy_from_Vendor_Name',
        '$filter' => 'Posting_Date ge ' . $sinceDate,
        '$orderby' => 'Posting_Date desc,Order_No desc',
    ], $environment);

    $rows = ratatoskr_odata_get_all_uncached($url, $auth);
    $orders = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $orderNo = trim((string) ($row['Order_No'] ?? ''));
        $receiptDate = trim((string) ($row['Posting_Date'] ?? ''));
        if ($orderNo === '' || $receiptDate === '') {
            continue;
        }

        $key = strtolower($orderNo);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $orders[] = [
            'order_no' => $orderNo,
            'order_date' => '',
            'vendor_no' => trim((string) ($row['Buy_from_Vendor_No'] ?? '')),
            'vendor_name' => trim((string) ($row['Buy_from_Vendor_Name'] ?? '')),
            'shipment_date' => '',
            'receipt_date' => $receiptDate,
            'received' => true,
            'status' => '',
            'vendor_order_no' => '',
            'needs_detail' => true,
            'source' => 'recent_received',
        ];
    }

    return $orders;
}

function ratatoskr_order_queue_payload(string $company): array
{
    $storedOrders = ratatoskr_store_load_received_orders($company);
    $latestReceiptDate = ratatoskr_store_get_latest_received_date($company);
    $latestNotReceivedOrderDate = ratatoskr_store_get_latest_not_received_order_date($company);
    $recentReceivedOrders = ratatoskr_fetch_recent_received_candidates($company, $latestReceiptDate, RATATOSKR_ORDER_LIST_TTL);
    $openOrders = ratatoskr_fetch_open_order_candidates($company, RATATOSKR_ORDER_LIST_TTL);

    $uniqueOrders = [];
    $orderedOrders = [];
    foreach (array_merge($storedOrders, $recentReceivedOrders, $openOrders) as $order) {
        if (!is_array($order)) {
            continue;
        }

        $orderNo = trim((string) ($order['order_no'] ?? ''));
        if ($orderNo === '') {
            continue;
        }

        $key = strtolower($orderNo);
        if (isset($uniqueOrders[$key])) {
            continue;
        }

        $uniqueOrders[$key] = true;
        $orderedOrders[] = $order;
    }

    return [
        'ok' => true,
        'company' => $company,
        'latest_received_date' => $latestReceiptDate,
        'latest_not_received_order_date' => $latestNotReceivedOrderDate,
        'orders' => $orderedOrders,
        'stored_order_count' => count($storedOrders),
        'open_order_count' => count($openOrders),
        'recent_received_count' => count($recentReceivedOrders),
    ];
}

function ratatoskr_fetch_order_list(string $company, int $ttl = RATATOSKR_ORDER_LIST_TTL): array
{
    $environment = auth_get_environment_for_company($company, $ttl);
    $auth = auth_get_auth_for_environment($environment);
    $url = ratatoskr_company_entity_url_with_query($company, 'PurchaseOrders', [
        '$select' => 'No,LVS_Order_Date,Document_Date,Posting_Date,Buy_from_Vendor_No,Buy_from_Vendor_Name,LVS_Completely_Received',
        '$orderby' => 'LVS_Order_Date desc,No desc',
    ], $environment);

    $rows = odata_get_all($url, $auth, $ttl);
    $orders = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $orderNo = trim((string) ($row['No'] ?? ''));
        if ($orderNo === '') {
            continue;
        }

        $orders[] = [
            'order_no' => $orderNo,
            'order_date' => ratatoskr_pick_date($row, ['LVS_Order_Date', 'Document_Date', 'Posting_Date']),
            'vendor_no' => trim((string) ($row['Buy_from_Vendor_No'] ?? '')),
            'vendor_name' => trim((string) ($row['Buy_from_Vendor_Name'] ?? '')),
            'received' => (bool) ($row['LVS_Completely_Received'] ?? false),
        ];
    }

    return $orders;
}

function ratatoskr_fetch_order_detail(string $company, string $orderNo, bool $receivedFlag, string $summaryOrderDate = '', bool $forceRefresh = false, int $ttl = RATATOSKR_ORDER_LIST_TTL): array
{
    $orderNoText = trim($orderNo);
    if ($orderNoText === '') {
        throw new RuntimeException('Ordernummer ontbreekt.');
    }

    $environment = auth_get_environment_for_company($company, $ttl);
    $auth = auth_get_auth_for_environment($environment);
    $summaryAgeDays = ratatoskr_days_since_date($summaryOrderDate);
    $detailTtl = $receivedFlag ? RATATOSKR_ORDER_TTL_RECEIVED : ratatoskr_ttl_for_open_order_age($summaryAgeDays);
    $useCache = !$receivedFlag && !$forceRefresh;
    $hadCacheMiss = false;

    $orderUrl = ratatoskr_company_entity_url_with_query($company, 'PurchaseOrders', [
        '$select' => 'No,LVS_Order_Date,Buy_from_Vendor_No,Buy_from_Vendor_Name,LVS_Ex_Factory_Date,LVS_Date_on_Board,LVS_Expected_Receipt_Date,LVS_Completely_Received,Status,Document_Date,Posting_Date,Vendor_Order_No',
        '$filter' => "No eq '" . str_replace("'", "''", $orderNoText) . "'",
    ], $environment);
    if ($useCache) {
        $orderFetch = ratatoskr_odata_get_all_with_cache_flag($orderUrl, $auth, $detailTtl);
        $orderRows = is_array($orderFetch['rows'] ?? null) ? $orderFetch['rows'] : [];
        $hadCacheMiss = $hadCacheMiss || !((bool) ($orderFetch['from_cache'] ?? false));
    } else {
        $orderRows = ratatoskr_odata_get_all_uncached($orderUrl, $auth);
    }
    $orderRow = null;
    foreach ($orderRows as $row) {
        if (is_array($row)) {
            $orderRow = $row;
            break;
        }
    }

    if (!is_array($orderRow)) {
        throw new RuntimeException('Inkooporder niet gevonden: ' . $orderNoText);
    }

    $receivedDate = '';
    if ($receivedFlag || (bool) ($orderRow['LVS_Completely_Received'] ?? false)) {
        $receiptUrl = ratatoskr_company_entity_url_with_query($company, 'PostedPurchaseReceipt', [
            '$select' => 'Order_No,Posting_Date,Document_Date,Buy_from_Vendor_Name,Buy_from_Vendor_No',
            '$filter' => "Order_No eq '" . str_replace("'", "''", $orderNoText) . "'",
            '$orderby' => 'Posting_Date desc',
        ], $environment);
        if ($useCache) {
            $receiptFetch = ratatoskr_odata_get_all_with_cache_flag($receiptUrl, $auth, $detailTtl);
            $receiptRows = is_array($receiptFetch['rows'] ?? null) ? $receiptFetch['rows'] : [];
            $hadCacheMiss = $hadCacheMiss || !((bool) ($receiptFetch['from_cache'] ?? false));
        } else {
            $receiptRows = ratatoskr_odata_get_all_uncached($receiptUrl, $auth);
        }
        foreach ($receiptRows as $receiptRow) {
            if (!is_array($receiptRow)) {
                continue;
            }

            $receivedDate = ratatoskr_pick_date($receiptRow, ['Posting_Date', 'Document_Date']);
            if ($receivedDate !== '') {
                break;
            }
        }

        if ($receivedDate === '') {
            $receiptLineUrl = ratatoskr_company_entity_url_with_query($company, 'PostedPurchaseReceiptLines', [
                '$select' => 'Document_No,Order_No',
                '$filter' => "Order_No eq '" . str_replace("'", "''", $orderNoText) . "'",
                '$orderby' => 'Document_No desc,Line_No asc',
            ], $environment);
            if ($useCache) {
                $receiptLineFetch = ratatoskr_odata_get_all_with_cache_flag($receiptLineUrl, $auth, $detailTtl);
                $receiptLineRows = is_array($receiptLineFetch['rows'] ?? null) ? $receiptLineFetch['rows'] : [];
                $hadCacheMiss = $hadCacheMiss || !((bool) ($receiptLineFetch['from_cache'] ?? false));
            } else {
                $receiptLineRows = ratatoskr_odata_get_all_uncached($receiptLineUrl, $auth);
            }
            $documentNo = '';
            foreach ($receiptLineRows as $receiptLineRow) {
                if (!is_array($receiptLineRow)) {
                    continue;
                }

                $documentNo = trim((string) ($receiptLineRow['Document_No'] ?? ''));
                if ($documentNo !== '') {
                    break;
                }
            }

            if ($documentNo !== '') {
                $receiptByDocumentUrl = ratatoskr_company_entity_url_with_query($company, 'PostedPurchaseReceipt', [
                    '$select' => 'No,Posting_Date,Document_Date',
                    '$filter' => "No eq '" . str_replace("'", "''", $documentNo) . "'",
                ], $environment);
                if ($useCache) {
                    $receiptByDocumentFetch = ratatoskr_odata_get_all_with_cache_flag($receiptByDocumentUrl, $auth, $detailTtl);
                    $receiptByDocumentRows = is_array($receiptByDocumentFetch['rows'] ?? null) ? $receiptByDocumentFetch['rows'] : [];
                    $hadCacheMiss = $hadCacheMiss || !((bool) ($receiptByDocumentFetch['from_cache'] ?? false));
                } else {
                    $receiptByDocumentRows = ratatoskr_odata_get_all_uncached($receiptByDocumentUrl, $auth);
                }
                foreach ($receiptByDocumentRows as $receiptByDocumentRow) {
                    if (!is_array($receiptByDocumentRow)) {
                        continue;
                    }

                    $receivedDate = ratatoskr_pick_date($receiptByDocumentRow, ['Posting_Date', 'Document_Date']);
                    if ($receivedDate !== '') {
                        break;
                    }
                }
            }

            if ($receivedDate === '') {
                $receivedCandidates = ratatoskr_fetch_recent_received_candidates($company, ratatoskr_store_get_latest_received_date($company), $detailTtl);
                foreach ($receivedCandidates as $receivedCandidate) {
                    if (!is_array($receivedCandidate)) {
                        continue;
                    }

                    if (strcasecmp(trim((string) ($receivedCandidate['order_no'] ?? '')), $orderNoText) !== 0) {
                        continue;
                    }

                    $receivedDate = trim((string) ($receivedCandidate['receipt_date'] ?? ''));
                    if ($receivedDate !== '') {
                        break;
                    }
                }
            }
        }
    }

    $orderDate = ratatoskr_pick_date($orderRow, ['LVS_Order_Date', 'Document_Date', 'Posting_Date']);
    $orderAgeDays = ratatoskr_days_since_date($orderDate !== '' ? $orderDate : $summaryOrderDate);
    if (!$receivedFlag && $receivedDate === '' && is_int($orderAgeDays)) {
        $detailTtl = ratatoskr_ttl_for_open_order_age($orderAgeDays);
    }

    $shipmentDate = ratatoskr_pick_date($orderRow, [
        'LVS_Date_on_Board',
        'LVS_Ex_Factory_Date',
        'LVS_Expected_Receipt_Date',
        'Expected_Receipt_Date',
    ]);

    if (!$receivedFlag && $receivedDate === '' && is_int($orderAgeDays) && $orderAgeDays > 730) {
        $referenceDate = ratatoskr_normalize_date_only($orderDate);
        if ($referenceDate === '') {
            $referenceDate = gmdate('Y-m-d');
        }

        $permanentOrder = [
            'order_no' => trim((string) ($orderRow['No'] ?? $orderNoText)),
            'order_date' => $orderDate,
            'vendor_no' => trim((string) ($orderRow['Buy_from_Vendor_No'] ?? '')),
            'vendor_name' => trim((string) ($orderRow['Buy_from_Vendor_Name'] ?? '')),
            'shipment_date' => $shipmentDate,
            'receipt_date' => $receivedDate,
            'status' => trim((string) ($orderRow['Status'] ?? '')),
            'vendor_order_no' => trim((string) ($orderRow['Vendor_Order_No'] ?? '')),
        ];

        ratatoskr_store_save_permanent_order($company, $permanentOrder, $referenceDate);

        return [
            'order_no' => $permanentOrder['order_no'],
            'order_date' => $permanentOrder['order_date'],
            'vendor_no' => $permanentOrder['vendor_no'],
            'vendor_name' => $permanentOrder['vendor_name'],
            'shipment_date' => $permanentOrder['shipment_date'],
            'receipt_date' => $permanentOrder['receipt_date'],
            'received' => false,
            'status' => $permanentOrder['status'],
            'vendor_order_no' => $permanentOrder['vendor_order_no'],
            'load_scope' => $detailTtl,
            'source' => 'permanent',
            'needs_detail' => false,
            'permanent_unknown_shipment' => $permanentOrder['shipment_date'] === '',
            'permanent_unknown_receipt' => $permanentOrder['receipt_date'] === '',
            'permanent_reference_date' => $referenceDate,
            'debug_cache_miss' => $hadCacheMiss,
        ];
    }

    ratatoskr_store_remove_permanent_order($company, trim((string) ($orderRow['No'] ?? $orderNoText)));

    return [
        'order_no' => trim((string) ($orderRow['No'] ?? $orderNoText)),
        'order_date' => $orderDate,
        'vendor_no' => trim((string) ($orderRow['Buy_from_Vendor_No'] ?? '')),
        'vendor_name' => trim((string) ($orderRow['Buy_from_Vendor_Name'] ?? '')),
        'shipment_date' => $shipmentDate,
        'receipt_date' => $receivedDate,
        'received' => (bool) ($orderRow['LVS_Completely_Received'] ?? false),
        'status' => trim((string) ($orderRow['Status'] ?? '')),
        'vendor_order_no' => trim((string) ($orderRow['Vendor_Order_No'] ?? '')),
        'load_scope' => $detailTtl,
        'debug_cache_miss' => $hadCacheMiss,
    ];
}