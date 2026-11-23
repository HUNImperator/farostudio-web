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
