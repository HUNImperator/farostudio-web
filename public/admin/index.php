<?php
/**
 * FARO admin – rendelések kezelése.
 * Oldalak: ?p=belepes | rendelesek (alap) | rendeles&id= | jelszo | kilepes | telepites
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';
require FARO_APP . '/auth.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

admin_munkamenet();
$p = (string)($_GET['p'] ?? 'rendelesek');
$uzenet = $_SESSION['uzenet'] ?? null;
unset($_SESSION['uzenet']);

function atiranyit(string $hova, ?string $uzenet = null): void
{
    if ($uzenet !== null) {
        $_SESSION['uzenet'] = $uzenet;
    }
    header('Location: ' . $hova);
    exit;
}

/* ---------- első indítás: admin létrehozása ---------- */
if (!admin_van()) {
    $hiba = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_ellenoriz();
        $token = (string)faro_config()['setup_token'];
        $f = trim((string)($_POST['felhasznalo'] ?? ''));
        $j = (string)($_POST['jelszo'] ?? '');
        if ($token === '' || !hash_equals($token, (string)($_POST['token'] ?? ''))) {
            $hiba = 'Hibás telepítési kód.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $f)) {
            $hiba = 'A felhasználónév 3–60 karakter: betű, szám, pont, kötőjel, aláhúzás.';
        } elseif ($e = jelszo_hiba($j, (string)($_POST['jelszo2'] ?? ''))) {
            $hiba = $e;
        } else {
            admin_letrehoz($f, $j);
            atiranyit('./?p=belepes', 'Az admin felhasználó elkészült, most jelentkezz be.');
        }
    }
    oldal('Első indítás', function () use ($hiba) { ?>
        <div class="login">
          <h1>Admin felhasználó létrehozása</h1>
          <p class="muted">Ez az oldal csak egyszer jelenik meg. A telepítési kódot a szerveren lévő faro-config.php fájlban találod (setup_token).</p>
          <?php if ($hiba): ?><p class="alert alert--err"><?= h($hiba) ?></p><?php endif; ?>
          <form method="post" autocomplete="off">
            <?= csrf_mezo() ?>
            <label>Telepítési kód<input name="token" type="password" required></label>
            <label>Felhasználónév<input name="felhasznalo" required pattern="[A-Za-z0-9._\x2D]{3,60}" value="<?= h((string)($_POST['felhasznalo'] ?? '')) ?>"></label>
            <label>Jelszó (legalább 10 karakter)<input name="jelszo" type="password" required minlength="10" autocomplete="new-password"></label>
            <label>Jelszó újra<input name="jelszo2" type="password" required minlength="10" autocomplete="new-password"></label>
            <button class="btn" type="submit">Létrehozás</button>
          </form>
        </div>
    <?php }, false);
    exit;
}

/* ---------- belépés ---------- */
if ($p === 'belepes') {
    $hiba = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_ellenoriz();
        if (belepes_tiltva()) {
            $hiba = 'Túl sok sikertelen próbálkozás. Kérlek, várj ' . BELEPES_ABLAK_PERC . ' percet.';
        } elseif (admin_belep(trim((string)($_POST['felhasznalo'] ?? '')), (string)($_POST['jelszo'] ?? ''))) {
            atiranyit('./');
        } else {
            $hiba = 'Hibás felhasználónév vagy jelszó.';
        }
    }
    oldal('Belépés', function () use ($hiba, $uzenet) { ?>
        <div class="login">
          <h1>Belépés</h1>
          <?php if ($uzenet): ?><p class="alert"><?= h($uzenet) ?></p><?php endif; ?>
          <?php if ($hiba): ?><p class="alert alert--err"><?= h($hiba) ?></p><?php endif; ?>
          <form method="post">
            <?= csrf_mezo() ?>
            <label>Felhasználónév<input name="felhasznalo" required autocomplete="username" autofocus value="<?= h((string)($_POST['felhasznalo'] ?? '')) ?>"></label>
            <label>Jelszó<input name="jelszo" type="password" required autocomplete="current-password"></label>
            <button class="btn" type="submit">Belépés</button>
          </form>
        </div>
    <?php }, false);
    exit;
}

$admin = admin_bejelentkezve();
if (!$admin) {
    atiranyit('./?p=belepes');
}

if ($p === 'kilepes') {
    admin_kilep();
    header('Location: ./?p=belepes');
    exit;
}

/* ---------- jelszócsere ---------- */
if ($p === 'jelszo') {
    $hiba = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_ellenoriz();
        $st = db()->prepare('SELECT jelszo_hash FROM adminok WHERE id = ?');
        $st->execute([$admin['id']]);
        if (!password_verify((string)($_POST['regi'] ?? ''), (string)$st->fetchColumn())) {
            $hiba = 'A jelenlegi jelszó nem megfelelő.';
        } elseif ($e = jelszo_hiba((string)($_POST['uj'] ?? ''), (string)($_POST['uj2'] ?? ''))) {
            $hiba = $e;
        } else {
            admin_jelszo_csere((int)$admin['id'], (string)$_POST['uj']);
            atiranyit('./', 'A jelszó megváltozott.');
        }
    }
    oldal('Jelszócsere', function () use ($hiba) { ?>
        <div class="login">
          <h1>Jelszócsere</h1>
          <?php if ($hiba): ?><p class="alert alert--err"><?= h($hiba) ?></p><?php endif; ?>
          <form method="post">
            <?= csrf_mezo() ?>
            <label>Jelenlegi jelszó<input name="regi" type="password" required autocomplete="current-password"></label>
            <label>Új jelszó (legalább 10 karakter)<input name="uj" type="password" required minlength="10" autocomplete="new-password"></label>
            <label>Új jelszó újra<input name="uj2" type="password" required minlength="10" autocomplete="new-password"></label>
            <button class="btn" type="submit">Mentés</button> <a href="./">Mégse</a>
          </form>
        </div>
    <?php });
    exit;
}

/* ---------- egy rendelés ---------- */
if ($p === 'rendeles') {
    $id = (int)($_GET['id'] ?? 0);
    $r = rendeles_betolt($id);
    if (!$r) {
        atiranyit('./', 'Nincs ilyen rendelés.');
    }
    $ki = $admin['felhasznalo'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_ellenoriz();
        $muvelet = (string)($_POST['muvelet'] ?? '');
        $vissza = './?p=rendeles&id=' . $id;
        switch ($muvelet) {
            case 'fizetve':
                rendeles_allapot($id, 'fizetve', $ki, 'fizetés beérkezett');
                atiranyit($vissza, 'Fizetettnek jelölve.');
            case 'feladva':
                $csomag = mb_substr(trim((string)($_POST['csomagszam'] ?? '')), 0, 50);
                if ($csomag !== '') {
                    db()->prepare('UPDATE rendelesek SET foxpost_csomagszam = ? WHERE id = ?')->execute([$csomag, $id]);
                }
                rendeles_allapot($id, 'feladva', $ki, $csomag !== '' ? 'csomagszám: ' . $csomag : '');
                atiranyit($vissza, 'Feladottnak jelölve.');
            case 'teljesitve':
                rendeles_allapot($id, 'teljesitve', $ki);
                atiranyit($vissza, 'Teljesítettnek jelölve.');
            case 'lemondva':
                rendeles_allapot($id, 'lemondva', $ki);
                atiranyit($vissza, 'Lemondottnak jelölve.');
            case 'allapot':
                $uj = (string)($_POST['allapot'] ?? '');
                if (isset(ALLAPOTOK[$uj])) {
                    rendeles_allapot($id, $uj, $ki, 'kézi módosítás');
                }
                atiranyit($vissza, 'Állapot módosítva.');
            case 'megjegyzes':
                $m = mb_substr(trim((string)($_POST['admin_megjegyzes'] ?? '')), 0, 2000);
                db()->prepare('UPDATE rendelesek SET admin_megjegyzes = ?, frissitve = ? WHERE id = ?')->execute([$m, most(), $id]);
                naplo($id, 'Belső megjegyzés módosítva.', $ki);
                atiranyit($vissza, 'Megjegyzés mentve.');
            case 'visszaigazolas':
                $ok = level_vevo_visszaigazolas($r);
                naplo($id, $ok ? 'Visszaigazoló e-mail újraküldve.' : 'A visszaigazoló e-mail újraküldése NEM sikerült.', $ki);
                atiranyit($vissza, $ok ? 'A visszaigazoló e-mailt újraküldtem.' : 'Az e-mail küldése nem sikerült.');
            case 'torles':
                if (($_POST['megerosit'] ?? '') !== $r['rendelesszam']) {
                    atiranyit($vissza, 'A törléshez írd be pontosan a rendelésszámot.');
                }
                rendeles_torol($id);
                atiranyit('./', 'A(z) ' . $r['rendelesszam'] . ' rendelés véglegesen törölve.');
        }
        atiranyit($vissza);
    }

    $naplo = rendeles_naplo($id);
    oldal($r['rendelesszam'], function () use ($r, $naplo, $uzenet) {
        $db = array_sum(array_column($r['tetelek'], 'db')); ?>
        <p><a href="./">← Vissza a rendelésekhez</a></p>
        <?php if ($uzenet): ?><p class="alert"><?= h($uzenet) ?></p><?php endif; ?>
        <div class="order-head">
          <div>
            <h1><?= h($r['rendelesszam']) ?></h1>
            <p class="muted"><?= h(datum($r['letrehozva'])) ?> · <?= h(FIZETESI_MODOK[$r['fizetes']] ?? $r['fizetes']) ?> · <?= $db ?> kötet
              <?php if ($r['fizetve_ekkor']): ?> · fizetve: <?= h(datum($r['fizetve_ekkor'])) ?><?php endif; ?></p>
            <?php if ($r['stripe_session']): ?><p class="muted small">Stripe fizetési azonosító: <?= h($r['stripe_session']) ?></p><?php endif; ?>
          </div>
          <span class="badge badge--<?= h($r['allapot']) ?>"><?= h(allapot_nev($r['allapot'])) ?></span>
        </div>

        <div class="grid">
          <section class="card">
            <h2>Teendők</h2>
            <div class="actions">
              <?php if (in_array($r['allapot'], ['uj', 'fizetesre_var'], true) && $r['fizetes'] === 'kartya'): ?>
                <p class="muted small" style="flex-basis:100%;margin:0">Bankkártyás rendelés: a „Fizetve” állapotot a Stripe automatikusan beállítja a sikeres fizetés után. Ha a vevő nem fizetett, a rendelést törölheted.</p>
              <?php endif; ?>
              <?php if (in_array($r['allapot'], ['uj', 'fizetesre_var'], true)): ?>
                <form method="post"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="fizetve">
                  <button class="btn">Fizetés beérkezett → Fizetve</button></form>
              <?php endif; ?>
              <?php if (in_array($r['allapot'], ['fizetve', 'csomag_letrehozva'], true)): ?>
                <form method="post" class="inline-form"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="feladva">
                  <input name="csomagszam" placeholder="Foxpost csomagszám (nem kötelező)" value="<?= h($r['foxpost_csomagszam']) ?>">
                  <button class="btn">Feladva</button></form>
              <?php endif; ?>
              <?php if ($r['allapot'] === 'feladva'): ?>
                <form method="post"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="teljesitve">
                  <button class="btn">Teljesítve</button></form>
              <?php endif; ?>
              <form method="post"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="visszaigazolas">
                <button class="btn btn--light">Visszaigazoló e-mail újraküldése</button></form>
              <?php if ($r['allapot'] !== 'lemondva'): ?>
                <form method="post"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="lemondva">
                  <button class="btn btn--light">Lemondás</button></form>
              <?php endif; ?>
            </div>
            <p class="muted small">A számla (Számlázz.hu) és a Foxpost-csomag automatikus létrehozása a következő fejlesztési lépésben kerül ide.</p>
            <details class="more">
              <summary>Állapot kézi módosítása</summary>
              <form method="post" class="inline-form"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="allapot">
                <select name="allapot"><?php foreach (ALLAPOTOK as $k => $n): ?>
                  <option value="<?= h($k) ?>"<?= $k === $r['allapot'] ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
                <button class="btn btn--light">Mentés</button></form>
            </details>
          </section>

          <section class="card">
            <h2>Vevő</h2>
            <p><strong><?= h($r['vevo_nev']) ?></strong><br>
              <a href="mailto:<?= h($r['vevo_email']) ?>"><?= h($r['vevo_email']) ?></a><br>
              <a href="tel:<?= h($r['vevo_telefon']) ?>"><?= h($r['vevo_telefon']) ?></a></p>
            <h3>Számlázás</h3>
            <p><?= h($r['szamla_nev']) ?><br><?= h($r['szamla_irsz'] . ' ' . $r['szamla_telepules']) ?><br><?= h($r['szamla_cim']) ?>
              <?php if ($r['szamla_adoszam']): ?><br>Adószám: <?= h($r['szamla_adoszam']) ?><?php endif; ?></p>
            <h3>Foxpost-automata</h3>
            <p><?= h($r['apt_nev']) ?><br><?= h($r['apt_cim']) ?><br><span class="muted small">Azonosító: <?= h($r['apt_id']) ?></span>
              <?php if ($r['foxpost_csomagszam']): ?><br>Csomagszám: <strong><?= h($r['foxpost_csomagszam']) ?></strong><?php endif; ?></p>
          </section>
        </div>

        <section class="card">
          <h2>Könyvek és dedikálás</h2>
          <table class="table">
            <thead><tr><th>Könyv</th><th>Dedikálás</th><th class="num">Db</th><th class="num">Egységár</th><th class="num">Összeg</th></tr></thead>
            <tbody>
            <?php foreach ($r['tetelek'] as $t): ?>
              <tr><td><?= h($t['cim']) ?></td><td class="dedik"><?= $t['dedikalas'] ? nl2br(h($t['dedikalas'])) : '<span class="muted">—</span>' ?></td>
                <td class="num"><?= (int)$t['db'] ?></td><td class="num"><?= h(ft((int)$t['egysegar'])) ?></td><td class="num"><?= h(ft($t['egysegar'] * $t['db'])) ?></td></tr>
            <?php endforeach; ?>
              <tr><td colspan="4">Foxpost csomagautomata</td><td class="num"><?= h(ft((int)$r['szallitas'])) ?></td></tr>
              <tr class="total"><td colspan="4">Összesen</td><td class="num"><?= h(ft((int)$r['vegosszeg'])) ?></td></tr>
            </tbody>
          </table>
          <?php if ($r['megjegyzes']): ?><h3>A vevő megjegyzése</h3><p class="note"><?= nl2br(h($r['megjegyzes'])) ?></p><?php endif; ?>
        </section>

        <div class="grid">
          <section class="card">
            <h2>Belső megjegyzés</h2>
            <form method="post"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="megjegyzes">
              <textarea name="admin_megjegyzes" rows="4" placeholder="Csak te látod."><?= h($r['admin_megjegyzes']) ?></textarea>
              <button class="btn btn--light">Mentés</button></form>
          </section>
          <section class="card">
            <h2>Napló</h2>
            <ul class="log"><?php foreach ($naplo as $n): ?>
              <li><span class="muted small"><?= h(datum($n['ido'])) ?> · <?= h($n['szerzo']) ?></span><br><?= h($n['esemeny']) ?></li>
            <?php endforeach; ?></ul>
          </section>
        </div>

        <section class="card card--danger">
          <h2>Rendelés törlése</h2>
          <p class="muted">A törlés végleges, nem vonható vissza. Megerősítésként írd be a rendelésszámot: <strong><?= h($r['rendelesszam']) ?></strong></p>
          <form method="post" class="inline-form"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="torles">
            <input name="megerosit" placeholder="<?= h($r['rendelesszam']) ?>" autocomplete="off">
            <button class="btn btn--danger">Végleges törlés</button></form>
        </section>
    <?php });
    exit;
}

/* ---------- rendelések listája ---------- */
$szuro = (string)($_GET['allapot'] ?? 'aktiv');
$kereses = trim((string)($_GET['q'] ?? ''));
$where = [];
$param = [];
if ($szuro === 'aktiv') {
    $where[] = "allapot IN ('uj','fizetesre_var','fizetve','csomag_letrehozva')";
} elseif (isset(ALLAPOTOK[$szuro])) {
    $where[] = 'allapot = ?';
    $param[] = $szuro;
}
if ($kereses !== '') {
    $where[] = '(rendelesszam LIKE ? OR vevo_nev LIKE ? OR vevo_email LIKE ?)';
    $q = '%' . $kereses . '%';
    array_push($param, $q, $q, $q);
}
$sql = 'SELECT r.*, (SELECT SUM(db) FROM rendeles_tetelek t WHERE t.rendeles_id = r.id) AS kotetek FROM rendelesek r'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.id DESC LIMIT 200';
$st = db()->prepare($sql);
$st->execute($param);
$lista = $st->fetchAll();
$szamok = [];
foreach (db()->query('SELECT allapot, COUNT(*) AS n FROM rendelesek GROUP BY allapot')->fetchAll() as $s) {
    $szamok[$s['allapot']] = (int)$s['n'];
}
$aktivDb = ($szamok['uj'] ?? 0) + ($szamok['fizetesre_var'] ?? 0) + ($szamok['fizetve'] ?? 0) + ($szamok['csomag_letrehozva'] ?? 0);

oldal('Rendelések', function () use ($lista, $szuro, $kereses, $szamok, $aktivDb, $uzenet) { ?>
    <?php if ($uzenet): ?><p class="alert"><?= h($uzenet) ?></p><?php endif; ?>
    <div class="list-head">
      <h1>Rendelések</h1>
      <form method="get" class="search">
        <input type="hidden" name="allapot" value="<?= h($szuro) ?>">
        <input name="q" value="<?= h($kereses) ?>" placeholder="Keresés: rendelésszám, név, e-mail" aria-label="Keresés">
        <button class="btn btn--light">Keresés</button>
      </form>
    </div>
    <nav class="tabs" aria-label="Szűrés állapot szerint">
      <a href="./?allapot=aktiv" class="<?= $szuro === 'aktiv' ? 'is-on' : '' ?>">Teendő <span><?= $aktivDb ?></span></a>
      <?php foreach (ALLAPOTOK as $k => $n): ?>
        <a href="./?allapot=<?= h($k) ?>" class="<?= $szuro === $k ? 'is-on' : '' ?>"><?= h($n) ?> <span><?= $szamok[$k] ?? 0 ?></span></a>
      <?php endforeach; ?>
      <a href="./?allapot=osszes" class="<?= $szuro === 'osszes' ? 'is-on' : '' ?>">Összes</a>
    </nav>
    <?php if (!$lista): ?>
      <p class="empty">Nincs megjeleníthető rendelés.</p>
    <?php else: ?>
    <table class="table table--list">
      <thead><tr><th>Rendelésszám</th><th>Dátum</th><th>Vevő</th><th class="num">Kötet</th><th class="num">Összeg</th><th>Fizetés</th><th>Állapot</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $r): ?>
        <tr onclick="location.href='./?p=rendeles&amp;id=<?= (int)$r['id'] ?>'">
          <td><a href="./?p=rendeles&amp;id=<?= (int)$r['id'] ?>"><strong><?= h($r['rendelesszam']) ?></strong></a></td>
          <td><?= h(datum($r['letrehozva'])) ?></td>
          <td><?= h($r['vevo_nev']) ?><br><span class="muted small"><?= h($r['vevo_email']) ?></span></td>
          <td class="num"><?= (int)$r['kotetek'] ?></td>
          <td class="num"><?= h(ft((int)$r['vegosszeg'])) ?></td>
          <td><?= $r['fizetes'] === 'kartya' ? 'Kártya' : 'Átutalás' ?></td>
          <td><span class="badge badge--<?= h($r['allapot']) ?>"><?= h(allapot_nev($r['allapot'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
<?php });

/* ---------- közös keret ---------- */
function datum(?string $d): string
{
    if (!$d) return '';
    $t = strtotime($d);
    return $t ? date('Y. m. d. H:i', $t) : $d;
}

function oldal(string $cim, callable $tartalom, bool $menu = true): void
{
    global $admin;
    header('Content-Type: text/html; charset=utf-8'); ?>
<!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= h($cim) ?> – FARO admin</title>
  <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="admin.css?v=1">
</head>
<body>
  <header class="top">
    <a class="brand" href="./"><img src="../assets/img/logo-faro-jel.svg" alt="" width="36" height="36"> FARO <span>admin</span></a>
    <?php if ($menu && !empty($admin)): ?>
    <nav>
      <a href="./">Rendelések</a>
      <a href="../" target="_blank" rel="noopener">Weboldal ↗</a>
      <a href="./?p=jelszo">Jelszócsere</a>
      <a href="./?p=kilepes">Kilépés (<?= h($admin['felhasznalo']) ?>)</a>
    </nav>
    <?php endif; ?>
  </header>
  <main class="wrap"><?php $tartalom(); ?></main>
</body>
</html>
<?php }
