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

require FARO_APP . '/stripe.php';

if ($r['fizetes'] === 'kartya' && !stripe_beallitva()) {
    valasz(['hiba' => 'A bankkártyás fizetés még nem érhető el. Kérlek, válaszd a banki átutalást.'], 422);
}

$rendeles = rendeles_letrehoz($r, 'fizetesre_var');

/* ---------- bankkártya: átirányítás a Stripe fizetőoldalára ----------
 * A visszaigazoló e-mail csak a sikeres fizetés után megy ki (stripe-webhook.php). */
if ($rendeles['fizetes'] === 'kartya') {
    try {
        $session = stripe_fizetooldal($rendeles);
    } catch (Throwable $e) {
        error_log('[FARO] ' . $e->getMessage());
        naplo((int)$rendeles['id'], 'A Stripe fizetőoldal létrehozása NEM sikerült: ' . $e->getMessage(), 'rendszer');
        valasz(['hiba' => 'A bankkártyás fizetés most nem indítható el. Kérlek, próbáld újra pár perc múlva, vagy válaszd a banki átutalást.'], 502);
    }
    db()->prepare('UPDATE rendelesek SET stripe_session = ? WHERE id = ?')->execute([$session['id'], $rendeles['id']]);
    naplo((int)$rendeles['id'], 'Átirányítás a Stripe fizetőoldalára.', 'rendszer');
    valasz([
        'rendelesszam' => $rendeles['rendelesszam'],
        'vegosszeg'    => (int)$rendeles['vegosszeg'],
        'fizetes'      => 'kartya',
        'atiranyitas'  => $session['url'],
    ]);
}

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
