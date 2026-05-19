<?php

/**
 * Constants
 */
require_once __DIR__ . '/ratatoskr_order_store.php';

const RATATOSKR_ORDER_LIST_TTL = 18000;
const RATATOSKR_ORDER_TTL_NOT_RECEIVED = 18000;
const RATATOSKR_ORDER_TTL_RECEIVED = 315360000;

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

function ratatoskr_fetch_open_order_candidates(string $company, int $ttl = RATATOSKR_ORDER_LIST_TTL): array
{
    $environment = auth_get_environment_for_company($company, $ttl);
    $auth = auth_get_auth_for_environment($environment);
    $url = ratatoskr_company_entity_url_with_query($company, 'PurchaseOrders', [
        '$select' => 'No,LVS_Order_Date,Document_Date,Posting_Date,Buy_from_Vendor_No,Buy_from_Vendor_Name,LVS_Ex_Factory_Date,LVS_Date_on_Board,LVS_Expected_Receipt_Date,LVS_Completely_Received,Status,Vendor_Order_No',
        '$filter' => 'LVS_Completely_Received eq false',
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
        $sinceDate = '1900-01-01';
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

function ratatoskr_fetch_order_detail(string $company, string $orderNo, bool $receivedFlag, int $ttl = RATATOSKR_ORDER_LIST_TTL): array
{
    $orderNoText = trim($orderNo);
    if ($orderNoText === '') {
        throw new RuntimeException('Ordernummer ontbreekt.');
    }

    $environment = auth_get_environment_for_company($company, $ttl);
    $auth = auth_get_auth_for_environment($environment);
    $detailTtl = $receivedFlag ? RATATOSKR_ORDER_TTL_RECEIVED : RATATOSKR_ORDER_TTL_NOT_RECEIVED;
    $useCache = !$receivedFlag;

    $orderUrl = ratatoskr_company_entity_url_with_query($company, 'PurchaseOrders', [
        '$select' => 'No,LVS_Order_Date,Buy_from_Vendor_No,Buy_from_Vendor_Name,LVS_Ex_Factory_Date,LVS_Date_on_Board,LVS_Expected_Receipt_Date,LVS_Completely_Received,Status,Document_Date,Posting_Date,Vendor_Order_No',
        '$filter' => "No eq '" . str_replace("'", "''", $orderNoText) . "'",
    ], $environment);
    $orderRows = $useCache ? odata_get_all($orderUrl, $auth, $detailTtl) : ratatoskr_odata_get_all_uncached($orderUrl, $auth);
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
        $receiptRows = $useCache ? odata_get_all($receiptUrl, $auth, $detailTtl) : ratatoskr_odata_get_all_uncached($receiptUrl, $auth);
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
            $receiptLineRows = $useCache ? odata_get_all($receiptLineUrl, $auth, $detailTtl) : ratatoskr_odata_get_all_uncached($receiptLineUrl, $auth);
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
                $receiptByDocumentRows = $useCache ? odata_get_all($receiptByDocumentUrl, $auth, $detailTtl) : ratatoskr_odata_get_all_uncached($receiptByDocumentUrl, $auth);
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

    $shipmentDate = ratatoskr_pick_date($orderRow, [
        'LVS_Date_on_Board',
        'LVS_Ex_Factory_Date',
        'LVS_Expected_Receipt_Date',
        'Expected_Receipt_Date',
    ]);

    return [
        'order_no' => trim((string) ($orderRow['No'] ?? $orderNoText)),
        'order_date' => ratatoskr_pick_date($orderRow, ['LVS_Order_Date', 'Document_Date', 'Posting_Date']),
        'vendor_no' => trim((string) ($orderRow['Buy_from_Vendor_No'] ?? '')),
        'vendor_name' => trim((string) ($orderRow['Buy_from_Vendor_Name'] ?? '')),
        'shipment_date' => $shipmentDate,
        'receipt_date' => $receivedDate,
        'received' => (bool) ($orderRow['LVS_Completely_Received'] ?? false),
        'status' => trim((string) ($orderRow['Status'] ?? '')),
        'vendor_order_no' => trim((string) ($orderRow['Vendor_Order_No'] ?? '')),
        'load_scope' => $detailTtl,
    ];
}