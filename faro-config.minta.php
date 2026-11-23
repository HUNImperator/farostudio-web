<?php
/**
 * FARO – beállítófájl MINTA
 *
 * Ezt a fájlt NE a weboldal mappájába tedd, hanem mellé:
 *   /home/whiteman/faro-config.php
 * (cPanel → Fájlkezelő → a home mappában „+ Fájl” → faro-config.php,
 *  majd jobb klikk → Szerkesztés, és másold be ezt a tartalmat.)
 *
 * A < … > közötti részeket cseréld le a saját adataidra.
 * Ez a fájl jelszavakat tartalmaz: soha ne töltsd fel a GitHubra,
 * és ne küldd el senkinek.
 */
return [
    // A weboldal címe (az e-mailekben lévő admin-linkhez)
    'site_url' => 'https://test.farostudio.hu',

    // Adatbázis (cPanel → MySQL® Databases)
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=whiteman_faro_rendeles;charset=utf8mb4',
        'user' => '<az adatbázis-felhasználó neve, pl. whiteman_valami>',
        'pass' => '<az adatbázis-felhasználó jelszava>',
    ],

    // E-mail küldés az info@farostudio.hu fiókból
    'smtp' => [
        'host'     => 'farostudio.hu',
        'port'     => 465,
        'secure'   => 'ssl',
        'user'     => 'info@farostudio.hu',
        'pass'     => '<az info@farostudio.hu postafiók jelszava>',
        'from'     => 'info@farostudio.hu',
        'fromName' => 'Csengeri Zsuzsa – FARO',
    ],

    // Ide érkezik az értesítés új rendelésről
    'admin_email' => 'info@farostudio.hu',

    // Egyszeri kód az admin felhasználó létrehozásához (első indításkor kéri).
    // Írj ide egy hosszú, véletlenszerű szöveget; a létrehozás után törölheted.
    'setup_token' => '<egy hosszú, véletlenszerű kód>',

    // Hibák részletes kiírása – élesben maradjon false
    'debug' => false,

    // Később: 'stripe' => [...], 'szamlazz' => [...], 'foxpost' => [...]
];
