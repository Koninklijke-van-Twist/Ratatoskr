<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
 * Functies
 */
function ratatoskr_bool_from_request(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $text = strtolower(trim((string) $value));
    return in_array($text, ['1', 'true', 'yes', 'on'], true);
}

function ratatoskr_action_is(string $expected): bool
{
    return (string) ($_GET['action'] ?? '') === $expected;
}

function ratatoskr_selected_company(array $companies): string
{
    $requested = trim((string) ($_GET['company'] ?? ''));
    if ($requested !== '' && in_array($requested, $companies, true)) {
        return $requested;
    }

    return (string) ($companies[0] ?? '');
}

function ratatoskr_order_list_payload(string $company): array
{
    return ratatoskr_order_queue_payload($company);
}

function ratatoskr_order_detail_payload(string $company): array
{
    $orderNo = trim((string) ($_POST['order_no'] ?? $_GET['order_no'] ?? ''));
    if ($orderNo === '') {
        throw new RuntimeException('Ordernummer ontbreekt.');
    }

    $receivedFlag = ratatoskr_bool_from_request($_POST['received'] ?? $_GET['received'] ?? false);
    $order = ratatoskr_fetch_order_detail($company, $orderNo, $receivedFlag);
    if (!is_array($order)) {
        throw new RuntimeException('Order niet gevonden.');
    }

    return [
        'ok' => true,
        'company' => $company,
        'order' => $order,
    ];
}

function ratatoskr_send_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ratatoskr_runtime_error_payload(Throwable $error, string $message, int $statusCode = 500): void
{
    ratatoskr_send_json([
        'ok' => false,
        'error' => $message,
        'details' => $error->getMessage(),
    ], $statusCode);
}

function ratatoskr_sync_received_orders_payload(string $company): array
{
    $raw = (string) ($_POST['orders_json'] ?? '');
    $latestReceivedDate = trim((string) ($_POST['latest_received_date'] ?? ''));
    if ($raw === '') {
        throw new RuntimeException('Ontvangen orders ontbreken.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Ongeldige JSON voor ontvangen orders.');
    }

    $receivedOrders = [];
    foreach ($decoded as $order) {
        if (!is_array($order)) {
            continue;
        }

        $receiptDate = trim((string) ($order['receipt_date'] ?? ''));
        $orderNo = trim((string) ($order['order_no'] ?? ''));
        if ($orderNo === '' || $receiptDate === '') {
            continue;
        }

        $receivedOrders[] = [
            'order_no' => $orderNo,
            'order_date' => trim((string) ($order['order_date'] ?? '')),
            'vendor_no' => trim((string) ($order['vendor_no'] ?? '')),
            'vendor_name' => trim((string) ($order['vendor_name'] ?? '')),
            'shipment_date' => trim((string) ($order['shipment_date'] ?? '')),
            'receipt_date' => $receiptDate,
            'received' => true,
            'status' => trim((string) ($order['status'] ?? '')),
            'vendor_order_no' => trim((string) ($order['vendor_order_no'] ?? '')),
        ];
    }

    if ($receivedOrders === []) {
        return [
            'ok' => true,
            'company' => $company,
            'saved_count' => 0,
            'latest_received_date' => $latestReceivedDate,
        ];
    }

    if ($latestReceivedDate === '') {
        $latestReceivedDate = $receivedOrders[0]['receipt_date'];
        foreach ($receivedOrders as $order) {
            if (strcmp((string) $order['receipt_date'], $latestReceivedDate) > 0) {
                $latestReceivedDate = (string) $order['receipt_date'];
            }
        }
    }

    ratatoskr_store_save_received_orders($company, $receivedOrders, $latestReceivedDate);

    return [
        'ok' => true,
        'company' => $company,
        'saved_count' => count($receivedOrders),
        'latest_received_date' => $latestReceivedDate,
    ];
}

/**
 * Page load
 */
$companies = ratatoskr_discover_companies();
$selectedCompany = ratatoskr_selected_company($companies);
$cacheWidget = injectTimerHtml([
    'title' => 'OData cache',
    'label' => 'Cache',
    'css' => <<<'CSS'
{{root}} {
	position: relative;
	display: block;
	margin-top: 12px;
}

{{root}} .odata-cache-widget {
	position: static;
	margin-left: auto;
}

{{root}} .odata-cache-popout {
	left: 0;
	right: 0;
	width: min(760px, calc(100vw - 32px));
	margin-top: 10px;
}
CSS,
]);

if (ratatoskr_action_is('order_list')) {
    $company = trim((string) ($_POST['company'] ?? ''));
    if ($company === '' || !in_array($company, $companies, true)) {
        ratatoskr_send_json(['ok' => false, 'error' => 'Kies een geldig bedrijf.'], 400);
    }

    try {
        ratatoskr_send_json(ratatoskr_order_list_payload($company));
    } catch (Throwable $error) {
        ratatoskr_runtime_error_payload($error, 'Inkooporders ophalen mislukt.');
    }
}

if (ratatoskr_action_is('order_detail')) {
    $company = trim((string) ($_POST['company'] ?? ''));
    if ($company === '' || !in_array($company, $companies, true)) {
        ratatoskr_send_json(['ok' => false, 'error' => 'Kies een geldig bedrijf.'], 400);
    }

    try {
        ratatoskr_send_json(ratatoskr_order_detail_payload($company));
    } catch (Throwable $error) {
        ratatoskr_runtime_error_payload($error, 'Inkooporder ophalen mislukt.');
    }
}

if (ratatoskr_action_is('sync_received_orders')) {
    $company = trim((string) ($_POST['company'] ?? ''));
    if ($company === '' || !in_array($company, $companies, true)) {
        ratatoskr_send_json(['ok' => false, 'error' => 'Kies een geldig bedrijf.'], 400);
    }

    try {
        ratatoskr_send_json(ratatoskr_sync_received_orders_payload($company));
    } catch (Throwable $error) {
        ratatoskr_runtime_error_payload($error, 'Opslaan van ontvangen orders mislukt.');
    }
}
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="brand.css">
    <title>Ratatoskr</title>
    <style>
        :root {
            --bg: var(--kvt-page-bg);
            --panel: #ffffff;
            --panel-alt: #f7fbff;
            --text: var(--kvt-text);
            --muted: var(--kvt-muted);
            --line: var(--kvt-line);
            --brand: var(--kvt-main-blue);
            --brand-light: var(--kvt-light-blue);
            --brand-dark: var(--kvt-perkins-blue);
            --danger: var(--kvt-danger);
            --success: #1f8b4c;
            --shadow: 0 18px 40px rgba(0, 82, 155, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: var(--text);
            background:
                radial-gradient(900px 500px at -10% -10%, rgba(51, 204, 255, 0.18), transparent 55%),
                radial-gradient(900px 500px at 110% 0%, rgba(0, 153, 204, 0.14), transparent 50%),
                radial-gradient(700px 400px at 50% 110%, rgba(0, 82, 155, 0.08), transparent 50%),
                var(--bg);
        }

        .page {
            max-width: 1180px;
            margin: 0 auto;
            padding: 16px;
        }

        .hero {
            position: relative;
            overflow: hidden;
            padding: 18px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(0, 82, 155, 0.98), rgba(0, 153, 204, 0.96));
            color: #fff;
            box-shadow: 0 20px 40px rgba(0, 82, 155, 0.24);
        }

        .hero::after {
            content: '';
            position: absolute;
            inset: -20% -10% auto auto;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.2) 0%, rgba(255, 255, 255, 0.02) 60%, transparent 70%);
            pointer-events: none;
        }

        .hero-grid {
            display: grid;
            gap: 16px;
        }

        .hero-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .hero-logo {
            width: min(240px, 54vw);
            height: auto;
            display: block;
        }

        .hero-kicker {
            margin: 0;
            font-size: 12px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.78);
        }

        .hero h1 {
            margin: 4px 0 0;
            font-size: clamp(30px, 6vw, 44px);
            line-height: 1.02;
        }

        .hero p {
            margin: 10px 0 0;
            max-width: 64ch;
            font-size: 15px;
            line-height: 1.5;
            color: rgba(255, 255, 255, 0.9);
        }

        .hero-copy {
            display: grid;
            gap: 10px;
        }

        .hero-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 32px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.2);
            font-size: 13px;
            font-weight: 700;
            backdrop-filter: blur(6px);
        }

        .shell {
            display: grid;
            gap: 16px;
            margin-top: 16px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid rgba(0, 82, 155, 0.12);
            border-radius: 20px;
            box-shadow: var(--shadow);
            padding: 16px;
        }

        .panel-title {
            margin: 0 0 6px;
            font-size: 18px;
            color: var(--brand-dark);
        }

        .panel-subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.45;
        }

        .controls-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: 1fr;
            margin-top: 14px;
        }

        .field label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
        }

        select,
        button {
            width: 100%;
            min-height: 44px;
            border-radius: 12px;
            border: 1px solid #b8cbe1;
            padding: 10px 12px;
            font-size: 15px;
            font-family: inherit;
        }

        button {
            cursor: pointer;
            font-weight: 700;
        }

        .btn-main {
            background: linear-gradient(135deg, var(--brand-dark), var(--brand));
            color: #fff;
            border-color: transparent;
        }

        .btn-ghost {
            background: #fff;
            color: var(--brand-dark);
        }

        .btn-inline {
            width: auto;
            min-height: 34px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 13px;
        }

        button[disabled] {
            opacity: 0.55;
            cursor: default;
        }

        .status-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 16px;
            background: var(--panel-alt);
            border: 1px solid #dbe9f7;
        }

        .status-text {
            margin: 0;
            font-size: 14px;
            color: var(--muted);
        }

        .status-text strong {
            color: var(--brand-dark);
        }

        .results-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .order-list {
            display: grid;
            gap: 12px;
            margin: 16px 0 0;
            padding: 0;
            list-style: none;
        }

        .order-card {
            border: 1px solid #d9e5f1;
            border-radius: 18px;
            padding: 14px;
            background: #fff;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
            animation: riseIn 260ms ease-out both;
        }

        .order-card:hover {
            border-color: rgba(0, 153, 204, 0.36);
            box-shadow: 0 12px 24px rgba(0, 82, 155, 0.09);
        }

        .order-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .order-number {
            margin: 0;
            font-size: 17px;
            color: var(--brand-dark);
        }

        .order-meta {
            margin: 4px 0 0;
            font-size: 13px;
            color: var(--muted);
        }

        .vendor-button {
            border: 0;
            background: rgba(0, 153, 204, 0.11);
            color: var(--brand-dark);
            padding: 8px 10px;
            border-radius: 999px;
            font-weight: 700;
            min-height: 34px;
            width: auto;
            max-width: 100%;
            text-align: left;
        }

        .vendor-button:hover {
            background: rgba(0, 153, 204, 0.18);
        }

        .date-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: 1fr;
            margin-top: 14px;
        }

        .date-box {
            padding: 10px 12px;
            border-radius: 14px;
            background: #f8fbff;
            border: 1px solid #e2edf7;
            min-width: 0;
        }

        .date-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .date-value {
            display: block;
            margin-top: 4px;
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
            word-break: break-word;
        }

        .date-note {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: var(--muted);
        }

        .order-error {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(180, 35, 24, 0.08);
            color: var(--danger);
            font-size: 13px;
        }

        .empty-state {
            padding: 18px 12px;
            border-radius: 16px;
            border: 1px dashed #b6c9df;
            background: #f9fcff;
            color: var(--muted);
            font-size: 14px;
        }

        .loader-overlay {
            position: fixed;
            inset: 0;
            z-index: 11000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(12, 24, 38, 0.56);
            backdrop-filter: blur(3px);
        }

        .loader-overlay.is-visible {
            display: flex;
        }

        .loader-card {
            width: min(100%, 520px);
            border-radius: 22px;
            padding: 18px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(245, 250, 255, 0.98));
            border: 1px solid rgba(183, 203, 228, 0.9);
            box-shadow: 0 24px 50px rgba(10, 18, 29, 0.3);
        }

        .loader-title {
            margin: 0;
            font-size: 22px;
            color: var(--brand-dark);
        }

        .loader-subtitle {
            margin: 6px 0 14px;
            font-size: 14px;
            color: var(--muted);
        }

        .loader-progress {
            height: 12px;
            border-radius: 999px;
            background: #deebf8;
            overflow: hidden;
            margin-bottom: 14px;
        }

        .loader-progress-bar {
            height: 100%;
            width: 0;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--brand-dark), var(--brand), var(--brand-light));
            transition: width 180ms ease;
        }

        .loader-steps {
            display: grid;
            gap: 8px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .loader-step {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 12px;
            background: #f7fbff;
            border: 1px solid #d9e8f4;
            font-size: 14px;
        }

        .loader-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid #99adc2;
            flex-shrink: 0;
        }

        .loader-step.is-current .loader-dot {
            border-color: var(--brand);
            background: rgba(0, 153, 204, 0.18);
            box-shadow: 0 0 0 4px rgba(0, 153, 204, 0.08);
        }

        .loader-step.is-done .loader-dot {
            border-color: var(--success);
            background: var(--success);
        }

        .loader-live {
            margin: 14px 0 0;
            font-size: 13px;
            color: var(--muted);
            min-height: 1.4em;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 12000;
            display: none;
            align-items: stretch;
            justify-content: center;
            padding: 12px;
            background: rgba(12, 20, 34, 0.58);
        }

        .modal-overlay.is-visible {
            display: flex;
        }

        .modal-card {
            width: min(100%, 980px);
            height: min(94vh, 900px);
            background: #fff;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid rgba(184, 203, 225, 0.9);
            box-shadow: 0 24px 60px rgba(4, 15, 29, 0.34);
            display: flex;
            flex-direction: column;
        }

        .modal-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            background: linear-gradient(135deg, var(--brand-dark), var(--brand));
            color: #fff;
        }

        .modal-title {
            margin: 0;
            font-size: 20px;
        }

        .modal-subtitle {
            margin: 6px 0 0;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.9);
        }

        .modal-close {
            width: auto;
            min-width: 40px;
            min-height: 40px;
            border: 0;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            color: #fff;
            font-size: 20px;
        }

        .modal-body {
            padding: 16px;
            overflow: auto;
            background:
                radial-gradient(800px 260px at 0% 0%, rgba(0, 153, 204, 0.06), transparent 60%),
                #fff;
        }

        .stats-grid {
            display: grid;
            gap: 12px;
        }

        .stats-card {
            border: 1px solid #dbe7f4;
            border-radius: 16px;
            padding: 14px;
            background: #fbfdff;
        }

        .stats-card h3 {
            margin: 0 0 10px;
            font-size: 16px;
            color: var(--brand-dark);
        }

        .stats-table {
            width: 100%;
            border-collapse: collapse;
        }

        .stats-table th,
        .stats-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #e3edf7;
            text-align: left;
            font-size: 13px;
            vertical-align: top;
        }

        .stats-table th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
        }

        .stats-table td {
            color: var(--text);
        }

        .stats-empty {
            padding: 12px;
            border-radius: 12px;
            background: #f7fbff;
            color: var(--muted);
            font-size: 13px;
        }

        .stats-note {
            margin: 12px 0 0;
            font-size: 12px;
            color: var(--muted);
        }

        @keyframes riseIn {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (min-width: 840px) {
            .hero-grid {
                grid-template-columns: minmax(0, 1.5fr) minmax(250px, 0.85fr);
                align-items: end;
            }

            .controls-grid {
                grid-template-columns: minmax(0, 1fr) auto;
            }

            .date-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .stats-grid {
                grid-template-columns: minmax(0, 1fr) minmax(260px, 0.72fr);
            }
        }

        @media (max-width: 839px) {
            .page {
                padding: 12px;
            }

            .hero {
                padding: 14px;
                border-radius: 18px;
            }

            .order-top {
                flex-direction: column;
            }

            .vendor-button {
                width: 100%;
            }

            .modal-overlay {
                padding: 0;
            }

            .modal-card {
                width: 100%;
                height: 100vh;
                border-radius: 0;
                border: 0;
            }
        }
    </style>
</head>

<body>
    <div class="page">
        <header class="hero">
            <div class="hero-grid">
                <div class="hero-copy">
                    <div class="hero-brand">
                        <img class="hero-logo" src="logo-website.png" alt="Ratatoskr logo">
                    </div>
                    <p class="hero-kicker">Ratatoskr</p>
                    <h1>Inkooporders per bedrijf</h1>
                </div>
                <div>
                    <?php echo $cacheWidget; ?>
                </div>
            </div>
        </header>

        <main class="shell">
            <section class="panel">
                <div class="results-head">
                    <div>
                        <h2 class="panel-title">Bedrijf kiezen</h2>
                        <p class="panel-subtitle">Orders worden pas geladen nadat je een bedrijf hebt gekozen en op
                            laden klikt.</p>
                    </div>
                </div>

                <div class="controls-grid">
                    <div class="field">
                        <label for="companySelect">Bedrijf</label>
                        <select id="companySelect" autocomplete="off">
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= htmlspecialchars($company, ENT_QUOTES) ?>" <?= $company === $selectedCompany ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($company) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>&nbsp;</label>
                        <button id="loadOrdersButton" class="btn-main" type="button">Laad inkooporders</button>
                    </div>
                </div>

                <div class="status-bar" aria-live="polite">
                    <p id="statusText" class="status-text">Kies een bedrijf om de orderlijst te laden.</p>
                    <button id="clearOrdersButton" class="btn-ghost btn-inline" type="button" disabled>Wis
                        lijst</button>
                </div>
            </section>

            <section class="panel">
                <div class="results-head">
                    <div>
                        <h2 class="panel-title">Inkooporders</h2>
                        <p class="panel-subtitle">Klik op een leverancier voor gemiddelde doorlooptijden over de
                            afgelopen maand, half jaar en jaar.</p>
                    </div>
                    <div id="summaryBadge" class="badge" style="display:none;"></div>
                </div>
                <div id="orderList" class="order-list">
                    <div class="empty-state">Nog geen orders geladen.</div>
                </div>
            </section>
        </main>
    </div>

    <div id="loaderOverlay" class="loader-overlay" aria-live="polite" aria-busy="true">
        <div class="loader-card">
            <h2 id="loaderTitle" class="loader-title">Inkooporders laden</h2>
            <p id="loaderSubtitle" class="loader-subtitle">Wachten op bedrijfskeuze...</p>
            <div class="loader-progress">
                <div id="loaderProgressBar" class="loader-progress-bar"></div>
            </div>
            <ul id="loaderSteps" class="loader-steps"></ul>
            <p id="loaderLive" class="loader-live"></p>
        </div>
    </div>

    <div id="vendorModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="vendorModalTitle">
        <div class="modal-card" tabindex="-1">
            <div class="modal-head">
                <div>
                    <h2 id="vendorModalTitle" class="modal-title">Leveranciersstatistieken</h2>
                    <p id="vendorModalSubtitle" class="modal-subtitle"></p>
                </div>
                <button id="vendorModalClose" class="modal-close" type="button" aria-label="Sluiten">&times;</button>
            </div>
            <div class="modal-body">
                <div class="stats-grid">
                    <div class="stats-card">
                        <h3>Gemiddelde doorlooptijd</h3>
                        <div id="vendorStatsArea"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function ()
        {
            const companySelect = document.getElementById('companySelect');
            const loadOrdersButton = document.getElementById('loadOrdersButton');
            const clearOrdersButton = document.getElementById('clearOrdersButton');
            const statusText = document.getElementById('statusText');
            const orderList = document.getElementById('orderList');
            const summaryBadge = document.getElementById('summaryBadge');
            const loaderOverlay = document.getElementById('loaderOverlay');
            const loaderTitle = document.getElementById('loaderTitle');
            const loaderSubtitle = document.getElementById('loaderSubtitle');
            const loaderSteps = document.getElementById('loaderSteps');
            const loaderLive = document.getElementById('loaderLive');
            const loaderProgressBar = document.getElementById('loaderProgressBar');
            const vendorModal = document.getElementById('vendorModal');
            const vendorModalTitle = document.getElementById('vendorModalTitle');
            const vendorModalSubtitle = document.getElementById('vendorModalSubtitle');
            const vendorModalClose = document.getElementById('vendorModalClose');
            const vendorStatsArea = document.getElementById('vendorStatsArea');

            const state = {
                orders: [],
                loading: false,
            };

            function escapeHtml (value)
            {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function postJson (url, payload)
            {
                return fetch(url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: new URLSearchParams(payload),
                    credentials: 'same-origin',
                    cache: 'no-store',
                }).then(function (response)
                {
                    return response.text().then(function (text)
                    {
                        let data = null;
                        try
                        {
                            data = text.trim() === '' ? null : JSON.parse(text);
                        } catch (error)
                        {
                            throw new Error('Ongeldige JSON-respons van de server.');
                        }

                        if (!response.ok)
                        {
                            throw new Error((data && data.error) ? data.error : ('HTTP ' + response.status));
                        }

                        return data;
                    });
                });
            }

            function formatDate (value)
            {
                const text = String(value || '').trim();
                if (text === '')
                {
                    return 'Niet bekend';
                }

                const parts = text.split('-');
                if (parts.length === 3)
                {
                    return parts[2] + '-' + parts[1] + '-' + parts[0];
                }

                return text;
            }

            function parseDateOnly (value)
            {
                const text = String(value || '').trim();
                if (text === '')
                {
                    return null;
                }

                const parts = text.split('-');
                if (parts.length !== 3)
                {
                    return null;
                }

                const year = Number(parts[0]);
                const month = Number(parts[1]);
                const day = Number(parts[2]);
                if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day))
                {
                    return null;
                }

                return Date.UTC(year, month - 1, day);
            }

            function formatDurationDays (value)
            {
                if (!Number.isFinite(value))
                {
                    return 'n.v.t.';
                }

                return value.toFixed(1).replace('.', ',') + ' dagen';
            }

            function setLoading (isLoading)
            {
                state.loading = Boolean(isLoading);
                loadOrdersButton.disabled = state.loading;
                companySelect.disabled = state.loading;
                clearOrdersButton.disabled = state.loading || state.orders.length === 0;
                loaderOverlay.classList.toggle('is-visible', state.loading);
            }

            function setStatus (message, isError)
            {
                statusText.textContent = message;
                statusText.style.color = isError ? 'var(--danger)' : 'var(--muted)';
            }

            function setSummary (orders)
            {
                if (!Array.isArray(orders) || orders.length === 0)
                {
                    summaryBadge.style.display = 'none';
                    return;
                }

                summaryBadge.style.display = 'inline-flex';
                summaryBadge.textContent = orders.length.toLocaleString('nl-NL') + ' orders geladen';
            }

            function showLoader (title, subtitle)
            {
                loaderTitle.textContent = title;
                loaderSubtitle.textContent = subtitle;
                loaderSteps.innerHTML = '';
                loaderLive.textContent = '';
                loaderProgressBar.style.width = '0%';
            }

            function formatLoaderStepLabel (item, index, currentIndex, currentPercent)
            {
                const label = escapeHtml(item);
                if (index < currentIndex)
                {
                    return label + ' (100%)';
                }

                if (index === currentIndex && Number.isFinite(currentPercent))
                {
                    return label + ' (' + Math.max(0, Math.min(100, Math.round(currentPercent))) + '%)';
                }

                return label;
            }

            function setLoaderSteps (items, currentIndex, currentPercent)
            {
                const safeItems = Array.isArray(items) ? items : [];
                loaderSteps.innerHTML = safeItems.map(function (item, index)
                {
                    const className = index < currentIndex ? 'is-done' : (index === currentIndex ? 'is-current' : '');
                    return '<li class="loader-step ' + className + '"><span class="loader-dot"></span><span>' + formatLoaderStepLabel(item, index, currentIndex, currentPercent) + '</span></li>';
                }).join('');
            }

            function updateLoaderProgress (current, total, detailText)
            {
                const safeTotal = Math.max(1, Number(total) || 1);
                const safeCurrent = Math.min(safeTotal, Math.max(0, Number(current) || 0));
                loaderProgressBar.style.width = Math.round((safeCurrent / safeTotal) * 100) + '%';
                loaderLive.textContent = detailText || '';
            }

            function hideLoader ()
            {
                setLoading(false);
            }

            function normalizeOrder (summary, detail)
            {
                const summaryData = summary || {};
                const detailData = detail || {};
                const firstNonEmpty = function ()
                {
                    for (let index = 0; index < arguments.length; index += 1)
                    {
                        const value = String(arguments[index] || '').trim();
                        if (value !== '')
                        {
                            return value;
                        }
                    }
                    return '';
                };
                const merged = Object.assign({}, summaryData, detailData);
                merged.order_no = firstNonEmpty(detailData.order_no, detailData.No, summaryData.order_no, summaryData.No);
                merged.order_date = firstNonEmpty(detailData.order_date, detailData.Order_Date, summaryData.order_date, summaryData.Order_Date);
                merged.vendor_no = firstNonEmpty(detailData.vendor_no, detailData.Buy_from_Vendor_No, summaryData.vendor_no, summaryData.Buy_from_Vendor_No);
                merged.vendor_name = firstNonEmpty(detailData.vendor_name, detailData.Buy_from_Vendor_Name, summaryData.vendor_name, summaryData.Buy_from_Vendor_Name);
                merged.shipment_date = firstNonEmpty(detailData.shipment_date, summaryData.shipment_date, detailData.ship_date, detailData.LVS_Ex_Factory_Date, detailData.LVS_Date_on_Board, detailData.LVS_Expected_Receipt_Date);
                merged.receipt_date = firstNonEmpty(detailData.receipt_date, summaryData.receipt_date, detailData.received_date, detailData.Posting_Date);
                merged.received = Boolean(summaryData.received || detailData.received || detailData.completely_received || detailData.LVS_Completely_Received);
                merged.needs_detail = Boolean(summaryData.needs_detail || detailData.needs_detail);
                merged.source = firstNonEmpty(detailData.source, summaryData.source);
                merged.status = firstNonEmpty(detailData.status, detailData.Status, summaryData.status, summaryData.Status);
                merged.vendor_order_no = firstNonEmpty(detailData.vendor_order_no, detailData.Vendor_Order_No, summaryData.vendor_order_no, summaryData.Vendor_Order_No);
                merged.load_error = String(detailData.load_error || summaryData.load_error || '').trim();
                return merged;
            }

            function sortOrders (orders)
            {
                return (Array.isArray(orders) ? orders.slice() : []).sort(function (left, right)
                {
                    const leftDate = parseDateOnly(left.order_date) || 0;
                    const rightDate = parseDateOnly(right.order_date) || 0;
                    if (rightDate !== leftDate)
                    {
                        return rightDate - leftDate;
                    }

                    return String(right.order_no || '').localeCompare(String(left.order_no || ''), 'nl', { numeric: true, sensitivity: 'base' });
                });
            }

            function renderOrders (orders)
            {
                state.orders = sortOrders(orders);
                clearOrdersButton.disabled = state.loading || state.orders.length === 0;
                setSummary(state.orders);

                if (state.orders.length === 0)
                {
                    orderList.innerHTML = '<div class="empty-state">Er zijn geen inkooporders gevonden voor dit bedrijf.</div>';
                    return;
                }

                orderList.innerHTML = state.orders.map(function (order, index)
                {
                    const orderNo = escapeHtml(order.order_no || '');
                    const vendorName = escapeHtml(order.vendor_name || 'Onbekende leverancier');
                    const vendorNo = escapeHtml(order.vendor_no || '');
                    const orderDateText = formatDate(order.order_date);
                    const shipmentText = order.shipment_date ? formatDate(order.shipment_date) : 'Nog niet verstuurd';
                    const receiptText = order.receipt_date ? formatDate(order.receipt_date) : 'Nog niet ontvangen';
                    const shipmentNote = order.shipment_date ? '' : 'Geen verzendingsdatum in BC.';
                    const receiptNote = order.receipt_date ? '' : 'Geen ontvangstdatum in BC.';
                    const orderNote = order.received ? 'Order staat als ontvangen gemarkeerd.' : 'Order staat nog open of deels open.';
                    const errorHtml = order.load_error ? '<div class="order-error">' + escapeHtml(order.load_error) + '</div>' : '';
                    const vendorButton = '<button type="button" class="vendor-button" data-vendor-no="' + vendorNo + '" data-vendor-name="' + vendorName + '">' + vendorName + '</button>';

                    return '<li class="order-card" style="animation-delay:' + Math.min(index * 18, 220) + 'ms">'
                        + '<div class="order-top">'
                        + '<div>'
                        + '<h3 class="order-number">' + orderNo + '</h3>'
                        + '<p class="order-meta">' + escapeHtml(orderNote) + '</p>'
                        + '</div>'
                        + '<div>' + vendorButton + '</div>'
                        + '</div>'
                        + '<div class="date-grid">'
                        + '<div class="date-box"><span class="date-label">Besteldatum</span><span class="date-value">' + escapeHtml(orderDateText) + '</span></div>'
                        + '<div class="date-box"><span class="date-label">Verzending</span><span class="date-value">' + escapeHtml(shipmentText) + '</span><span class="date-note">' + escapeHtml(shipmentNote) + '</span></div>'
                        + '<div class="date-box"><span class="date-label">Ontvangst</span><span class="date-value">' + escapeHtml(receiptText) + '</span><span class="date-note">' + escapeHtml(receiptNote) + '</span></div>'
                        + '</div>'
                        + errorHtml
                        + '</li>';
                }).join('');
            }

            function periodLabel (monthsBack)
            {
                if (monthsBack === 1)
                {
                    return 'Afgelopen maand';
                }
                if (monthsBack === 6)
                {
                    return 'Afgelopen half jaar';
                }
                return 'Afgelopen jaar';
            }

            function startForPeriod (monthsBack)
            {
                const start = new Date();
                start.setHours(0, 0, 0, 0);
                start.setMonth(start.getMonth() - monthsBack);
                return start.getTime();
            }

            function averageDuration (orders, predicate, selector)
            {
                let total = 0;
                let count = 0;

                for (const order of orders)
                {
                    if (!predicate(order))
                    {
                        continue;
                    }

                    const duration = selector(order);
                    if (!Number.isFinite(duration) || duration < 0)
                    {
                        continue;
                    }

                    total += duration;
                    count += 1;
                }

                return count === 0 ? null : total / count;
            }

            function calculateVendorStats (vendorNo, vendorName)
            {
                const vendorKey = String(vendorNo || '').trim().toLowerCase();
                const vendorLabel = String(vendorName || '').trim();
                const vendorOrders = state.orders.filter(function (order)
                {
                    const orderVendorNo = String(order.vendor_no || '').trim().toLowerCase();
                    const orderVendorName = String(order.vendor_name || '').trim().toLowerCase();
                    if (vendorKey !== '')
                    {
                        return orderVendorNo === vendorKey;
                    }
                    return orderVendorName === vendorLabel.toLowerCase();
                });

                const ranges = [1, 6, 12].map(function (monthsBack)
                {
                    const startMs = startForPeriod(monthsBack);
                    const endMs = Date.now();
                    const periodOrders = vendorOrders.filter(function (order)
                    {
                        const orderMs = parseDateOnly(order.order_date);
                        return orderMs !== null && orderMs >= startMs && orderMs <= endMs;
                    });

                    const orderToShip = averageDuration(periodOrders, function (order)
                    {
                        return parseDateOnly(order.order_date) !== null && parseDateOnly(order.shipment_date) !== null;
                    }, function (order)
                    {
                        const orderMs = parseDateOnly(order.order_date);
                        const shipMs = parseDateOnly(order.shipment_date);
                        return orderMs === null || shipMs === null ? NaN : (shipMs - orderMs) / 86400000;
                    });

                    const shipToReceipt = averageDuration(periodOrders, function (order)
                    {
                        return parseDateOnly(order.shipment_date) !== null && parseDateOnly(order.receipt_date) !== null;
                    }, function (order)
                    {
                        const shipMs = parseDateOnly(order.shipment_date);
                        const receiptMs = parseDateOnly(order.receipt_date);
                        return shipMs === null || receiptMs === null ? NaN : (receiptMs - shipMs) / 86400000;
                    });

                    const totalLead = averageDuration(periodOrders, function (order)
                    {
                        return parseDateOnly(order.order_date) !== null && parseDateOnly(order.receipt_date) !== null;
                    }, function (order)
                    {
                        const orderMs = parseDateOnly(order.order_date);
                        const receiptMs = parseDateOnly(order.receipt_date);
                        return orderMs === null || receiptMs === null ? NaN : (receiptMs - orderMs) / 86400000;
                    });

                    return {
                        label: periodLabel(monthsBack),
                        count: periodOrders.length,
                        orderToShip: orderToShip,
                        shipToReceipt: shipToReceipt,
                        totalLead: totalLead,
                    };
                });

                return {
                    vendorName: vendorLabel || vendorKey || 'Onbekende leverancier',
                    stats: ranges,
                };
            }

            function renderVendorStats (payload)
            {
                const rows = Array.isArray(payload.stats) ? payload.stats : [];
                if (rows.length === 0)
                {
                    vendorStatsArea.innerHTML = '<div class="stats-empty">Geen gegevens beschikbaar voor deze leverancier.</div>';
                    return;
                }

                let html = '<table class="stats-table"><thead><tr><th>Periode</th><th>Bestelling → versturen</th><th>Versturen → ontvangen</th><th>Totaal</th><th>Orders</th></tr></thead><tbody>';
                for (const row of rows)
                {
                    html += '<tr>'
                        + '<td>' + escapeHtml(row.label) + '</td>'
                        + '<td>' + escapeHtml(formatDurationDays(row.orderToShip)) + '</td>'
                        + '<td>' + escapeHtml(formatDurationDays(row.shipToReceipt)) + '</td>'
                        + '<td>' + escapeHtml(formatDurationDays(row.totalLead)) + '</td>'
                        + '<td>' + escapeHtml(String(row.count || 0)) + '</td>'
                        + '</tr>';
                }
                html += '</tbody></table>';
                vendorStatsArea.innerHTML = html;
            }

            function openVendorModal (vendorNo, vendorName)
            {
                const payload = calculateVendorStats(vendorNo, vendorName);
                vendorModalTitle.textContent = 'Leveranciersstatistieken';
                vendorModalSubtitle.textContent = payload.vendorName;
                renderVendorStats(payload);
                vendorModal.classList.add('is-visible');
                vendorModal.querySelector('.modal-card').focus({ preventScroll: true });
            }

            function closeVendorModal ()
            {
                vendorModal.classList.remove('is-visible');
            }

            async function loadOrders ()
            {
                const company = String(companySelect.value || '').trim();
                if (company === '')
                {
                    setStatus('Kies eerst een bedrijf.', true);
                    return;
                }

                try
                {
                    setLoading(true);
                    showLoader('Inkooporders laden', 'Lijst met orderkoppen ophalen voor ' + company + '.');
                    setLoaderSteps(['Orderkoppen ophalen', 'Orderdetails laden', 'Lijst tonen'], 0, 0);
                    updateLoaderProgress(0, 1, 'Orderkoppen ophalen...');

                    const listPayload = await postJson('index.php?action=order_list', { company: company });
                    const summaries = Array.isArray(listPayload.orders) ? listPayload.orders : [];
                    const storedSummaries = summaries.filter(function (order)
                    {
                        return Boolean(order && order.needs_detail === false);
                    });
                    const detailSummaries = summaries.filter(function (order)
                    {
                        return !order || order.needs_detail !== false;
                    });
                    if (summaries.length === 0)
                    {
                        renderOrders([]);
                        setStatus('Geen inkooporders gevonden voor ' + company + '.', false);
                        return;
                    }

                    const ordersByNo = new Map();
                    const upsertOrder = function (order)
                    {
                        const normalizedOrder = normalizeOrder(order, order);
                        const orderNo = String(normalizedOrder.order_no || '').trim();
                        if (orderNo === '')
                        {
                            return;
                        }

                        const existingOrder = ordersByNo.get(orderNo) || {};
                        ordersByNo.set(orderNo, Object.assign({}, existingOrder, normalizedOrder));
                    };

                    storedSummaries.forEach(function (order)
                    {
                        upsertOrder(order);
                    });

                    const totalOrders = Math.max(1, summaries.length);
                    const storedPercent = Math.min(100, Math.round((storedSummaries.length / totalOrders) * 100));
                    setLoaderSteps(['Historische orders laden', 'Orderdetails laden', 'Lijst tonen'], 1, 0);
                    updateLoaderProgress(storedSummaries.length, totalOrders, storedSummaries.length + ' historische orders geladen.');

                    for (let index = 0; index < detailSummaries.length; index += 1)
                    {
                        const summary = detailSummaries[index] || {};
                        const orderNo = String(summary.order_no || '').trim();
                        if (orderNo === '')
                        {
                            continue;
                        }

                        const detailPercent = totalOrders > 0 ? ((storedSummaries.length + index + 1) / totalOrders) * 100 : 100;
                        setLoaderSteps(['Historische orders geladen', 'Orderdetails laden', 'Lijst tonen'], 1, detailPercent);
                        updateLoaderProgress(storedSummaries.length + index + 1, totalOrders, 'Order ' + (storedSummaries.length + index + 1) + ' van ' + totalOrders + ' (' + Math.round(detailPercent) + '%): ' + orderNo);
                        try
                        {
                            const detailPayload = await postJson('index.php?action=order_detail', {
                                company: company,
                                order_no: orderNo,
                                received: summary.received ? '1' : '0',
                            });

                            const detailOrder = detailPayload && detailPayload.order ? detailPayload.order : {};
                            upsertOrder(normalizeOrder(summary, detailOrder));
                        } catch (error)
                        {
                            upsertOrder(normalizeOrder(summary, {
                                shipment_date: '',
                                receipt_date: '',
                                load_error: (error && error.message) ? error.message : 'Orderdetails konden niet worden geladen.',
                            }));
                        }
                    }

                    const detailedOrders = sortOrders(Array.from(ordersByNo.values()));
                    renderOrders(detailedOrders);
                    setStatus(detailedOrders.length + ' orders geladen voor ' + company + '.', false);
                    setLoaderSteps(['SQLite geladen', 'Orderdetails geladen', 'Lijst getoond'], 2, 100);
                    updateLoaderProgress(detailedOrders.length, detailedOrders.length, 'Klaar.');

                    const receivedOrders = detailedOrders.filter(function (order)
                    {
                        return String(order.receipt_date || '').trim() !== '';
                    });
                    if (receivedOrders.length > 0)
                    {
                        let latestReceiptDate = '';
                        for (const order of receivedOrders)
                        {
                            const receiptDate = String(order.receipt_date || '').trim();
                            if (receiptDate !== '' && (latestReceiptDate === '' || receiptDate > latestReceiptDate))
                            {
                                latestReceiptDate = receiptDate;
                            }
                        }

                        if (latestReceiptDate !== '')
                        {
                            try
                            {
                                const syncPayload = await postJson('index.php?action=sync_received_orders', {
                                    company: company,
                                    latest_received_date: latestReceiptDate,
                                    orders_json: JSON.stringify(receivedOrders),
                                });

                                if (syncPayload && syncPayload.ok)
                                {
                                    setStatus(detailedOrders.length + ' orders geladen voor ' + company + '. ' + String(syncPayload.saved_count || receivedOrders.length) + ' ontvangen orders gesynchroniseerd.', false);
                                }
                            }
                            catch (error)
                            {
                                setStatus((error && error.message) ? error.message : 'Ontvangen orders konden niet worden opgeslagen.', true);
                            }
                        }
                    }
                } catch (error)
                {
                    setStatus((error && error.message) ? error.message : 'Laden mislukt.', true);
                    renderOrders([]);
                } finally
                {
                    hideLoader();
                }
            }

            function clearOrders ()
            {
                state.orders = [];
                orderList.innerHTML = '<div class="empty-state">Nog geen orders geladen.</div>';
                setSummary([]);
                clearOrdersButton.disabled = true;
                setStatus('De lijst is gewist. Kies opnieuw een bedrijf en laad de orders.', false);
            }

            companySelect.addEventListener('change', function ()
            {
                try
                {
                    localStorage.setItem('ratatoskr.selected_company', companySelect.value);
                } catch (error)
                {
                    void error;
                }
                clearOrders();
            });

            loadOrdersButton.addEventListener('click', function ()
            {
                void loadOrders();
            });

            clearOrdersButton.addEventListener('click', clearOrders);

            orderList.addEventListener('click', function (event)
            {
                const button = event.target.closest('.vendor-button');
                if (!button)
                {
                    return;
                }

                const vendorNo = String(button.getAttribute('data-vendor-no') || '').trim();
                const vendorName = String(button.getAttribute('data-vendor-name') || button.textContent || '').trim();
                openVendorModal(vendorNo, vendorName);
            });

            vendorModalClose.addEventListener('click', closeVendorModal);
            vendorModal.addEventListener('click', function (event)
            {
                if (event.target === vendorModal)
                {
                    closeVendorModal();
                }
            });

            document.addEventListener('keydown', function (event)
            {
                if (event.key === 'Escape' && vendorModal.classList.contains('is-visible'))
                {
                    closeVendorModal();
                }
            });

            try
            {
                const rememberedCompany = localStorage.getItem('ratatoskr.selected_company');
                if (rememberedCompany)
                {
                    const option = Array.from(companySelect.options).find(function (item)
                    {
                        return item.value === rememberedCompany;
                    });
                    if (option)
                    {
                        companySelect.value = rememberedCompany;
                    }
                }
            } catch (error)
            {
                void error;
            }

            setSummary([]);
            setStatus('Kies een bedrijf om de orderlijst te laden.', false);
        })();
    </script>
</body>

</html>