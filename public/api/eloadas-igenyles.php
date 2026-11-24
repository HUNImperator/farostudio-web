<?php
/**
 * POST api/eloadas-igenyles.php – előadás igénylése (eloadas-igenyles.html).
 * Levél az info@ címre (válaszcím: a küldő) + visszaigazolás a küldőnek.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';

urlap_kezel([
    'level_cim'   => 'Új előadás-igénylés',
    'targy'       => function (array $a): string { return 'Előadás-igénylés – ' . $a['intezmeny']; },
    'koszono_cim' => 'Köszönöm a megkeresést!',
    'koszono'     => 'Köszönöm a megkeresést! Hamarosan jelentkezem, hogy egyeztessük a részleteket.',
    'mezok'       => [
        'nev'       => ['cimke' => 'Név', 'max' => 150, 'kotelezo' => 'Hiányzik a név.'],
        'intezmeny' => ['cimke' => 'Intézmény / közösség', 'kotelezo' => 'Hiányzik az intézmény / közösség neve.'],
        'email'     => ['cimke' => 'E-mail-cím', 'tipus' => 'email', 'kotelezo' => 'Hiányzik az e-mail-cím.'],
        'telefon'   => ['cimke' => 'Telefonszám', 'max' => 30],
        'helyszin'  => ['cimke' => 'Település, helyszín', 'kotelezo' => 'Hiányzik a település, helyszín.'],
        'alkalom'   => ['cimke' => 'Alkalom', 'tipus' => 'valasztas', 'opciok' => ['Előadás', 'Interaktív beszélgetés', 'Könyvbemutató, író-olvasó találkozó', 'Workshop', 'Egyéb']],
        'tema'      => ['cimke' => 'Téma'],
        'idopont'   => ['cimke' => 'Tervezett időpont / időszak'],
        'letszam'   => ['cimke' => 'Várható létszám és közönség'],
        'uzenet'    => ['cimke' => 'Üzenet, egyéb kérés', 'max' => 3000, 'tipus' => 'tobbsoros'],
    ],
]);
