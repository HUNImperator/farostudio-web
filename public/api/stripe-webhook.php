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

function webhook_valasz(int $kod, string $uzenet): void
{
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
if (!stripe_alairas_ervenyes($torzs, $fejlec, (string)faro_config()['stripe']['webhook_secret'])) {
    webhook_valasz(400, 'Érvénytelen aláírás.');
}

$esemeny = json_decode($torzs, true);
$tipus = (string)($esemeny['type'] ?? '');
$s = $esemeny['data']['object'] ?? [];
if (($s['object'] ?? '') !== 'checkout.session') {
    webhook_valasz(200, 'Nem figyelt esemény.');
}

$id = (int)($s['metadata']['rendeles_id'] ?? 0);
$r = $id ? rendeles_betolt($id) : null;
if (!$r || ($r['stripe_session'] ?? '') !== ($s['id'] ?? '')) {
    // ismeretlen rendelés (pl. a régi webshopból) – nyugtázzuk, hogy a Stripe ne küldje újra
    webhook_valasz(200, 'Ismeretlen rendelés.');
}

switch ($tipus) {
    case 'checkout.session.completed':
    case 'checkout.session.async_payment_succeeded':
        if (($s['payment_status'] ?? '') !== 'paid') {
            naplo($id, 'Stripe: a fizetés folyamatban (még nincs jóváírva).', 'stripe');
            break;
        }
        $vart = stripe_osszeg((int)$r['vegosszeg']);
        if ((int)($s['amount_total'] ?? -1) !== $vart || strtolower((string)($s['currency'] ?? '')) !== 'huf') {
            naplo($id, 'FIGYELEM: a Stripe-on fizetett összeg (' . (int)($s['amount_total'] ?? 0) / 100 . ' ' . ($s['currency'] ?? '?') . ') eltér a rendeléstől!', 'stripe');
            break;
        }
        if ($r['allapot'] === 'lemondva') {
            naplo($id, 'FIGYELEM: lemondott rendelésre érkezett bankkártyás fizetés – visszatérítés szükséges a Stripe-ban!', 'stripe');
            break;
        }
        if (!in_array($r['allapot'], ['uj', 'fizetesre_var'], true)) {
            break; // már feldolgoztuk (a Stripe többször is küldheti)
        }
        rendeles_allapot($id, 'fizetve', 'stripe', 'bankkártyás fizetés sikeres');
        $r = rendeles_betolt($id);
        $ok = level_vevo_visszaigazolas($r);
        naplo($id, $ok ? 'Visszaigazoló e-mail elküldve a vevőnek.' : 'A visszaigazoló e-mail küldése NEM sikerült.', 'rendszer');
        if (!level_admin_uj_rendeles($r)) {
            naplo($id, 'Az értesítő e-mail (új rendelés) küldése NEM sikerült.', 'rendszer');
        }
        break;

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
