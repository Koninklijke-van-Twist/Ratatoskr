<?php

/**
 * Constants
 */
const RATATOSKR_RECEIVED_DB_FILENAME = 'ratatoskr_received_orders.sqlite3';
const RATATOSKR_RECEIVED_STATE_FILENAME = 'ratatoskr_received_state.json';

/**
 * Functies
 */
function ratatoskr_store_cache_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    return $dir;
}

function ratatoskr_store_db_path(): string
{
    return ratatoskr_store_cache_dir() . DIRECTORY_SEPARATOR . RATATOSKR_RECEIVED_DB_FILENAME;
}

function ratatoskr_store_state_path(): string
{
    return ratatoskr_store_cache_dir() . DIRECTORY_SEPARATOR . RATATOSKR_RECEIVED_STATE_FILENAME;
}

function ratatoskr_store_company_key(string $company): string
{
    return strtolower(trim($company));
}

function ratatoskr_store_open_db(): SQLite3
{
    if (!class_exists('SQLite3')) {
        throw new RuntimeException('SQLite3 is niet beschikbaar in deze PHP-runtime.');
    }

    $db = new SQLite3(ratatoskr_store_db_path());
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA synchronous=NORMAL;');
    $db->exec('CREATE TABLE IF NOT EXISTS received_orders (company TEXT NOT NULL, order_no TEXT NOT NULL, order_date TEXT NOT NULL DEFAULT "", vendor_no TEXT NOT NULL DEFAULT "", vendor_name TEXT NOT NULL DEFAULT "", shipment_date TEXT NOT NULL DEFAULT "", receipt_date TEXT NOT NULL DEFAULT "", status TEXT NOT NULL DEFAULT "", vendor_order_no TEXT NOT NULL DEFAULT "", updated_at TEXT NOT NULL DEFAULT "", PRIMARY KEY (company, order_no))');
    $db->exec('CREATE TABLE IF NOT EXISTS sync_state (company TEXT NOT NULL PRIMARY KEY, latest_receipt_date TEXT NOT NULL DEFAULT "", updated_at TEXT NOT NULL DEFAULT "")');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_received_orders_company_receipt ON received_orders(company, receipt_date DESC, order_date DESC)');

    return $db;
}

function ratatoskr_store_state_read(): array
{
    $path = ratatoskr_store_state_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $companies = is_array($decoded['companies'] ?? null) ? $decoded['companies'] : [];
    return $companies;
}

function ratatoskr_store_state_write(array $companies): bool
{
    $payload = [
        'updated_at' => gmdate('c'),
        'companies' => $companies,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }

    return file_put_contents(ratatoskr_store_state_path(), $json, LOCK_EX) !== false;
}

function ratatoskr_store_get_latest_received_date(string $company): string
{
    $companyKey = ratatoskr_store_company_key($company);
    $state = ratatoskr_store_state_read();
    $jsonDate = trim((string) ($state[$companyKey]['latest_received_date'] ?? ''));

    $dbDate = '';
    try {
        $db = ratatoskr_store_open_db();
        $stmt = $db->prepare('SELECT latest_receipt_date FROM sync_state WHERE company = :company LIMIT 1');
        $stmt->bindValue(':company', $companyKey, SQLITE3_TEXT);
        $result = $stmt->execute();
        if ($result instanceof SQLite3Result) {
            $row = $result->fetchArray(SQLITE3_ASSOC);
            if (is_array($row)) {
                $dbDate = trim((string) ($row['latest_receipt_date'] ?? ''));
            }
            $result->finalize();
        }
        $db->close();
    } catch (Throwable $ignoredError) {
        $dbDate = '';
    }

    if ($jsonDate === '') {
        return $dbDate;
    }

    if ($dbDate === '') {
        return $jsonDate;
    }

    return strcmp($jsonDate, $dbDate) >= 0 ? $jsonDate : $dbDate;
}

function ratatoskr_store_load_received_orders(string $company): array
{
    $companyKey = ratatoskr_store_company_key($company);
    $rows = [];

    try {
        $db = ratatoskr_store_open_db();
        $stmt = $db->prepare('SELECT order_no, order_date, vendor_no, vendor_name, shipment_date, receipt_date, status, vendor_order_no FROM received_orders WHERE company = :company ORDER BY receipt_date DESC, order_date DESC, order_no DESC');
        $stmt->bindValue(':company', $companyKey, SQLITE3_TEXT);
        $result = $stmt->execute();
        if ($result instanceof SQLite3Result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (!is_array($row)) {
                    continue;
                }

                $orderNo = trim((string) ($row['order_no'] ?? ''));
                $receiptDate = trim((string) ($row['receipt_date'] ?? ''));
                if ($orderNo === '' || $receiptDate === '') {
                    continue;
                }

                $rows[] = [
                    'order_no' => $orderNo,
                    'order_date' => trim((string) ($row['order_date'] ?? '')),
                    'vendor_no' => trim((string) ($row['vendor_no'] ?? '')),
                    'vendor_name' => trim((string) ($row['vendor_name'] ?? '')),
                    'shipment_date' => trim((string) ($row['shipment_date'] ?? '')),
                    'receipt_date' => $receiptDate,
                    'received' => true,
                    'status' => trim((string) ($row['status'] ?? '')),
                    'vendor_order_no' => trim((string) ($row['vendor_order_no'] ?? '')),
                    'needs_detail' => false,
                    'source' => 'sqlite',
                ];
            }

            $result->finalize();
        }
        $db->close();
    } catch (Throwable $ignoredError) {
        return [];
    }

    return $rows;
}

function ratatoskr_store_save_received_orders(string $company, array $orders, string $latestReceiptDate): bool
{
    $companyKey = ratatoskr_store_company_key($company);
    $latestReceiptDate = trim($latestReceiptDate);
    $now = gmdate('c');

    try {
        $db = ratatoskr_store_open_db();
        $db->exec('BEGIN IMMEDIATE');

        $upsert = $db->prepare('INSERT INTO received_orders (company, order_no, order_date, vendor_no, vendor_name, shipment_date, receipt_date, status, vendor_order_no, updated_at) VALUES (:company, :order_no, :order_date, :vendor_no, :vendor_name, :shipment_date, :receipt_date, :status, :vendor_order_no, :updated_at) ON CONFLICT(company, order_no) DO UPDATE SET order_date = excluded.order_date, vendor_no = excluded.vendor_no, vendor_name = excluded.vendor_name, shipment_date = excluded.shipment_date, receipt_date = excluded.receipt_date, status = excluded.status, vendor_order_no = excluded.vendor_order_no, updated_at = excluded.updated_at');
        if (!$upsert instanceof SQLite3Stmt) {
            throw new RuntimeException('Kon SQLite UPSERT-statement niet maken.');
        }

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $orderNo = trim((string) ($order['order_no'] ?? ''));
            $receiptDate = trim((string) ($order['receipt_date'] ?? ''));
            if ($orderNo === '' || $receiptDate === '') {
                continue;
            }

            $upsert->bindValue(':company', $companyKey, SQLITE3_TEXT);
            $upsert->bindValue(':order_no', $orderNo, SQLITE3_TEXT);
            $upsert->bindValue(':order_date', trim((string) ($order['order_date'] ?? '')), SQLITE3_TEXT);
            $upsert->bindValue(':vendor_no', trim((string) ($order['vendor_no'] ?? '')), SQLITE3_TEXT);
            $upsert->bindValue(':vendor_name', trim((string) ($order['vendor_name'] ?? '')), SQLITE3_TEXT);
            $upsert->bindValue(':shipment_date', trim((string) ($order['shipment_date'] ?? '')), SQLITE3_TEXT);
            $upsert->bindValue(':receipt_date', $receiptDate, SQLITE3_TEXT);
            $upsert->bindValue(':status', trim((string) ($order['status'] ?? '')), SQLITE3_TEXT);
            $upsert->bindValue(':vendor_order_no', trim((string) ($order['vendor_order_no'] ?? '')), SQLITE3_TEXT);
            $upsert->bindValue(':updated_at', $now, SQLITE3_TEXT);
            $result = $upsert->execute();
            if ($result instanceof SQLite3Result) {
                $result->finalize();
            }
        }

        $stateStmt = $db->prepare('INSERT INTO sync_state (company, latest_receipt_date, updated_at) VALUES (:company, :latest_receipt_date, :updated_at) ON CONFLICT(company) DO UPDATE SET latest_receipt_date = excluded.latest_receipt_date, updated_at = excluded.updated_at');
        if (!$stateStmt instanceof SQLite3Stmt) {
            throw new RuntimeException('Kon SQLite state-statement niet maken.');
        }

        $stateStmt->bindValue(':company', $companyKey, SQLITE3_TEXT);
        $stateStmt->bindValue(':latest_receipt_date', $latestReceiptDate, SQLITE3_TEXT);
        $stateStmt->bindValue(':updated_at', $now, SQLITE3_TEXT);
        $result = $stateStmt->execute();
        if ($result instanceof SQLite3Result) {
            $result->finalize();
        }

        $db->exec('COMMIT');
        $db->close();
    } catch (Throwable $error) {
        if (isset($db) && $db instanceof SQLite3) {
            @$db->exec('ROLLBACK');
            @$db->close();
        }
        throw $error;
    }

    $state = ratatoskr_store_state_read();
    $state[$companyKey] = [
        'latest_received_date' => $latestReceiptDate,
        'updated_at' => $now,
    ];

    return ratatoskr_store_state_write($state);
}
