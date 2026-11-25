<?php
defined('FARO') or exit;

/**
 * Foxpost Web API (https://webapi.foxpost.hu, Basic auth + api-key fejléc).
 *   POST /api/parcel            csomag létrehozása (tömb, egy elemmel)
 *   POST /api/label/{A6|A7…}    címke PDF-ben (a csomagszámok tömbje)
 *   GET  /api/tracking/{kód}    a csomag állapotai
 *   DELETE /api/parcel/{kód}    csomag törlése (amíg nincs feladva)
 * Beállítás: faro-config.php → 'foxpost' => ['felhasznalo', 'jelszo', 'api_kulcs', 'alap_meret', 'cimke_meret']
 */

const FOXPOST_MERETEK = ['xs' => 'XS', 's' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL'];
const FOXPOST_CIMKE_MERETEK = ['A6' => 'A6 (egy címke egy lapon)', 'A7' => 'A7 (négy címke egy A4-es lapon)', 'A5' => 'A5', '_85X85' => '85×85 mm (címkenyomtató)'];
// a vevő átvette
const FOXPOST_ATVEVE = ['RECEIVE', 'HDRECEIVE', 'COLLECTED'];
// gond van: lejárt az átvételi idő, visszaküldés, kézbesíthetetlen…
const FOXPOST_GOND = ['OVERTIMEOUT', 'OVERTIMED', 'RETURN', 'BACKTOSENDER', 'RETURNED', 'HDRETURN', 'HDUNDELIVERABLE', 'MISSORT', 'BACKLOGINFULL', 'BACKLOGINFAIL', 'EMPTYSLOT'];

function foxpost_cfg(): array
{
    return faro_config()['foxpost'] ?? [];
}

function foxpost_beallitva(): bool
{
    $c = foxpost_cfg();
    return !empty($c['felhasznalo']) && !empty($c['jelszo']) && !empty($c['api_kulcs']);
}

function foxpost_kovetes_url(string $kod): string
{
    return 'https://foxpost.hu/csomagkovetes?code=' . rawurlencode($kod);
}

/** @return array{0:int,1:string,2:string,3:string} [HTTP-kód, válasz, Content-Type, cURL-hiba] */
function foxpost_hivas(string $metodus, string $ut, $test = null, string $accept = 'application/json'): array
{
    $c = foxpost_cfg();
    $ch = curl_init(rtrim($c['api_url'] ?? 'https://webapi.foxpost.hu', '/') . $ut);
    $fejlec = ['api-key: ' . ($c['api_kulcs'] ?? ''), 'Accept: ' . $accept];
    if ($test !== null) {
        $fejlec[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($test, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodus,
        CURLOPT_HTTPHEADER     => $fejlec,
        CURLOPT_USERPWD        => ($c['felhasznalo'] ?? '') . ':' . ($c['jelszo'] ?? ''),
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $valasz = curl_exec($ch);
    $hiba = $valasz === false ? curl_error($ch) : '';
    $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tipus = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$kod, (string)$valasz, $tipus, $hiba];
}

/** Érthető magyar hibaüzenet a Foxpost válaszából. */
function foxpost_hiba(int $kod, string $valasz, string $curlHiba = ''): string
{
    if ($curlHiba !== '') return 'A Foxpost nem érhető el (' . $curlHiba . ').';
    $forditas = [
        'WRONG_USERNAME_OR_PASSWORD' => 'hibás Foxpost felhasználónév vagy jelszó (faro-config.php)',
        'INVALID_APIKEY'             => 'hibás Foxpost API-kulcs (faro-config.php)',
        'INVALID_APM_ID'             => 'a választott csomagautomatát a Foxpost nem fogadta el (lehet, hogy megszűnt vagy nem fogad ilyen csomagot) – kérj a vevőtől másik automatát, és add meg a rendelésnél',
        'INVALID_PHONE'              => 'a vevő telefonszámát a Foxpost nem fogadta el',
        'INVALID_RECIPIENT_PHONE'    => 'a vevő telefonszámát a Foxpost nem fogadta el',
        'INVALID_EMAIL'              => 'a vevő e-mail-címét a Foxpost nem fogadta el',
        'INVALID_SIZE'               => 'ezt a csomagméretet a Foxpost nem fogadta el',
        'PARCEL_NOT_FOUND'           => 'a Foxpost nem találja a csomagot',
    ];
    $j = json_decode($valasz, true);
    $uzenetek = [];
    if (is_array($j)) {
        if (!empty($j['error'])) $uzenetek[] = $forditas[$j['error']] ?? (string)$j['error'];
        foreach ($j['parcels'] ?? [] as $p) {
            foreach ($p['errors'] ?? [] as $e) {
                $m = (string)($e['message'] ?? '');
                $uzenetek[] = $forditas[$m] ?? trim(($e['field'] ?? '') . ': ' . $m, ': ');
            }
        }
    }
    if (!$uzenetek) $uzenetek[] = 'HTTP ' . $kod . ($valasz !== '' ? ' – ' . mb_substr(strip_tags($valasz), 0, 200) : '');
    return 'Foxpost: ' . implode('; ', array_unique($uzenetek)) . '.';
}

/** +36… formátum (a rendelésnél magyar mobilszámot kérünk). */
function foxpost_telefon(string $t): string
{
    $d = preg_replace('/\D+/', '', $t);
    if (strpos($d, '0036') === 0) $d = substr($d, 4);
    elseif (strpos($d, '06') === 0) $d = substr($d, 2);
    elseif (strpos($d, '36') === 0 && strlen($d) === 11) $d = substr($d, 2);
    return '+36' . $d;
}

/* ---------- csomag létrehozása ---------- */
function foxpost_csomag_letrehoz(array $r, string $meret, string $ki): string
{
    if (!foxpost_beallitva()) return 'A Foxpost-kapcsolat nincs beállítva (faro-config.php → foxpost).';
    if (!rendeles_van($r, 'konyv') || $r['apt_id'] === '') return 'Ebben a rendelésben nincs Foxpost-szállítás.';
    if (!empty($r['foxpost_csomagszam'])) return 'Ehhez a rendeléshez már van csomag: ' . $r['foxpost_csomagszam'] . '.';
    if (!isset(FOXPOST_MERETEK[$meret])) $meret = foxpost_cfg()['alap_meret'] ?? 's';

    $csomag = [
        'recipientName'  => mb_substr($r['vevo_nev'], 0, 150),
        'recipientPhone' => foxpost_telefon($r['vevo_telefon']),
        'recipientEmail' => $r['vevo_email'],
        'destination'    => $r['apt_id'],
        'size'           => $meret,
        'refCode'        => mb_substr($r['rendelesszam'], 0, 30),
        'comment'        => 'Könyv',
    ];
    [$kod, $valasz, , $curlHiba] = foxpost_hivas('POST', '/api/parcel?isWeb=true&isRedirect=false', [$csomag]);
    $j = json_decode($valasz, true);
    $p = is_array($j) ? ($j['parcels'][0] ?? []) : [];
    $szam = (string)($p['clFoxId'] ?? $p['clfoxId'] ?? $p['barcode'] ?? $p['newBarcode'] ?? '');
    if ($curlHiba !== '' || $kod >= 300 || empty($j['valid']) || $szam === '' || !empty($p['errors'])) {
        $u = foxpost_hiba($kod, $valasz, $curlHiba);
        naplo((int)$r['id'], 'Foxpost-csomag létrehozása nem sikerült: ' . $u, $ki);
        return $u;
    }
    db()->prepare('UPDATE rendelesek SET foxpost_csomagszam = ?, foxpost_meret = ?, foxpost_allapot = ?, foxpost_allapot_szoveg = ?, foxpost_frissitve = ? WHERE id = ?')
        ->execute([$szam, $meret, 'CREATE', 'A csomag létrejött a Foxpost rendszerében.', most(), (int)$r['id']]);
    rendeles_allapot((int)$r['id'], 'csomag_letrehozva', $ki, 'Foxpost-csomagszám: ' . $szam . ', méret: ' . FOXPOST_MERETEK[$meret]);
    return 'A Foxpost-csomag elkészült: ' . $szam . '. Töltsd le a címkét, ragaszd a csomagra, és add fel egy Foxpost-automatában.';
}

/* ---------- címke ---------- */
/** @return array{0:bool,1:string} [siker, PDF vagy hibaüzenet] */
function foxpost_cimke(array $csomagszamok, string $meret): array
{
    if (!foxpost_beallitva()) return [false, 'A Foxpost-kapcsolat nincs beállítva.'];
    if (!isset(FOXPOST_CIMKE_MERETEK[$meret])) $meret = 'A6';
    [$kod, $valasz, $tipus, $curlHiba] = foxpost_hivas('POST', '/api/label/' . $meret, array_values($csomagszamok), 'application/pdf');
    if ($curlHiba === '' && $kod === 200 && strpos($valasz, '%PDF') === 0) return [true, $valasz];
    return [false, foxpost_hiba($kod, $valasz, $curlHiba)];
}

/* ---------- csomag törlése (amíg nincs feladva) ---------- */
function foxpost_csomag_torol(array $r, string $ki): string
{
    $szam = (string)$r['foxpost_csomagszam'];
    if ($szam === '') return 'Nincs Foxpost-csomag.';
    if ($r['allapot'] !== 'csomag_letrehozva') return 'A csomagot már feladtad, ezért nem törölhető.';
    [$kod, $valasz, , $curlHiba] = foxpost_hivas('DELETE', '/api/parcel/' . rawurlencode($szam) . '?isWeb=true');
    if ($curlHiba !== '' || $kod >= 300) {
        return foxpost_hiba($kod, $valasz, $curlHiba);
    }
    db()->prepare('UPDATE rendelesek SET foxpost_csomagszam = NULL, foxpost_meret = NULL, foxpost_allapot = NULL, foxpost_allapot_szoveg = NULL, foxpost_frissitve = ? WHERE id = ?')
        ->execute([most(), (int)$r['id']]);
    rendeles_allapot((int)$r['id'], 'fizetve', $ki, 'a Foxpost-csomag (' . $szam . ') törölve');
    return 'A Foxpost-csomag (' . $szam . ') törölve. Szükség esetén újra létrehozhatod.';
}

/* ---------- követés ---------- */
/** A csomag állapotlistája (a legfrissebb elöl), vagy hibaüzenet. */
function foxpost_kovetes(string $szam): array
{
    [$kod, $valasz, , $curlHiba] = foxpost_hivas('GET', '/api/tracking/' . rawurlencode($szam));
    $j = json_decode($valasz, true);
    if ($curlHiba !== '' || $kod !== 200 || !is_array($j)) {
        return ['hiba' => foxpost_hiba($kod, $valasz, $curlHiba)];
    }
    $nyomok = array_values(array_filter($j['traces'] ?? [], 'is_array'));
    usort($nyomok, function ($a, $b) { return strcmp((string)($b['statusDate'] ?? ''), (string)($a['statusDate'] ?? '')); });
    return ['nyomok' => $nyomok, 'becsult' => (string)($j['estimatedDelivery'] ?? '')];
}

/**
 * A csomag állapotának lekérése és a rendelés léptetése:
 * átadva a Foxpostnak → Feladva (+ levél a vevőnek); átvette a vevő → Teljesítve; gond → napló + levél neked.
 */
function foxpost_frissit(array $r, string $ki): string
{
    $szam = (string)$r['foxpost_csomagszam'];
    if ($szam === '' || !foxpost_beallitva()) return '';
    $k = foxpost_kovetes($szam);
    if (isset($k['hiba'])) return $k['hiba'];
    $nyomok = $k['nyomok'];
    if (!$nyomok) return 'A Foxpost még nem küldött állapotot.';
    $utolso = $nyomok[0];
    $allapot = (string)($utolso['status'] ?? '');
    $szoveg = trim((string)($utolso['longName'] ?? $utolso['shortName'] ?? $allapot));
    $regi = (string)$r['foxpost_allapot'];
    db()->prepare('UPDATE rendelesek SET foxpost_allapot = ?, foxpost_allapot_szoveg = ?, foxpost_frissitve = ? WHERE id = ?')
        ->execute([$allapot, mb_substr($szoveg, 0, 255), most(), (int)$r['id']]);
    if ($allapot !== $regi) {
        naplo((int)$r['id'], 'Foxpost: ' . $szoveg . ' (' . $allapot . ')', 'Foxpost');
    }
    $voltAtadva = (bool)array_filter($nyomok, function ($n) { return ($n['status'] ?? 'CREATE') !== 'CREATE'; });
    if ($voltAtadva && $r['allapot'] === 'csomag_letrehozva') {
        rendeles_allapot((int)$r['id'], 'feladva', 'Foxpost', 'a csomag a Foxpostnál');
        level_feladva(rendeles_betolt((int)$r['id']));
    }
    if (in_array($allapot, FOXPOST_ATVEVE, true) && in_array(rendeles_betolt((int)$r['id'])['allapot'], ['csomag_letrehozva', 'feladva'], true)) {
        rendeles_allapot((int)$r['id'], 'teljesitve', 'Foxpost', 'a vevő átvette a csomagot');
    }
    if (in_array($allapot, FOXPOST_GOND, true) && $allapot !== $regi) {
        $cfg = faro_config();
        $link = rtrim($cfg['site_url'], '/') . '/admin/?p=rendeles&id=' . (int)$r['id'];
        level_kuld($cfg['admin_email'], 'Csengeri Zsuzsa', 'Foxpost: figyelmet igényel – ' . $r['rendelesszam'],
            level_keret('Foxpost-csomag: figyelmet igényel', '<p>A(z) <strong>' . h($r['rendelesszam']) . '</strong> rendelés csomagja (' . h($szam) . '):</p><p><strong>' . h($szoveg) . '</strong></p><p>Vevő: ' . h($r['vevo_nev']) . ', ' . h($r['vevo_telefon']) . ', ' . h($r['vevo_email']) . '</p>' . level_gomb($link, 'A rendelés megnyitása')),
            "A(z) {$r['rendelesszam']} rendelés csomagja ($szam): $szoveg\nVevő: {$r['vevo_nev']}, {$r['vevo_telefon']}, {$r['vevo_email']}\n$link\n");
    }
    return 'Foxpost-állapot: ' . rtrim($szoveg, '. ') . '.';
}

/** Napi frissítés: a még úton lévő csomagok. */
function foxpost_napi(): array
{
    if (!foxpost_beallitva()) return [];
    $ki = [];
    $st = db()->query("SELECT id FROM rendelesek WHERE foxpost_csomagszam IS NOT NULL AND foxpost_csomagszam <> '' AND allapot IN ('csomag_letrehozva', 'feladva')");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $r = rendeles_betolt((int)$id);
        $u = foxpost_frissit($r, 'Foxpost');
        $uj = rendeles_betolt((int)$id);
        // csak a változást (vagy a hibát) jelentjük
        if ($uj['foxpost_allapot'] !== $r['foxpost_allapot'] || $uj['allapot'] !== $r['allapot'] || strpos($u, 'Foxpost:') === 0) {
            $ki[] = $r['rendelesszam'] . ': ' . $u;
        }
        usleep(300000);
    }
    return $ki;
}

/* ---------- levél a vevőnek: úton a csomag ---------- */
function level_feladva(array $r): bool
{
    $szam = (string)$r['foxpost_csomagszam'];
    $kov = $szam !== '' ? foxpost_kovetes_url($szam) : '';
    $html = '<p>Kedves ' . h($r['vevo_nev']) . '!</p>'
        . '<p>A(z) <strong>' . h($r['rendelesszam']) . '</strong> rendelésed csomagját feladtam, a Foxpost hamarosan a választott automatába szállítja:</p>'
        . '<p style="margin:0 0 18px"><strong>' . h($r['apt_nev']) . '</strong><br>' . h($r['apt_cim']) . '</p>'
        . ($szam !== '' ? '<p>Csomagszám: <strong>' . h($szam) . '</strong></p>' . level_gomb($kov, 'A csomag követése') : '')
        . '<p>Amikor a csomag megérkezik, a Foxpost SMS-ben és e-mailben küldi az átvételhez szükséges kódot. A csomagot az értesítésben megadott határidőn belül veheted át.</p>'
        . '<p>Jó olvasást kívánok!</p><p>Szeretettel:<br>Csengeri Zsuzsa</p>' . level_jogi_lab();
    $szoveg = "Kedves {$r['vevo_nev']}!\n\nA(z) {$r['rendelesszam']} rendelésed csomagját feladtam, a Foxpost hamarosan a választott automatába szállítja:\n{$r['apt_nev']}, {$r['apt_cim']}\n\n"
        . ($szam !== '' ? "Csomagszám: $szam\nKövetés: $kov\n\n" : '')
        . "Amikor a csomag megérkezik, a Foxpost SMS-ben és e-mailben küldi az átvételhez szükséges kódot.\n\nJó olvasást kívánok!\n\nSzeretettel:\nCsengeri Zsuzsa\n\n" . level_jogi_lab(false);
    $ok = level_kuld($r['vevo_email'], $r['vevo_nev'], 'Úton a csomagod – ' . $r['rendelesszam'], level_keret('Úton a csomagod', $html), $szoveg, faro_config()['admin_email']);
    naplo((int)$r['id'], $ok ? 'Levél a vevőnek: a csomag úton van.' : 'A „csomag úton” levelet nem sikerült elküldeni.', 'rendszer');
    return $ok;
}
