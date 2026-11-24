<?php
/**
 * GET api/blog.php – a kezdőlap Gondolatok csempéjéhez: a témakörök és a legutóbbi 3 írás.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';
require_once FARO_APP . '/blog.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

$ki = ['temak' => [], 'legutobbi' => []];
foreach (blog_temak() as $t) {
    $ki['temak'][] = ['kulcs' => $t['kulcs'], 'nev' => $t['nev']];
}
foreach (blog_megjelent(3) as $c) {
    $ki['legutobbi'][] = ['cim' => $c['cim'], 'url' => 'gondolatok/' . $c['slug'], 'datum' => blog_datum($c['megjelenes'])];
}
echo json_encode($ki, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
