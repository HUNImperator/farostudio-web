<?php
defined('FARO') or exit;

/**
 * Stripe – bankkártyás fizetés a Stripe saját fizetőoldalán (Checkout).
 * Külső könyvtár nélkül, a Stripe REST API-ját hívjuk cURL-lel.
 *
 * Beállítás (faro-config.php):
 *   'stripe' => [
 *       'secret_key'     => 'sk_test_…' vagy 'sk_live_…',
 *       'webhook_secret' => 'whsec_…',
 *   ],
 *
 * Forint: a Stripe API-ban két tizedesjegyű pénznemként kell átadni,
 * vagyis 1500 Ft = 150000.
 */

function stripe_beallitva(): bool
{
    $s = faro_config()['stripe'] ?? [];
    return !empty($s['secret_key']) && !empty($s['webhook_secret']);
}

function stripe_osszeg(int $forint): int
{
    return $forint * 100;
}

function stripe_api(string $metodus, string $ut, array $parameterek = [], string $verzio = '2024-06-20'): array
{
    $s = faro_config()['stripe'];
    $alap = rtrim($s['api_base'] ?? 'https://api.stripe.com', '/');
    $ch = curl_init($alap . $ut);
    $fejlec = ['Stripe-Version: ' . $verzio];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $s['secret_key'] . ':',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $fejlec,
    ]);
    if ($metodus === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameterek, '', '&'));
    }
    $valasz = curl_exec($ch);
    $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hiba = curl_error($ch);
    curl_close($ch);
    if ($valasz === false) {
        throw new RuntimeException('A Stripe nem érhető el: ' . $hiba);
    }
    $adat = json_decode((string)$valasz, true);
    if ($kod >= 400 || !is_array($adat)) {
        $uzenet = is_array($adat) && isset($adat['error']['message']) ? $adat['error']['message'] : ('HTTP ' . $kod);
        throw new RuntimeException('Stripe hiba: ' . $uzenet);
    }
    return $adat;
}

/** Fizetőoldal (Checkout Session) létrehozása egy rendeléshez. Visszaadja a session-t (id, url). */
function stripe_fizetooldal(array $r): array
{
    $site = rtrim(faro_config()['site_url'], '/');
    $szam = $r['rendelesszam'];
    $p = [
        'mode'                => 'payment',
        'locale'              => 'hu',
        'payment_method_types' => ['card'],
        'customer_email'      => $r['vevo_email'],
        'client_reference_id' => $szam,
        'metadata'            => ['rendeles_id' => (string)$r['id'], 'rendelesszam' => $szam],
        'payment_intent_data' => [
            'description' => 'FARO rendelés ' . $szam,
            'metadata'    => ['rendeles_id' => (string)$r['id'], 'rendelesszam' => $szam],
        ],
        'success_url' => $site . '/rendeles-koszonjuk.html?rendeles=' . rawurlencode($szam) . '&fizetes=kartya',
        'cancel_url'  => $site . '/rendeles.html?megszakitva=' . rawurlencode($szam),
        'line_items'  => [],
    ];
    foreach ($r['tetelek'] as $t) {
        $p['line_items'][] = [
            'quantity'   => (int)$t['db'],
            'price_data' => [
                'currency'     => 'huf',
                'unit_amount'  => stripe_osszeg((int)$t['egysegar']),
                'product_data' => ['name' => (($t['tipus'] ?? 'konyv') === 'konyv' ? $t['cim'] . ' – Csengeri Zsuzsa (dedikált példány)' : $t['cim'])],
            ],
        ];
    }
    if ((int)$r['szallitas'] > 0) {
        $p['line_items'][] = [
            'quantity'   => 1,
            'price_data' => [
                'currency'     => 'huf',
                'unit_amount'  => stripe_osszeg((int)$r['szallitas']),
                'product_data' => ['name' => 'Foxpost csomagautomata – ' . $r['apt_nev']],
            ],
        ];
    }
    // A Link (Stripe saját mentett-kártyás fizetése) ne jelenjen meg; Apple Pay / Google Pay a kártyával együtt marad.
    // A wallet_options a 2025-04-30.basil API-verziótól létezik; ha a Stripe mégis elutasítaná, nélküle készül a fizetőoldal.
    try {
        return stripe_api('POST', '/v1/checkout/sessions', $p + ['wallet_options' => ['link' => ['display' => 'never']]], '2025-04-30.basil');
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'A Stripe nem érhető el') === 0) throw $e;
        return stripe_api('POST', '/v1/checkout/sessions', $p);
    }
}

/**
 * Webhook-aláírás ellenőrzése (Stripe-Signature: t=…,v1=…).
 * Az aláírt tartalom: "{t}.{nyers törzs}", HMAC-SHA256 a webhook-titokkal.
 */
function stripe_alairas_ervenyes(string $torzs, string $fejlec, string $titok, int $turesMp = 300): bool
{
    $t = null;
    $alairasok = [];
    foreach (explode(',', $fejlec) as $resz) {
        $kv = explode('=', trim($resz), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't') $t = $kv[1];
        if ($kv[0] === 'v1') $alairasok[] = $kv[1];
    }
    if ($t === null || !ctype_digit($t) || !$alairasok) {
        return false;
    }
    if (abs(time() - (int)$t) > $turesMp) {
        return false;
    }
    $varhato = hash_hmac('sha256', $t . '.' . $torzs, $titok);
    foreach ($alairasok as $a) {
        if (hash_equals($varhato, $a)) {
            return true;
        }
    }
    return false;
}

/**
 * Egy Checkout Session alapján a rendelés fizetettnek jelölése (webhook és admin közös logikája).
 * Visszaad: rövid eredmény szöveg.
 */
function stripe_fizetes_feldolgoz(array $r, array $s, string $szerzo): string
{
    $id = (int)$r['id'];
    if (($s['payment_status'] ?? '') !== 'paid') {
        return 'A fizetés még nem történt meg (Stripe állapot: ' . ($s['payment_status'] ?? '?') . ', ' . ($s['status'] ?? '?') . ').';
    }
    $vart = stripe_osszeg((int)$r['vegosszeg']);
    if ((int)($s['amount_total'] ?? -1) !== $vart || strtolower((string)($s['currency'] ?? '')) !== 'huf') {
        naplo($id, 'FIGYELEM: a Stripe-on fizetett összeg (' . (int)($s['amount_total'] ?? 0) / 100 . ' ' . ($s['currency'] ?? '?') . ') eltér a rendeléstől!', $szerzo);
        return 'Eltérő összeg – a rendelés nem lett Fizetve.';
    }
    if ($r['allapot'] === 'lemondva') {
        naplo($id, 'FIGYELEM: lemondott rendelésre érkezett bankkártyás fizetés – visszatérítés szükséges a Stripe-ban!', $szerzo);
        return 'Lemondott rendelésre érkezett fizetés.';
    }
    if (!in_array($r['allapot'], ['uj', 'fizetesre_var'], true)) {
        return 'Már feldolgozva korábban.';
    }
    rendeles_allapot($id, 'fizetve', $szerzo, 'bankkártyás fizetés sikeres');
    $r = rendeles_betolt($id);
    $ok = level_vevo_visszaigazolas($r);
    naplo($id, $ok ? 'Visszaigazoló e-mail elküldve a vevőnek.' : 'A visszaigazoló e-mail küldése NEM sikerült.', 'rendszer');
    if (!level_admin_uj_rendeles($r)) {
        naplo($id, 'Az értesítő e-mail (új rendelés) küldése NEM sikerült.', 'rendszer');
    }
    $szla = szamlazz_szamla_kiallit(rendeles_betolt($id), 'rendszer');
    $video = video_fizetve(rendeles_betolt($id), 'rendszer');
    return trim('Rendben: a rendelés Fizetve, e-mailek elküldve. ' . $szla . ' ' . $video);
}

/** Részleges (vagy teljes) visszatérítés a rendelés bankkártyás fizetéséből. */
function stripe_visszaterites(array $r, int $forint): array
{
    $s = stripe_api('GET', '/v1/checkout/sessions/' . rawurlencode($r['stripe_session']));
    $pi = $s['payment_intent'] ?? '';
    if (!$pi) {
        throw new RuntimeException('A Stripe-munkamenethez nem tartozik fizetés.');
    }
    return stripe_api('POST', '/v1/refunds', [
        'payment_intent' => is_array($pi) ? $pi['id'] : $pi,
        'amount'         => stripe_osszeg($forint),
        'reason'         => 'requested_by_customer',
        'metadata'       => ['rendelesszam' => $r['rendelesszam']],
    ]);
}
