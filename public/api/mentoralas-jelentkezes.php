<?php
/**
 * POST api/mentoralas-jelentkezes.php – jelentkezés mentorálásra (mentoralas-jelentkezes.html).
 * Levél az info@ címre (válaszcím: a küldő) + visszaigazolás a küldőnek.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';

urlap_kezel([
    'level_cim'   => 'Új jelentkezés mentorálásra',
    'targy'       => function (array $a): string { return 'Mentorálás – jelentkezés: ' . $a['nev']; },
    'koszono_cim' => 'Köszönöm a jelentkezésed!',
    'koszono'     => 'Köszönöm a jelentkezésed! Hamarosan írok, hogy egyeztessük az első alkalmat.',
    // az „Egyéb” jelölő mellé beírt szöveg a listába kerül: „Egyéb: …”
    'elofeldolgoz' => function (array $a): array {
        if ($a['egyeb'] !== '') {
            $a['segitseg'] = array_values(array_diff($a['segitseg'], ['Egyéb']));
            $a['segitseg'][] = 'Egyéb: ' . $a['egyeb'];
        }
        return $a;
    },
    'mezok'       => [
        'nev'        => ['cimke' => 'Név', 'max' => 150, 'kotelezo' => 'Hiányzik a név.'],
        'email'      => ['cimke' => 'E-mail-cím', 'tipus' => 'email', 'kotelezo' => 'Hiányzik az e-mail-cím.'],
        'telefon'    => ['cimke' => 'Telefonszám', 'max' => 30],
        'szintek'    => ['cimke' => 'Elvégzett One Brain® szintek', 'tipus' => 'lista', 'kotelezo' => 'Jelöld be, mely One Brain® szinteket végezted el.', 'opciok' => [
            'Még egyiket sem, most tervezem a kezdést',
            'One Brain I. – A szakma eszközei',
            'One Brain II. – A One Brain alapjai',
            'One Brain III. – Testkód',
            'One Brain IV. – Haladó One Brain',
            'One Brain V. – Szavaknál is hangosabban',
            'One Brain VI. – Szerkezeti idegtan',
        ]],
        'oktato'     => ['cimke' => 'Oktató, iskola'],
        'vizsga'     => ['cimke' => 'Állami kineziológus vizsga', 'tipus' => 'valasztas', 'opciok' => ['Még nem', 'Készülök rá', 'Igen']],
        'gyakorlat'  => ['cimke' => 'Gyakorlat', 'tipus' => 'valasztas', 'opciok' => ['Még csak a tanfolyamokon gyakoroltam', 'Néhány gyakorló alkalom ismerősökkel', 'Rendszeresen dolgozom kliensekkel']],
        'segitseg'   => ['cimke' => 'Kért segítség', 'tipus' => 'lista', 'opciok' => [
            'Technikák, tesztelés gyakorlása',
            'Esetmegbeszélés',
            'Felkészülés vizsgára vagy esettanulmányra',
            'Saját elakadásaim, önismeret a munkában',
            'A praxis elindítása (ügyfélszerzés, árazás, keretek)',
            'Egyéb',
        ]],
        'egyeb'      => ['cimke' => 'Egyéb', 'max' => 500, 'rejtett_levelben' => true],
        'forma'      => ['cimke' => 'Forma', 'tipus' => 'valasztas', 'opciok' => ['Személyesen', 'Online', 'Mindegy']],
        'gyakorisag' => ['cimke' => 'Gyakoriság', 'tipus' => 'valasztas', 'opciok' => ['Egy alkalom', 'Rendszeresen (pl. havonta)', 'Még nem tudom']],
        'raer'       => ['cimke' => 'Alkalmas időpont'],
        'uzenet'     => ['cimke' => 'Üzenet', 'max' => 3000, 'tipus' => 'tobbsoros'],
    ],
]);
