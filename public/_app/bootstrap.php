<?php
/**
 * FARO – közös indító: beállítások, adatbázis, segédfüggvények.
 * Ez a mappa kívülről nem érhető el (.htaccess), a fájlokat csak
 * az api/ és az admin/ program tölti be.
 */
declare(strict_types=1);

define('FARO', true);
define('FARO_APP', __DIR__);
define('FARO_PUBLIC', dirname(__DIR__));

date_default_timezone_set('Europe/Budapest');
mb_internal_encoding('UTF-8');

/* ---------- beállítások ----------
 * A titkos adatok (jelszavak, kulcsok) a webgyökéren KÍVÜL, a
 * faro-config.php fájlban vannak, hogy soha ne kerüljenek a GitHubra
 * és a böngészőből se legyenek elérhetők.
 * Helye: a weboldal mappája mellett vagy eggyel feljebb
 * (/home/whiteman/faro-config.php).
 */
/**
 * A beállítófájl helye: FARO_CONFIG, különben a weboldal mappája mellett,
 * vagy eggyel feljebb (ha a weboldal a public_html egy almappájában van,
 * pl. /home/whiteman/public_html/farostudio.hu → /home/whiteman/faro-config.php).
 */
function faro_config_ut(): string
{
    if (getenv('FARO_CONFIG')) return (string)getenv('FARO_CONFIG');
    foreach ([dirname(FARO_PUBLIC), dirname(FARO_PUBLIC, 2)] as $m) {
        if (is_file($m . '/faro-config.php')) return $m . '/faro-config.php';
    }
    return dirname(FARO_PUBLIC) . '/faro-config.php';
}

/** A webgyökéren kívüli mappa, ahol a beállítófájl van (a videók és igazolások alapértelmezett helye). */
function faro_kulso_mappa(): string
{
    return dirname(faro_config_ut());
}

function faro_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $path = faro_config_ut();
    if (!is_file($path)) {
        faro_fatal('Hiányzik a beállítófájl (faro-config.php).');
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        faro_fatal('A beállítófájl hibás.');
    }
    $defaults = [
        'site_url'      => 'https://farostudio.hu',
        'admin_email'   => 'info@farostudio.hu',
        'mail_mode'     => 'smtp',          // smtp | log (helyi teszteléshez)
        'mail_log'      => sys_get_temp_dir() . '/faro-mail.log',
        'debug'         => false,
        'setup_token'   => '',
        'bank' => [
            'kedvezmenyezett' => 'Csengeri Zsuzsa',
            'bank'            => 'OTP Bank',
            'szamlaszam'      => '11773683-23190308-00000000',
        ],
        'atutalas_hatarido_nap' => null,    // ha szám, a levélben határidőként jelenik meg
    ];
    $cfg = array_replace_recursive($defaults, $loaded);
    return $cfg;
}

function faro_fatal(string $uzenet, int $kod = 500): void
{
    http_response_code($kod);
    if (faro_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['hiba' => $uzenet], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif;padding:2rem">' . h($uzenet) . '</p>';
    }
    exit;
}

function faro_wants_json(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return strpos($uri, '/api/') !== false;
}

/* ---------- hibakezelés ---------- */
set_exception_handler(function (Throwable $e): void {
    error_log('[FARO] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $cfg = null;
    try { $cfg = faro_config(); } catch (Throwable $x) {}
    $uzenet = ($cfg && !empty($cfg['debug'])) ? $e->getMessage() : 'Váratlan hiba történt. Kérlek, próbáld újra később.';
    faro_fatal($uzenet);
});

/* ---------- segédek ---------- */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ft(int $osszeg): string
{
    // magyar ezres tagolás: 4 jegyig egyben (2900 Ft), felette szóközzel (12 000 Ft)
    $s = (string)$osszeg;
    if (strlen($s) > 4) {
        $s = number_format($osszeg, 0, ',', "\u{00A0}");
    }
    return $s . "\u{00A0}Ft";
}

function most(): string
{
    return date('Y-m-d H:i:s');
}

require FARO_APP . '/db.php';
require FARO_APP . '/termekek.php';
require FARO_APP . '/rendelesek.php';
require FARO_APP . '/levelek.php';
require FARO_APP . '/szamlazz.php';
require FARO_APP . '/urlapok.php';
require FARO_APP . '/videok.php';
require FARO_APP . '/foxpost.php';
