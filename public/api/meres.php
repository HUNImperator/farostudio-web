<?php
/**
 * POST api/meres.php – süti nélküli látogatottságmérés (assets/js/meres.js).
 * Mindig 204-et ad vissza; hibánál sem zavarja a látogatót.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';
require_once FARO_APP . '/statisztika.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // csak a saját oldalainkról érkező kérést fogadjuk
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $originHost = $origin !== '' ? (string)parse_url($origin, PHP_URL_HOST) : '';
    $sajat = $origin === '' || stat_sajat_host($originHost) || $originHost === preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    $be = json_decode((string)file_get_contents('php://input', false, null, 0, 4000), true);
    if ($sajat && is_array($be)) {
        try {
            stat_rogzit($be);
        } catch (Throwable $e) {
            error_log('FARO meres: ' . $e->getMessage());
        }
    }
}
http_response_code(204);
