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
    'site_url' => 'https://farostudio.hu',

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

    // Stripe – bankkártyás fizetés
    // Teszteléshez a Stripe TESZT módjának kulcsai (sk_test_…),
    // élesben az éles kulcs (sk_live_…) és az éles webhook titka (webhook: https://farostudio.hu/api/stripe-webhook.php).
    'stripe' => [
        'secret_key'     => '<Stripe titkos kulcs: sk_test_… vagy sk_live_…>',
        'webhook_secret' => '<a webhook aláíró titka: whsec_…>',
    ],

    // Számlázz.hu – Számla Agent (Számlázz.hu fiók → Beállítások → Számla Agent kulcsok)
    'szamlazz' => [
        'agent_kulcs' => '',     // üresen hagyva nem készül számla
        'eszamla'     => true,   // true = e-számla (PDF), false = papír alapú
    ],

    // One Brain® videók: a HLS-videók és a feltöltött igazolások helye (a webgyökéren KÍVÜL).
    // Ha nem adod meg, az alapértelmezés: /home/whiteman/faro-videok és /home/whiteman/faro-feltoltesek
    // 'video_mappa'     => '/home/whiteman/faro-videok',
    // 'feltoltes_mappa' => '/home/whiteman/faro-feltoltesek',

    // Foxpost Web API – az adatok a foxpost.hu üzleti fiókban: Beállítások (https://foxpost.hu/beallitasok)
    'foxpost' => [
        'felhasznalo' => '',       // API felhasználónév (Basic auth username)
        'jelszo'      => '',       // API jelszó (Basic auth password)
        'api_kulcs'   => '',       // API-key
        'alap_meret'  => 's',      // alapértelmezett csomagméret: xs, s, m, l, xl
        'cimke_meret' => 'A6',     // címke: A6, A7 (4 db/A4), A5 vagy _85X85
    ],
];
