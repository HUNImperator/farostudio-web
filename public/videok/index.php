<?php
/**
 * One Brain® videós oldal – belépés, saját sorozatok, lejátszó, hosszabbítás.
 *   (alap)            belépés / saját sorozatok
 *   ?p=nezo&s=…&v=NN  lejátszó
 *   ?p=jelszo         jelszócsere
 *   ?p=elfelejtett    jelszó-visszaállítás kérése
 *   ?p=uj-jelszo&t=…  új jelszó megadása (levélben kapott linkről)
 *   ?p=kilep          kilépés
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';
require FARO_APP . '/video_munkamenet.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$p = (string)($_GET['p'] ?? '');
$f = video_bejelentkezve($mashol);
$uzenet = null;
$hiba = null;

function ide(string $hova = './'): void
{
    header('Location: ' . $hova);
    exit;
}

/* ---------- kilépés ---------- */
if ($p === 'kilep') {
    video_kilep($f);
    ide('./?kilepve=1');
}

/* ---------- jelszó-visszaállítás kérése ---------- */
if ($p === 'elfelejtett') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if (video_belepes_tiltva()) {
            $hiba = 'Túl sok próbálkozás. Kérlek, próbáld újra negyedóra múlva.';
        } else {
            $ff = video_felhasznalo_email($email);
            db()->prepare('INSERT INTO belepesi_kiserletek (ip, ido, sikeres) VALUES (?,?,0)')->execute(['v:' . kliens_ip(), most()]);
            if ($ff) {
                $token = bin2hex(random_bytes(24));
                db()->prepare('UPDATE video_felhasznalok SET jelszo_token = ?, jelszo_token_lejar = ? WHERE id = ?')
                    ->execute([hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600), $ff['id']]);
                level_video_jelszo_link($ff, video_url('?p=uj-jelszo&t=' . $ff['id'] . '.' . $token));
            }
            $uzenet = 'Ha ezzel az e-mail-címmel van hozzáférésed, néhány percen belül levelet kapsz egy linkkel, amellyel új jelszót adhatsz meg.';
        }
    }
    videok_oldal('Elfelejtett jelszó', function () use ($uzenet, $hiba) { ?>
      <section class="request-hero"><div class="container request__container">
        <p class="section-eyebrow"><a href="./" style="text-decoration:none">← Vissza a belépéshez</a></p>
        <h1 class="checkout__title">Elfelejtett jelszó</h1>
        <p class="request__intro">Add meg az e-mail-címed, és küldök egy linket, amellyel új jelszót állíthatsz be.</p>
      </div></section>
      <section class="request"><div class="container video-narrow">
        <?php if ($uzenet): ?><p class="order-msg"><?= h($uzenet) ?></p><?php endif; ?>
        <?php if ($hiba): ?><p class="order-msg order-msg--error"><?= h($hiba) ?></p><?php endif; ?>
        <?php if (!$uzenet): ?>
        <form method="post" class="video-form">
          <div class="field"><label for="email">E-mail-cím</label><input id="email" name="email" type="email" autocomplete="email" required></div>
          <button class="btn btn--dark" type="submit">Link küldése&nbsp;<span class="btn__arrow" aria-hidden="true">→</span></button>
        </form>
        <?php endif; ?>
      </div></section>
    <?php });
    exit;
}

/* ---------- új jelszó a levélben kapott linkről ---------- */
if ($p === 'uj-jelszo') {
    $t = (string)($_GET['t'] ?? '');
    $ff = null;
    if (preg_match('/^(\d+)\.([a-f0-9]{48})$/', $t, $m)) {
        $x = video_felhasznalo((int)$m[1]);
        if ($x && $x['jelszo_token'] && hash_equals($x['jelszo_token'], hash('sha256', $m[2])) && strtotime((string)$x['jelszo_token_lejar']) > time()) {
            $ff = $x;
        }
    }
    if ($ff && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $uj = (string)($_POST['uj'] ?? '');
        if (mb_strlen($uj) < 8) {
            $hiba = 'A jelszó legalább 8 karakter legyen.';
        } elseif ($uj !== (string)($_POST['uj2'] ?? '')) {
            $hiba = 'A két jelszó nem egyezik.';
        } else {
            db()->prepare('UPDATE video_felhasznalok SET jelszo_hash = ?, jelszo_token = NULL, munkamenet = NULL WHERE id = ?')
                ->execute([password_hash($uj, PASSWORD_DEFAULT), $ff['id']]);
            video_belep($ff['email'], $uj);
            ide('./?uzenet=jelszo');
        }
    }
    videok_oldal('Új jelszó', function () use ($ff, $hiba) { ?>
      <section class="request-hero"><div class="container request__container">
        <h1 class="checkout__title">Új jelszó</h1>
      </div></section>
      <section class="request"><div class="container video-narrow">
        <?php if (!$ff): ?>
          <p class="order-msg order-msg--error">Ez a link lejárt vagy már felhasználták. <a href="./?p=elfelejtett">Kérj újat.</a></p>
        <?php else: ?>
          <?php if ($hiba): ?><p class="order-msg order-msg--error"><?= h($hiba) ?></p><?php endif; ?>
          <form method="post" class="video-form">
            <div class="field"><label for="uj">Új jelszó <span class="opt">(legalább 8 karakter)</span></label><input id="uj" name="uj" type="password" autocomplete="new-password" minlength="8" required></div>
            <div class="field"><label for="uj2">Új jelszó még egyszer</label><input id="uj2" name="uj2" type="password" autocomplete="new-password" minlength="8" required></div>
            <button class="btn btn--dark" type="submit">Mentés és belépés&nbsp;<span class="btn__arrow" aria-hidden="true">→</span></button>
          </form>
        <?php endif; ?>
      </div></section>
    <?php });
    exit;
}

/* ---------- belépés ---------- */
if (!$f) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (video_belepes_tiltva()) {
            $hiba = 'Túl sok sikertelen próbálkozás. Kérlek, próbáld újra negyedóra múlva.';
        } elseif (video_belep((string)($_POST['email'] ?? ''), (string)($_POST['jelszo'] ?? ''))) {
            $vissza = (string)($_POST['vissza'] ?? '');
            ide(preg_match('/^\?p=nezo&s=[a-z0-9\-]+&v=\d{2}$/', $vissza) ? './' . $vissza : './');
        } else {
            $hiba = 'Hibás e-mail-cím vagy jelszó.';
        }
    }
    $vissza = $p === 'nezo' ? '?p=nezo&s=' . (string)($_GET['s'] ?? '') . '&v=' . (string)($_GET['v'] ?? '') : '';
    videok_oldal('Belépés – One Brain® videók', function () use ($hiba, $mashol, $vissza) { ?>
      <section class="request-hero"><div class="container request__container">
        <p class="section-eyebrow"><a href="../oktato-videok.html" style="text-decoration:none">← One Brain® oktató videók</a></p>
        <h1 class="checkout__title">Belépés a videókhoz</h1>
        <p class="request__intro">A belépési adatokat a jóváhagyás után e-mailben küldtem. Egyszerre egy eszközön lehetsz bejelentkezve.</p>
      </div></section>
      <section class="request"><div class="container video-narrow">
        <?php if (isset($_GET['kilepve'])): ?><p class="order-msg">Kiléptél. Várlak vissza!</p><?php endif; ?>
        <?php if ($mashol && !$hiba): ?><p class="order-msg order-msg--error">Másik eszközön (vagy böngészőben) beléptél, ezért itt kiléptettelek. Ha nem te voltál, kérj új jelszót.</p><?php endif; ?>
        <?php if ($hiba): ?><p class="order-msg order-msg--error"><?= h($hiba) ?></p><?php endif; ?>
        <form method="post" action="./" class="video-form">
          <input type="hidden" name="vissza" value="<?= h($vissza) ?>">
          <div class="field"><label for="email">E-mail-cím</label><input id="email" name="email" type="email" autocomplete="username" required value="<?= h((string)($_POST['email'] ?? '')) ?>"></div>
          <div class="field"><label for="jelszo">Jelszó</label><input id="jelszo" name="jelszo" type="password" autocomplete="current-password" required></div>
          <button class="btn btn--dark" type="submit">Belépés&nbsp;<span class="btn__arrow" aria-hidden="true">→</span></button>
          <p class="video-form__links"><a href="./?p=elfelejtett">Elfelejtettem a jelszavam</a></p>
        </form>
        <p class="video-form__note">Még nincs hozzáférésed? <a href="../oktato-videok.html">Válassz videósorozatot →</a></p>
      </div></section>
    <?php });
    exit;
}

/* ---------- jelszócsere ---------- */
if ($p === 'jelszo') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $uj = (string)($_POST['uj'] ?? '');
        if (!video_csrf_ok()) {
            $hiba = 'Lejárt az űrlap, kérlek, próbáld újra.';
        } elseif (!password_verify((string)($_POST['regi'] ?? ''), $f['jelszo_hash'])) {
            $hiba = 'A jelenlegi jelszó nem megfelelő.';
        } elseif (mb_strlen($uj) < 8) {
            $hiba = 'Az új jelszó legalább 8 karakter legyen.';
        } elseif ($uj !== (string)($_POST['uj2'] ?? '')) {
            $hiba = 'A két új jelszó nem egyezik.';
        } else {
            db()->prepare('UPDATE video_felhasznalok SET jelszo_hash = ? WHERE id = ?')->execute([password_hash($uj, PASSWORD_DEFAULT), $f['id']]);
            ide('./?uzenet=jelszo');
        }
    }
    videok_oldal('Jelszócsere', function () use ($hiba) { ?>
      <section class="request-hero"><div class="container request__container">
        <p class="section-eyebrow"><a href="./" style="text-decoration:none">← Videóim</a></p>
        <h1 class="checkout__title">Jelszócsere</h1>
      </div></section>
      <section class="request"><div class="container video-narrow">
        <?php if ($hiba): ?><p class="order-msg order-msg--error"><?= h($hiba) ?></p><?php endif; ?>
        <form method="post" class="video-form">
          <input type="hidden" name="csrf" value="<?= h(video_csrf()) ?>">
          <div class="field"><label for="regi">Jelenlegi jelszó</label><input id="regi" name="regi" type="password" autocomplete="current-password" required></div>
          <div class="field"><label for="uj">Új jelszó <span class="opt">(legalább 8 karakter)</span></label><input id="uj" name="uj" type="password" autocomplete="new-password" minlength="8" required></div>
          <div class="field"><label for="uj2">Új jelszó még egyszer</label><input id="uj2" name="uj2" type="password" autocomplete="new-password" minlength="8" required></div>
          <button class="btn btn--dark" type="submit">Mentés&nbsp;<span class="btn__arrow" aria-hidden="true">→</span></button>
        </form>
      </div></section>
    <?php });
    exit;
}

$sajat = video_sajat_hozzaferesek($f['email']);

/* ---------- lejátszó ---------- */
if ($p === 'nezo') {
    $sid = (string)($_GET['s'] ?? '');
    $s = video_sorozat($sid);
    $h = $s ? video_nezheti($f, $sid) : null;
    if (!$s || !$h) {
        ide('./');
    }
    $n = max(1, min(count($s['videok']), (int)($_GET['v'] ?? 1)));
    $v = str_pad((string)$n, 2, '0', STR_PAD_LEFT);
    $feltoltve = is_file(video_mappa() . '/' . $sid . '/' . $v . '/index.m3u8');
    videok_oldal($s['videok'][$n - 1] . ' – ' . $s['rovidCim'], function () use ($s, $sid, $h, $n, $v, $f, $feltoltve) {
        $hatra = video_hatra_nap($h); ?>
      <section class="watch">
        <div class="container">
          <p class="section-eyebrow"><a href="./" style="text-decoration:none">← Videóim</a></p>
          <div class="watch__head">
            <div>
              <h1 class="watch__title"><?= h($s['rovidCim']) ?> <span><?= h($s['alcim']) ?></span></h1>
            </div>
            <p class="watch__access<?= $hatra <= 10 ? ' is-soon' : '' ?>">Hozzáférés: még <strong><?= $hatra ?> nap</strong> (<?= h(video_datum($h['lejarat'])) ?>)</p>
          </div>
          <div class="watch__grid">
            <div>
              <?php if ($feltoltve): ?>
              <div class="player" data-player data-src="media.php?s=<?= h(rawurlencode($sid)) ?>&amp;v=<?= h($v) ?>" data-wm="<?= h($f['email']) ?>" data-kulcs="<?= h($sid . '-' . $v) ?>">
                <video playsinline controls controlslist="nodownload nofullscreen noremoteplayback" disablepictureinpicture preload="metadata"></video>
                <div class="player__wm" aria-hidden="true"></div>
                <button class="player__fs" type="button" aria-label="Teljes képernyő" title="Teljes képernyő">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                </button>
                <p class="player__msg" hidden></p>
              </div>
              <?php else: ?>
              <div class="player player--empty"><p>Ez a videó még feltöltés alatt áll – hamarosan itt lesz.</p></div>
              <?php endif; ?>
              <div class="watch__now">
                <p class="watch__num"><?= $n ?> / <?= count($s['videok']) ?></p>
                <h2><?= h($s['videok'][$n - 1]) ?></h2>
                <div class="watch__nav">
                  <?php if ($n > 1): ?><a class="btn btn--light-line" href="./?p=nezo&amp;s=<?= h($sid) ?>&amp;v=<?= $n - 1 ?>">← Előző</a><?php endif; ?>
                  <?php if ($n < count($s['videok'])): ?><a class="btn btn--dark" href="./?p=nezo&amp;s=<?= h($sid) ?>&amp;v=<?= $n + 1 ?>">Következő videó&nbsp;<span class="btn__arrow" aria-hidden="true">→</span></a><?php endif; ?>
                </div>
              </div>
            </div>
            <nav class="watch__list" aria-label="A sorozat videói">
              <ol>
                <?php foreach ($s['videok'] as $i => $cim): $k = str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT); ?>
                  <li><a href="./?p=nezo&amp;s=<?= h($sid) ?>&amp;v=<?= $i + 1 ?>" data-kulcs="<?= h($sid . '-' . $k) ?>"<?= $i + 1 === $n ? ' aria-current="true"' : '' ?>>
                    <span class="watch__i"><?= $i + 1 ?></span><span><?= h($cim) ?></span></a></li>
                <?php endforeach; ?>
              </ol>
            </nav>
          </div>
        </div>
      </section>
    <?php }, true);
    exit;
}

/* ---------- saját sorozatok ---------- */
if (isset($_GET['uzenet']) && $_GET['uzenet'] === 'jelszo') {
    $uzenet = 'A jelszavad megváltozott.';
}
videok_oldal('Videóim – One Brain®', function () use ($f, $sajat, $uzenet) {
    $sorozatok = video_sorozatok();
    $tovabbi = array_filter($sorozatok, function ($s) use ($sajat) { return !isset($sajat[$s['id']]); }); ?>
  <section class="request-hero"><div class="container">
    <?php $reszek = preg_split('/\s+/', trim($f['nev'])); ?>
    <h1 class="checkout__title">Szia, <?= h(count($reszek) > 1 ? $reszek[1] : $reszek[0]) ?>!</h1>
    <p class="request__intro">Itt találod a One Brain® videósorozataidat. A videókat a saját tempódban nézheted; ha lejárna a hozzáférésed, egy kattintással meghosszabbíthatod.</p>
  </div></section>

  <section class="request"><div class="container">
    <?php if ($uzenet): ?><p class="order-msg"><?= h($uzenet) ?></p><?php endif; ?>
    <h2 class="howto__title">Videósorozataim</h2>
    <?php if (!$sajat): ?><p class="muted">Még nincs videósorozatod.</p><?php endif; ?>
    <div class="series-grid">
    <?php foreach ($sorozatok as $s): if (!isset($sajat[$s['id']])) continue; $h = $sajat[$s['id']]; $a = video_allapot($h); $hatra = video_hatra_nap($h); ?>
      <article class="series-card series-card--<?= h($a) ?>">
        <div class="series-card__cover" aria-hidden="true"><span>One Brain®</span><strong><?= h($s['romai']) ?></strong></div>
        <div class="series-card__body">
          <h3><?= h($s['rovidCim']) ?></h3>
          <p class="series-card__sub"><?= h($s['alcim']) ?> · <?= count($s['videok']) ?> videó</p>
          <?php if ($a === 'aktiv'): ?>
            <p class="series-card__state<?= $hatra <= 10 ? ' is-soon' : '' ?>">Még <strong><?= $hatra ?> nap</strong> · <?= h(video_datum_ig($h['lejarat'])) ?></p>
            <div class="series-card__bar"><span style="width:<?= max(3, min(100, round($hatra / video_nap() * 100))) ?>%"></span></div>
            <div class="series-card__actions">
              <a class="btn btn--dark" href="./?p=nezo&amp;s=<?= h($s['id']) ?>&amp;v=1" data-folytat="<?= h($s['id']) ?>">Megnézem&nbsp;<span class="btn__arrow" aria-hidden="true">→</span></a>
              <button class="btn btn--light-line" type="button" data-hosszabbit="<?= h($s['id']) ?>">Meghosszabbítom +<?= video_nap() ?> nap · <?= h(ft((int)$s['ar'])) ?></button>
            </div>
          <?php elseif ($a === 'lejart'): ?>
            <p class="series-card__state is-over">Lejárt: <?= h(video_datum($h['lejarat'])) ?></p>
            <div class="series-card__actions">
              <button class="btn btn--dark" type="button" data-hosszabbit="<?= h($s['id']) ?>">Meghosszabbítom +<?= video_nap() ?> nap · <?= h(ft((int)$s['ar'])) ?></button>
            </div>
          <?php else: ?>
            <p class="series-card__state">Jóváhagyásra vár – amint ellenőriztem az igazolásodat, megnyitom.</p>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    </div>

    <?php if ($tovabbi): ?>
    <h2 class="howto__title" style="margin-top:56px">További sorozatok</h2>
    <p class="muted" style="margin:-8px 0 22px;max-width:70ch">Ha elvégezted a következő modult is, itt megrendelheted a sorozatát. A rendeléskor ehhez is töltsd fel az igazolást; a jóváhagyás után ugyanezzel a belépéssel éred el, saját <?= video_nap() ?> napos hozzáféréssel.</p>
    <div class="series-grid">
    <?php foreach ($tovabbi as $s): ?>
      <article class="series-card series-card--uj">
        <div class="series-card__cover" aria-hidden="true"><span>One Brain®</span><strong><?= h($s['romai']) ?></strong></div>
        <div class="series-card__body">
          <h3><?= h($s['rovidCim']) ?></h3>
          <p class="series-card__sub"><?= h($s['alcim']) ?> · <?= count($s['videok']) ?> videó</p>
          <div class="series-card__actions">
            <button class="btn" type="button" data-megrendel="<?= h($s['id']) ?>">Megrendelem · <?= h(ft((int)$s['ar'])) ?></button>
            <a class="series-card__more" href="../oktato-videok.html#<?= h($s['id']) ?>">A sorozat videói</a>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div></section>
<?php });

/* ---------- közös keret ---------- */
function videok_oldal(string $cim, callable $tartalom, bool $lejatszo = false): void
{
    global $f;
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store'); ?>
<!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= h($cim) ?> | Csengeri Zsuzsa</title>
  <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="../assets/css/style.css?v=26">
</head>
<body data-nincs-panel<?= $f ? ' data-vevo-email="' . h($f['email']) . '" data-vevo-nev="' . h($f['nev']) . '"' : '' ?>>
  <header class="site-header video-header">
    <div class="container site-header__inner">
      <a class="logo" href="../" aria-label="FARO – Csengeri Zsuzsa, kezdőlap">
        <img class="logo__jel" src="../assets/img/logo-faro-jel.svg" alt="" width="58" height="58">
        <span class="logo__text"><span class="logo__mark">FARO</span><span class="logo__sub">One Brain® videók</span></span>
      </a>
      <?php if ($f): ?>
      <nav class="video-nav" aria-label="Videós oldal">
        <a href="./">Videóim</a>
        <a href="./?p=jelszo">Jelszócsere</a>
        <a href="./?p=kilep">Kilépés</a>
      </nav>
      <?php endif; ?>
    </div>
  </header>
  <main id="tartalom"><?php $tartalom(); ?></main>
  <footer class="site-footer">
    <div class="container">
      <ul class="site-footer__links">
        <li><a href="../aszf.html">ÁSZF</a></li>
        <li><a href="../adatkezeles.html">Adatkezelési tájékoztató</a></li>
        <li><a href="../elallas.html">Elállás a szerződéstől</a></li>
        <li><a href="../impresszum.html">Impresszum</a></li>
      </ul>
      <p>© <?= date('Y') ?> Csengeri Zsuzsa – FARO · <a href="mailto:info@farostudio.hu">info@farostudio.hu</a></p>
    </div>
  </footer>
  <script src="../assets/js/meres.js?v=1" defer></script>
  <script src="../assets/js/konyvespolc.js?v=5" defer></script>
  <?php if ($lejatszo): ?><script src="../assets/vendor/hls/hls.min.js?v=1.6.13" defer></script><?php endif; ?>
  <script src="../assets/js/videok.js?v=2" defer></script>
</body>
</html>
<?php }
