<?php
defined('FARO') or exit;

/* =========================================================
   Egy cikk oldala (az admin előnézete is ezt használja)
   ========================================================= */
function blog_cikk_oldal(array $c, string $gy, bool $elonezet): void
{
    $alap = rtrim(faro_config()['site_url'], '/');
    $t = blog_tema($c['tema_id'] ? (int)$c['tema_id'] : null);
    $url = $alap . '/gondolatok/' . $c['slug'];
    $kep = null;
    if ($c['kezdokep'] && ($k = blog_kep($c['kezdokep']))) {
        $kep = $alap . '/feltoltes/blog/' . $c['kezdokep'] . '-' . max($k['meretek']) . '.jpg';
    }
    $leiras = trim((string)($c['meta_leiras'] ?: $c['bevezeto'])) ?: mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$c['tartalom']))), 0, 160);
    oldal_fej([
        'cim' => $c['cim'] . ' – Csengeri Zsuzsa', 'og_cim' => $c['cim'], 'leiras' => $leiras, 'gy' => $gy,
        'aktiv' => 'gondolatok/', 'kanonikus' => $url, 'kep' => $kep, 'tipus' => 'article',
        'fejbe' => '  <meta property="article:published_time" content="' . h(date('c', strtotime($c['megjelenes']))) . '">' . "\n",
        'noindex' => $elonezet,
        'schema' => [array_filter([
            '@type' => 'BlogPosting', '@id' => $url . '#cikk', 'url' => $url, 'mainEntityOfPage' => $url,
            'headline' => $c['cim'], 'description' => $leiras, 'inLanguage' => 'hu',
            'datePublished' => date('c', strtotime($c['megjelenes'])),
            'dateModified' => date('c', strtotime((string)($c['frissitve'] ?: $c['megjelenes']))),
            'image' => $kep, 'articleSection' => $t['nev'] ?? null,
            'wordCount' => count(preg_split('/\s+/u', trim(strip_tags((string)$c['tartalom'])), -1, PREG_SPLIT_NO_EMPTY)) ?: null,
            'author' => ['@id' => $alap . '/#szemely'], 'publisher' => ['@id' => $alap . '/#szemely'],
            'isPartOf' => ['@id' => $alap . '/gondolatok/#blog'],
        ])],
    ]);
    $vissza = $elonezet ? '#' : $gy . 'gondolatok/'; ?>
<?php if ($elonezet): ?>
    <div class="cikk-elonezet" role="status">Előnézet – <?= blog_el($c) ? 'ez a cikk már megjelent.' : ($c['allapot'] === 'kozzeteve' ? 'időzítve: ' . h(blog_datum($c['megjelenes'])) . ' ' . h(date('H:i', strtotime($c['megjelenes']))) . '.' : 'piszkozat, a látogatók még nem látják.') ?></div>
<?php endif; ?>
    <article class="cikk">
      <header class="cikk__fej">
        <div class="container cikk__szuk">
          <p class="section-eyebrow"><a href="<?= h($vissza) ?>" style="text-decoration:none">← Gondolatok, blogok</a></p>
          <h1 class="cikk__cim"><?= h($c['cim']) ?></h1>
          <p class="cikk__meta">
            <time datetime="<?= h(date('Y-m-d', strtotime($c['megjelenes']))) ?>"><?= h(blog_datum($c['megjelenes'])) ?></time>
            <?= $t ? ' · <a href="' . h($vissza) . ($elonezet ? '' : '#' . h($t['kulcs'])) . '">' . h($t['nev']) . '</a>' : '' ?>
            · <?= (int)$c['olvasasi_ido'] ?> perc olvasás
          </p>
<?php if (trim((string)$c['bevezeto']) !== ''): ?>
          <p class="cikk__bevezeto"><?= h($c['bevezeto']) ?></p>
<?php endif; ?>
        </div>
      </header>
<?php if ($c['kezdokep']): ?>
      <figure class="cikk__kezdokep">
        <?php $kk = blog_kep($c['kezdokep']); ?>
        <?php $kicsi = $kk && max($kk['meretek']) < 900; ?>
        <?= blog_kep_html($c['kezdokep'], $gy, (string)$c['kezdokep_alt'], $kicsi ? max($kk['meretek']) . 'px' : '(max-width: 1000px) 100vw, 1000px', $kicsi ? 'kicsi' : '', false) ?>
      </figure>
<?php endif; ?>
      <div class="container cikk__szuk">
        <div class="cikk__szoveg">
<?= blog_megjelenit((string)$c['tartalom'], $gy) ?>
        </div>
        <p class="cikk__szerzo">Csengeri Zsuzsa</p>

        <div class="cikk__lab">
          <p class="cikk__kerdes">Gondolatod vagy kérdésed támadt az írás kapcsán? Írd meg nekem: <a href="mailto:info@farostudio.hu?subject=<?= rawurlencode($c['cim']) ?>">info@farostudio.hu</a></p>
          <div class="cikk__megosztas" aria-label="Megosztás">
            <span>Ha tetszett, oszd meg:</span>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($url) ?>" target="_blank" rel="noopener">Facebook</a>
            <a href="mailto:?subject=<?= rawurlencode($c['cim']) ?>&amp;body=<?= rawurlencode($url) ?>">E-mail</a>
            <button type="button" class="cikk__masol" data-masol="<?= h($url) ?>">Link másolása</button>
          </div>
        </div>
      </div>
    </article>

<?php $hasonlok = blog_hasonlok($c); if ($hasonlok): ?>
    <section class="section-pad cikk-hasonlok" aria-labelledby="hasonlo-cim">
      <div class="container">
        <h2 class="section-title" id="hasonlo-cim">Ez is érdekelhet</h2>
        <div class="posts">
<?php foreach ($hasonlok as $h): $ht = blog_tema($h['tema_id'] ? (int)$h['tema_id'] : null); ?>
          <article class="post post--kep">
            <a class="post__kep" href="<?= $elonezet ? '#' : h($h['slug']) ?>" tabindex="-1" aria-hidden="true">
              <?= $h['kezdokep'] ? blog_kep_html($h['kezdokep'], $gy, '', '(max-width: 640px) 100vw, 380px') : '<img src="' . $gy . 'assets/img/gondolatok-illusztracio.svg" alt="" class="post__kep-helyett" width="420" height="330">' ?>
            </a>
            <div class="post__belso">
              <p class="post__meta"><?= h(blog_datum($h['megjelenes'], false)) ?><?= $ht ? ' · ' . h($ht['nev']) : '' ?></p>
              <h3 class="post__title"><a href="<?= $elonezet ? '#' : h($h['slug']) ?>"><?= h($h['cim']) ?></a></h3>
            </div>
          </article>
<?php endforeach; ?>
        </div>
      </div>
    </section>
<?php endif;
    oldal_lab(['gy' => $gy, 'js' => ['assets/js/yt.js?v=1', 'assets/js/cikk.js?v=1']]);
}
