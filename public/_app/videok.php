<?php
defined('FARO') or exit;

/**
 * One Brain® videósorozatok: feltöltött igazolások, hozzáférések,
 * jóváhagyás / elutasítás, hosszabbítás, videós felhasználók és levelek.
 *
 * Hozzáférés állapotai (video_hozzaferesek.allapot):
 *   fuggoben   – megrendelve, jóváhagyásra vár (jóváhagyni a fizetés után lehet)
 *   aktiv      – jóváhagyva, a lejáratig nézhető (lejárat után „lejart”-nak számít)
 *   lejart     – lejárt (a napi feladat állítja át; meghosszabbítható)
 *   elutasitva – az igazolás nem fogadható el
 */

const VIDEO_ALLAPOTOK = [
    'fuggoben'   => 'Jóváhagyásra vár',
    'aktiv'      => 'Aktív',
    'lejart'     => 'Lejárt',
    'elutasitva' => 'Elutasítva',
];

/* =========================================================
   Feltöltött igazolások (útlevél / oklevél)
   ========================================================= */
const FELTOLTES_TIPUSOK = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];
const FELTOLTES_MAX_DB = 3;
const FELTOLTES_MAX_OSSZES = 10 * 1024 * 1024;   // 10 MB összesen

/** A feltöltések mappája – a webgyökéren kívül (alapból: /home/<felhasználó>/faro-feltoltesek). */
function feltoltes_mappa(): string
{
    $m = faro_config()['feltoltes_mappa'] ?? (dirname(FARO_PUBLIC) . '/faro-feltoltesek');
    if (!is_dir($m)) {
        @mkdir($m, 0750, true);
        @file_put_contents($m . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $m;
}

function feltoltes_utvonal(array $f): string
{
    return feltoltes_mappa() . '/' . basename($f['fajl']);
}

/**
 * A $_FILES['igazolas'] ellenőrzése. Visszaad: [fájlok listája, hibák].
 * Fájl: ['tmp' => …, 'nev' => …, 'mime' => …, 'meret' => …]
 */
function feltoltes_ellenoriz(?array $mezo): array
{
    $hibak = [];
    $fajlok = [];
    if (!$mezo || !isset($mezo['name'])) {
        return [[], ['Töltsd fel az útleveled vagy az okleveled fotóját.']];
    }
    $nevek = (array)$mezo['name'];
    $osszes = 0;
    foreach ($nevek as $i => $nev) {
        $hiba = (int)((array)$mezo['error'])[$i];
        if ($hiba === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($hiba === UPLOAD_ERR_INI_SIZE || $hiba === UPLOAD_ERR_FORM_SIZE) {
            $hibak[] = 'A feltöltött fájl túl nagy (legfeljebb 10 MB).';
            continue;
        }
        if ($hiba !== UPLOAD_ERR_OK) {
            $hibak[] = 'A fájl feltöltése nem sikerült, kérlek, próbáld újra.';
            continue;
        }
        $tmp = ((array)$mezo['tmp_name'])[$i];
        if (!is_uploaded_file($tmp) && !defined('FARO_TESZT')) {
            $hibak[] = 'Hibás feltöltés.';
            continue;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!isset(FELTOLTES_TIPUSOK[$mime])) {
            $hibak[] = 'Csak JPG, PNG, WEBP képet vagy PDF-et tölthetsz fel (' . mb_substr((string)$nev, 0, 60) . ').';
            continue;
        }
        $meret = (int)filesize($tmp);
        $osszes += $meret;
        $fajlok[] = ['tmp' => $tmp, 'nev' => mb_substr(basename((string)$nev), 0, 150), 'mime' => $mime, 'meret' => $meret];
    }
    if (!$fajlok && !$hibak) {
        $hibak[] = 'Töltsd fel az útleveled vagy az okleveled fotóját.';
    }
    if (count($fajlok) > FELTOLTES_MAX_DB) {
        $hibak[] = 'Legfeljebb ' . FELTOLTES_MAX_DB . ' fájlt tölthetsz fel.';
    }
    if ($osszes > FELTOLTES_MAX_OSSZES) {
        $hibak[] = 'A feltöltött fájlok összesen legfeljebb 10 MB-osak lehetnek.';
    }
    return [$fajlok, $hibak];
}

function feltoltes_ment(int $rendelesId, array $fajlok): void
{
    $mappa = feltoltes_mappa();
    foreach ($fajlok as $f) {
        $nev = $rendelesId . '-' . bin2hex(random_bytes(12)) . '.' . FELTOLTES_TIPUSOK[$f['mime']];
        $cel = $mappa . '/' . $nev;
        $ok = defined('FARO_TESZT') ? copy($f['tmp'], $cel) : move_uploaded_file($f['tmp'], $cel);
        if (!$ok) {
            throw new RuntimeException('A feltöltött fájl nem menthető.');
        }
        @chmod($cel, 0640);
        db()->prepare('INSERT INTO rendeles_feltoltesek (rendeles_id, eredeti_nev, fajl, mime, meret, letrehozva) VALUES (?,?,?,?,?,?)')
            ->execute([$rendelesId, $f['nev'], $nev, $f['mime'], $f['meret'], most()]);
    }
}

function feltoltesek(int $rendelesId): array
{
    $st = db()->prepare('SELECT * FROM rendeles_feltoltesek WHERE rendeles_id = ? ORDER BY id');
    $st->execute([$rendelesId]);
    return $st->fetchAll();
}

/** Csatolmány-lista a levélhez. */
function feltoltes_csatolmanyok(int $rendelesId): array
{
    $c = [];
    foreach (feltoltesek($rendelesId) as $f) {
        $ut = feltoltes_utvonal($f);
        if (is_file($ut)) {
            $c[] = [$ut, $f['eredeti_nev']];
        }
    }
    return $c;
}

/* =========================================================
   Hozzáférések
   ========================================================= */
function video_nap(): int
{
    return katalogus()['video_nap'];
}

/** A tényleges állapot (az „aktiv”, de már lejárt hozzáférés „lejart”). */
function video_allapot(array $h): string
{
    if ($h['allapot'] === 'aktiv' && $h['lejarat'] !== null && strtotime($h['lejarat']) <= time()) {
        return 'lejart';
    }
    return $h['allapot'];
}

function video_hatra_nap(array $h): int
{
    if (!$h['lejarat']) return 0;
    return max(0, (int)ceil((strtotime($h['lejarat']) - time()) / 86400));
}

function video_hozzaferes(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM video_hozzaferesek WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Az e-mail-címhez tartozó legutóbbi, nem elutasított hozzáférés az adott sorozathoz. */
function video_hozzaferes_email_sorozat(string $email, string $sorozat): ?array
{
    $st = db()->prepare("SELECT * FROM video_hozzaferesek WHERE email = ? AND sorozat = ? AND allapot <> 'elutasitva' ORDER BY id DESC LIMIT 1");
    $st->execute([mb_strtolower($email), $sorozat]);
    return $st->fetch() ?: null;
}

function video_hozzaferesek_email(string $email): array
{
    $st = db()->prepare('SELECT * FROM video_hozzaferesek WHERE email = ? ORDER BY sorozat, id DESC');
    $st->execute([mb_strtolower($email)]);
    return $st->fetchAll();
}

/** Sorozatonként a legfrissebb, nem elutasított hozzáférés (a videós oldalhoz). */
function video_sajat_hozzaferesek(string $email): array
{
    $o = [];
    foreach (video_hozzaferesek_email($email) as $h) {
        if ($h['allapot'] === 'elutasitva' || isset($o[$h['sorozat']])) continue;
        $o[$h['sorozat']] = $h;
    }
    return $o;
}

function video_rendeles_hozzaferesek(int $rendelesId): array
{
    $st = db()->prepare('SELECT * FROM video_hozzaferesek WHERE rendeles_id = ? ORDER BY id');
    $st->execute([$rendelesId]);
    return $st->fetchAll();
}

/** „1,2,3” → „I., II., III. modul” */
function video_modulok_szoveg(string $lista): string
{
    $r = ['', 'I.', 'II.', 'III.', 'IV.', 'V.', 'VI.'];
    $o = [];
    foreach (array_filter(explode(',', $lista)) as $m) {
        $o[] = $r[(int)$m] ?? $m;
    }
    return $o ? implode(', ', $o) . ' modul' : '–';
}

const HONAPOK = ['', 'január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];

/** „2027. február 22.” */
function video_datum(?string $d): string
{
    if (!$d) return '–';
    $t = strtotime($d);
    return date('Y', $t) . '. ' . HONAPOK[(int)date('n', $t)] . ' ' . (int)date('j', $t) . '.';
}

/** „2027. február 22-ig” */
function video_datum_ig(?string $d): string
{
    return rtrim(video_datum($d), '.') . '-ig';
}

/* =========================================================
   Videós felhasználók
   ========================================================= */
function video_felhasznalo_email(string $email): ?array
{
    $st = db()->prepare('SELECT * FROM video_felhasznalok WHERE email = ?');
    $st->execute([mb_strtolower($email)]);
    return $st->fetch() ?: null;
}

function video_felhasznalo(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM video_felhasznalok WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Könnyen begépelhető jelszó: 3×4 karakter, kötőjellel (pl. „k7Qm-x4Rt-p9Zh”). */
function video_jelszo_general(): string
{
    $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $r = '';
    for ($i = 0; $i < 12; $i++) {
        if ($i && $i % 4 === 0) $r .= '-';
        $r .= $abc[random_int(0, strlen($abc) - 1)];
    }
    return $r;
}

/** Felhasználó létrehozása; visszaad: [felhasználó, új jelszó vagy null, ha már létezett]. */
function video_felhasznalo_biztosit(string $email, string $nev): array
{
    $f = video_felhasznalo_email($email);
    if ($f) {
        return [$f, null];
    }
    $jelszo = video_jelszo_general();
    db()->prepare('INSERT INTO video_felhasznalok (email, nev, jelszo_hash, letrehozva) VALUES (?,?,?,?)')
        ->execute([mb_strtolower($email), $nev, password_hash($jelszo, PASSWORD_DEFAULT), most()]);
    return [video_felhasznalo_email($email), $jelszo];
}

function video_uj_jelszo(int $felhasznaloId): string
{
    $jelszo = video_jelszo_general();
    db()->prepare('UPDATE video_felhasznalok SET jelszo_hash = ?, munkamenet = NULL, jelszo_token = NULL WHERE id = ?')
        ->execute([password_hash($jelszo, PASSWORD_DEFAULT), $felhasznaloId]);
    return $jelszo;
}

function video_url(string $ut = ''): string
{
    return rtrim(faro_config()['site_url'], '/') . '/videok/' . $ut;
}

/* =========================================================
   Fizetés után: hosszabbítások alkalmazása
   ========================================================= */
/**
 * A rendelés „Fizetve” lett: a hosszabbítások azonnal érvényesek,
 * a videósorozatok jóváhagyásra várnak. Visszaad: rövid összefoglaló.
 */
function video_fizetve(array $r, string $szerzo): string
{
    $id = (int)$r['id'];
    $uzenet = [];
    foreach ($r['tetelek'] as $t) {
        if (($t['tipus'] ?? 'konyv') !== 'hosszabbitas') continue;
        $termek = termek($t['termek_id']);
        $sorozat = $termek ? $termek['sorozat'] : preg_replace('/-hosszabbitas$/', '', $t['termek_id']);
        $jel = 'Hosszabbítás alkalmazva: ' . $sorozat;
        $st = db()->prepare('SELECT COUNT(*) FROM rendeles_naplo WHERE rendeles_id = ? AND esemeny LIKE ?');
        $st->execute([$id, $jel . '%']);
        if ((int)$st->fetchColumn() > 0) continue;   // már megtörtént
        $h = video_hozzaferes_email_sorozat($r['vevo_email'], $sorozat);
        if (!$h || !in_array(video_allapot($h), ['aktiv', 'lejart'], true)) {
            naplo($id, 'A hosszabbítás NEM alkalmazható (' . $sorozat . '): nincs meglévő hozzáférés ehhez az e-mail-címhez. Kézzel kell rendezni.', $szerzo);
            $uzenet[] = 'A hosszabbítás nem alkalmazható – nézd meg a naplót.';
            continue;
        }
        $alap = max(time(), $h['lejarat'] ? strtotime($h['lejarat']) : time());
        $uj = date('Y-m-d H:i:s', $alap + video_nap() * 86400);
        db()->prepare("UPDATE video_hozzaferesek SET allapot = 'aktiv', lejarat = ?, figyelmeztetve = 0 WHERE id = ?")->execute([$uj, $h['id']]);
        naplo($id, $jel . ' – új lejárat: ' . video_datum($uj), $szerzo);
        level_video_hosszabbitva($r, $termek ? termek($sorozat) : ['rovidCim' => $sorozat], $uj);
        $uzenet[] = 'Hosszabbítás: ' . (termek($sorozat)['rovidCim'] ?? $sorozat) . ' → ' . video_datum($uj);
    }
    if (rendeles_van($r, 'video')) {
        $uzenet[] = 'A videósorozat(ok) jóváhagyásra várnak (Felhasználók).';
    }
    rendeles_teljesites_ellenoriz($id, $szerzo);
    return implode(' ', $uzenet);
}

/** Ha a rendelésben nincs könyv, és minden videós tétel rendezett, a rendelés „Teljesítve”. */
function rendeles_teljesites_ellenoriz(int $id, string $szerzo): void
{
    $r = rendeles_betolt($id);
    if (!$r || empty($r['fizetve_ekkor']) || rendeles_van($r, 'konyv') || in_array($r['allapot'], ['teljesitve', 'lemondva'], true)) {
        return;
    }
    $fuggo = 0;
    $jovahagyott = 0;
    foreach (video_rendeles_hozzaferesek($id) as $h) {
        if ($h['allapot'] === 'fuggoben') $fuggo++;
        if ($h['allapot'] !== 'elutasitva') $jovahagyott++;
    }
    if ($fuggo > 0) {
        return;
    }
    if ($jovahagyott === 0 && !rendeles_van($r, 'hosszabbitas')) {
        rendeles_allapot($id, 'lemondva', $szerzo, 'minden videósorozat elutasítva');
        return;
    }
    rendeles_allapot($id, 'teljesitve', $szerzo, 'videós hozzáférés rendezve');
}

/* =========================================================
   Jóváhagyás / elutasítás (admin)
   ========================================================= */
function video_jovahagy(int $hid, string $szerzo): string
{
    $h = video_hozzaferes($hid);
    if (!$h || $h['allapot'] !== 'fuggoben') {
        return 'Ez a hozzáférés nem vár jóváhagyásra.';
    }
    $r = rendeles_betolt((int)$h['rendeles_id']);
    if (!$r || empty($r['fizetve_ekkor'])) {
        return 'Jóváhagyni csak fizetett rendelést lehet – előbb jelöld a rendelést Fizetve állapotúra.';
    }
    if ($r['allapot'] === 'lemondva') {
        return 'A rendelés le van mondva.';
    }
    [$f, $jelszo] = video_felhasznalo_biztosit($h['email'], $r['vevo_nev']);
    $kezd = most();
    $lejar = date('Y-m-d H:i:s', time() + video_nap() * 86400);
    db()->prepare("UPDATE video_hozzaferesek SET allapot = 'aktiv', felhasznalo_id = ?, kezdete = ?, lejarat = ?, dontes_ekkor = ?, figyelmeztetve = 0 WHERE id = ?")
        ->execute([$f['id'], $kezd, $lejar, $kezd, $hid]);
    $sorozat = termek($h['sorozat']);
    $ok = level_video_jovahagyva($r, $sorozat, $lejar, $jelszo);
    naplo((int)$r['id'], 'Videós hozzáférés jóváhagyva: ' . ($sorozat['rovidCim'] ?? $h['sorozat']) . ', lejárat: ' . video_datum($lejar)
        . ($jelszo ? ' Új belépés létrehozva.' : '') . ($ok ? ' Levél elküldve.' : ' A levél küldése NEM sikerült!'), $szerzo);
    rendeles_teljesites_ellenoriz((int)$r['id'], $szerzo);
    return 'Jóváhagyva: ' . ($sorozat['rovidCim'] ?? $h['sorozat']) . ' (' . $h['email'] . '), lejárat: ' . video_datum($lejar)
        . ($ok ? ' A belépési adatokat e-mailben elküldtem.' : ' FIGYELEM: a levél nem ment el – küldj új jelszót a Felhasználóknál.');
}

function video_elutasit(int $hid, string $indok, string $szerzo): string
{
    $h = video_hozzaferes($hid);
    if (!$h || $h['allapot'] !== 'fuggoben') {
        return 'Ez a hozzáférés nem vár jóváhagyásra.';
    }
    $r = rendeles_betolt((int)$h['rendeles_id']);
    $sorozat = termek($h['sorozat']);
    $most = most();
    db()->prepare("UPDATE video_hozzaferesek SET allapot = 'elutasitva', dontes_ekkor = ?, dontes_megj = ? WHERE id = ?")
        ->execute([$most, mb_substr($indok, 0, 500), $hid]);
    $osszeg = (int)($sorozat['ar'] ?? 0);
    foreach ($r['tetelek'] as $t) {
        if ($t['termek_id'] === $h['sorozat']) $osszeg = (int)$t['egysegar'];
    }
    $kiir = ['Elutasítva: ' . ($sorozat['rovidCim'] ?? $h['sorozat']) . '.'];

    // visszatérítés
    $visszaSzoveg = '';
    if (!empty($r['fizetve_ekkor'])) {
        if ($r['fizetes'] === 'kartya' && !empty($r['stripe_session'])) {
            require_once FARO_APP . '/stripe.php';
            try {
                $vt = stripe_visszaterites($r, $osszeg);
                naplo((int)$r['id'], 'Stripe-visszatérítés: ' . ft($osszeg) . ' (' . $vt['id'] . ', ' . $vt['status'] . ').', $szerzo);
                $kiir[] = 'A Stripe visszatérítette a ' . ft($osszeg) . '-ot a vevő kártyájára.';
                $visszaSzoveg = 'A ' . ft($osszeg) . ' összeget visszatérítettem a bankkártyádra; a jóváírás a bankodtól függően néhány nap.';
            } catch (Throwable $e) {
                naplo((int)$r['id'], 'A Stripe-visszatérítés NEM sikerült (' . ft($osszeg) . '): ' . $e->getMessage(), $szerzo);
                $kiir[] = 'FIGYELEM: a visszatérítés nem sikerült (' . $e->getMessage() . ') – a Stripe felületén kell visszatéríteni.';
                $visszaSzoveg = 'A ' . ft($osszeg) . ' összeget hamarosan visszatérítem.';
            }
        } else {
            naplo((int)$r['id'], 'Átutalással fizetett rendelés: a ' . ft($osszeg) . ' összeget kézzel kell visszautalni.', $szerzo);
            $kiir[] = 'Átutalással fizetett: a ' . ft($osszeg) . '-ot kézzel kell visszautalnod (kérd el a vevő bankszámlaszámát).';
            $visszaSzoveg = 'A ' . ft($osszeg) . ' összeget visszautalom – kérlek, válaszlevélben írd meg, melyik bankszámlára.';
        }
    }

    // számla: ha a rendelésben csak elutasított videó van, sztornó; különben kézi helyesbítés
    if (!empty($r['szamlaszam']) && empty($r['sztorno_szamlaszam'])) {
        $maradt = false;
        foreach ($r['tetelek'] as $t) {
            if (($t['tipus'] ?? 'konyv') !== 'video') { $maradt = true; break; }
        }
        foreach (video_rendeles_hozzaferesek((int)$r['id']) as $x) {
            if ($x['allapot'] !== 'elutasitva') $maradt = true;
        }
        if (!$maradt) {
            $kiir[] = szamlazz_sztorno($r, $szerzo);
        } else {
            naplo((int)$r['id'], 'A számlán más tétel is szerepel: a(z) ' . ($sorozat['rovidCim'] ?? '') . ' tételt a Számlázz.hu-ban kell helyesbíteni (sztornó + új számla a megmaradó tételekre).', $szerzo);
            $kiir[] = 'A számlán más tétel is van: a Számlázz.hu-ban helyesbítsd (sztornó + új számla a többi tételre).';
        }
    }
    $ok = level_video_elutasitva($r, $sorozat, $indok, $visszaSzoveg);
    naplo((int)$r['id'], 'Videós hozzáférés elutasítva: ' . ($sorozat['rovidCim'] ?? $h['sorozat']) . ($indok !== '' ? ' (indok: ' . $indok . ')' : '') . ($ok ? '. Levél elküldve.' : '. A levél küldése NEM sikerült!'), $szerzo);
    rendeles_teljesites_ellenoriz((int)$r['id'], $szerzo);
    return implode(' ', $kiir);
}

/** Kézi hosszabbítás az adminból (pl. ajándék, reklamáció). */
function video_kezi_hosszabbitas(int $hid, int $nap, string $szerzo): string
{
    $h = video_hozzaferes($hid);
    if (!$h || !in_array(video_allapot($h), ['aktiv', 'lejart'], true) || $nap < 1 || $nap > 365) {
        return 'Ez a hozzáférés nem hosszabbítható.';
    }
    $alap = max(time(), $h['lejarat'] ? strtotime($h['lejarat']) : time());
    $uj = date('Y-m-d H:i:s', $alap + $nap * 86400);
    db()->prepare("UPDATE video_hozzaferesek SET allapot = 'aktiv', lejarat = ?, figyelmeztetve = 0 WHERE id = ?")->execute([$uj, $hid]);
    naplo((int)$h['rendeles_id'], 'Kézi hosszabbítás: ' . $h['sorozat'] . ' +' . $nap . ' nap → ' . video_datum($uj), $szerzo);
    return 'Meghosszabbítva: új lejárat ' . video_datum($uj);
}

/* =========================================================
   Levelek
   ========================================================= */
function level_gomb(string $url, string $szoveg): string
{
    return '<p><a href="' . h($url) . '" style="display:inline-block;background:#111;color:#fff;padding:12px 20px;text-decoration:none">' . h($szoveg) . '</a></p>';
}

/* ---------- a digitális tartalom teljesítésének megerősítése (45/2014. Korm. r. 29. § (1) m) ---------- */
function video_teljesites_megerosites(): array
{
    $t = 'A hozzáférés megnyitásával a szerződés teljesítése megkezdődött. Ahogy a rendeléskor kifejezetten kérted és tudomásul vetted, ezzel a 14 napos elállási jogod megszűnt.';
    return ['<p style="font-size:13px;color:#6e6a66">' . h($t) . '</p>', $t . "\n\n"];
}

function level_video_jovahagyva(array $r, ?array $sorozat, string $lejarat, ?string $jelszo): bool
{
    $nev = $sorozat['rovidCim'] ?? 'videósorozat';
    $url = video_url();
    $html = '<p>Kedves ' . h($r['vevo_nev']) . '!</p>'
        . '<p>Ellenőriztem az igazolásodat, és megnyitottam számodra a(z) <strong>' . h($nev) . ' – ' . h($sorozat['alcim'] ?? '') . '</strong> videósorozatot. '
        . 'A videókat <strong>' . h(video_datum_ig($lejarat)) . '</strong> nézheted.</p>';
    $szoveg = "Kedves {$r['vevo_nev']}!\n\nEllenőriztem az igazolásodat, és megnyitottam számodra a(z) $nev videósorozatot. A videókat " . video_datum_ig($lejarat) . " nézheted.\n\n";
    if ($jelszo) {
        $html .= '<div style="background:#f0e5db;padding:16px 18px;margin:0 0 18px"><p style="margin:0 0 8px"><strong>Belépési adataid</strong></p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:15px">'
            . '<tr><td style="padding:2px 14px 2px 0;color:#6e6a66">Oldal</td><td><a href="' . h($url) . '">' . h($url) . '</a></td></tr>'
            . '<tr><td style="padding:2px 14px 2px 0;color:#6e6a66">E-mail-cím</td><td>' . h($r['vevo_email']) . '</td></tr>'
            . '<tr><td style="padding:2px 14px 2px 0;color:#6e6a66">Jelszó</td><td><strong style="font-family:monospace;font-size:17px">' . h($jelszo) . '</strong></td></tr>'
            . '</table></div><p>A jelszót belépés után megváltoztathatod. Egyszerre egy eszközön lehetsz bejelentkezve.</p>';
        $szoveg .= "Belépési adataid:\nOldal: $url\nE-mail-cím: {$r['vevo_email']}\nJelszó: $jelszo\n\nA jelszót belépés után megváltoztathatod. Egyszerre egy eszközön lehetsz bejelentkezve.\n\n";
    } else {
        $html .= '<p>A szokásos belépési adataiddal éred el a videós oldalon.</p>';
        $szoveg .= "A szokásos belépési adataiddal éred el a videós oldalon: $url\n\n";
    }
    [$mHtml, $mSzoveg] = video_teljesites_megerosites();
    $html .= level_gomb($url, 'Belépés a videókhoz') . '<p>Jó tanulást kívánok!</p><p>Szeretettel:<br>Csengeri Zsuzsa</p>' . $mHtml;
    $szoveg .= "Jó tanulást kívánok!\n\nSzeretettel:\nCsengeri Zsuzsa\n\n" . $mSzoveg;
    return level_kuld($r['vevo_email'], $r['vevo_nev'], 'Hozzáférés: ' . $nev . ' videósorozat', level_keret('Megnyílt a videósorozatod', $html), $szoveg);
}

function level_video_elutasitva(array $r, ?array $sorozat, string $indok, string $visszaSzoveg): bool
{
    $nev = $sorozat['rovidCim'] ?? 'videósorozat';
    $html = '<p>Kedves ' . h($r['vevo_nev']) . '!</p>'
        . '<p>Sajnos a(z) <strong>' . h($nev) . '</strong> videósorozathoz feltöltött igazolás alapján nem tudom megnyitni a hozzáférést'
        . ($indok !== '' ? ':</p><p style="background:#f0e5db;padding:12px 16px">' . nl2br(h($indok)) . '</p>' : '.</p>')
        . ($visszaSzoveg !== '' ? '<p>' . h($visszaSzoveg) . '</p>' : '')
        . '<p>Ha úgy gondolod, tévedés történt, vagy más igazolásod is van, válaszolj erre a levélre.</p><p>Szeretettel:<br>Csengeri Zsuzsa</p>';
    $szoveg = "Kedves {$r['vevo_nev']}!\n\nSajnos a(z) $nev videósorozathoz feltöltött igazolás alapján nem tudom megnyitni a hozzáférést"
        . ($indok !== '' ? ":\n$indok\n\n" : ".\n\n") . ($visszaSzoveg !== '' ? "$visszaSzoveg\n\n" : '')
        . "Ha úgy gondolod, tévedés történt, vagy más igazolásod is van, válaszolj erre a levélre.\n\nSzeretettel:\nCsengeri Zsuzsa\n";
    return level_kuld($r['vevo_email'], $r['vevo_nev'], 'A(z) ' . $nev . ' videósorozat rendeléséről', level_keret('A videósorozat rendeléséről', $html), $szoveg);
}

function level_video_hosszabbitva(array $r, ?array $sorozat, string $lejarat): bool
{
    $nev = $sorozat['rovidCim'] ?? 'videósorozat';
    $html = '<p>Kedves ' . h($r['vevo_nev']) . '!</p><p>Köszönöm! A(z) <strong>' . h($nev) . '</strong> videósorozathoz a hozzáférésedet meghosszabbítottam, mostantól <strong>'
        . h(video_datum_ig($lejarat)) . '</strong> nézheted.</p>' . level_gomb(video_url(), 'Belépés a videókhoz') . '<p>Szeretettel:<br>Csengeri Zsuzsa</p>' . video_teljesites_megerosites()[0];
    $szoveg = "Kedves {$r['vevo_nev']}!\n\nKöszönöm! A(z) $nev videósorozathoz a hozzáférésedet meghosszabbítottam, mostantól " . video_datum_ig($lejarat) . " nézheted.\n\n" . video_url() . "\n\nSzeretettel:\nCsengeri Zsuzsa\n\n" . video_teljesites_megerosites()[1];
    return level_kuld($r['vevo_email'], $r['vevo_nev'], 'Meghosszabbítva: ' . $nev, level_keret('Meghosszabbítottam a hozzáférésed', $html), $szoveg);
}

function level_video_figyelmeztetes(array $f, array $h): bool
{
    $s = termek($h['sorozat']);
    $nev = $s['rovidCim'] ?? $h['sorozat'];
    $nap = video_hatra_nap($h);
    $html = '<p>Kedves ' . h($f['nev']) . '!</p><p>A(z) <strong>' . h($nev) . '</strong> videósorozathoz a hozzáférésed <strong>' . $nap . ' nap múlva lejár</strong> (' 
        . h(video_datum($h['lejarat'])) . ').</p><p>Ha szeretnéd tovább nézni, a videós oldalon a „Meghosszabbítom” gombbal további '
        . video_nap() . ' nappal meghosszabbíthatod, ugyanazon az áron (' . h(ft((int)($s['ar'] ?? 0))) . ').</p>'
        . level_gomb(video_url(), 'Belépés és hosszabbítás') . '<p>Szeretettel:<br>Csengeri Zsuzsa</p>';
    $szoveg = "Kedves {$f['nev']}!\n\nA(z) $nev videósorozathoz a hozzáférésed $nap nap múlva lejár (" . video_datum($h['lejarat']) . ").\n\n"
        . 'Ha szeretnéd tovább nézni, a videós oldalon a „Meghosszabbítom” gombbal további ' . video_nap() . ' nappal meghosszabbíthatod, ugyanazon az áron (' . ft((int)($s['ar'] ?? 0)) . ").\n\n"
        . video_url() . "\n\nSzeretettel:\nCsengeri Zsuzsa\n";
    return level_kuld($f['email'], $f['nev'], 'Hamarosan lejár: ' . $nev, level_keret('Hamarosan lejár a hozzáférésed', $html), $szoveg);
}

function level_video_uj_jelszo(array $f, string $jelszo): bool
{
    $url = video_url();
    $html = '<p>Kedves ' . h($f['nev']) . '!</p><p>Új jelszót állítottam be a videós oldalhoz.</p>'
        . '<div style="background:#f0e5db;padding:16px 18px;margin:0 0 18px"><table role="presentation" cellpadding="0" cellspacing="0" style="font-size:15px">'
        . '<tr><td style="padding:2px 14px 2px 0;color:#6e6a66">E-mail-cím</td><td>' . h($f['email']) . '</td></tr>'
        . '<tr><td style="padding:2px 14px 2px 0;color:#6e6a66">Jelszó</td><td><strong style="font-family:monospace;font-size:17px">' . h($jelszo) . '</strong></td></tr>'
        . '</table></div>' . level_gomb($url, 'Belépés a videókhoz') . '<p>Szeretettel:<br>Csengeri Zsuzsa</p>';
    $szoveg = "Kedves {$f['nev']}!\n\nÚj jelszót állítottam be a videós oldalhoz.\nE-mail-cím: {$f['email']}\nJelszó: $jelszo\n\n$url\n\nSzeretettel:\nCsengeri Zsuzsa\n";
    return level_kuld($f['email'], $f['nev'], 'Új jelszó a videós oldalhoz', level_keret('Új jelszó', $html), $szoveg);
}

function level_video_jelszo_link(array $f, string $link): bool
{
    $html = '<p>Kedves ' . h($f['nev']) . '!</p><p>Jelszó-visszaállítást kértél a videós oldalhoz. Az alábbi gombbal 1 órán belül új jelszót adhatsz meg.</p>'
        . level_gomb($link, 'Új jelszó megadása') . '<p style="color:#6e6a66;font-size:13px">Ha nem te kérted, nyugodtan hagyd figyelmen kívül ezt a levelet.</p>';
    $szoveg = "Kedves {$f['nev']}!\n\nJelszó-visszaállítást kértél a videós oldalhoz. Az alábbi linken 1 órán belül új jelszót adhatsz meg:\n$link\n\nHa nem te kérted, nyugodtan hagyd figyelmen kívül ezt a levelet.\n";
    return level_kuld($f['email'], $f['nev'], 'Jelszó-visszaállítás – videós oldal', level_keret('Jelszó-visszaállítás', $html), $szoveg);
}

function level_video_lejart(array $f, array $h): bool
{
    $s = termek($h['sorozat']);
    $nev = $s['rovidCim'] ?? $h['sorozat'];
    $html = '<p>Kedves ' . h($f['nev']) . '!</p><p>A(z) <strong>' . h($nev) . '</strong> videósorozathoz a hozzáférésed ma lejárt.</p>'
        . '<p>Ha szeretnéd még nézni, a videós oldalon belépve a „Meghosszabbítom” gombbal bármikor újabb ' . video_nap() . ' napra megnyithatod, új igazolás nélkül ('
        . h(ft((int)($s['ar'] ?? 0))) . ').</p>' . level_gomb(video_url(), 'Belépés és hosszabbítás') . '<p>Szeretettel:<br>Csengeri Zsuzsa</p>';
    $szoveg = "Kedves {$f['nev']}!\n\nA(z) $nev videósorozathoz a hozzáférésed ma lejárt.\n\nHa szeretnéd még nézni, a videós oldalon belépve a „Meghosszabbítom” gombbal bármikor újabb "
        . video_nap() . ' napra megnyithatod, új igazolás nélkül (' . ft((int)($s['ar'] ?? 0)) . ").\n\n" . video_url() . "\n\nSzeretettel:\nCsengeri Zsuzsa\n";
    return level_kuld($f['email'], $f['nev'], 'Lejárt: ' . $nev, level_keret('Lejárt a hozzáférésed', $html), $szoveg);
}
