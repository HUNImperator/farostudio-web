<?php
/**
 * Gondolatok, blogok – a cikkek listája (gondolatok/) és egy cikk (gondolatok/<webcím>).
 * A szép webcímet a gondolatok/.htaccess irányítja ide (?cikk=<webcím>).
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';
require_once FARO_APP . '/blog.php';
require_once FARO_APP . '/sablon.php';
require_once FARO_APP . '/blog_oldal.php';

$gy = '../';
$alap = rtrim(faro_config()['site_url'], '/');
$slug = (string)($_GET['cikk'] ?? '');

/* ---------- egy cikk ---------- */
if ($slug !== '') {
    // a perjellel végződő változat átirányítása, hogy a relatív hivatkozások stimmeljenek
    $ut = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (substr($ut, -1) === '/' && strpos($ut, '/gondolatok/' . $slug . '/') !== false) {
        header('Location: ' . rtrim($ut, '/'), true, 301);
        exit;
    }
    $c = preg_match('/^[a-z0-9-]{1,120}$/', $slug) ? blog_cikk_slug($slug) : null;
    if (!$c || !blog_el($c)) {
        http_response_code(404);
        oldal_fej(['cim' => 'Nem található | Csengeri Zsuzsa', 'gy' => $gy, 'aktiv' => 'gondolatok/']); ?>
    <section class="request-hero"><div class="container">
      <h1 class="checkout__title">Ez az írás nem található</h1>
      <p class="request__intro">Lehet, hogy megváltozott a címe, vagy még nem jelent meg.</p>
      <p><a class="btn" href="./">Vissza az írásokhoz&nbsp;<span class="btn__arrow" aria-hidden="true">→</span></a></p>
    </div></section>
<?php   oldal_lab(['gy' => $gy]);
        exit;
    }
    blog_cikk_oldal($c, $gy, false);
    exit;
}

/* ---------- lista ---------- */
$cikkek = blog_megjelent();
$temak = blog_temak();
$hasznalt = [];
foreach ($cikkek as $c) if ($c['tema_id']) $hasznalt[(int)$c['tema_id']] = true;

oldal_fej([
    'cim' => 'Gondolatok, blogok – Csengeri Zsuzsa | FARO',
    'leiras' => 'Gondolatok, blogok – Csengeri Zsuzsa jegyzetei önismeretről, családi mintákról, írói műhelytitkokról és újrakezdésről.',
    'gy' => $gy, 'aktiv' => 'gondolatok/', 'kanonikus' => $alap . '/gondolatok/',
]); ?>
    <section class="page-hero" aria-labelledby="oldal-cim">
      <div class="container page-hero__inner">
        <div>
          <p class="page-hero__eyebrow">Gondolatok, blogok</p>
          <h1 class="page-hero__title" id="oldal-cim">Pillanatképek a belső útról</h1>
          <p class="page-hero__lead">Vannak felismerések, amelyek nem kívánnak egy egész regényt, mégis képesek új irányba fordítani a napunkat – vagy akár az életünket. Ezen az oldalon olyan rövidebb írásokat, jegyzeteket és történetmorzsákat gyűjtök össze, amelyek a kineziológusi praxisom tapasztalataiból, az írói műhelyem csendjéből és a mindennapok apró csodáiból születnek. Olvasd őket úgy, mint egy jó beszélgetést egy csésze tea mellett.</p>
        </div>
        <figure class="page-hero__media page-hero__media--plain" style="margin:0">
          <img src="<?= $gy ?>assets/img/gondolatok-illusztracio.svg" width="420" height="330" alt="Nyitott jegyzetfüzet tollal és egy gőzölgő teáscsésze" style="-webkit-mask-image:none;mask-image:none">
        </figure>
      </div>
    </section>

    <section class="section-pad" aria-labelledby="irasok-cim">
      <div class="container">
        <h2 class="visually-hidden" id="irasok-cim">Írások</h2>
        <ul class="filters" aria-label="Szűrés témakör szerint">
          <li><button class="filter" type="button" data-filter="osszes" aria-pressed="true" data-desc="">Minden írás</button></li>
<?php foreach ($temak as $t): ?>
          <li><button class="filter" type="button" data-filter="<?= h($t['kulcs']) ?>" aria-pressed="false" data-desc="<?= h($t['leiras']) ?>"><?= h($t['nev']) ?></button></li>
<?php endforeach; ?>
        </ul>
        <p class="filter-desc" aria-live="polite"></p>

        <div class="posts">
<?php foreach ($cikkek as $c): $t = blog_tema($c['tema_id'] ? (int)$c['tema_id'] : null); ?>
          <article class="post post--kep" data-tema="<?= h($t['kulcs'] ?? '') ?>">
            <a class="post__kep" href="<?= h($c['slug']) ?>" tabindex="-1" aria-hidden="true">
<?php if ($c['kezdokep']): ?>
              <?= blog_kep_html($c['kezdokep'], $gy, '', '(max-width: 640px) 100vw, (max-width: 900px) 50vw, 380px') ?>
<?php else: ?>
              <img src="<?= $gy ?>assets/img/gondolatok-illusztracio.svg" alt="" class="post__kep-helyett" width="420" height="330">
<?php endif; ?>
            </a>
            <div class="post__belso">
              <p class="post__meta"><?= h(blog_datum($c['megjelenes'], false)) ?><?= $t ? ' · ' . h($t['nev']) : '' ?></p>
              <h3 class="post__title"><a href="<?= h($c['slug']) ?>"><?= h($c['cim']) ?></a></h3>
              <?php if (trim((string)$c['bevezeto']) !== ''): ?><p><?= h($c['bevezeto']) ?></p><?php endif; ?>
              <p class="post__tovabb"><a href="<?= h($c['slug']) ?>">Tovább olvasom<span class="visually-hidden">: <?= h($c['cim']) ?></span>&nbsp;<span aria-hidden="true">→</span></a> <span class="post__ido"><?= (int)$c['olvasasi_ido'] ?> perc</span></p>
            </div>
          </article>
<?php endforeach; ?>
          <div class="posts-empty" data-empty<?= $cikkek ? ' hidden' : '' ?>>
            <img src="<?= $gy ?>assets/img/gondolatok-illusztracio.svg" width="160" height="126" alt="">
            <p><?= $cikkek ? 'Ebben a témakörben még nincs írás.' : 'Az első írások hamarosan megérkeznek ide.' ?></p>
          </div>
        </div>
      </div>
    </section>
<?php
oldal_lab(['gy' => $gy]);
