<?php
defined('FARO') or exit;

/**
 * Saját látogatottságmérés – süti és IP-tárolás nélkül.
 *
 * - A látogatót naponta egy titkos, véletlen kulccsal képzett kód azonosítja
 *   (HMAC: IP + böngésző + nap). A kulcs és a kódok a nap végén törlődnek,
 *   így a kód utólag nem köthető vissza, és napokon át senki sem követhető.
 * - Minden más csak összesített napi számláló (stat_szamlalo: nap, tipus, kulcs, db).
 *
 * Számlálótípusok:
 *   oldal      – oldalmegtekintés oldalanként (kulcs: útvonal)
 *   ora        – oldalmegtekintés óránként (00–23)
 *   latogato   – napi egyedi látogatók (kulcs: '')
 *   belepo     – az első megnézett oldal
 *   forras     – honnan jött (Google, Facebook, Közvetlen…)
 *   hivatkozo  – a hivatkozó webhely domainje
 *   kampany    – utm_source / utm_campaign
 *   eszkoz, bongeszo, os
 *   egyoldalas – csak egy oldalt megnéző látogatók (a nap lezárásakor)
 *   esemeny    – kosarba:<id>, urlap:<oldal>, video:<sorozat>, kattintas:email|telefon
 */

const STAT_ESEMENY_MINTA = '/^(kosarba|urlap|video|kattintas):[A-Za-z0-9\/._-]{1,120}$/';

function stat_novel(string $nap, string $tipus, string $kulcs, int $n = 1): void
{
    if ($n <= 0) return;
    $kulcs = mb_substr($kulcs, 0, 190);
    if (db_driver() === 'mysql') {
        $sql = 'INSERT INTO stat_szamlalo (nap, tipus, kulcs, db) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE db = db + VALUES(db)';
    } else {
        $sql = 'INSERT INTO stat_szamlalo (nap, tipus, kulcs, db) VALUES (?,?,?,?) ON CONFLICT(nap, tipus, kulcs) DO UPDATE SET db = db + excluded.db';
    }
    db()->prepare($sql)->execute([$nap, $tipus, $kulcs, $n]);
}

/** A mai titkos kulcs; az első kéréskor jön létre, ekkor zárjuk le a korábbi napokat is. */
function stat_so(string $nap): string
{
    $st = db()->prepare('SELECT so FROM stat_so WHERE nap = ?');
    $st->execute([$nap]);
    $so = $st->fetchColumn();
    if ($so) return (string)$so;
    $uj = bin2hex(random_bytes(32));
    $ins = db_driver() === 'mysql' ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO';
    db()->prepare("$ins stat_so (nap, so) VALUES (?, ?)")->execute([$nap, $uj]);
    stat_lezar($nap);
    $st->execute([$nap]);
    return (string)$st->fetchColumn();
}

/**
 * A mai nap előtti napok lezárása: az egyoldalas látogatások összesítése,
 * majd a napi kódok és kulcsok törlése. Kétszeri futás esetén sem számol duplán
 * (csak a ténylegesen törölt sorok számítanak).
 */
function stat_lezar(string $ma): void
{
    $napok = db()->prepare('SELECT DISTINCT nap FROM stat_napi_latogato WHERE nap < ?');
    $napok->execute([$ma]);
    foreach ($napok->fetchAll(PDO::FETCH_COLUMN) as $nap) {
        $del = db()->prepare('DELETE FROM stat_napi_latogato WHERE nap = ? AND oldalak <= 1');
        $del->execute([$nap]);
        stat_novel((string)$nap, 'egyoldalas', '', $del->rowCount());
        db()->prepare('DELETE FROM stat_napi_latogato WHERE nap = ?')->execute([$nap]);
    }
    db()->prepare('DELETE FROM stat_so WHERE nap < ?')->execute([$ma]);
}

/* ---------- a böngésző azonosítása (csak kategóriák) ---------- */
function stat_robot(string $ua): bool
{
    return $ua === '' || (bool)preg_match('~(?<!cu)bot\b|bot/|crawl|spider|slurp|bingpreview|facebookexternalhit|facebookcatalog|embedly|whatsapp|telegram|skype|discord|preview|headless|phantom|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|curl|wget|python|java/|go-http|okhttp|axios|node-fetch|scrapy|httpclient|semrush|ahrefs|mj12|dotbot|petal|yandex|baidu|bytespider|gptbot|claude|ccbot|anthropic|perplexity|applebot|duckassist~i', $ua);
}

function stat_eszkoz(string $ua): string
{
    if (preg_match('~iPad|Tablet|PlayBook|Silk|Kindle|SM-T\d|Tab\b~i', $ua) || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) {
        return 'Tablet';
    }
    if (preg_match('~Mobi|iPhone|iPod|Android|Windows Phone|BlackBerry|Opera Mini~i', $ua)) {
        return 'Mobil';
    }
    return 'Asztali gép';
}

function stat_bongeszo(string $ua): string
{
    $minta = [
        'FBAN|FBAV|FB_IAB'  => 'Facebook (alkalmazásban)',
        'Instagram'         => 'Instagram (alkalmazásban)',
        'SamsungBrowser'    => 'Samsung Internet',
        'Edg/|Edge/|EdgA/|EdgiOS/' => 'Edge',
        'OPR/|Opera'        => 'Opera',
        'YaBrowser'         => 'Yandex',
        'Firefox/|FxiOS/'   => 'Firefox',
        'CriOS/'            => 'Chrome',
        'Chrome/'           => 'Chrome',
        'Safari/'           => 'Safari',
    ];
    foreach ($minta as $m => $nev) {
        if (preg_match('~' . $m . '~', $ua)) return $nev;
    }
    return 'Egyéb';
}

function stat_os(string $ua): string
{
    if (preg_match('~iPhone|iPad|iPod~', $ua)) return 'iOS / iPadOS';
    if (stripos($ua, 'Android') !== false) return 'Android';
    if (stripos($ua, 'CrOS') !== false) return 'ChromeOS';
    if (stripos($ua, 'Windows') !== false) return 'Windows';
    if (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) return 'macOS';
    if (stripos($ua, 'Linux') !== false) return 'Linux';
    return 'Egyéb';
}

/** Saját webhely-e (a www. és az aldomain nem számít): farostudio.hu és a mostani host. */
function stat_sajat_host(string $host): bool
{
    $host = strtolower(preg_replace('/^www\./i', '', $host));
    $sajat = strtolower(preg_replace('/^www\./i', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $sajat = preg_replace('/:\d+$/', '', $sajat);
    return $host === $sajat || $host === 'farostudio.hu' || substr($host, -14) === '.farostudio.hu';
}

/**
 * Forrás (csatorna) és hivatkozó domain a referrer, az utm_source és a böngésző alapján.
 * @return array{0:string,1:string} [csatorna, domain ('' ha nincs külső hivatkozó)]
 */
function stat_forras(string $ref, string $utm, string $ua): array
{
    $host = '';
    if ($ref !== '') {
        $p = parse_url($ref);
        if (($p['scheme'] ?? '') === 'android-app') {
            $host = strtolower((string)($p['host'] ?? ''));
            if (strpos($host, 'com.google.android.gm') === 0) return ['E-mail', 'Gmail alkalmazás'];
            if (strpos($host, 'com.google.android.googlequicksearchbox') === 0) return ['Google', 'Google alkalmazás'];
            if (strpos($host, 'com.facebook') === 0) return ['Facebook', 'Facebook alkalmazás'];
            if (strpos($host, 'com.instagram') === 0) return ['Instagram', 'Instagram alkalmazás'];
            return ['Más weboldal / alkalmazás', $host];
        }
        $host = strtolower(preg_replace('/^www\./i', '', (string)($p['host'] ?? '')));
        if ($host !== '' && stat_sajat_host($host)) {
            $host = '';
        }
    }
    $csatornak = [
        '~(^|\.)mail\.google\.|outlook\.(live|office)|mail\.yahoo|freemail\.hu|citromail\.hu|^mail\.|webmail~' => 'E-mail',
        '~(^|\.)google\.~'                      => 'Google',
        '~(^|\.)bing\.com$~'                    => 'Bing',
        '~duckduckgo\.com$~'                    => 'DuckDuckGo',
        '~(^|\.)yahoo\.~'                       => 'Yahoo',
        '~ecosia\.org$|startpage\.com$|search\.brave\.com$|qwant\.com$~' => 'Más kereső',
        '~(^|\.)(facebook\.com|fb\.com|fb\.me)$|messenger\.com$~' => 'Facebook',
        '~(^|\.)instagram\.com$~'               => 'Instagram',
        '~(^|\.)(youtube\.com|youtu\.be)$~'     => 'YouTube',
        '~(^|\.)tiktok\.com$~'                  => 'TikTok',
        '~(^|\.)linkedin\.com$|lnkd\.in$~'      => 'LinkedIn',
        '~(^|\.)pinterest\.~'                   => 'Pinterest',
        '~(^|\.)(t\.co|twitter\.com|x\.com)$~'  => 'X (Twitter)',
        '~chatgpt\.com$|openai\.com$|perplexity\.ai$|claude\.ai$|gemini\.google~' => 'MI-asszisztens',
    ];
    if ($utm !== '') {
        $u = strtolower($utm);
        $utmMap = ['facebook' => 'Facebook', 'fb' => 'Facebook', 'instagram' => 'Instagram', 'ig' => 'Instagram',
            'google' => 'Google', 'youtube' => 'YouTube', 'email' => 'E-mail', 'e-mail' => 'E-mail', 'hirlevel' => 'E-mail',
            'newsletter' => 'E-mail', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn'];
        foreach ($utmMap as $k => $v) {
            if (strpos($u, $k) !== false) return [$v, $host];
        }
        return ['Kampány: ' . mb_substr($utm, 0, 60), $host];
    }
    if ($host !== '') {
        foreach ($csatornak as $m => $nev) {
            if (preg_match($m, $host)) return [$nev, $host];
        }
        return ['Más weboldal', $host];
    }
    // nincs külső hivatkozó: alkalmazáson belüli böngészőből jött?
    if (preg_match('~FBAN|FBAV|FB_IAB~', $ua)) return ['Facebook', ''];
    if (stripos($ua, 'Instagram') !== false) return ['Instagram', ''];
    return ['Közvetlen / könyvjelző', ''];
}

/** Az útvonal egységesítése (lekérdezés és horgony nélkül; /index.html → /). */
function stat_utvonal(string $u): ?string
{
    $u = (string)parse_url($u, PHP_URL_PATH);
    if ($u === '' || $u[0] !== '/' || strlen($u) > 190 || !preg_match('~^/[A-Za-z0-9/_.\-]*$~', $u)) return null;
    if (preg_match('~^/(admin|api|_app)(/|$)~', $u)) return null;
    $u = preg_replace('~/index\.(html|php)$~', '/', $u);
    return $u;
}

/**
 * Egy oldalmegtekintés vagy esemény rögzítése.
 * $be: t ('o' oldal / 'e' esemény), o (útvonal), r (referrer), u (utm_source), c (utm_campaign), e (esemény)
 */
function stat_rogzit(array $be): void
{
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
    if (stat_robot($ua)) return;
    $str = function ($k, $max) use ($be) {
        return isset($be[$k]) && is_scalar($be[$k]) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$be[$k])), 0, $max) : '';
    };
    $nap = date('Y-m-d');
    $tipus = $str('t', 2);

    if ($tipus === 'e') {
        $e = $str('e', 140);
        if (preg_match(STAT_ESEMENY_MINTA, $e)) stat_novel($nap, 'esemeny', $e);
        return;
    }
    if ($tipus !== 'o') return;
    $ut = stat_utvonal($str('o', 400));
    if ($ut === null) return;

    stat_novel($nap, 'oldal', $ut);
    stat_novel($nap, 'ora', date('H'));

    // napi egyedi látogató: HMAC(IP + böngésző) a napi titkos kulccsal; az IP-t nem tároljuk
    $kod = substr(hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $ua, stat_so($nap)), 0, 16);
    $most = date('Y-m-d H:i:s');
    $ins = db_driver() === 'mysql' ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO';
    $st = db()->prepare("$ins stat_napi_latogato (nap, kod, oldalak, utolso) VALUES (?, ?, 1, ?)");
    $st->execute([$nap, $kod, $most]);
    if ($st->rowCount() === 0) {
        db()->prepare('UPDATE stat_napi_latogato SET oldalak = oldalak + 1, utolso = ? WHERE nap = ? AND kod = ?')->execute([$most, $nap, $kod]);
        return;
    }
    // a mai első megtekintése: a látogatóra vonatkozó számlálók
    [$forras, $domain] = stat_forras($str('r', 500), $str('u', 80), $ua);
    stat_novel($nap, 'latogato', '');
    stat_novel($nap, 'belepo', $ut);
    stat_novel($nap, 'forras', $forras);
    if ($domain !== '') stat_novel($nap, 'hivatkozo', $domain);
    $utm = $str('u', 80);
    if ($utm !== '') stat_novel($nap, 'kampany', $utm . (($c = $str('c', 80)) !== '' ? ' / ' . $c : ''));
    stat_novel($nap, 'eszkoz', stat_eszkoz($ua));
    stat_novel($nap, 'bongeszo', stat_bongeszo($ua));
    stat_novel($nap, 'os', stat_os($ua));
}

/* ---------- lekérdezések az admin oldalhoz ---------- */

/** Kulcsonkénti összeg egy időszakra, csökkenő sorrendben. */
function stat_lista(string $tipus, string $tol, string $ig, int $limit = 0, string $elotag = ''): array
{
    $sql = 'SELECT kulcs, SUM(db) AS db FROM stat_szamlalo WHERE tipus = ? AND nap BETWEEN ? AND ?'
        . ($elotag !== '' ? ' AND kulcs LIKE ?' : '') . ' GROUP BY kulcs ORDER BY db DESC, kulcs';
    if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;
    $st = db()->prepare($sql);
    $st->execute($elotag !== '' ? [$tipus, $tol, $ig, $elotag . '%'] : [$tipus, $tol, $ig]);
    $ki = [];
    foreach ($st->fetchAll() as $r) $ki[(string)$r['kulcs']] = (int)$r['db'];
    return $ki;
}

function stat_osszeg(string $tipus, string $tol, string $ig): int
{
    $st = db()->prepare('SELECT COALESCE(SUM(db), 0) FROM stat_szamlalo WHERE tipus = ? AND nap BETWEEN ? AND ?');
    $st->execute([$tipus, $tol, $ig]);
    return (int)$st->fetchColumn();
}

/** Napi idősor: [nap => ['latogato' => n, 'oldal' => n]] minden napra (0-val kitöltve). */
function stat_napok(string $tol, string $ig): array
{
    $ki = [];
    for ($t = strtotime($tol); $t <= strtotime($ig); $t = strtotime('+1 day', $t)) {
        $ki[date('Y-m-d', $t)] = ['latogato' => 0, 'oldal' => 0];
    }
    $st = db()->prepare("SELECT nap, tipus, SUM(db) AS db FROM stat_szamlalo WHERE tipus IN ('latogato','oldal') AND nap BETWEEN ? AND ? GROUP BY nap, tipus");
    $st->execute([$tol, $ig]);
    foreach ($st->fetchAll() as $r) {
        $n = substr((string)$r['nap'], 0, 10);
        if (isset($ki[$n])) $ki[$n][$r['tipus']] = (int)$r['db'];
    }
    return $ki;
}

/** Egyoldalas látogatások: a lezárt napokból + a mai nap élőben. */
function stat_egyoldalas(string $tol, string $ig): int
{
    $n = stat_osszeg('egyoldalas', $tol, $ig);
    $ma = date('Y-m-d');
    if ($ma >= $tol && $ma <= $ig) {
        $st = db()->prepare('SELECT COUNT(*) FROM stat_napi_latogato WHERE nap = ? AND oldalak <= 1');
        $st->execute([$ma]);
        $n += (int)$st->fetchColumn();
    }
    return $n;
}

/** Az utolsó 5 percben aktív látogatók száma. */
function stat_most_aktiv(): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM stat_napi_latogato WHERE nap = ? AND utolso >= ?');
    $st->execute([date('Y-m-d'), date('Y-m-d H:i:s', time() - 300)]);
    return (int)$st->fetchColumn();
}

/** Olvasható oldalnév az útvonalhoz (a HTML-fájl <title> eleméből). */
function stat_oldalnev(string $ut): string
{
    static $cache = [];
    if (isset($cache[$ut])) return $cache[$ut];
    $nev = '';
    $fajl = $ut === '/' ? '/index.html' : $ut;
    if (substr($fajl, -1) === '/') $fajl .= 'index.html';
    $ut_fajl = realpath(FARO_PUBLIC . $fajl);
    if ($ut_fajl && strpos($ut_fajl, realpath(FARO_PUBLIC)) === 0 && preg_match('/\.html$/', $ut_fajl)) {
        $eleje = (string)file_get_contents($ut_fajl, false, null, 0, 4000);
        if (preg_match('~<title>(.*?)</title>~s', $eleje, $m)) {
            $nev = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            $nev = trim(preg_replace('/(\s*[|–-]\s*(Csengeri Zsuzsa|FARO))+\s*$/u', '', $nev));
        }
    }
    if ($ut === '/') $nev = 'Kezdőlap';
    if ($nev === '' && strpos($ut, '/videok/') === 0) $nev = 'Videós oldal';
    return $cache[$ut] = $nev;
}
