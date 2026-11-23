<?php
/**
 * POST api/rendeles.php – új rendelés fogadása a rendelési oldalról.
 * Válasz: {"rendelesszam": "...", "vegosszeg": 12000, "fizetes": "atutalas"}
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function valasz(array $adat, int $kod = 200): void
{
    http_response_code($kod);
    echo json_encode($adat, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    valasz(['hiba' => 'Csak POST kérés fogadható.'], 405);
}
$nyers = file_get_contents('php://input', false, null, 0, 50000);
$be = json_decode((string)$nyers, true);
if (!is_array($be)) {
    valasz(['hiba' => 'Hibás kérés.'], 400);
}
// csapda a robotoknak: ezt a rejtett mezőt ember nem tölti ki
if (!empty($be['weboldal'])) {
    valasz(['hiba' => 'Hibás kérés.'], 400);
}

[$r, $hibak] = rendeles_ellenoriz($be);
if ($hibak) {
    valasz(['hiba' => implode(' ', $hibak)], 422);
}

if ($r['fizetes'] === 'kartya') {
    // A Stripe-os fizetés a következő fejlesztési lépésben készül el.
    if (empty(faro_config()['stripe']['secret_key'])) {
        valasz(['hiba' => 'A bankkártyás fizetés még nem érhető el. Kérlek, válaszd a banki átutalást.'], 422);
    }
}

$allapot = $r['fizetes'] === 'atutalas' ? 'fizetesre_var' : 'uj';
$rendeles = rendeles_letrehoz($r, $allapot);

$okVevo = level_vevo_visszaigazolas($rendeles);
naplo((int)$rendeles['id'], $okVevo ? 'Visszaigazoló e-mail elküldve a vevőnek.' : 'A visszaigazoló e-mail küldése NEM sikerült.', 'rendszer');
$okAdmin = level_admin_uj_rendeles($rendeles);
if (!$okAdmin) {
    naplo((int)$rendeles['id'], 'Az értesítő e-mail (új rendelés) küldése NEM sikerült.', 'rendszer');
}

valasz([
    'rendelesszam' => $rendeles['rendelesszam'],
    'vegosszeg'    => (int)$rendeles['vegosszeg'],
    'fizetes'      => $rendeles['fizetes'],
]);
