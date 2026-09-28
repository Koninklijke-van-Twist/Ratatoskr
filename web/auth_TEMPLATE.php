<?php
/**
 * Auth-template voor Ratatoskr.
 *
 * Zet Mímir en de Business Central-credentials naast elkaar in web/auth.php
 * (niet in git). De BC-blok is de automatische fallback wanneer Mímir uitvalt,
 * zowel voor live requests als voor nightly.php / CLI:
 *   $mimirApi  = 'mimir_…';
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *
 * Met $mimirApi gezet gaan fetches eerst naar Mímir en bij een fout naar de BC-vars.
 * Zonder $mimirApi wordt alleen het BC-blok gebruikt.
 * Laat $baseUrl, $auth_list, $environment en $auth staan, ook als $mimirApi gezet is.
 */

// --- Mímir (aanbevolen) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (directe route, én fallback als Mímir faalt) ---
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
];
$environment = 'Production';
$auth = $auth_list[$environment];
$baseUrl = 'https://my-bc-domain.com:7148/';

$allowedUsers = [
    'user@domain.nl',
];
