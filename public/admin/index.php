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

/* ---------- látogatottság ---------- */
if ($p === 'statisztika') {
    require FARO_APP . '/admin_statisztika.php';
    admin_statisztika_oldal();
    exit;
}

/* ---------- külső értesítések naplója (Stripe) ---------- */
if ($p === 'ertesitesek') {
    $sorok = db()->query('SELECT * FROM ertesites_naplo ORDER BY id DESC LIMIT 100')->fetchAll();
    oldal('Stripe-értesítések', function () use ($sorok) { ?>
        <h1>Stripe-értesítések</h1>
        <p class="muted">A Stripe által a szervernek küldött értesítések és a szerver válasza (az utolsó 100, 90 napig őrizzük). Ha egy fizetés után a rendelés nem lett „Fizetve”, itt látszik az ok.</p>
        <?php if (!$sorok): ?>
          <p class="empty">Még nem érkezett értesítés a Stripe-tól. Ha már fizettél teszt kártyával, a Stripe nem küldte el az értesítést: ellenőrizd a Stripe-ban a webhook címét, az eseményeket, és hogy ugyanabban a módban (teszt/éles) készült-e, mint a kulcs.</p>
        <?php else: ?>
        <table class="table">
          <thead><tr><th>Időpont</th><th>Esemény</th><th>Rendelés</th><th class="num">Válasz</th><th>Eredmény</th></tr></thead>
          <tbody>
          <?php foreach ($sorok as $w): ?>
            <tr>
              <td style="white-space:nowrap"><?= h(datum($w['ido'])) ?></td>
              <td><?= h($w['esemeny'] ?: '—') ?></td>
              <td><?= $w['rendeles_id'] ? '<a href="./?p=rendeles&amp;id=' . (int)$w['rendeles_id'] . '">megnyitás</a>' : '—' ?></td>
              <td class="num"><span class="badge <?= (int)$w['kod'] >= 400 ? 'badge--lemondva' : 'badge--fizetve' ?>"><?= (int)$w['kod'] ?></span></td>
              <td><?= h($w['eredmeny']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
    <?php });
    exit;
}

/* ---------- feltöltött igazolás megnyitása ---------- */
if ($p === 'feltoltes') {
    $st = db()->prepare('SELECT * FROM rendeles_feltoltesek WHERE id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $f = $st->fetch();
    $ut = $f ? feltoltes_utvonal($f) : '';
    if (!$f || !is_file($ut)) {
        http_response_code(404);
        exit('A fájl nem található.');
    }
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($ut));
    header('Content-Disposition: inline; filename="' . rawurlencode($f['eredeti_nev']) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; plugin-types application/pdf");
    readfile($ut);
    exit;
}

/* ---------- videófájlok ellenőrzése a szerveren ---------- */
if ($p === 'videok-ellenorzes') {
    require_once FARO_APP . '/video_munkamenet.php';
    $mappa = video_mappa();
    $sorok = [];
    foreach (video_sorozatok() as $s) {
        $sm = $mappa . '/' . $s['id'];
        $kulcs = is_file($sm . '/kulcs.key') ? filesize($sm . '/kulcs.key') : null;
        $kesz = 0;
        $hianyzo = [];
        $darabHiba = [];
        foreach ($s['videok'] as $i => $cim) {
            $nn = str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);
            $lista = $sm . '/' . $nn . '/index.m3u8';
            if (!is_file($lista)) { $hianyzo[] = $nn; continue; }
            $kesz++;
            // a listában szereplő darabok megvannak-e
            foreach (file($lista, FILE_IGNORE_NEW_LINES) as $sor) {
                $sor = trim($sor);
                if ($sor !== '' && $sor[0] !== '#' && !is_file($sm . '/' . $nn . '/' . basename($sor))) { $darabHiba[] = $nn; break; }
            }
        }
        $sorok[] = ['s' => $s, 'mappa' => is_dir($sm), 'kulcs' => $kulcs, 'kesz' => $kesz, 'hianyzo' => $hianyzo, 'darabHiba' => $darabHiba];
    }
    $talalt = is_dir($mappa) ? array_values(array_diff(scandir($mappa), ['.', '..'])) : [];
    oldal('Videófájlok ellenőrzése', function () use ($mappa, $sorok, $talalt) { ?>
        <p><a href="./?p=felhasznalok">← Felhasználók</a></p>
        <h1>Videófájlok ellenőrzése</h1>
        <section class="card">
          <p>Videók mappája a szerveren: <strong><?= h($mappa) ?></strong> –
            <?= is_dir($mappa) ? '<span class="ok">megvan</span>' : '<span class="err">NEM TALÁLHATÓ</span>' ?></p>
          <p class="small muted">A mappában ezt látom: <?= $talalt ? h(implode(', ', array_slice($talalt, 0, 20))) : '–' ?></p>
          <?php if (ini_get('open_basedir')): ?><p class="small muted">PHP open_basedir: <?= h(ini_get('open_basedir')) ?></p><?php endif; ?>
          <table class="table table--compact">
            <thead><tr><th>Sorozat</th><th>Mappa</th><th>Kulcs</th><th>Videók</th><th>Hiány / hiba</th></tr></thead>
            <tbody>
            <?php foreach ($sorok as $x): $ok = $x['mappa'] && $x['kulcs'] === 16 && !$x['hianyzo'] && !$x['darabHiba']; ?>
              <tr>
                <td><?= h($x['s']['rovidCim']) ?><br><span class="muted small"><?= h($x['s']['id']) ?></span></td>
                <td><?= $x['mappa'] ? '<span class="ok">✓</span>' : '<span class="err">nincs</span>' ?></td>
                <td><?= $x['kulcs'] === 16 ? '<span class="ok">✓</span>' : '<span class="err">' . ($x['kulcs'] === null ? 'nincs' : 'hibás (' . (int)$x['kulcs'] . ' bájt)') . '</span>' ?></td>
                <td><?= $x['kesz'] ?> / <?= count($x['s']['videok']) ?></td>
                <td><?= $ok ? '<span class="ok">rendben</span>' : '<span class="err">'
                    . ($x['hianyzo'] ? 'hiányzik: ' . h(implode(', ', $x['hianyzo'])) . ' ' : '')
                    . ($x['darabHiba'] ? 'hiányos darabok: ' . h(implode(', ', $x['darabHiba'])) : '') . '</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </section>
    <?php });
    exit;
}

/* ---------- videós felhasználók és hozzáférések ---------- */
if ($p === 'felhasznalok') {
    $ki = $admin['felhasznalo'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_ellenoriz();
        $hid = (int)($_POST['hid'] ?? 0);
        $vissza = './?p=felhasznalok' . (isset($_POST['vissza_rendeles']) ? '' : '');
        if (!empty($_POST['vissza_rendeles'])) {
            $vissza = './?p=rendeles&id=' . (int)$_POST['vissza_rendeles'];
        }
        switch ((string)($_POST['muvelet'] ?? '')) {
            case 'jovahagy':
                atiranyit($vissza, video_jovahagy($hid, $ki));
            case 'elutasit':
                if (empty($_POST['megerosit_elutasit'])) {
                    atiranyit($vissza, 'Az elutasításhoz pipáld be a megerősítést.');
                }
                atiranyit($vissza, video_elutasit($hid, mb_substr(trim((string)($_POST['indok'] ?? '')), 0, 500), $ki));
            case 'hosszabbit':
                atiranyit($vissza, video_kezi_hosszabbitas($hid, (int)($_POST['nap'] ?? 0), $ki));
            case 'uj_jelszo':
                $f = video_felhasznalo((int)($_POST['fid'] ?? 0));
                if (!$f) atiranyit($vissza, 'Nincs ilyen felhasználó.');
                $jelszo = video_uj_jelszo((int)$f['id']);
                $ok = level_video_uj_jelszo($f, $jelszo);
                atiranyit($vissza, $ok ? 'Új jelszót küldtem a(z) ' . $f['email'] . ' címre.' : 'Az új jelszó beállítva, de a levél NEM ment el.');
        }
        atiranyit($vissza);
    }
    $fuggok = db()->query("SELECT h.*, r.rendelesszam, r.vevo_nev, r.fizetve_ekkor, r.allapot AS r_allapot, r.fizetes, r.elvegzett_modulok
        FROM video_hozzaferesek h JOIN rendelesek r ON r.id = h.rendeles_id WHERE h.allapot = 'fuggoben' ORDER BY h.id")->fetchAll();
    $kereses = trim((string)($_GET['q'] ?? ''));
    if ($kereses !== '') {
        $st = db()->prepare('SELECT * FROM video_felhasznalok WHERE email LIKE ? OR nev LIKE ? ORDER BY id DESC');
        $st->execute(['%' . $kereses . '%', '%' . $kereses . '%']);
    } else {
        $st = db()->query('SELECT * FROM video_felhasznalok ORDER BY id DESC LIMIT 300');
    }
    $felhasznalok = $st->fetchAll();
    oldal('Felhasználók', function () use ($fuggok, $felhasznalok, $kereses, $uzenet) { ?>
        <?php if ($uzenet): ?><p class="alert"><?= h($uzenet) ?></p><?php endif; ?>
        <div class="list-head"><h1 style="margin:0">Felhasználók – videós hozzáférések</h1><a class="btn btn--light" href="./?p=videok-ellenorzes">Videófájlok ellenőrzése</a></div>

        <section class="card">
          <h2>Jóváhagyásra vár <span class="count"><?= count($fuggok) ?></span></h2>
          <?php if (!$fuggok): ?>
            <p class="muted">Nincs jóváhagyásra váró videós rendelés.</p>
          <?php endif; ?>
          <?php foreach ($fuggok as $h): $s = termek($h['sorozat']); ?>
            <?= admin_fuggo_doboz($h, $s) ?>
          <?php endforeach; ?>
        </section>

        <section class="card">
          <div class="list-head">
            <h2 style="margin:0">Videós felhasználók</h2>
            <form method="get" class="search"><input type="hidden" name="p" value="felhasznalok">
              <input name="q" value="<?= h($kereses) ?>" placeholder="Keresés: név, e-mail" aria-label="Keresés">
              <button class="btn btn--light">Keresés</button></form>
          </div>
          <?php if (!$felhasznalok): ?><p class="muted">Még nincs videós felhasználó – az első jóváhagyáskor jön létre.</p><?php endif; ?>
          <?php foreach ($felhasznalok as $f): $hk = video_hozzaferesek_email($f['email']); ?>
            <div class="user">
              <div class="user__head">
                <div><strong><?= h($f['nev']) ?></strong> · <a href="mailto:<?= h($f['email']) ?>"><?= h($f['email']) ?></a><br>
                  <span class="muted small">Létrehozva: <?= h(datum($f['letrehozva'])) ?> · utolsó belépés: <?= $f['utolso_belepes'] ? h(datum($f['utolso_belepes'])) : 'még nem lépett be' ?></span></div>
                <form method="post" onsubmit="return confirm('Új jelszót generálok és elküldöm e-mailben. Mehet?')"><?= csrf_mezo() ?>
                  <input type="hidden" name="muvelet" value="uj_jelszo"><input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
                  <button class="btn btn--light">Új jelszó küldése</button></form>
              </div>
              <table class="table table--compact">
                <thead><tr><th>Sorozat</th><th>Állapot</th><th>Kezdete</th><th>Lejárat</th><th>Rendelés</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($hk as $h): $a = video_allapot($h); $s = termek($h['sorozat']); ?>
                  <tr>
                    <td><?= h($s['rovidCim'] ?? $h['sorozat']) ?></td>
                    <td><span class="badge badge--v-<?= h($a) ?>"><?= h(VIDEO_ALLAPOTOK[$a] ?? $a) ?></span>
                      <?php if ($a === 'aktiv'): ?><br><span class="muted small">még <?= video_hatra_nap($h) ?> nap</span><?php endif; ?></td>
                    <td><?= h(video_datum($h['kezdete'])) ?></td>
                    <td><?= h(video_datum($h['lejarat'])) ?></td>
                    <td><?php $rs = db()->prepare('SELECT rendelesszam FROM rendelesek WHERE id = ?'); $rs->execute([$h['rendeles_id']]); $rsz = $rs->fetchColumn(); ?>
                      <?= $rsz ? '<a href="./?p=rendeles&amp;id=' . (int)$h['rendeles_id'] . '">' . h($rsz) . '</a>' : '<span class="muted">törölve</span>' ?></td>
                    <td><?php if (in_array($a, ['aktiv', 'lejart'], true)): ?>
                      <form method="post" class="inline-form"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="hosszabbit"><input type="hidden" name="hid" value="<?= (int)$h['id'] ?>">
                        <select name="nap" aria-label="Hosszabbítás"><option value="7">+7 nap</option><option value="30">+30 nap</option><option value="90" selected>+90 nap</option></select>
                        <button class="btn btn--light">Kézi hosszabbítás</button></form>
                    <?php elseif ($a === 'elutasitva' && $h['dontes_megj']): ?><span class="muted small">Indok: <?= h($h['dontes_megj']) ?></span><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endforeach; ?>
        </section>
    <?php });
    exit;
}

/** Egy jóváhagyásra váró videós hozzáférés doboza (Felhasználók és a rendelés oldala). */
function admin_fuggo_doboz(array $h, ?array $s, ?int $visszaRendeles = null): string
{
    $r = rendeles_betolt((int)$h['rendeles_id']);
    $fizetve = !empty($r['fizetve_ekkor']);
    ob_start(); ?>
    <div class="pending">
      <div class="pending__head">
        <div>
          <strong><?= h($s['rovidCim'] ?? $h['sorozat']) ?></strong> – <?= h($s['alcim'] ?? '') ?><br>
          <?= h($r['vevo_nev']) ?> · <a href="mailto:<?= h($h['email']) ?>"><?= h($h['email']) ?></a> ·
          <a href="./?p=rendeles&amp;id=<?= (int)$r['id'] ?>"><?= h($r['rendelesszam']) ?></a> · <?= h(datum($h['letrehozva'])) ?>
        </div>
        <span class="badge badge--<?= $fizetve ? 'fizetve' : 'fizetesre_var' ?>"><?= $fizetve ? 'Fizetve' : 'Fizetésre vár' ?></span>
      </div>
      <p class="small">Elvégzett modulok a vevő szerint: <strong><?= h(video_modulok_szoveg($r['elvegzett_modulok'] ?? '')) ?></strong>
        <?php if (!in_array((string)($s['modul'] ?? ''), explode(',', (string)$r['elvegzett_modulok']), true)): ?> <span class="err">– a(z) <?= h($s['romai'] ?? '') ?> modul nincs bejelölve!</span><?php endif; ?></p>
      <div class="proofs">
        <?php foreach (feltoltesek((int)$r['id']) as $f): ?>
          <a class="proof" href="./?p=feltoltes&amp;id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener">
            <?php if (strpos($f['mime'], 'image/') === 0): ?><img src="./?p=feltoltes&amp;id=<?= (int)$f['id'] ?>" alt="" loading="lazy"><?php else: ?><span class="proof__pdf">PDF</span><?php endif; ?>
            <span class="small"><?= h($f['eredeti_nev']) ?></span></a>
        <?php endforeach; ?>
        <?php if (!feltoltesek((int)$r['id'])): ?><p class="muted small">Nincs feltöltött igazolás.</p><?php endif; ?>
      </div>
      <div class="actions">
        <form method="post" action="./?p=felhasznalok"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="jovahagy"><input type="hidden" name="hid" value="<?= (int)$h['id'] ?>">
          <?php if ($visszaRendeles): ?><input type="hidden" name="vissza_rendeles" value="<?= $visszaRendeles ?>"><?php endif; ?>
          <button class="btn"<?= $fizetve ? '' : ' disabled title="Előbb a rendelésnek fizetettnek kell lennie."' ?>>Jóváhagyom – hozzáférés megnyitása</button></form>
        <details class="more">
          <summary>Elutasítás</summary>
          <form method="post" action="./?p=felhasznalok"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="elutasit"><input type="hidden" name="hid" value="<?= (int)$h['id'] ?>">
            <?php if ($visszaRendeles): ?><input type="hidden" name="vissza_rendeles" value="<?= $visszaRendeles ?>"><?php endif; ?>
            <textarea name="indok" rows="2" placeholder="Indoklás a vevőnek (bekerül a levélbe), pl. „A feltöltött oklevél a II. modulról szól.”"></textarea>
            <label class="small"><input type="checkbox" name="megerosit_elutasit" value="1" required> Igen, elutasítom. <?= $fizetve ? ($r['fizetes'] === 'kartya' ? 'A ' . h(ft((int)($s['ar'] ?? 0))) . ' a Stripe-on automatikusan visszatérül.' : 'Az összeget kézzel kell visszautalni.') : '' ?></label>
            <button class="btn btn--danger">Elutasítás</button></form>
        </details>
      </div>
    </div>
    <?php return (string)ob_get_clean();
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
                $szla = szamlazz_szamla_kiallit(rendeles_betolt($id), $ki);
                $video = video_fizetve(rendeles_betolt($id), $ki);
                atiranyit($vissza, trim('Fizetettnek jelölve. ' . $szla . ' ' . $video));
            case 'szamla':
                atiranyit($vissza, szamlazz_szamla_kiallit($r, $ki));
            case 'sztorno':
                if (empty($_POST['megerosit_sztorno'])) {
                    atiranyit($vissza, 'A sztornózáshoz pipáld be a megerősítést.');
                }
                atiranyit($vissza, szamlazz_sztorno($r, $ki));
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
            case 'stripe_ellenoriz':
                require_once FARO_APP . '/stripe.php';
                if (!$r['stripe_session'] || !stripe_beallitva()) {
                    atiranyit($vissza, 'Ehhez a rendeléshez nincs Stripe-fizetés.');
                }
                try {
                    $sess = stripe_api('GET', '/v1/checkout/sessions/' . rawurlencode($r['stripe_session']));
                    $eredmeny = stripe_fizetes_feldolgoz($r, $sess, $ki);
                } catch (Throwable $e) {
                    $eredmeny = 'A Stripe lekérdezése nem sikerült: ' . $e->getMessage();
                }
                naplo($id, 'Stripe-fizetés kézi ellenőrzése: ' . $eredmeny, $ki);
                atiranyit($vissza, $eredmeny);
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
            <p class="muted"><?= h(datum($r['letrehozva'])) ?> · <?= h(FIZETESI_MODOK[$r['fizetes']] ?? $r['fizetes']) ?> · <?= count($r['tetelek']) ?> tétel
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
                <p class="muted small" style="flex-basis:100%;margin:0">Bankkártyás rendelés: a „Fizetve” állapotot a Stripe automatikusan beállítja a sikeres fizetés után. Ha mégsem frissült, kérdezd le a Stripe-tól. Ha a vevő nem fizetett, a rendelést törölheted.</p>
                <?php if ($r['stripe_session']): ?>
                <form method="post"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="stripe_ellenoriz">
                  <button class="btn">Fizetés lekérdezése a Stripe-tól</button></form>
                <?php endif; ?>
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
            <h3>Számla</h3>
            <?php if ($r['szamlaszam']): ?>
              <p>Számlaszám: <strong><?= h($r['szamlaszam']) ?></strong>
                <?php if ($r['sztorno_szamlaszam']): ?><br>Sztornózva – sztornó számla: <strong><?= h($r['sztorno_szamlaszam']) ?></strong><?php endif; ?></p>
              <?php if (!$r['sztorno_szamlaszam']): ?>
              <details class="more">
                <summary>Számla sztornózása</summary>
                <form method="post" class="inline-form"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="sztorno">
                  <label class="small"><input type="checkbox" name="megerosit_sztorno" value="1" required> Igen, sztornózom a(z) <?= h($r['szamlaszam']) ?> számlát (a sztornó számlát a Számlázz.hu elküldi a vevőnek).</label>
                  <button class="btn btn--light">Sztornó</button></form>
              </details>
              <?php endif; ?>
            <?php elseif (!szamlazz_beallitva()): ?>
              <p class="muted small">A Számlázz.hu nincs beállítva (faro-config.php → szamlazz → agent_kulcs), ezért számla nem készül.</p>
            <?php elseif ($r['fizetve_ekkor'] && $r['allapot'] !== 'lemondva'): ?>
              <p class="muted small">Még nincs számla (ha az automatikus kiállítás nem sikerült, az oka a naplóban látszik).</p>
              <form method="post"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="szamla">
                <button class="btn">Számla kiállítása</button></form>
            <?php else: ?>
              <p class="muted small">A számla automatikusan elkészül, amikor a rendelés Fizetve lesz, és a Számlázz.hu e-mailben elküldi a vevőnek.</p>
            <?php endif; ?>
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
            <?php if ($r['apt_nev'] !== ''): ?>
            <h3>Foxpost-automata</h3>
            <p><?= h($r['apt_nev']) ?><br><?= h($r['apt_cim']) ?><br><span class="muted small">Azonosító: <?= h($r['apt_id']) ?></span>
              <?php if ($r['foxpost_csomagszam']): ?><br>Csomagszám: <strong><?= h($r['foxpost_csomagszam']) ?></strong><?php endif; ?></p>
            <?php endif; ?>
          </section>
        </div>

        <section class="card">
          <h2>Tételek</h2>
          <table class="table">
            <thead><tr><th>Tétel</th><th>Dedikálás</th><th class="num">Db</th><th class="num">Egységár</th><th class="num">Összeg</th></tr></thead>
            <tbody>
            <?php foreach ($r['tetelek'] as $t): ?>
              <tr><td><?= h($t['cim']) ?></td><td class="dedik"><?= $t['dedikalas'] ? nl2br(h($t['dedikalas'])) : '<span class="muted">—</span>' ?></td>
                <td class="num"><?= (int)$t['db'] ?></td><td class="num"><?= h(ft((int)$t['egysegar'])) ?></td><td class="num"><?= h(ft($t['egysegar'] * $t['db'])) ?></td></tr>
            <?php endforeach; ?>
              <?php if ((int)$r['szallitas'] > 0): ?><tr><td colspan="4">Foxpost csomagautomata</td><td class="num"><?= h(ft((int)$r['szallitas'])) ?></td></tr><?php endif; ?>
              <tr class="total"><td colspan="4">Összesen</td><td class="num"><?= h(ft((int)$r['vegosszeg'])) ?></td></tr>
            </tbody>
          </table>
          <?php if ($r['megjegyzes']): ?><h3>A vevő megjegyzése</h3><p class="note"><?= nl2br(h($r['megjegyzes'])) ?></p><?php endif; ?>
        </section>

        <?php $vh = video_rendeles_hozzaferesek((int)$r['id']); if ($vh): ?>
        <section class="card">
          <h2>Videós hozzáférés</h2>
          <?php foreach ($vh as $h): $s = termek($h['sorozat']); $a = video_allapot($h); ?>
            <?php if ($a === 'fuggoben'): ?>
              <?= admin_fuggo_doboz($h, $s, (int)$r['id']) ?>
            <?php else: ?>
              <p><strong><?= h($s['rovidCim'] ?? $h['sorozat']) ?></strong> – <span class="badge badge--v-<?= h($a) ?>"><?= h(VIDEO_ALLAPOTOK[$a] ?? $a) ?></span>
                <?php if ($h['lejarat']): ?> · lejárat: <?= h(video_datum($h['lejarat'])) ?><?php endif; ?>
                <?php if ($a === 'elutasitva' && $h['dontes_megj']): ?><br><span class="muted small">Indok: <?= h($h['dontes_megj']) ?></span><?php endif; ?></p>
            <?php endif; ?>
          <?php endforeach; ?>
          <p class="small"><a href="./?p=felhasznalok">Összes videós felhasználó →</a></p>
        </section>
        <?php endif; ?>

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
      <thead><tr><th>Rendelésszám</th><th>Dátum</th><th>Vevő</th><th class="num">Tétel</th><th class="num">Összeg</th><th>Fizetés</th><th>Állapot</th></tr></thead>
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
  <link rel="stylesheet" href="admin.css?v=4">
</head>
<body>
  <header class="top">
    <a class="brand" href="./"><img src="../assets/img/logo-faro-jel.svg" alt="" width="36" height="36"> FARO <span>admin</span></a>
    <?php if ($menu && !empty($admin)): ?>
    <nav>
      <a href="./">Rendelések</a>
      <?php $fuggoDb = (int)db()->query("SELECT COUNT(*) FROM video_hozzaferesek WHERE allapot = 'fuggoben'")->fetchColumn(); ?>
      <a href="./?p=felhasznalok">Felhasználók<?= $fuggoDb ? ' <span class="nav-count">' . $fuggoDb . '</span>' : '' ?></a>
      <a href="./?p=statisztika">Statisztika</a>
      <a href="./?p=ertesitesek">Stripe-értesítések</a>
      <a href="../" target="_blank" rel="noopener">Weboldal ↗</a>
      <a href="./?p=jelszo">Jelszócsere</a>
      <a href="./?p=kilepes">Kilépés (<?= h($admin['felhasznalo']) ?>)</a>
    </nav>
    <?php endif; ?>
  </header>
  <main class="wrap"><?php $tartalom(); ?></main>
  <?php if (!empty($admin)): ?>
  <script>/* az admin böngészője ne számítson bele a látogatottságba (a statisztika oldalon visszakapcsolható) */
  try { if (localStorage.getItem("faro-szamol-admin") !== "1") localStorage.setItem("faro-nem-szamol", "1"); } catch (e) {}</script>
  <?php endif; ?>
</body>
</html>
<?php }
