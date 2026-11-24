<?php
defined('FARO') or exit;
require_once FARO_APP . '/statisztika.php';

/**
 * Admin → Statisztika: a süti nélküli látogatottságmérés összesítései.
 * Az admin/index.php hívja meg bejelentkezett adminnal.
 */
function sz(int $n): string
{
    return number_format($n, 0, ',', "\u{00A0}");
}

function szazalek(float $resz, float $egesz, int $tized = 0): string
{
    return $egesz > 0 ? number_format($resz / $egesz * 100, $tized, ',', ' ') . '%' : '–';
}

/** Változás az előző időszakhoz képest: ['szoveg', 'fel'|'le'|''] */
function valtozas(float $most, float $elotte): array
{
    if ($elotte <= 0) return ['', ''];   // az előző időszakban még nem volt adat
    $v = ($most - $elotte) / $elotte * 100;
    if (abs($v) < 0.5) return ['±0%', ''];
    return [($v > 0 ? '+' : '−') . number_format(abs($v), 0, ',', ' ') . '%', $v > 0 ? 'fel' : 'le'];
}

function admin_statisztika_idoszak(): array
{
    $ma = date('Y-m-d');
    $valasztott = (string)($_GET['i'] ?? '30');
    $tol = $_GET['tol'] ?? '';
    $ig = $_GET['ig'] ?? '';
    if ($valasztott === 'egyedi' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tol) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ig) && $tol <= $ig) {
        $ig = min($ig, $ma);
        $cim = video_datum($tol) . ' – ' . video_datum($ig);
    } else {
        $presetek = ['ma' => 0, '7' => 6, '30' => 29, '90' => 89, '365' => 364];
        if ($valasztott === 'ev') {
            $tol = date('Y') . '-01-01';
        } else {
            if (!isset($presetek[$valasztott])) $valasztott = '30';
            $tol = date('Y-m-d', strtotime("-{$presetek[$valasztott]} days"));
        }
        $ig = $ma;
        $cim = ['ma' => 'Ma', '7' => 'Az utolsó 7 nap', '30' => 'Az utolsó 30 nap', '90' => 'Az utolsó 90 nap', '365' => 'Az utolsó 12 hónap', 'ev' => 'Idén'][$valasztott];
    }
    $napok = (int)round((strtotime($ig) - strtotime($tol)) / 86400) + 1;
    $elozoIg = date('Y-m-d', strtotime($tol . ' -1 day'));
    $elozoTol = date('Y-m-d', strtotime($elozoIg . ' -' . ($napok - 1) . ' days'));
    return compact('valasztott', 'tol', 'ig', 'cim', 'napok', 'elozoTol', 'elozoIg');
}

function admin_statisztika_rendelesek(string $tol, string $ig): array
{
    $a = $tol . ' 00:00:00';
    $b = $ig . ' 23:59:59';
    $st = db()->prepare('SELECT COUNT(*) FROM rendelesek WHERE letrehozva BETWEEN ? AND ?');
    $st->execute([$a, $b]);
    $leadott = (int)$st->fetchColumn();
    $st = db()->prepare("SELECT COUNT(*), COALESCE(SUM(vegosszeg), 0) FROM rendelesek WHERE fizetve_ekkor BETWEEN ? AND ? AND allapot <> 'lemondva'");
    $st->execute([$a, $b]);
    [$fizetett, $bevetel] = $st->fetch(PDO::FETCH_NUM);
    return ['leadott' => $leadott, 'fizetett' => (int)$fizetett, 'bevetel' => (int)$bevetel];
}

/** Sávos lista (egy sorozat, kék sávok). $sorok: [kulcs => db]; $nev: callable(kulcs) => [cím, alcím] */
function stat_savos_lista(array $sorok, int $osszes, callable $nev, string $egyseg, int $max = 10): void
{
    if (!$sorok) {
        echo '<p class="muted small">Ebben az időszakban nincs adat.</p>';
        return;
    }
    $sorok = array_slice($sorok, 0, $max, true);
    $csucs = max($sorok);
    echo '<table class="stat-list"><thead><tr><th scope="col">Megnevezés</th><th scope="col" class="num">' . h($egyseg) . '</th><th scope="col" class="num">Arány</th></tr></thead><tbody>';
    foreach ($sorok as $k => $db) {
        [$cim, $al] = $nev((string)$k);
        $w = $csucs > 0 ? max(1, round($db / $csucs * 100, 1)) : 0;
        echo '<tr><td><div class="stat-list__nev">' . h($cim) . ($al !== '' ? ' <span class="muted small">' . h($al) . '</span>' : '') . '</div>'
            . '<div class="stat-bar" aria-hidden="true"><span style="width:' . $w . '%"></span></div></td>'
            . '<td class="num">' . sz($db) . '</td><td class="num muted">' . szazalek($db, $osszes) . '</td></tr>';
    }
    echo '</tbody></table>';
}

/** Oszlopdiagram CSS-sel (napszak, hét napjai), tooltip a data-tip attribútumból. */
function stat_oszlopok(array $ertekek, callable $tip, string $leiras, array $tengely): void
{
    $csucs = max(1, max($ertekek));
    echo '<div class="stat-cols" role="img" aria-label="' . h($leiras) . '">';
    foreach ($ertekek as $k => $v) {
        $mag = $v > 0 ? max(2, round($v / $csucs * 100, 1)) : 0;
        echo '<div class="stat-col" tabindex="0" data-tip="' . h($tip($k, $v)) . '"><span style="height:' . $mag . '%"></span></div>';
    }
    echo '</div><div class="stat-cols__axis" aria-hidden="true">';
    foreach ($tengely as $c) echo '<span>' . h($c) . '</span>';
    echo '</div>';
}

function admin_statisztika_oldal(): void
{
    $i = admin_statisztika_idoszak();
    ['tol' => $tol, 'ig' => $ig] = $i;

    $lat = stat_osszeg('latogato', $tol, $ig);
    $meg = stat_osszeg('oldal', $tol, $ig);
    $latE = stat_osszeg('latogato', $i['elozoTol'], $i['elozoIg']);
    $megE = stat_osszeg('oldal', $i['elozoTol'], $i['elozoIg']);
    $egy = stat_egyoldalas($tol, $ig);
    $eszkoz = stat_lista('eszkoz', $tol, $ig);
    $rend = admin_statisztika_rendelesek($tol, $ig);
    $rendE = admin_statisztika_rendelesek($i['elozoTol'], $i['elozoIg']);
    $aktiv = stat_most_aktiv();
    $kezdet = db()->query('SELECT MIN(nap) FROM stat_szamlalo')->fetchColumn();

    // idősor: napi, 92 napnál hosszabb időszaknál heti összesítés
    $napi = stat_napok($tol, $ig);
    $cimkek = [];
    $sLat = [];
    $sMeg = [];
    if (count($napi) > 92) {
        $het = [];
        foreach ($napi as $nap => $v) {
            $hetfo = date('Y-m-d', strtotime('monday this week', strtotime($nap)));
            $het[$hetfo] = $het[$hetfo] ?? ['latogato' => 0, 'oldal' => 0];
            $het[$hetfo]['latogato'] += $v['latogato'];
            $het[$hetfo]['oldal'] += $v['oldal'];
        }
        foreach ($het as $nap => $v) {
            $cimkek[] = date('m.d.', strtotime($nap)) . '-i hét';
            $sLat[] = $v['latogato'];
            $sMeg[] = $v['oldal'];
        }
        $bontas = 'hetente';
    } else {
        $napNevek = ['V', 'H', 'K', 'Sze', 'Cs', 'P', 'Szo'];
        foreach ($napi as $nap => $v) {
            $cimkek[] = date('m.d.', strtotime($nap)) . ' ' . $napNevek[(int)date('w', strtotime($nap))];
            $sLat[] = $v['latogato'];
            $sMeg[] = $v['oldal'];
        }
        $bontas = 'naponta';
    }

    // a hét napjai: átlagos napi látogató
    $hetNapok = ['Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat', 'Vasárnap'];
    $hetOssz = array_fill(0, 7, 0);
    $hetDb = array_fill(0, 7, 0);
    foreach ($napi as $nap => $v) {
        $w = ((int)date('N', strtotime($nap))) - 1;
        $hetOssz[$w] += $v['latogato'];
        $hetDb[$w]++;
    }
    $hetAtlag = [];
    foreach ($hetOssz as $w => $o) $hetAtlag[$w] = $hetDb[$w] ? round($o / $hetDb[$w], 1) : 0;

    $orak = array_fill_keys(array_map(function ($h) { return sprintf('%02d', $h); }, range(0, 23)), 0);
    foreach (stat_lista('ora', $tol, $ig) as $k => $v) if (isset($orak[$k])) $orak[$k] = $v;

    $esemenyek = stat_lista('esemeny', $tol, $ig);
    $kosarba = 0;
    $kosarTermekek = [];
    $urlapKuldes = [];
    $videoLejatszas = [];
    $kattintas = ['email' => 0, 'telefon' => 0];
    foreach ($esemenyek as $k => $v) {
        [$fajta, $ertek] = explode(':', $k, 2) + [1 => ''];
        if ($fajta === 'kosarba') { $kosarba += $v; $kosarTermekek[$ertek] = ($kosarTermekek[$ertek] ?? 0) + $v; }
        elseif ($fajta === 'urlap') $urlapKuldes[$ertek] = $v;
        elseif ($fajta === 'video') $videoLejatszas[$ertek] = $v;
        elseif ($fajta === 'kattintas' && isset($kattintas[$ertek])) $kattintas[$ertek] = $v;
    }
    arsort($kosarTermekek);
    arsort($videoLejatszas);
    $oldalak = stat_lista('oldal', $tol, $ig);

    $presetek = ['ma' => 'Ma', '7' => '7 nap', '30' => '30 nap', '90' => '90 nap', '365' => '12 hónap', 'ev' => 'Idén'];
    $oldalNev = function (string $k): array {
        $n = stat_oldalnev($k);
        return $n !== '' ? [$n, $k] : [$k, ''];
    };

    oldal('Statisztika', function () use ($i, $lat, $meg, $latE, $megE, $egy, $eszkoz, $rend, $rendE, $aktiv, $kezdet, $cimkek, $sLat, $sMeg, $bontas, $hetNapok, $hetAtlag, $orak, $kosarba, $kosarTermekek, $urlapKuldes, $videoLejatszas, $kattintas, $oldalak, $presetek, $oldalNev, $tol, $ig) {
        [$dLat, $iLat] = valtozas($lat, $latE);
        [$dMeg, $iMeg] = valtozas($meg, $megE);
        [$dRend, $iRend] = valtozas($rend['leadott'], $rendE['leadott']);
        $mobil = ($eszkoz['Mobil'] ?? 0) + ($eszkoz['Tablet'] ?? 0);
        ?>
        <div class="list-head">
          <div>
            <h1>Statisztika</h1>
            <p class="muted" style="margin:0"><?= h($i['cim']) ?> · <?= h(video_datum($tol)) ?> – <?= h(video_datum($ig)) ?><?= $i['napok'] > 1 ? ' · előző időszak: ' . h(video_datum($i['elozoTol'])) . ' – ' . h(video_datum($i['elozoIg'])) : '' ?></p>
          </div>
          <p class="stat-live"><span class="stat-live__dot" aria-hidden="true"></span><strong><?= sz($aktiv) ?></strong> látogató az elmúlt 5 percben</p>
        </div>

        <form class="stat-filter" method="get">
          <input type="hidden" name="p" value="statisztika">
          <nav class="tabs" aria-label="Időszak">
            <?php foreach ($presetek as $k => $c): ?>
              <a href="./?p=statisztika&amp;i=<?= h($k) ?>"<?= $i['valasztott'] === $k ? ' class="is-on" aria-current="true"' : '' ?>><?= h($c) ?></a>
            <?php endforeach; ?>
          </nav>
          <div class="stat-filter__custom">
            <input type="hidden" name="i" value="egyedi">
            <label class="visually-hidden" for="tol">Ettől</label><input type="date" id="tol" name="tol" value="<?= h($tol) ?>" max="<?= h(date('Y-m-d')) ?>">
            <span aria-hidden="true">–</span>
            <label class="visually-hidden" for="ig">Eddig</label><input type="date" id="ig" name="ig" value="<?= h($ig) ?>" max="<?= h(date('Y-m-d')) ?>">
            <button class="btn btn--light" type="submit">Mutasd</button>
          </div>
        </form>

        <?php if (!$kezdet): ?>
          <p class="empty">Még nincs mérési adat. A mérés az új oldalak élesítése után indul: amint valaki megnyit egy oldalt, itt megjelenik.</p>
        <?php return; endif; ?>

        <div class="stat-tiles">
          <div class="stat-tile stat-tile--hero">
            <p class="stat-tile__label">Látogatók</p>
            <p class="stat-tile__value"><?= sz($lat) ?></p>
            <?php if ($dLat !== ''): ?><p class="stat-delta stat-delta--<?= $iLat ?>"><?= $iLat === 'fel' ? '▲' : ($iLat === 'le' ? '▼' : '') ?> <?= h($dLat) ?> <span class="muted">az előző időszakhoz</span></p><?php endif; ?>
          </div>
          <div class="stat-tile">
            <p class="stat-tile__label">Oldalmegtekintések</p>
            <p class="stat-tile__value"><?= sz($meg) ?></p>
            <?php if ($dMeg !== ''): ?><p class="stat-delta stat-delta--<?= $iMeg ?>"><?= $iMeg === 'fel' ? '▲' : ($iMeg === 'le' ? '▼' : '') ?> <?= h($dMeg) ?></p><?php endif; ?>
          </div>
          <div class="stat-tile">
            <p class="stat-tile__label">Oldal / látogató</p>
            <p class="stat-tile__value"><?= $lat ? number_format($meg / $lat, 1, ',', ' ') : '–' ?></p>
            <p class="stat-delta muted">egyoldalas: <?= szazalek($egy, $lat) ?></p>
          </div>
          <div class="stat-tile">
            <p class="stat-tile__label">Mobilról / tabletről</p>
            <p class="stat-tile__value"><?= szazalek($mobil, $lat) ?></p>
            <p class="stat-delta muted"><?= sz($mobil) ?> látogató</p>
          </div>
          <div class="stat-tile">
            <p class="stat-tile__label">Rendelések</p>
            <p class="stat-tile__value"><?= sz($rend['leadott']) ?></p>
            <?php if ($dRend !== ''): ?><p class="stat-delta stat-delta--<?= $iRend ?>"><?= $iRend === 'fel' ? '▲' : ($iRend === 'le' ? '▼' : '') ?> <?= h($dRend) ?></p><?php endif; ?>
          </div>
          <div class="stat-tile">
            <p class="stat-tile__label">Befolyt összeg</p>
            <p class="stat-tile__value"><?= h(ft($rend['bevetel'])) ?></p>
            <p class="stat-delta muted"><?= sz($rend['fizetett']) ?> fizetett rendelés</p>
          </div>
        </div>

        <section class="card">
          <div class="stat-card-head">
            <h2>Látogatók és oldalmegtekintések <span class="muted small">(<?= h($bontas) ?>)</span></h2>
            <ul class="stat-legend">
              <li><span class="stat-key stat-key--line" style="--c:var(--s1)"></span>Látogatók</li>
              <li><span class="stat-key stat-key--line" style="--c:var(--s2)"></span>Oldalmegtekintések</li>
            </ul>
          </div>
          <div class="stat-line" data-vonal tabindex="0" aria-label="Vonaldiagram: látogatók és oldalmegtekintések <?= h($bontas) ?>. A nyilakkal lépkedhetsz."
               data-adat="<?= h(json_encode(['cimkek' => $cimkek, 'sorozatok' => [['nev' => 'Látogatók', 'szin' => '#2a78d6', 'ertekek' => $sLat], ['nev' => 'Oldalmegtekintések', 'szin' => '#eb6834', 'ertekek' => $sMeg]]], JSON_UNESCAPED_UNICODE)) ?>"></div>
          <details class="more">
            <summary>Táblázat</summary>
            <table class="table table--compact">
              <thead><tr><th>Időszak</th><th class="num">Látogatók</th><th class="num">Oldalmegtekintések</th></tr></thead>
              <tbody>
              <?php foreach (array_reverse(array_keys($cimkek)) as $n): ?>
                <tr><td><?= h($cimkek[$n]) ?></td><td class="num"><?= sz($sLat[$n]) ?></td><td class="num"><?= sz($sMeg[$n]) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </details>
        </section>

        <div class="grid">
          <section class="card">
            <h2>Legnézettebb oldalak</h2>
            <?php stat_savos_lista($oldalak, $meg, $oldalNev, 'Megtekintés', 12); ?>
          </section>
          <section class="card">
            <h2>Honnan érkeztek?</h2>
            <?php stat_savos_lista(stat_lista('forras', $tol, $ig), $lat, function ($k) { return [$k, '']; }, 'Látogató'); ?>
            <?php $hiv = stat_lista('hivatkozo', $tol, $ig, 10); if ($hiv): ?>
              <h3>Hivatkozó webhelyek</h3>
              <?php stat_savos_lista($hiv, $lat, function ($k) { return [$k, '']; }, 'Látogató'); ?>
            <?php endif; ?>
            <?php $kamp = stat_lista('kampany', $tol, $ig, 10); if ($kamp): ?>
              <h3>Kampányok (utm)</h3>
              <?php stat_savos_lista($kamp, $lat, function ($k) { return [$k, '']; }, 'Látogató'); ?>
            <?php endif; ?>
          </section>
        </div>

        <div class="grid">
          <section class="card">
            <h2>Eszközök</h2>
            <?php
            $eszSorrend = ['Mobil' => 'var(--s1)', 'Asztali gép' => 'var(--s2)', 'Tablet' => 'var(--s3)'];
            $eszOssz = array_sum($eszkoz);
            if ($eszOssz): ?>
              <div class="stat-seg" role="img" aria-label="<?= h(implode(', ', array_map(function ($k) use ($eszkoz, $eszOssz) { return $k . ' ' . szazalek($eszkoz[$k] ?? 0, $eszOssz); }, array_keys($eszSorrend)))) ?>">
                <?php foreach ($eszSorrend as $k => $szin): $v = $eszkoz[$k] ?? 0; if (!$v) continue; $sz = $v / $eszOssz * 100; ?>
                  <span tabindex="0" style="flex:<?= $v ?> 1 0;background:<?= $szin ?>" data-tip="<?= h($k . ': ' . sz($v) . ' látogató (' . szazalek($v, $eszOssz) . ')') ?>"><?= $sz >= 9 ? h(szazalek($v, $eszOssz)) : '' ?></span>
                <?php endforeach; ?>
              </div>
              <ul class="stat-legend">
                <?php foreach ($eszSorrend as $k => $szin): if (empty($eszkoz[$k])) continue; ?>
                  <li><span class="stat-key" style="--c:<?= $szin ?>"></span><?= h($k) ?> <span class="muted"><?= sz($eszkoz[$k]) ?></span></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?><p class="muted small">Ebben az időszakban nincs adat.</p><?php endif; ?>
            <h3>Böngészők</h3>
            <?php stat_savos_lista(stat_lista('bongeszo', $tol, $ig), $lat, function ($k) { return [$k, '']; }, 'Látogató', 8); ?>
            <h3>Operációs rendszerek</h3>
            <?php stat_savos_lista(stat_lista('os', $tol, $ig), $lat, function ($k) { return [$k, '']; }, 'Látogató', 8); ?>
          </section>
          <section class="card">
            <h2>Belépő oldalak</h2>
            <p class="muted small">Ezen az oldalon kezdték a látogatást.</p>
            <?php stat_savos_lista(stat_lista('belepo', $tol, $ig), $lat, $oldalNev, 'Látogató', 10); ?>
          </section>
        </div>

        <div class="grid">
          <section class="card">
            <h2>Napszak</h2>
            <p class="muted small">Oldalmegtekintések óránként (magyar idő szerint).</p>
            <?php stat_oszlopok($orak, function ($k, $v) { return (int)$k . '–' . ((int)$k + 1) . ' óra: ' . sz($v) . ' megtekintés'; }, 'Oldalmegtekintések óránként', ['0', '6', '12', '18', '23']); ?>
          </section>
          <section class="card">
            <h2>A hét napjai</h2>
            <p class="muted small">Átlagos napi látogatószám.</p>
            <?php stat_oszlopok($hetAtlag, function ($k, $v) use ($hetNapok) { return $hetNapok[$k] . ': átlagosan ' . number_format($v, 1, ',', ' ') . ' látogató'; }, 'Átlagos látogatók a hét napjain', ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V']); ?>
          </section>
        </div>

        <section class="card">
          <h2>Vásárlás lépései</h2>
          <p class="muted small">A kosárba tétel a mérés indulása óta számít; a rendelések a rendelési rendszerből jönnek.</p>
          <?php
          $tolcser = [
              ['Megrendelésembe tett termék', $kosarba],
              ['A rendelési oldal megnyitása', $oldalak['/rendeles.html'] ?? 0],
              ['Elküldött rendelés', $rend['leadott']],
              ['Fizetett rendelés', $rend['fizetett']],
          ];
          $tCsucs = max(1, max(array_column($tolcser, 1)));
          $tSzinek = ['#86b6ef', '#5598e7', '#2a78d6', '#1c5cab'];
          ?>
          <table class="stat-list stat-funnel">
            <thead><tr><th scope="col">Lépés</th><th scope="col" class="num">Szám</th><th scope="col" class="num">Az előzőhöz</th></tr></thead>
            <tbody>
            <?php foreach ($tolcser as $n => [$nev, $v]): ?>
              <tr><td><div class="stat-list__nev"><?= h($nev) ?></div><div class="stat-bar stat-bar--thick" aria-hidden="true"><span style="width:<?= $v ? max(1, round($v / $tCsucs * 100, 1)) : 0 ?>%;background:<?= $tSzinek[$n] ?>"></span></div></td>
                <td class="num"><?= sz((int)$v) ?></td>
                <td class="num muted"><?= $n && $tolcser[$n - 1][1] >= $v ? szazalek($v, $tolcser[$n - 1][1]) : '' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php if ($kosarTermekek): ?>
            <h3>Mit tettek a Megrendelésembe?</h3>
            <?php stat_savos_lista($kosarTermekek, $kosarba, function ($k) {
                $t = termek(preg_replace('/-hosszabbitas$/', '', $k));
                $cim = $t ? ($t['rovidCim'] ?? $t['cim'] ?? $k) : $k;
                return [$cim . (substr($k, -13) === '-hosszabbitas' ? ' – hosszabbítás' : ''), ''];
            }, 'Alkalom'); ?>
          <?php endif; ?>
        </section>

        <div class="grid">
          <section class="card">
            <h2>Űrlapok</h2>
            <div class="stat-scroll"><table class="table table--compact">
              <thead><tr><th>Űrlap</th><th class="num">Megnézték</th><th class="num">Elküldték</th><th class="num">Arány</th></tr></thead>
              <tbody>
              <?php foreach (['/eloadas-igenyles.html', '/mentoralas-jelentkezes.html', '/informalis-kineziologia-bejelentkezes.html', '/bio-narrativa-jelentkezes.html', '/elallas.html'] as $u):
                  $m = $oldalak[$u] ?? 0; $k = $urlapKuldes[$u] ?? 0; ?>
                <tr><td><?= h(stat_oldalnev($u) ?: $u) ?></td><td class="num"><?= sz($m) ?></td><td class="num"><?= sz($k) ?></td><td class="num muted"><?= szazalek($k, $m) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
            <h3>Kapcsolatfelvétel</h3>
            <p>E-mail-címre kattintás: <strong><?= sz($kattintas['email']) ?></strong> · Telefonszámra kattintás: <strong><?= sz($kattintas['telefon']) ?></strong></p>
          </section>
          <section class="card">
            <h2>Videók</h2>
            <?php $aktivHozz = (int)db()->query("SELECT COUNT(*) FROM video_hozzaferesek WHERE allapot = 'aktiv'")->fetchColumn(); ?>
            <p>Élő hozzáférések most: <strong><?= sz($aktivHozz) ?></strong></p>
            <h3>Lejátszások sorozatonként</h3>
            <?php stat_savos_lista($videoLejatszas, array_sum($videoLejatszas), function ($k) { $t = termek($k); return [$t['rovidCim'] ?? $k, $t['alcim'] ?? '']; }, 'Lejátszás'); ?>
            <p class="muted small">Egy lejátszás = egy videó elindítása (a megnyitás után az első indítás).</p>
          </section>
        </div>

        <p class="muted small">A mérés süti nélkül működik: a látogatót csak aznapra, egy naponta cserélődő titkos kulccsal képzett kód azonosítja, az IP-címet nem tároljuk. A számok ezért becslések (pl. ugyanaz a látogató két napon kétszer számít), és nem tartalmazzák a robotokat, a „Ne kövess” beállítású böngészőket és a te böngésződet.<?= $kezdet ? ' A mérés kezdete: ' . h(video_datum((string)$kezdet)) : '' ?>
          <a href="#" data-szamol-valt>Ezt a böngészőt mégis számold bele</a></p>

        <div class="stat-tip" role="tooltip" hidden></div>
        <script src="statisztika.js?v=1" defer></script>
    <?php });
}
