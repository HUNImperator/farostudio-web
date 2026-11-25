<?php
/**
 * POST api/kapcsolat.php – általános kapcsolatfelvételi űrlap (kapcsolat.html).
 * Levél az info@ címre (válaszcím: a küldő) + visszaigazolás a küldőnek.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';

urlap_kezel([
    'level_cim'   => 'Új üzenet',
    'targy'       => function (array $a): string { return 'Üzenet a weboldalról: ' . $a['nev']; },
    'koszono_cim' => 'Köszönöm az üzeneted!',
    'koszono'     => 'Köszönöm, hogy írtál! Hamarosan válaszolok.',
    'mezok'       => [
        'nev'    => ['cimke' => 'Név', 'max' => 150, 'kotelezo' => 'Hiányzik a név.'],
        'email'  => ['cimke' => 'E-mail-cím', 'tipus' => 'email', 'kotelezo' => 'Hiányzik az e-mail-cím.'],
        'uzenet' => ['cimke' => 'Üzenet', 'max' => 5000, 'tipus' => 'tobbsoros', 'kotelezo' => 'Hiányzik az üzenet.'],
    ],
]);
