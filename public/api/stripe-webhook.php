<?php
/**
 * POST api/stripe-webhook.php – a Stripe értesíti a szervert a fizetésről.
 * Figyelt események:
 *   checkout.session.completed (payment_status = paid)
 *   checkout.session.async_payment_succeeded
 *   checkout.session.async_payment_failed
 *   checkout.session.expired
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';
require FARO_APP . '/stripe.php';

header('Content-Type: application/json; charset=utf-8');

$GLOBALS['wh_tipus'] = '';
$GLOBALS['wh_rendeles'] = null;

function webhook_valasz(int $kod, string $uzenet): void
{
    // minden beérkező értesítést naplózunk (admin → Stripe-értesítések)
    try {
        db()->prepare('INSERT INTO ertesites_naplo (ido, forras, esemeny, rendeles_id, kod, eredmeny) VALUES (?,?,?,?,?,?)')
            ->execute([most(), 'stripe', mb_substr((string)$GLOBALS['wh_tipus'], 0, 100), $GLOBALS['wh_rendeles'], $kod, mb_substr($uzenet, 0, 500)]);
        // 90 napnál régebbi bejegyzések törlése
        db()->prepare('DELETE FROM ertesites_naplo WHERE ido < ?')->execute([date('Y-m-d H:i:s', time() - 90 * 86400)]);
    } catch (Throwable $e) {
        error_log('[FARO] webhook-napló hiba: ' . $e->getMessage());
    }
    http_response_code($kod);
    echo json_encode(['uzenet' => $uzenet], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    webhook_valasz(405, 'Csak POST.');
}
if (!stripe_beallitva()) {
    webhook_valasz(503, 'A Stripe nincs beállítva.');
}

$torzs = (string)file_get_contents('php://input');
$fejlec = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$esemeny = json_decode($torzs, true);
$tipus = (string)($esemeny['type'] ?? '');
$GLOBALS['wh_tipus'] = $tipus;
if ($fejlec === '') {
    webhook_valasz(400, 'Érvénytelen aláírás: hiányzik a Stripe-Signature fejléc.');
}
if (!stripe_alairas_ervenyes($torzs, $fejlec, (string)faro_config()['stripe']['webhook_secret'])) {
    $t = preg_match('/t=(\d+)/', $fejlec, $m) ? (int)$m[1] : 0;
    $elteres = $t ? abs(time() - $t) : null;
    webhook_valasz(400, 'Érvénytelen aláírás' . ($elteres !== null && $elteres > 300
        ? ' (az időbélyeg ' . $elteres . ' mp-cel eltér a szerver órájától).'
        : ' – valószínűleg a faro-config.php-ban lévő webhook_secret nem ehhez a végponthoz tartozik.'));
}
$s = $esemeny['data']['object'] ?? [];
if (($s['object'] ?? '') !== 'checkout.session') {
    webhook_valasz(200, isset($esemeny['data']['object'])
        ? 'Nem figyelt esemény.'
        : 'Az értesítésben nincs fizetési adat – a Stripe-ban a végpont „thin” formátumra van állítva, „snapshot” kell.');
}

$id = (int)($s['metadata']['rendeles_id'] ?? 0);
$r = $id ? rendeles_betolt($id) : null;
$GLOBALS['wh_rendeles'] = $r ? $id : null;
if (!$r || ($r['stripe_session'] ?? '') !== ($s['id'] ?? '')) {
    // ismeretlen rendelés (pl. a régi webshopból) – nyugtázzuk, hogy a Stripe ne küldje újra
    webhook_valasz(200, 'Ismeretlen rendelés (nem az új oldalról indult fizetés).');
}

switch ($tipus) {
    case 'checkout.session.completed':
    case 'checkout.session.async_payment_succeeded':
        webhook_valasz(200, stripe_fizetes_feldolgoz($r, $s, 'stripe'));

    case 'checkout.session.async_payment_failed':
        naplo($id, 'Stripe: a fizetés sikertelen.', 'stripe');
        break;

    case 'checkout.session.expired':
        if ($r['allapot'] === 'fizetesre_var') {
            naplo($id, 'Stripe: a fizetőoldal lejárt, fizetés nem történt.', 'stripe');
        }
        break;
}

webhook_valasz(200, 'Rendben.');
