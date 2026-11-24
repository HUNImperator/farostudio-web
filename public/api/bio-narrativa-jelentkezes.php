<?php
/**
 * POST api/bio-narrativa-jelentkezes.php – ismerkedő beszélgetés kérése a Bio-Narratíva programhoz
 * (bio-narrativa-jelentkezes.html). Levél az info@ címre + visszaigazolás a küldőnek.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';

urlap_kezel([
    'level_cim'   => 'Új ismerkedő beszélgetés kérése (Bio-Narratíva)',
    'targy'       => function (array $a): string { return 'Bio-Narratíva – ismerkedő beszélgetés: ' . $a['nev']; },
    'koszono_cim' => 'Köszönöm, hogy megkerestél!',
    'koszono'     => 'Köszönöm, hogy megkerestél! 1–2 napon belül jelentkezem, hogy egyeztessük a beszélgetés időpontját.',
    'mezok'       => [
        'nev'       => ['cimke' => 'Név', 'max' => 150, 'kotelezo' => 'Hiányzik a név.'],
        'email'     => ['cimke' => 'E-mail-cím', 'tipus' => 'email', 'kotelezo' => 'Hiányzik az e-mail-cím.'],
        'telefon'   => ['cimke' => 'Telefonszám', 'max' => 30, 'kotelezo' => 'Hiányzik a telefonszám.'],
        'forma'     => ['cimke' => 'Hogyan beszéljünk', 'tipus' => 'valasztas', 'opciok' => ['Telefonon', 'Videóhívásban']],
        'raer'      => ['cimke' => 'Alkalmas időpont a következő 1–2 hétben'],
        'miert'     => ['cimke' => 'Mi hív most a programba', 'max' => 3000, 'tipus' => 'tobbsoros', 'kotelezo' => 'Írd le pár mondatban, mi hív most a programba.'],
        'cel'       => ['cimke' => 'Mi legyen másképp a program végére', 'max' => 3000, 'tipus' => 'tobbsoros'],
        'onismeret' => ['cimke' => 'Korábbi önismereti munka', 'tipus' => 'valasztas', 'opciok' => ['Nem', 'Igen, már dolgoztunk együtt', 'Igen, máshol']],
        'keretek'   => ['cimke' => 'Program keretei (10 alkalom, 500 000 Ft) tudomásul véve', 'tipus' => 'jelolo', 'kotelezo' => 'A program kereteinek tudomásulvétele kötelező.'],
    ],
]);
