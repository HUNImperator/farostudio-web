<?php
defined('FARO') or exit;

/**
 * Adatbázis-kapcsolat és táblák létrehozása.
 * Éles szerveren MySQL/MariaDB, helyi teszteléshez SQLite is megy.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $c = faro_config()['db'] ?? null;
    if (!$c || empty($c['dsn'])) {
        faro_fatal('Hiányoznak az adatbázis-beállítások.');
    }
    $pdo = new PDO($c['dsn'], $c['user'] ?? null, $c['pass'] ?? null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    if (db_driver() === 'mysql') {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_hungarian_ci");
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    } else {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    db_migrate($pdo);
    return $pdo;
}

function db_driver(): string
{
    return strpos(faro_config()['db']['dsn'], 'sqlite:') === 0 ? 'sqlite' : 'mysql';
}

function db_migrate(PDO $pdo): void
{
    db_migrate_webhook($pdo);
    db_migrate_sztorno($pdo);
    db_migrate_video($pdo);
    db_migrate_stat($pdo);
    db_migrate_blog($pdo);
    // ha a táblák már megvannak, nincs teendő
    try {
        $pdo->query('SELECT 1 FROM belepesi_kiserletek LIMIT 1');
        return;
    } catch (PDOException $e) {
        // még nincsenek táblák – létrehozzuk őket
    }
    $mysql = db_driver() === 'mysql';
    $id    = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk    = $mysql ? 'INT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
    $tail  = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci' : '';
    $text  = $mysql ? 'TEXT' : 'TEXT';
    $dt    = $mysql ? 'DATETIME' : 'TEXT';

    $pdo->exec("CREATE TABLE IF NOT EXISTS rendelesek (
        id $id,
        rendelesszam VARCHAR(20) NULL,
        letrehozva $dt NOT NULL,
        frissitve $dt NOT NULL,
        allapot VARCHAR(30) NOT NULL,
        fizetes VARCHAR(20) NOT NULL,
        vevo_nev VARCHAR(150) NOT NULL,
        vevo_email VARCHAR(190) NOT NULL,
        vevo_telefon VARCHAR(30) NOT NULL,
        szamla_nev VARCHAR(190) NOT NULL,
        szamla_irsz VARCHAR(10) NOT NULL,
        szamla_telepules VARCHAR(100) NOT NULL,
        szamla_cim VARCHAR(190) NOT NULL,
        szamla_adoszam VARCHAR(20) NOT NULL DEFAULT '',
        apt_id VARCHAR(40) NOT NULL,
        apt_nev VARCHAR(190) NOT NULL,
        apt_cim VARCHAR(255) NOT NULL,
        megjegyzes $text NULL,
        konyvek_osszeg INT NOT NULL,
        szallitas INT NOT NULL,
        vegosszeg INT NOT NULL,
        fizetve_ekkor $dt NULL,
        szamlaszam VARCHAR(50) NULL,
        sztorno_szamlaszam VARCHAR(50) NULL,
        foxpost_csomagszam VARCHAR(50) NULL,
        stripe_session VARCHAR(255) NULL,
        admin_megjegyzes $text NULL
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rendeles_tetelek (
        id $id,
        rendeles_id $fk,
        termek_id VARCHAR(60) NOT NULL,
        cim VARCHAR(190) NOT NULL,
        egysegar INT NOT NULL,
        db INT NOT NULL,
        dedikalas $text NULL,
        FOREIGN KEY (rendeles_id) REFERENCES rendelesek(id) ON DELETE CASCADE
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rendeles_naplo (
        id $id,
        rendeles_id $fk,
        ido $dt NOT NULL,
        esemeny VARCHAR(500) NOT NULL,
        szerzo VARCHAR(60) NOT NULL,
        FOREIGN KEY (rendeles_id) REFERENCES rendelesek(id) ON DELETE CASCADE
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS adminok (
        id $id,
        felhasznalo VARCHAR(60) NOT NULL,
        jelszo_hash VARCHAR(255) NOT NULL,
        letrehozva $dt NOT NULL,
        utolso_belepes $dt NULL
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS belepesi_kiserletek (
        id $id,
        ip VARCHAR(64) NOT NULL,
        ido $dt NOT NULL,
        sikeres INT NOT NULL DEFAULT 0
    )$tail");

    // indexek (ismételt futtatáskor a hibát elnyeljük)
    foreach ([
        'CREATE UNIQUE INDEX ux_rendelesszam ON rendelesek (rendelesszam)',
        'CREATE INDEX ix_allapot ON rendelesek (allapot)',
        'CREATE INDEX ix_kiserlet_ip ON belepesi_kiserletek (ip, ido)',
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* már létezik */ }
    }
}

/** Külső értesítések (pl. Stripe webhook) naplója – hibakereséshez. */
function db_migrate_webhook(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM ertesites_naplo LIMIT 1');
        return;
    } catch (PDOException $e) {
    }
    $mysql = db_driver() === 'mysql';
    $id   = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $dt   = $mysql ? 'DATETIME' : 'TEXT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS ertesites_naplo (
        id $id,
        ido $dt NOT NULL,
        forras VARCHAR(30) NOT NULL,
        esemeny VARCHAR(100) NOT NULL DEFAULT '',
        rendeles_id INT NULL,
        kod INT NOT NULL,
        eredmeny VARCHAR(500) NOT NULL
    )$tail");
}

/** Sztornó számlaszám oszlop (a már létező táblákhoz utólag). */
function db_migrate_sztorno(PDO $pdo): void
{
    try {
        $pdo->query('SELECT sztorno_szamlaszam FROM rendelesek LIMIT 1');
        return;
    } catch (PDOException $e) {
    }
    try {
        $pdo->query('SELECT 1 FROM rendelesek LIMIT 1');
    } catch (PDOException $e) {
        return; // a tábla még nincs meg – a létrehozáskor már benne lesz
    }
    $pdo->exec('ALTER TABLE rendelesek ADD COLUMN sztorno_szamlaszam VARCHAR(50) NULL');
}

/**
 * Videósorozatok: tételtípus, elvégzett modulok, feltöltött igazolások,
 * videós felhasználók és hozzáférések.
 */
function db_migrate_video(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM video_hozzaferesek LIMIT 1');
        return;
    } catch (PDOException $e) {
    }
    try {
        $pdo->query('SELECT 1 FROM rendelesek LIMIT 1');
    } catch (PDOException $e) {
        return; // az alaptáblák még nincsenek meg – a következő kéréskor jövünk vissza
    }
    $mysql = db_driver() === 'mysql';
    $id   = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $dt   = $mysql ? 'DATETIME' : 'TEXT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci' : '';
    foreach ([
        "ALTER TABLE rendeles_tetelek ADD COLUMN tipus VARCHAR(20) NOT NULL DEFAULT 'konyv'",
        "ALTER TABLE rendelesek ADD COLUMN elvegzett_modulok VARCHAR(40) NOT NULL DEFAULT ''",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* már megvan */ }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS rendeles_feltoltesek (
        id $id,
        rendeles_id INT NOT NULL,
        eredeti_nev VARCHAR(190) NOT NULL,
        fajl VARCHAR(100) NOT NULL,
        mime VARCHAR(60) NOT NULL,
        meret INT NOT NULL,
        letrehozva $dt NOT NULL
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_felhasznalok (
        id $id,
        email VARCHAR(190) NOT NULL,
        nev VARCHAR(150) NOT NULL,
        jelszo_hash VARCHAR(255) NOT NULL,
        letrehozva $dt NOT NULL,
        utolso_belepes $dt NULL,
        munkamenet VARCHAR(64) NULL,
        munkamenet_ido $dt NULL,
        jelszo_token VARCHAR(64) NULL,
        jelszo_token_lejar $dt NULL
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_hozzaferesek (
        id $id,
        rendeles_id INT NOT NULL,
        email VARCHAR(190) NOT NULL,
        sorozat VARCHAR(40) NOT NULL,
        allapot VARCHAR(20) NOT NULL,
        felhasznalo_id INT NULL,
        letrehozva $dt NOT NULL,
        dontes_ekkor $dt NULL,
        dontes_megj VARCHAR(500) NULL,
        kezdete $dt NULL,
        lejarat $dt NULL,
        figyelmeztetve INT NOT NULL DEFAULT 0
    )$tail");
    foreach ([
        'CREATE UNIQUE INDEX ux_video_email ON video_felhasznalok (email)',
        'CREATE INDEX ix_hozzaferes_email ON video_hozzaferesek (email, sorozat)',
        'CREATE INDEX ix_hozzaferes_rendeles ON video_hozzaferesek (rendeles_id)',
        'CREATE INDEX ix_feltoltes_rendeles ON rendeles_feltoltesek (rendeles_id)',
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* már létezik */ }
    }
}

/**
 * Látogatottságmérés (süti nélkül): csak összesített napi számlálók,
 * a napi egyedi látogatók kódja és a napi titkos kulcs a nap végéig él.
 */
function db_migrate_stat(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM stat_szamlalo LIMIT 1');
        return;
    } catch (PDOException $e) {
    }
    $mysql = db_driver() === 'mysql';
    $d    = $mysql ? 'DATE' : 'TEXT';
    $dt   = $mysql ? 'DATETIME' : 'TEXT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS stat_szamlalo (
        nap $d NOT NULL,
        tipus VARCHAR(20) NOT NULL,
        kulcs VARCHAR(190) NOT NULL,
        db INT NOT NULL DEFAULT 0,
        PRIMARY KEY (nap, tipus, kulcs)
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS stat_napi_latogato (
        nap $d NOT NULL,
        kod CHAR(16) NOT NULL,
        oldalak INT NOT NULL DEFAULT 0,
        utolso $dt NOT NULL,
        PRIMARY KEY (nap, kod)
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS stat_so (
        nap $d NOT NULL PRIMARY KEY,
        so VARCHAR(64) NOT NULL
    )$tail");
    try { $pdo->exec('CREATE INDEX ix_stat_tipus ON stat_szamlalo (tipus, nap)'); } catch (PDOException $e) { /* már létezik */ }
}

/** Blog: témák, cikkek, feltöltött képek. */
function db_migrate_blog(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM blog_cikkek LIMIT 1');
        return;
    } catch (PDOException $e) {
    }
    $mysql = db_driver() === 'mysql';
    $id   = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $dt   = $mysql ? 'DATETIME' : 'TEXT';
    $hosszu = $mysql ? 'MEDIUMTEXT' : 'TEXT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS blog_temak (
        id $id,
        kulcs VARCHAR(60) NOT NULL,
        nev VARCHAR(100) NOT NULL,
        leiras VARCHAR(300) NOT NULL DEFAULT '',
        sorrend INT NOT NULL DEFAULT 0
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS blog_cikkek (
        id $id,
        slug VARCHAR(120) NOT NULL,
        cim VARCHAR(200) NOT NULL,
        bevezeto TEXT NULL,
        tartalom $hosszu NULL,
        tema_id INT NULL,
        kezdokep VARCHAR(40) NULL,
        kezdokep_alt VARCHAR(200) NOT NULL DEFAULT '',
        meta_leiras VARCHAR(300) NOT NULL DEFAULT '',
        allapot VARCHAR(20) NOT NULL DEFAULT 'piszkozat',
        megjelenes $dt NOT NULL,
        olvasasi_ido INT NOT NULL DEFAULT 1,
        letrehozva $dt NOT NULL,
        frissitve $dt NOT NULL
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS blog_kepek (
        id $id,
        kulcs VARCHAR(40) NOT NULL,
        cikk_id INT NULL,
        szel INT NOT NULL,
        mag INT NOT NULL,
        meretek VARCHAR(60) NOT NULL,
        webp INT NOT NULL DEFAULT 0,
        letrehozva $dt NOT NULL
    )$tail");
    foreach ([
        'CREATE UNIQUE INDEX ux_blog_slug ON blog_cikkek (slug)',
        'CREATE INDEX ix_blog_megjelenes ON blog_cikkek (allapot, megjelenes)',
        'CREATE UNIQUE INDEX ux_blog_tema ON blog_temak (kulcs)',
        'CREATE UNIQUE INDEX ux_blog_kep ON blog_kepek (kulcs)',
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* már létezik */ }
    }
    // a meglévő négy témakör
    $ins = $pdo->prepare('INSERT INTO blog_temak (kulcs, nev, leiras, sorrend) VALUES (?,?,?,?)');
    foreach ([
        ['belso-beke', 'Belső béke & Önismeret', 'Gondolatok a stresszoldásról, az elengedésről és a tudatosságról.', 1],
        ['csaladi-mintak', 'Családi minták & Sorsfonalak', 'Transzgenerációs összefüggések közérthetően.', 2],
        ['iroi-muhely', 'Írói műhelytitkok', 'Hogyan születnek a karakterek és a történetek.', 3],
        ['ujrakezdes', 'Újrakezdés & Életerő', 'Inspiráció a változáshoz, kortól függetlenül.', 4],
    ] as $t) {
        try { $ins->execute($t); } catch (PDOException $e) { /* már megvan */ }
    }
}
