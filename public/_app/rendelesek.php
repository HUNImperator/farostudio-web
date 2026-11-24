<?php
defined('FARO') or exit;

/* ---------- állapotok ---------- */
const ALLAPOTOK = [
    'uj'                => 'Új',
    'fizetesre_var'     => 'Fizetésre vár',
    'fizetve'           => 'Fizetve',
    'csomag_letrehozva' => 'Csomag létrehozva',
    'feladva'           => 'Feladva',
    'teljesitve'        => 'Teljesítve',
    'lemondva'          => 'Lemondva',
];
const FIZETESI_MODOK = [
    'kartya'   => 'Bankkártya (Stripe)',
    'atutalas' => 'Banki átutalás',
];

function allapot_nev(string $a): string
{
    return ALLAPOTOK[$a] ?? $a;
}

/* ---------- beérkező rendelés ellenőrzése ---------- */
function rendeles_ellenoriz(array $be): array
{
    $hibak = [];
    $s = function ($ut, int $max = 190) use ($be): string {
        $v = $be;
        foreach ($ut as $k) {
            $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : '';
        }
        $v = is_scalar($v) ? trim((string)$v) : '';
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        return mb_substr($v, 0, $max);
    };

    $r = [
        'vevo_nev'         => $s(['vevo', 'nev'], 150),
        'vevo_email'       => $s(['vevo', 'email']),
        'vevo_telefon'     => $s(['vevo', 'telefon'], 30),
        'szamla_nev'       => $s(['szamlazas', 'nev']),
        'szamla_irsz'      => $s(['szamlazas', 'irsz'], 10),
        'szamla_telepules' => $s(['szamlazas', 'telepules'], 100),
        'szamla_cim'       => $s(['szamlazas', 'cim']),
        'szamla_adoszam'   => $s(['szamlazas', 'adoszam'], 20),
        'apt_id'           => $s(['foxpost', 'id'], 40),
        'apt_nev'          => $s(['foxpost', 'nev']),
        'apt_cim'          => $s(['foxpost', 'cim'], 255),
        'fizetes'          => $s(['fizetes'], 20),
        'megjegyzes'       => $s(['megjegyzes'], 1000),
    ];

    // tételek – csak létező, elérhető termék, szerveroldali árral
    $tetelek = [];
    $lista = isset($be['tetelek']) && is_array($be['tetelek']) ? $be['tetelek'] : [];
    foreach (array_slice($lista, 0, 30) as $t) {
        $id = is_array($t) && isset($t['id']) ? (string)$t['id'] : '';
        $db = is_array($t) && isset($t['db']) ? (int)$t['db'] : 0;
        $termek = termek($id);
        if (!$termek || empty($termek['elerheto']) || !is_int($termek['ar'] ?? null)) {
            $hibak[] = 'Egy tétel jelenleg nem rendelhető.';
            continue;
        }
        $tipus = $termek['tipus'];
        if ($tipus !== 'konyv') {
            $db = 1;   // videósorozatból és hosszabbításból 1 db
        }
        if ($db < 1 || $db > 10) {
            $hibak[] = 'Érvénytelen darabszám.';
            continue;
        }
        $dedik = $tipus === 'konyv' && isset($t['dedikalas']) && is_scalar($t['dedikalas']) ? mb_substr(trim((string)$t['dedikalas']), 0, 300) : '';
        if (isset($tetelek[$id])) {
            if ($tipus === 'konyv') {
                $tetelek[$id]['db'] = min(10, $tetelek[$id]['db'] + $db);
            }
        } else {
            $tetelek[$id] = ['termek_id' => $id, 'tipus' => $tipus, 'cim' => $termek['cim'], 'egysegar' => $termek['ar'], 'db' => $db, 'dedikalas' => $dedik];
        }
    }
    if (!$tetelek) $hibak[] = 'A megrendelés üres.';
    $vanTipus = function (string $tipus) use ($tetelek): bool {
        foreach ($tetelek as $t) {
            if ($t['tipus'] === $tipus) return true;
        }
        return false;
    };
    $vanKonyv = $vanTipus('konyv');
    $vanVideo = $vanTipus('video');
    $vanHosszabbitas = $vanTipus('hosszabbitas');

    if ($r['vevo_nev'] === '') $hibak[] = 'Hiányzik a név.';
    if (!filter_var($r['vevo_email'], FILTER_VALIDATE_EMAIL)) $hibak[] = 'Érvénytelen e-mail-cím.';
    $r['vevo_email'] = mb_strtolower($r['vevo_email']);
    $tel = preg_replace('/[\s\-()\/]/', '', $r['vevo_telefon']);
    if ($vanKonyv) {
        // a Foxpost SMS-ben küldi az átvételi kódot, ezért magyar mobilszám kell
        if (!preg_match('/^(\+36|06|0036)(20|30|31|50|70)\d{7}$/', $tel)) {
            $hibak[] = 'Érvénytelen mobilszám.';
        } else {
            $r['vevo_telefon'] = '+36' . preg_replace('/^(\+36|06|0036)/', '', $tel);
        }
    } elseif (!preg_match('/^\+?\d{6,15}$/', $tel)) {
        $hibak[] = 'Érvénytelen telefonszám.';
    }
    if ($r['szamla_nev'] === '') $r['szamla_nev'] = $r['vevo_nev'];
    if (!preg_match('/^\d{4}$/', $r['szamla_irsz'])) $hibak[] = 'Érvénytelen irányítószám.';
    if ($r['szamla_telepules'] === '') $hibak[] = 'Hiányzik a település.';
    if ($r['szamla_cim'] === '') $hibak[] = 'Hiányzik az utca, házszám.';
    if ($r['szamla_adoszam'] !== '' && !preg_match('/^\d{8}-\d-\d{2}$/', $r['szamla_adoszam'])) $hibak[] = 'Érvénytelen adószám.';
    if ($vanKonyv) {
        if ($r['apt_id'] === '' || $r['apt_nev'] === '') $hibak[] = 'Nincs kiválasztva Foxpost-automata.';
    } else {
        $r['apt_id'] = $r['apt_nev'] = $r['apt_cim'] = '';
    }
    if (!isset(FIZETESI_MODOK[$r['fizetes']])) $hibak[] = 'Ismeretlen fizetési mód.';

    // videósorozatok: elvégzett modulok, elállási nyilatkozat, meglévő hozzáférések
    $modulok = [];
    if ($vanVideo) {
        foreach ((isset($be['modulok']) && is_array($be['modulok']) ? $be['modulok'] : []) as $m) {
            $m = (int)$m;
            if ($m >= 1 && $m <= 6) $modulok[$m] = $m;
        }
        ksort($modulok);
        if (!$modulok) {
            $hibak[] = 'Jelöld be, mely modulokat végezted el.';
        }
        foreach ($tetelek as $t) {
            if ($t['tipus'] === 'video' && !isset($modulok[(int)termek($t['termek_id'])['modul']])) {
                $hibak[] = 'Csak elvégzett modulhoz rendelhetsz videósorozatot (' . termek($t['termek_id'])['rovidCim'] . ').';
            }
        }
    }
    if (($vanVideo || $vanHosszabbitas || $vanTipus('program')) && empty($be['digitalis'])) {
        $hibak[] = 'A digitális tartalom rendeléséhez el kell fogadnod a rá vonatkozó nyilatkozatot.';
    }
    if (($vanVideo || $vanHosszabbitas) && filter_var($r['vevo_email'], FILTER_VALIDATE_EMAIL)) {
        foreach ($tetelek as $t) {
            if ($t['tipus'] === 'video') {
                $h = video_hozzaferes_email_sorozat($r['vevo_email'], $t['termek_id']);
                if ($h && in_array(video_allapot($h), ['fuggoben', 'aktiv'], true)) {
                    $hibak[] = 'A(z) ' . termek($t['termek_id'])['rovidCim'] . ' sorozathoz már van ' . (video_allapot($h) === 'aktiv' ? 'élő hozzáférésed – a videós oldalon meghosszabbíthatod.' : 'jóváhagyásra váró rendelésed.');
                }
            } elseif ($t['tipus'] === 'hosszabbitas') {
                $sorozat = termek($t['termek_id'])['sorozat'];
                if (isset($tetelek[$sorozat])) {
                    $hibak[] = 'Ugyanazt a sorozatot nem rendelheted meg és hosszabbíthatod egyszerre.';
                }
                $h = video_hozzaferes_email_sorozat($r['vevo_email'], $sorozat);
                if (!$h || !in_array(video_allapot($h), ['aktiv', 'lejart'], true)) {
                    $hibak[] = 'Hosszabbítani csak meglévő hozzáférést lehet – ehhez az e-mail-címhez nem tartozik ilyen (' . termek($sorozat)['rovidCim'] . '). A videós oldalon használt e-mail-címet add meg.';
                }
            }
        }
    }

    $r['tetelek'] = array_values($tetelek);
    $r['van_konyv'] = $vanKonyv;
    $r['van_video'] = $vanVideo;
    $r['elvegzett_modulok'] = implode(',', $modulok);
    $r['konyvek_osszeg'] = array_sum(array_map(function ($t) { return $t['egysegar'] * $t['db']; }, $r['tetelek']));
    $r['szallitas'] = $vanKonyv ? (int)katalogus()['szallitas']['ar'] : 0;
    $r['vegosszeg'] = $r['konyvek_osszeg'] + $r['szallitas'];

    return [$r, array_values(array_unique($hibak))];
}

/* ---------- mentés ---------- */
function rendeles_letrehoz(array $r, string $kezdoAllapot): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $most = most();
        $st = $pdo->prepare('INSERT INTO rendelesek
            (letrehozva, frissitve, allapot, fizetes, vevo_nev, vevo_email, vevo_telefon,
             szamla_nev, szamla_irsz, szamla_telepules, szamla_cim, szamla_adoszam,
             apt_id, apt_nev, apt_cim, megjegyzes, konyvek_osszeg, szallitas, vegosszeg, elvegzett_modulok)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $most, $most, $kezdoAllapot, $r['fizetes'], $r['vevo_nev'], $r['vevo_email'], $r['vevo_telefon'],
            $r['szamla_nev'], $r['szamla_irsz'], $r['szamla_telepules'], $r['szamla_cim'], $r['szamla_adoszam'],
            $r['apt_id'], $r['apt_nev'], $r['apt_cim'], $r['megjegyzes'], $r['konyvek_osszeg'], $r['szallitas'], $r['vegosszeg'],
            $r['elvegzett_modulok'] ?? '',
        ]);
        $id = (int)$pdo->lastInsertId();
        $szam = 'FARO-' . date('Y') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE rendelesek SET rendelesszam = ? WHERE id = ?')->execute([$szam, $id]);

        $ti = $pdo->prepare('INSERT INTO rendeles_tetelek (rendeles_id, termek_id, cim, egysegar, db, dedikalas, tipus) VALUES (?,?,?,?,?,?,?)');
        foreach ($r['tetelek'] as $t) {
            $ti->execute([$id, $t['termek_id'], $t['cim'], $t['egysegar'], $t['db'], $t['dedikalas'], $t['tipus'] ?? 'konyv']);
            // videósorozat: jóváhagyásra váró hozzáférés (a jóváhagyás a fizetés után lehetséges)
            if (($t['tipus'] ?? 'konyv') === 'video') {
                $pdo->prepare('INSERT INTO video_hozzaferesek (rendeles_id, email, sorozat, allapot, letrehozva) VALUES (?,?,?,?,?)')
                    ->execute([$id, $r['vevo_email'], $t['termek_id'], 'fuggoben', $most]);
            }
        }
        naplo($id, 'Rendelés beérkezett (' . FIZETESI_MODOK[$r['fizetes']] . ', ' . ft($r['vegosszeg']) . ').', 'rendszer');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return rendeles_betolt($id);
}

function rendeles_betolt(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM rendelesek WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        return null;
    }
    $t = db()->prepare('SELECT * FROM rendeles_tetelek WHERE rendeles_id = ? ORDER BY id');
    $t->execute([$id]);
    $r['tetelek'] = $t->fetchAll();
    return $r;
}

function naplo(int $rendelesId, string $esemeny, string $szerzo): void
{
    db()->prepare('INSERT INTO rendeles_naplo (rendeles_id, ido, esemeny, szerzo) VALUES (?,?,?,?)')
        ->execute([$rendelesId, most(), mb_substr($esemeny, 0, 500), $szerzo]);
}

function rendeles_naplo(int $id): array
{
    $st = db()->prepare('SELECT * FROM rendeles_naplo WHERE rendeles_id = ? ORDER BY id DESC');
    $st->execute([$id]);
    return $st->fetchAll();
}

function rendeles_allapot(int $id, string $uj, string $szerzo, string $megj = ''): void
{
    if (!isset(ALLAPOTOK[$uj])) {
        throw new InvalidArgumentException('Ismeretlen állapot.');
    }
    $r = rendeles_betolt($id);
    if (!$r || $r['allapot'] === $uj) {
        return;
    }
    $mezok = 'allapot = ?, frissitve = ?';
    $ertekek = [$uj, most()];
    if ($uj === 'fizetve' && empty($r['fizetve_ekkor'])) {
        $mezok .= ', fizetve_ekkor = ?';
        $ertekek[] = most();
    }
    $ertekek[] = $id;
    db()->prepare("UPDATE rendelesek SET $mezok WHERE id = ?")->execute($ertekek);
    naplo($id, 'Állapot: ' . allapot_nev($r['allapot']) . ' → ' . allapot_nev($uj) . ($megj !== '' ? ' (' . $megj . ')' : ''), $szerzo);
}

/** Van-e adott típusú tétel a (betöltött) rendelésben. */
function rendeles_van(array $r, string $tipus): bool
{
    foreach ($r['tetelek'] as $t) {
        if (($t['tipus'] ?? 'konyv') === $tipus) return true;
    }
    return false;
}

function rendeles_torol(int $id): void
{
    // a tételek és a napló az idegen kulcs miatt vele együtt törlődik
    foreach (feltoltesek($id) as $f) {
        @unlink(feltoltes_utvonal($f));
    }
    db()->prepare('DELETE FROM rendeles_feltoltesek WHERE rendeles_id = ?')->execute([$id]);
    db()->prepare("DELETE FROM video_hozzaferesek WHERE rendeles_id = ? AND allapot IN ('fuggoben','elutasitva')")->execute([$id]);
    db()->prepare('DELETE FROM rendeles_tetelek WHERE rendeles_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM rendeles_naplo WHERE rendeles_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM rendelesek WHERE id = ?')->execute([$id]);
}
