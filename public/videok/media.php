<?php
/**
 * A videók kiszolgálása (HLS): lejátszási lista, titkosított darabok és a kulcs.
 * Csak bejelentkezett, élő hozzáféréssel rendelkező felhasználónak.
 *
 *   media.php?s=one-brain-2&v=03            → a 3. videó lejátszási listája (átírt címekkel)
 *   media.php?s=one-brain-2&v=03&f=d0042.ts → egy titkosított darab
 *   media.php?s=one-brain-2&f=kulcs         → a sorozat AES-128 kulcsa
 *
 * A fájlok a webgyökéren kívül vannak: {video_mappa}/{sorozat}/kulcs.key és {video_mappa}/{sorozat}/{NN}/…
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';
require FARO_APP . '/video_munkamenet.php';

function media_hiba(int $kod, string $uzenet): void
{
    http_response_code($kod);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $uzenet;
    exit;
}

$f = video_bejelentkezve($mashol);
if (!$f) {
    media_hiba(403, $mashol ? 'Másik eszközön beléptél – itt kiléptettünk.' : 'Lejárt a belépés, kérlek, lépj be újra.');
}
$sorozat = (string)($_GET['s'] ?? '');
$s = video_sorozat($sorozat);
if (!$s) {
    media_hiba(404, 'Nincs ilyen sorozat.');
}
if (!video_nezheti($f, $sorozat)) {
    media_hiba(403, 'Ehhez a sorozathoz nincs élő hozzáférésed.');
}
$mappa = video_mappa() . '/' . $sorozat;
$fajl = (string)($_GET['f'] ?? '');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

/* ---------- kulcs ---------- */
if ($fajl === 'kulcs') {
    $kulcs = @file_get_contents($mappa . '/kulcs.key');
    if ($kulcs === false || strlen($kulcs) !== 16) {
        media_hiba(404, 'A kulcs nem található.');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: 16');
    header('Cache-Control: private, no-store');
    echo $kulcs;
    exit;
}

$v = (string)($_GET['v'] ?? '');
if (!preg_match('/^\d{2}$/', $v) || (int)$v < 1 || (int)$v > count($s['videok'])) {
    media_hiba(404, 'Nincs ilyen videó.');
}
$videoMappa = $mappa . '/' . $v;

/* ---------- lejátszási lista: a darabok és a kulcs címét ide irányítjuk ---------- */
if ($fajl === '') {
    $lista = @file($videoMappa . '/index.m3u8', FILE_IGNORE_NEW_LINES);
    if ($lista === false) {
        media_hiba(404, 'Ez a videó még nincs feltöltve.');
    }
    $alap = 'media.php?s=' . rawurlencode($sorozat);
    $ki = [];
    foreach ($lista as $sor) {
        $sor = rtrim($sor, "\r");
        if (strpos($sor, '#EXT-X-KEY') === 0) {
            $sor = preg_replace('/URI="[^"]*"/', 'URI="' . $alap . '&f=kulcs"', $sor);
        } elseif ($sor !== '' && $sor[0] !== '#') {
            $sor = $alap . '&v=' . $v . '&f=' . rawurlencode(basename($sor));
        }
        $ki[] = $sor;
    }
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: private, no-store');
    echo implode("\n", $ki) . "\n";
    exit;
}

/* ---------- egy (titkosított) darab ---------- */
if (!preg_match('/^[A-Za-z0-9_\-]{1,40}\.(ts|m4s|mp4)$/', $fajl)) {
    media_hiba(400, 'Hibás kérés.');
}
$ut = $videoMappa . '/' . $fajl;
if (!is_file($ut)) {
    media_hiba(404, 'A videórészlet nem található.');
}
// a munkamenet-zárolás nélkül, hogy a párhuzamos letöltések ne várjanak egymásra
$meret = filesize($ut);
header('Content-Type: ' . (substr($fajl, -3) === '.ts' ? 'video/mp2t' : 'video/mp4'));
header('Content-Length: ' . $meret);
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline');
$fh = fopen($ut, 'rb');
fpassthru($fh);
fclose($fh);
