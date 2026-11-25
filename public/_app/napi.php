<?php
/**
 * Napi ütemezett feladat (cPanel → Cron Jobs, naponta egyszer):
 *   php /home/whiteman/test.farostudio.hu/_app/napi.php
 *
 * 1. Figyelmeztető levél 10 nappal a videós hozzáférés lejárta előtt.
 * 2. Lejárt hozzáférések lezárása („Lejárt”), értesítő levéllel.
 * 3. Adattakarítás: ha egy e-mail-címhez egy éve nincs élő vagy jóváhagyásra váró
 *    hozzáférés, a videós fiók és a feltöltött igazolások törlődnek
 *    (a rendelések megmaradnak – azokat csak kézzel lehet törölni).
 * 4. Régi belépési próbálkozások törlése (30 nap).
 * 5. Látogatottságmérés: a korábbi napok lezárása (napi kódok és kulcsok törlése).
 * 6. Foxpost: az úton lévő csomagok állapotának frissítése.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

const FIGYELMEZTETES_NAP = 10;
const TOROLES_NAP = 365;

$most = time();
$naplo = [];

/* 1. figyelmeztetés */
$st = db()->prepare("SELECT * FROM video_hozzaferesek WHERE allapot = 'aktiv' AND figyelmeztetve = 0 AND lejarat > ? AND lejarat <= ?");
$st->execute([date('Y-m-d H:i:s', $most), date('Y-m-d H:i:s', $most + FIGYELMEZTETES_NAP * 86400)]);
foreach ($st->fetchAll() as $h) {
    $f = video_felhasznalo_email($h['email']);
    if (!$f) continue;
    if (level_video_figyelmeztetes($f, $h)) {
        db()->prepare('UPDATE video_hozzaferesek SET figyelmeztetve = 1 WHERE id = ?')->execute([$h['id']]);
        naplo((int)$h['rendeles_id'], 'Figyelmeztető levél: a(z) ' . $h['sorozat'] . ' hozzáférés lejár (' . video_datum($h['lejarat']) . ').', 'napi feladat');
        $naplo[] = 'Figyelmeztetés: ' . $h['email'] . ' – ' . $h['sorozat'];
    }
}

/* 2. lejárt hozzáférések */
$st = db()->prepare("SELECT * FROM video_hozzaferesek WHERE allapot = 'aktiv' AND lejarat <= ?");
$st->execute([date('Y-m-d H:i:s', $most)]);
foreach ($st->fetchAll() as $h) {
    db()->prepare("UPDATE video_hozzaferesek SET allapot = 'lejart' WHERE id = ?")->execute([$h['id']]);
    naplo((int)$h['rendeles_id'], 'A videós hozzáférés lejárt: ' . $h['sorozat'] . '.', 'napi feladat');
    $f = video_felhasznalo_email($h['email']);
    if ($f) {
        level_video_lejart($f, $h);
    }
    $naplo[] = 'Lejárt: ' . $h['email'] . ' – ' . $h['sorozat'];
}

/* 3. adattakarítás egy év után */
$hatar = date('Y-m-d H:i:s', $most - TOROLES_NAP * 86400);
$emailek = db()->query('SELECT DISTINCT email FROM video_hozzaferesek')->fetchAll(PDO::FETCH_COLUMN);
foreach ($emailek as $email) {
    $hk = video_hozzaferesek_email($email);
    $elo = false;
    $utolso = '';
    foreach ($hk as $h) {
        if (in_array(video_allapot($h), ['fuggoben', 'aktiv'], true)) $elo = true;
        $utolso = max($utolso, (string)$h['lejarat'], (string)$h['dontes_ekkor'], (string)$h['letrehozva']);
    }
    if ($elo || $utolso === '' || $utolso > $hatar) continue;
    // feltöltött igazolások törlése (az e-mail-cím videós rendeléseinél)
    $rs = db()->prepare('SELECT DISTINCT rendeles_id FROM video_hozzaferesek WHERE email = ?');
    $rs->execute([$email]);
    foreach ($rs->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $db = 0;
        foreach (feltoltesek((int)$rid) as $fl) {
            @unlink(feltoltes_utvonal($fl));
            $db++;
        }
        if ($db) {
            db()->prepare('DELETE FROM rendeles_feltoltesek WHERE rendeles_id = ?')->execute([$rid]);
            naplo((int)$rid, 'Adattakarítás: ' . $db . ' feltöltött igazolás törölve (egy éve nincs élő hozzáférés).', 'napi feladat');
        }
    }
    $f = video_felhasznalo_email($email);
    if ($f) {
        db()->prepare('UPDATE video_hozzaferesek SET felhasznalo_id = NULL WHERE felhasznalo_id = ?')->execute([$f['id']]);
        db()->prepare('DELETE FROM video_felhasznalok WHERE id = ?')->execute([$f['id']]);
        $naplo[] = 'Fiók törölve: ' . $email;
    }
}

/* 4. régi belépési próbálkozások */
db()->prepare('DELETE FROM belepesi_kiserletek WHERE ido < ?')->execute([date('Y-m-d H:i:s', $most - 30 * 86400)]);

/* 6. Foxpost: az úton lévő csomagok állapota (Feladva / Teljesítve / figyelmeztetés) */
foreach (foxpost_napi() as $sor) {
    $naplo[] = 'Foxpost – ' . $sor;
}

/* 5. látogatottságmérés: a korábbi napok lezárása (napi kódok és kulcsok törlése) */
require_once FARO_APP . '/statisztika.php';
stat_lezar(date('Y-m-d'));

echo date('Y-m-d H:i') . ' – napi feladat kész. ' . ($naplo ? implode('; ', $naplo) : 'Nem volt teendő.') . "\n";
