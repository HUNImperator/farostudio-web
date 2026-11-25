<?php
/**
 * Oldaltérkép a keresőknek (/sitemap.xml → ide irányít a gyökér .htaccess).
 * A statikus oldalak + a megjelent blogírások.
 */
require __DIR__ . '/_app/bootstrap.php';
require_once FARO_APP . '/blog.php';

$alap = rtrim(faro_config()['site_url'], '/');
$oldalak = [
    '', 'rolam.html', 'konyvek.html', 'eloadasok.html', 'eloadas-igenyles.html', 'mentoralas.html',
    'mentoralas-jelentkezes.html', 'informalis-kineziologia.html', 'informalis-kineziologia-bejelentkezes.html',
    'szemelyiseg-elemzo-modszer.html', 'oktato-videok.html', 'bio-narrativa.html', 'bio-narrativa-jelentkezes.html',
    'gondolatok/', 'aszf.html', 'adatkezeles.html', 'elallas.html', 'impresszum.html', 'kapcsolat.html',
];

$sorok = [];
foreach ($oldalak as $o) {
    $f = $o === '' ? 'index.html' : ($o === 'gondolatok/' ? 'gondolatok/index.php' : $o);
    $sorok[] = [$alap . '/' . $o, is_file(__DIR__ . '/' . $f) ? date('Y-m-d', (int)filemtime(__DIR__ . '/' . $f)) : null];
}
try {
    foreach (blog_megjelent() as $c) {
        $sorok[] = [$alap . '/gondolatok/' . $c['slug'], substr((string)$c['frissitve'], 0, 10)];
    }
} catch (Throwable $e) {
    // adatbázis nélkül a statikus oldalak akkor is megjelennek
}

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($sorok as [$url, $mod]) {
    echo '  <url><loc>', htmlspecialchars($url, ENT_XML1), '</loc>', $mod ? '<lastmod>' . $mod . '</lastmod>' : '', "</url>\n";
}
echo "</urlset>\n";
