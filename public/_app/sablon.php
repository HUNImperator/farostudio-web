<?php
defined('FARO') or exit;

/**
 * Közös fejléc és lábléc a PHP-ből készülő nyilvános oldalakhoz (blog).
 * A statikus HTML-oldalakéval azonos szerkezet; $o['gy'] a gyökér relatív útvonala (pl. '../').
 *   cim, leiras, gy, kanonikus (teljes URL), kep (teljes URL, megosztáshoz), tipus ('website'|'article'),
 *   aktiv (a főmenü aktív pontjának href-je), body (body attribútumok), fejbe (extra <head> tartalom),
 *   schema (az oldal saját schema.org elemei; a közöseket a _app/schema_alap.json adja), noindex
 */
/** schema.org JSON-LD: a közös elemek (weboldal, személy, vállalkozás) + az oldal sajátjai. */
function schema_blokk(array $sajat): string
{
    $alap = json_decode((string)@file_get_contents(FARO_APP . '/schema_alap.json'), true) ?: [];
    $adat = ['@context' => 'https://schema.org', '@graph' => array_merge($alap, $sajat)];
    $json = json_encode($adat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    return "  <script type=\"application/ld+json\">\n" . $json . "\n  </script>\n";
}

function oldal_fej(array $o): void
{
    $gy = $o['gy'] ?? '';
    $menu = [
        'rolam.html' => 'Bemutatkozás',
        'konyvek.html' => 'Írások és regények',
        'eloadasok.html' => 'Előadások és programok',
        'mentoralas.html' => 'Szakmai mentorálás',
        'informalis-kineziologia.html' => 'Informális Kineziológia',
        'bio-narrativa.html' => 'Bio-Narratíva program',
        'gondolatok/' => 'Gondolatok, blogok',
    ];
    header('Content-Type: text/html; charset=utf-8'); ?>
<!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (!empty($o['noindex'])): ?>
  <meta name="robots" content="noindex, nofollow">
<?php endif; ?>
  <title><?= h($o['cim']) ?></title>
  <meta name="description" content="<?= h($o['leiras'] ?? '') ?>">
<?php if (!empty($o['kanonikus'])): ?>
  <link rel="canonical" href="<?= h($o['kanonikus']) ?>">
  <meta property="og:url" content="<?= h($o['kanonikus']) ?>">
<?php endif; ?>
  <meta property="og:type" content="<?= h($o['tipus'] ?? 'website') ?>">
  <meta property="og:title" content="<?= h($o['og_cim'] ?? $o['cim']) ?>">
  <meta property="og:description" content="<?= h($o['leiras'] ?? '') ?>">
  <meta property="og:locale" content="hu_HU">
<?php if (!empty($o['kep'])): ?>
  <meta property="og:image" content="<?= h($o['kep']) ?>">
<?php endif; ?>
  <link rel="icon" href="<?= $gy ?>assets/img/favicon.svg" type="image/svg+xml">
  <link rel="icon" href="<?= $gy ?>assets/img/favicon-32.png" sizes="32x32" type="image/png">
  <link rel="apple-touch-icon" href="<?= $gy ?>assets/img/favicon-180.png">
  <link rel="preload" href="<?= $gy ?>assets/fonts/oswald-var.woff" as="font" type="font/woff" crossorigin>
  <link rel="preload" href="<?= $gy ?>assets/fonts/opensans-var.woff" as="font" type="font/woff" crossorigin>
  <link rel="stylesheet" href="<?= $gy ?>assets/css/style.css?v=31">
<?= $o['fejbe'] ?? '' ?>
<?php if (empty($o['noindex'])) echo schema_blokk($o['schema'] ?? []); ?>
</head>
<body<?= isset($o['body']) ? ' ' . $o['body'] : '' ?>>
  <a class="skip-link" href="#tartalom">Ugrás a tartalomra</a>

  <header class="site-header">
    <div class="container site-header__inner">
      <a class="logo" href="<?= $gy ?: './' ?>" aria-label="FARO – Csengeri Zsuzsa, kezdőlap">
        <img class="logo__jel" src="<?= $gy ?>assets/img/logo-faro-jel.svg" width="58" height="58" alt="">
        <span class="logo__text">
          <span class="logo__mark">FARO</span>
          <span class="logo__sub">Csengeri Zsuzsa</span>
        </span>
      </a>

      <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="fomenu" aria-label="Menü megnyitása">
        <span class="nav-toggle__bar"></span>
      </button>

      <nav class="nav" id="fomenu" aria-label="Főmenü">
        <ul class="nav__list">
<?php foreach ($menu as $href => $nev): ?>
          <li><a class="nav__link" href="<?= $gy . $href ?>"<?= ($o['aktiv'] ?? '') === $href ? ' aria-current="page"' : '' ?>><?= h($nev) ?></a></li>
<?php endforeach; ?>
        </ul>
      </nav>
    </div>
  </header>

  <main id="tartalom">
<?php
}

function oldal_lab(array $o): void
{
    $gy = $o['gy'] ?? '';
    $csempek = ['nyito' => 'Bemutatkozás', 'irasok' => 'Írások és regények', 'eloadasok' => 'Előadások és programok', 'mentoralas' => 'Szakmai mentorálás',
        'informalis-kineziologia' => 'Informális Kineziológia', 'bio-narrativa' => 'Bio-Narratíva program', 'gondolatok' => 'Gondolatok, blogok']; ?>
  </main>

  <nav class="tile-nav" aria-label="Ugrás a kezdőlap csempéihez">
    <button class="tile-nav__toggle" type="button" aria-expanded="false" aria-controls="csempe-lista">Tartalom</button>
    <ul class="tile-nav__list" id="csempe-lista">
<?php foreach ($csempek as $k => $nev): ?>
      <li><a class="tile-nav__link" href="<?= $gy ?: './' ?>#<?= $k ?>" data-tile="<?= $k ?>"><?= h($nev) ?></a></li>
<?php endforeach; ?>
    </ul>
  </nav>

  <footer class="site-footer">
    <div class="container">
      <ul class="site-footer__links">
        <li><a href="<?= $gy ?>aszf.html">ÁSZF</a></li>
        <li><a href="<?= $gy ?>adatkezeles.html">Adatkezelési tájékoztató</a></li>
        <li><a href="<?= $gy ?>elallas.html">Elállás a szerződéstől</a></li>
        <li><a href="<?= $gy ?>impresszum.html">Impresszum</a></li>
        <li><a href="<?= $gy ?>kapcsolat.html">Kapcsolat</a></li>
      </ul>
      <p class="site-footer__cim">Csengeri Zsuzsa kineziológus · 2310 Szigetszentmiklós, Szent Miklós útja 18/A/1. · személyesen és online · <a href="https://www.facebook.com/utkozbenkonyv" target="_blank" rel="me noopener">Facebook</a></p>
      <p>© <?= date('Y') ?> Csengeri Zsuzsa – FARO</p>
    </div>
  </footer>

  <script src="<?= $gy ?>assets/js/main.js?v=5" defer></script>
  <script src="<?= $gy ?>assets/js/meres.js?v=1" defer></script>
  <script src="<?= $gy ?>assets/js/konyvespolc.js?v=5" defer></script>
<?php foreach ($o['js'] ?? [] as $js): ?>
  <script src="<?= $gy . $js ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
