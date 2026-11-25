<?php
defined('FARO') or exit;
require_once FARO_APP . '/blog.php';

/**
 * Admin → Blog: cikklista, szerkesztő, képfeltöltés, mentés, előnézet, témák.
 *   ?p=blog               lista (+ régi cikkek áthozása, törlés)
 *   ?p=blog-szerk[&id=]   szerkesztő
 *   ?p=blog-ment          mentés (POST JSON, X-CSRF)
 *   ?p=blog-kep           képfeltöltés (POST multipart, X-CSRF)
 *   ?p=blog-elonezet&id=  előnézet a weboldal kinézetével
 *   ?p=blog-temak         témakörök
 */
function admin_blog(string $p): void
{
    switch ($p) {
        case 'blog-ment': admin_blog_ment(); return;
        case 'blog-kep': admin_blog_kep(); return;
        case 'blog-elonezet': admin_blog_elonezet(); return;
        case 'blog-szerk': admin_blog_szerk(); return;
        case 'blog-temak': admin_blog_temak(); return;
        default: admin_blog_lista();
    }
}

function admin_blog_json(array $adat, int $kod = 200): void
{
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($adat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_blog_csrf_fejlec(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        admin_blog_json(['hiba' => 'A munkamenet lejárt, kérlek, töltsd újra az oldalt (a módosításaidat előtte másold ki).'], 400);
    }
}

function admin_blog_allapot(array $c): array
{
    if ($c['allapot'] !== 'kozzeteve') return ['piszkozat', 'Piszkozat'];
    if ($c['megjelenes'] > most()) return ['idozitve', 'Időzítve'];
    return ['megjelent', 'Megjelent'];
}

/** A cikkhez tartozó képek fájljainak törlése. */
function admin_blog_kepek_torol(int $cikkId): void
{
    $st = db()->prepare('SELECT kulcs FROM blog_kepek WHERE cikk_id = ?');
    $st->execute([$cikkId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) {
        foreach (glob(blog_mappa() . '/' . $k . '-*') ?: [] as $f) @unlink($f);
    }
    db()->prepare('DELETE FROM blog_kepek WHERE cikk_id = ?')->execute([$cikkId]);
}

/* ---------- lista ---------- */
function admin_blog_lista(): void
{
    global $uzenet;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        csrf_ellenoriz();
        $m = (string)($_POST['muvelet'] ?? '');
        if ($m === 'import') {
            atiranyit('./?p=blog', implode(' ', blog_regi_import()) ?: 'Nincs áthozandó cikk.');
        }
        if ($m === 'kepcsere') {
            atiranyit('./?p=blog', implode(' ', blog_regi_kepcsere()) ?: 'Nincs cserélendő kép.');
        }
        if ($m === 'torol') {
            $c = blog_cikk((int)($_POST['id'] ?? 0));
            if ($c && ($_POST['megerosit'] ?? '') === '1') {
                admin_blog_kepek_torol((int)$c['id']);
                db()->prepare('DELETE FROM blog_cikkek WHERE id = ?')->execute([(int)$c['id']]);
                atiranyit('./?p=blog', '„' . $c['cim'] . '” törölve.');
            }
            atiranyit('./?p=blog');
        }
    }
    $szuro = (string)($_GET['allapot'] ?? '');
    $kereso = trim((string)($_GET['q'] ?? ''));
    $cikkek = db()->query('SELECT * FROM blog_cikkek ORDER BY megjelenes DESC, id DESC')->fetchAll();
    $db = ['' => count($cikkek), 'megjelent' => 0, 'idozitve' => 0, 'piszkozat' => 0];
    foreach ($cikkek as $c) $db[admin_blog_allapot($c)[0]]++;
    $lista = array_filter($cikkek, function ($c) use ($szuro, $kereso) {
        if ($szuro !== '' && admin_blog_allapot($c)[0] !== $szuro) return false;
        return $kereso === '' || mb_stripos($c['cim'] . ' ' . $c['bevezeto'], $kereso) !== false;
    });
    $regi = blog_regi_hianyzik();
    $kepcsere = blog_regi_kepcsere_kell();

    oldal('Blog', function () use ($lista, $db, $szuro, $kereso, $regi, $kepcsere, $uzenet) { ?>
        <div class="list-head">
          <h1>Blog</h1>
          <div class="actions" style="margin:0">
            <a class="btn" href="./?p=blog-szerk">+ Új írás</a>
            <a class="btn btn--light" href="./?p=blog-temak">Témakörök</a>
            <a class="btn btn--light" href="../gondolatok/" target="_blank" rel="noopener">Megnyitás a weboldalon ↗</a>
          </div>
        </div>
        <?php if ($uzenet): ?><p class="alert"><?= h($uzenet) ?></p><?php endif; ?>
        <?php if ($regi): ?>
          <div class="card note" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
            <p style="margin:0">A régi weboldalról <?= count($regi) ?> írás még nincs áthozva: <?= h(implode(', ', array_map(function ($c) { return '„' . $c['cim'] . '”'; }, $regi))) ?>.</p>
            <form method="post"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="import"><button class="btn">Áthozom őket</button></form>
          </div>
        <?php endif; ?>
        <?php if ($kepcsere): ?>
          <div class="card note" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
            <p style="margin:0">Elkészült a régi írások egységes, 600×300-as kezdőképe: <?= h(implode(', ', array_map(function ($t) { return '„' . $t['cikk']['cim'] . '”'; }, $kepcsere))) ?>.</p>
            <form method="post"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="kepcsere"><button class="btn">Lecserélem</button></form>
          </div>
        <?php endif; ?>
        <form class="list-head" method="get">
          <input type="hidden" name="p" value="blog">
          <nav class="tabs" aria-label="Szűrés">
            <?php foreach (['' => 'Mind', 'megjelent' => 'Megjelent', 'idozitve' => 'Időzítve', 'piszkozat' => 'Piszkozat'] as $k => $n): ?>
              <a href="./?p=blog<?= $k !== '' ? '&amp;allapot=' . $k : '' ?>"<?= $szuro === $k ? ' class="is-on"' : '' ?>><?= h($n) ?><span><?= (int)$db[$k] ?></span></a>
            <?php endforeach; ?>
          </nav>
          <div class="search"><input name="q" value="<?= h($kereso) ?>" placeholder="Keresés a címben, bevezetőben"><button class="btn btn--light">Keresés</button></div>
        </form>
        <?php if (!$lista): ?>
          <p class="empty"><?= $db[''] ? 'Nincs a szűrésnek megfelelő írás.' : 'Még nincs írás. Kezdd az „Új írás” gombbal!' ?></p>
        <?php else: ?>
        <table class="table table--list blog-lista">
          <thead><tr><th></th><th>Cím</th><th>Témakör</th><th>Megjelenés</th><th>Állapot</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($lista as $c): [$ak, $an] = admin_blog_allapot($c); $t = blog_tema($c['tema_id'] ? (int)$c['tema_id'] : null); $k = $c['kezdokep'] ? blog_kep($c['kezdokep']) : null; ?>
            <tr onclick="if(!event.target.closest('a,button,form'))location.href='./?p=blog-szerk&amp;id=<?= (int)$c['id'] ?>'">
              <td class="blog-lista__kep"><?php if ($k): ?><img src="../feltoltes/blog/<?= h($c['kezdokep']) ?>-<?= min($k['meretek']) ?>.jpg" alt=""><?php endif; ?></td>
              <td><a href="./?p=blog-szerk&amp;id=<?= (int)$c['id'] ?>"><strong><?= h($c['cim'] ?: '(cím nélkül)') ?></strong></a><br><span class="muted small">gondolatok/<?= h($c['slug']) ?> · <?= (int)$c['olvasasi_ido'] ?> perc</span></td>
              <td><?= h($t['nev'] ?? '—') ?></td>
              <td style="white-space:nowrap"><?= h(datum($c['megjelenes'])) ?></td>
              <td><span class="badge badge--b-<?= $ak ?>"><?= h($an) ?></span></td>
              <td class="num">
                <?php if ($ak === 'megjelent'): ?><a href="../gondolatok/<?= h($c['slug']) ?>" target="_blank" rel="noopener" class="small">megnyitás ↗</a><?php else: ?><a href="./?p=blog-elonezet&amp;id=<?= (int)$c['id'] ?>" target="_blank" rel="noopener" class="small">előnézet ↗</a><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
    <?php });
}

/* ---------- szerkesztő ---------- */
function admin_blog_szerk(): void
{
    $id = (int)($_GET['id'] ?? 0);
    $c = $id ? blog_cikk($id) : null;
    if ($id && !$c) atiranyit('./?p=blog', 'Ez az írás nem található.');
    $c = $c ?: ['id' => 0, 'cim' => '', 'slug' => '', 'bevezeto' => '', 'tartalom' => '', 'tema_id' => null, 'kezdokep' => null,
        'kezdokep_alt' => '', 'meta_leiras' => '', 'allapot' => 'piszkozat', 'megjelenes' => most(), 'olvasasi_ido' => 1];
    $k = $c['kezdokep'] ? blog_kep($c['kezdokep']) : null;
    [$ak, $an] = admin_blog_allapot($c);

    oldal($c['id'] ? 'Írás szerkesztése' : 'Új írás', function () use ($c, $k, $ak, $an) { ?>
        <p style="margin:0 0 10px"><a href="./?p=blog">← Vissza a blog írásaihoz</a></p>
        <div class="szerk" data-blog-szerk data-id="<?= (int)$c['id'] ?>" data-csrf="<?= h($_SESSION['csrf'] ?? '') ?>" data-allapot="<?= h($c['allapot']) ?>">
          <div class="szerk__fo">
            <label class="szerk__cimke" for="cim">Cím</label>
            <textarea id="cim" class="szerk__cim" rows="1" maxlength="200" placeholder="Az írás címe"><?= h($c['cim']) ?></textarea>
            <label class="szerk__cimke" for="bevezeto">Bevezető <span class="muted">– a listában és a cikk tetején jelenik meg, 1–2 mondat</span></label>
            <textarea id="bevezeto" class="szerk__bevezeto" rows="2" maxlength="600" placeholder="Röviden, miről szól az írás?"><?= h((string)$c['bevezeto']) ?></textarea>

            <div class="szerk__eszkoztar" role="toolbar" aria-label="Formázás">
              <button type="button" data-cmd="p" title="Bekezdés">¶</button>
              <button type="button" data-cmd="h2" title="Alcím">H2</button>
              <button type="button" data-cmd="h3" title="Kisebb alcím">H3</button>
              <span class="szerk__valaszto"></span>
              <button type="button" data-cmd="bold" title="Félkövér (Ctrl+B)"><b>F</b></button>
              <button type="button" data-cmd="italic" title="Dőlt (Ctrl+I)"><i>D</i></button>
              <button type="button" data-cmd="blockquote" title="Idézet">❝</button>
              <span class="szerk__valaszto"></span>
              <button type="button" data-cmd="ul" title="Felsorolás">• Lista</button>
              <button type="button" data-cmd="ol" title="Számozott lista">1. Lista</button>
              <span class="szerk__valaszto"></span>
              <button type="button" data-cmd="link" title="Link">Link</button>
              <button type="button" data-cmd="unlink" title="Link eltávolítása">Link ×</button>
              <span class="szerk__valaszto"></span>
              <button type="button" data-cmd="kep" title="Kép beszúrása">Kép</button>
              <button type="button" data-cmd="video" title="YouTube-videó beszúrása">Videó</button>
              <button type="button" data-cmd="hr" title="Elválasztó vonal">—</button>
              <span class="szerk__valaszto"></span>
              <button type="button" data-cmd="undo" title="Visszavonás (Ctrl+Z)">↶</button>
              <button type="button" data-cmd="redo" title="Újra (Ctrl+Y)">↷</button>
              <button type="button" data-cmd="tisztit" title="Formázás törlése">Tx</button>
            </div>
            <div id="szoveg" class="szerk__szoveg" contenteditable="true" spellcheck="true" lang="hu" aria-label="Az írás szövege"><?= blog_szerkesztohoz((string)$c['tartalom'], '../') ?></div>
            <p class="szerk__statusz muted small"><span data-szamlalo></span> · Képet behúzhatsz vagy bemásolhatsz a szövegbe is.</p>
            <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-kep-fajl hidden>
          </div>

          <aside class="szerk__oldal">
            <div class="card">
              <h2>Közzététel</h2>
              <p>Állapot: <span class="badge badge--b-<?= $ak ?>" data-allapot-jel><?= h($an) ?></span></p>
              <fieldset class="szerk__radio">
                <label><input type="radio" name="allapot" value="piszkozat"<?= $c['allapot'] !== 'kozzeteve' ? ' checked' : '' ?>> Piszkozat <span class="muted small">(csak te látod)</span></label>
                <label><input type="radio" name="allapot" value="kozzeteve"<?= $c['allapot'] === 'kozzeteve' ? ' checked' : '' ?>> Közzétéve</label>
              </fieldset>
              <label>Megjelenés ideje
                <input type="datetime-local" id="megjelenes" value="<?= h(date('Y-m-d\TH:i', strtotime($c['megjelenes']))) ?>">
              </label>
              <p class="muted small" style="margin-top:-6px">Ha jövőbeli időpontot adsz meg, az írás akkor jelenik meg (időzítés).</p>
              <div class="actions">
                <button class="btn" type="button" data-ment>Mentés</button>
                <button class="btn btn--light" type="button" data-elonezet>Előnézet ↗</button>
              </div>
              <p class="small" data-mentes-uzenet aria-live="polite"></p>
              <p class="small" data-megnyit<?= $ak === 'megjelent' ? '' : ' hidden' ?>><a href="../gondolatok/<?= h($c['slug']) ?>" target="_blank" rel="noopener">Megnyitás a weboldalon ↗</a></p>
            </div>

            <div class="card">
              <h2>Kezdőkép</h2>
              <div class="szerk__kezdokep" data-kezdokep-doboz>
                <img data-kezdokep-kep alt=""<?= $k ? ' src="../feltoltes/blog/' . h($c['kezdokep']) . '-' . min($k['meretek'][count($k['meretek']) - 1], 960) . '.jpg"' : ' hidden' ?>>
                <p class="muted small" data-kezdokep-ures<?= $k ? ' hidden' : '' ?>>Még nincs kezdőkép. Fekvő (pl. 3:2 arányú), legalább 1200 px széles kép mutat a legjobban.</p>
              </div>
              <input type="hidden" id="kezdokep" value="<?= h((string)$c['kezdokep']) ?>">
              <div class="actions">
                <button class="btn btn--light" type="button" data-kezdokep-valaszt><?= $k ? 'Csere' : 'Kép feltöltése' ?></button>
                <button class="btn btn--light" type="button" data-kezdokep-torol<?= $k ? '' : ' hidden' ?>>Eltávolítás</button>
              </div>
              <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-kezdokep-fajl hidden>
              <label>Rövid leírás a képről <span class="muted small" style="text-transform:none;letter-spacing:0">(akadálymentesség)</span>
                <input id="kezdokep_alt" maxlength="200" value="<?= h((string)$c['kezdokep_alt']) ?>">
              </label>
            </div>

            <div class="card">
              <h2>Beállítások</h2>
              <label>Témakör
                <select id="tema">
                  <option value="">– nincs –</option>
                  <?php foreach (blog_temak() as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"<?= (int)$c['tema_id'] === (int)$t['id'] ? ' selected' : '' ?>><?= h($t['nev']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Webcím
                <span class="szerk__slug"><span class="muted">…/gondolatok/</span><input id="slug" value="<?= h($c['slug']) ?>" maxlength="80" pattern="[a-z0-9-]+" data-kezi="<?= $c['id'] ? '1' : '' ?>"></span>
              </label>
              <label>Leírás a keresőknek és a megosztáshoz <span class="muted small" style="text-transform:none;letter-spacing:0">(ha üres, a bevezető)</span>
                <textarea id="meta" rows="3" maxlength="300"><?= h((string)$c['meta_leiras']) ?></textarea>
              </label>
            </div>

            <?php if ($c['id']): ?>
            <details class="card more">
              <summary>Írás törlése</summary>
              <form method="post" action="./?p=blog" onsubmit="return confirm('Biztosan törlöd? Ez nem vonható vissza.')">
                <?= csrf_mezo() ?><input type="hidden" name="muvelet" value="torol"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <label class="small"><input type="checkbox" name="megerosit" value="1" required style="width:auto"> Igen, véglegesen törlöm az írást és a képeit.</label>
                <button class="btn btn--danger">Törlés</button>
              </form>
            </details>
            <?php endif; ?>
          </aside>
        </div>
        <script src="blog-szerkeszto.js?v=1" defer></script>
    <?php });
}

/* ---------- mentés ---------- */
function admin_blog_ment(): void
{
    admin_blog_csrf_fejlec();
    $be = json_decode((string)file_get_contents('php://input', false, null, 0, 3000000), true);
    if (!is_array($be)) admin_blog_json(['hiba' => 'Hibás kérés.'], 400);
    $str = function (string $k, int $max) use ($be): string {
        return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)($be[$k] ?? ''))), 0, $max);
    };
    $id = (int)($be['id'] ?? 0);
    $regi = $id ? blog_cikk($id) : null;
    if ($id && !$regi) admin_blog_json(['hiba' => 'Az írás közben törölve lett.'], 404);

    $cim = preg_replace('/\s+/u', ' ', $str('cim', 200));
    $tartalom = blog_tisztit((string)($be['tartalom'] ?? ''));
    $allapot = ($be['allapot'] ?? '') === 'kozzeteve' ? 'kozzeteve' : 'piszkozat';
    if ($allapot === 'kozzeteve' && ($cim === '' || trim(strip_tags($tartalom)) === '' && strpos($tartalom, '<figure') === false)) {
        admin_blog_json(['hiba' => 'Közzétételhez kell cím és szöveg.'], 422);
    }
    $temaId = (int)($be['tema'] ?? 0);
    $temaId = isset(blog_temak()[$temaId]) ? $temaId : null;
    $kezdo = $str('kezdokep', 40);
    $kezdo = preg_match(BLOG_KEP_MINTA, $kezdo) && blog_kep($kezdo) ? $kezdo : null;
    $megj = $str('megjelenes', 20);
    $megj = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $megj) ? str_replace('T', ' ', $megj) . ':00' : ($regi['megjelenes'] ?? most());
    $slugBe = $str('slug', 80);
    $slug = blog_slug($slugBe !== '' ? $slugBe : ($cim !== '' ? $cim : 'iras'), $id ?: null);
    $adat = [$slug, $cim, $str('bevezeto', 600), $tartalom, $temaId, $kezdo, $str('kezdokep_alt', 200), $str('meta', 300), $allapot, $megj, blog_olvasasi_ido($tartalom), most()];

    if ($regi) {
        db()->prepare('UPDATE blog_cikkek SET slug=?, cim=?, bevezeto=?, tartalom=?, tema_id=?, kezdokep=?, kezdokep_alt=?, meta_leiras=?, allapot=?, megjelenes=?, olvasasi_ido=?, frissitve=? WHERE id=?')
            ->execute(array_merge($adat, [$id]));
    } else {
        db()->prepare('INSERT INTO blog_cikkek (slug, cim, bevezeto, tartalom, tema_id, kezdokep, kezdokep_alt, meta_leiras, allapot, megjelenes, olvasasi_ido, frissitve, letrehozva) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array_merge($adat, [most()]));
        $id = (int)db()->lastInsertId();
    }
    // a használt képek a cikkhez kötve; a kicserélt kezdőkép és a kitörölt képek fájljai törlődnek
    preg_match_all('/data-kep="([a-z0-9-]+)"/', $tartalom, $m);
    $hasznalt = array_values(array_unique(array_filter(array_merge($m[1], [$kezdo]))));
    $st = db()->prepare('SELECT kulcs FROM blog_kepek WHERE cikk_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) {
        if (!in_array($k, $hasznalt, true)) {
            foreach (glob(blog_mappa() . '/' . $k . '-*') ?: [] as $f) @unlink($f);
            db()->prepare('DELETE FROM blog_kepek WHERE kulcs = ?')->execute([$k]);
        }
    }
    foreach ($hasznalt as $k) {
        db()->prepare('UPDATE blog_kepek SET cikk_id = ? WHERE kulcs = ? AND (cikk_id IS NULL OR cikk_id = ?)')->execute([$id, $k, $id]);
    }
    // YouTube-előnézetek a saját tárhelyre
    preg_match_all('/data-yt="([A-Za-z0-9_-]{11})"/', $tartalom, $y);
    foreach (array_unique($y[1]) as $yt) blog_yt_kep($yt);

    $c = blog_cikk($id);
    [$ak, $an] = admin_blog_allapot($c);
    admin_blog_json([
        'ok' => true, 'id' => $id, 'slug' => $c['slug'], 'allapot' => $ak, 'allapotNev' => $an,
        'ido' => date('H:i'), 'olvasasiIdo' => (int)$c['olvasasi_ido'],
    ]);
}

/* ---------- képfeltöltés ---------- */
function admin_blog_kep(): void
{
    admin_blog_csrf_fejlec();
    $f = $_FILES['kep'] ?? null;
    if (!$f || !is_uploaded_file($f['tmp_name'] ?? '') || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $kod = (int)($f['error'] ?? 0);
        admin_blog_json(['hiba' => in_array($kod, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'A kép túl nagy a szerver beállításaihoz.' : 'A feltöltés nem sikerült.'], 400);
    }
    try {
        $k = blog_kep_ment($f['tmp_name'], ($_POST['fajta'] ?? '') === 'kezdo' ? 'kezdo' : 'kep');
    } catch (Throwable $e) {
        admin_blog_json(['hiba' => $e->getMessage()], 422);
    }
    $leg = max($k['meretek']);
    admin_blog_json(['ok' => true, 'kulcs' => $k['kulcs'], 'src' => '../feltoltes/blog/' . $k['kulcs'] . '-' . $leg . '.jpg', 'szel' => (int)$k['szel'], 'mag' => (int)$k['mag']]);
}

/* ---------- előnézet ---------- */
function admin_blog_elonezet(): void
{
    $c = blog_cikk((int)($_GET['id'] ?? 0));
    if (!$c) atiranyit('./?p=blog', 'Ez az írás nem található.');
    require_once FARO_APP . '/sablon.php';
    require_once FARO_APP . '/blog_oldal.php';
    blog_cikk_oldal($c, '../', true);
}

/* ---------- témakörök ---------- */
function admin_blog_temak(): void
{
    global $uzenet;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        csrf_ellenoriz();
        $m = (string)($_POST['muvelet'] ?? '');
        $nev = mb_substr(trim((string)($_POST['nev'] ?? '')), 0, 100);
        $leiras = mb_substr(trim((string)($_POST['leiras'] ?? '')), 0, 300);
        $sorrend = (int)($_POST['sorrend'] ?? 0);
        if ($m === 'uj' && $nev !== '') {
            $kulcs = substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', strtr(mb_strtolower($nev), ['ő' => 'o', 'ű' => 'u'])))), '-'), 0, 60) ?: 'tema';
            $alap = $kulcs;
            for ($i = 2; db()->query('SELECT COUNT(*) FROM blog_temak WHERE kulcs = ' . db()->quote($kulcs))->fetchColumn(); $i++) $kulcs = $alap . '-' . $i;
            db()->prepare('INSERT INTO blog_temak (kulcs, nev, leiras, sorrend) VALUES (?,?,?,?)')->execute([$kulcs, $nev, $leiras, $sorrend ?: 99]);
            atiranyit('./?p=blog-temak', 'Az új témakör elkészült: ' . $nev . '.');
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($m === 'ment' && $id && $nev !== '') {
            db()->prepare('UPDATE blog_temak SET nev = ?, leiras = ?, sorrend = ? WHERE id = ?')->execute([$nev, $leiras, $sorrend, $id]);
            atiranyit('./?p=blog-temak', 'Mentve.');
        }
        if ($m === 'torol' && $id) {
            $st = db()->prepare('SELECT COUNT(*) FROM blog_cikkek WHERE tema_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) atiranyit('./?p=blog-temak', 'Ehhez a témakörhöz tartozik írás, ezért nem törölhető. Előbb tedd át az írásokat másik témakörbe.');
            db()->prepare('DELETE FROM blog_temak WHERE id = ?')->execute([$id]);
            atiranyit('./?p=blog-temak', 'A témakör törölve.');
        }
        atiranyit('./?p=blog-temak');
    }
    $szam = [];
    foreach (db()->query('SELECT tema_id, COUNT(*) AS db FROM blog_cikkek GROUP BY tema_id')->fetchAll() as $r) $szam[(int)$r['tema_id']] = (int)$r['db'];
    oldal('Témakörök', function () use ($szam, $uzenet) { ?>
        <p style="margin:0 0 10px"><a href="./?p=blog">← Vissza a blog írásaihoz</a></p>
        <h1>Témakörök</h1>
        <p class="muted">A témakörök szűrőként jelennek meg a Gondolatok oldalon és a kezdőlapon, a sorrend szerint. A leírás a szűrő kiválasztásakor látszik.</p>
        <?php if ($uzenet): ?><p class="alert"><?= h($uzenet) ?></p><?php endif; ?>
        <table class="table">
          <thead><tr><th>Név</th><th>Leírás</th><th class="num">Sorrend</th><th class="num">Írás</th><th></th></tr></thead>
          <tbody>
          <?php foreach (blog_temak() as $t): ?>
            <tr>
              <td colspan="3">
                <form method="post" class="tema-sor"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="ment"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <input name="nev" value="<?= h($t['nev']) ?>" required maxlength="100" aria-label="Név">
                  <input name="leiras" value="<?= h($t['leiras']) ?>" maxlength="300" aria-label="Leírás">
                  <input name="sorrend" type="number" value="<?= (int)$t['sorrend'] ?>" aria-label="Sorrend">
                  <button class="btn btn--light">Mentés</button>
                </form>
              </td>
              <td class="num"><?= (int)($szam[(int)$t['id']] ?? 0) ?></td>
              <td class="num">
                <form method="post" onsubmit="return confirm('Törlöd a témakört?')"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="torol"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn--light"<?= !empty($szam[(int)$t['id']]) ? ' disabled title="Tartozik hozzá írás"' : '' ?>>Törlés</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <section class="card" style="margin-top:18px">
          <h2>Új témakör</h2>
          <form method="post" class="tema-sor"><?= csrf_mezo() ?><input type="hidden" name="muvelet" value="uj">
            <input name="nev" placeholder="Név" required maxlength="100">
            <input name="leiras" placeholder="Rövid leírás" maxlength="300">
            <input name="sorrend" type="number" placeholder="Sorrend">
            <button class="btn">Hozzáadás</button>
          </form>
        </section>
    <?php });
}
