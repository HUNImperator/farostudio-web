<?php
defined('FARO') or exit;

/**
 * Admin belépés: jelszó-hash, biztonságos munkamenet, CSRF-védelem,
 * belépési próbálkozások korlátozása (15 percenként legfeljebb 5 hibás).
 */
const BELEPES_MAX_HIBA = 5;
const BELEPES_ABLAK_PERC = 15;
const MUNKAMENET_PERC = 120;

function admin_munkamenet(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_name('FAROADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/admin/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    // tétlenség után kiléptetés
    if (!empty($_SESSION['admin_id']) && (time() - ($_SESSION['utolso'] ?? 0)) > MUNKAMENET_PERC * 60) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['utolso'] = time();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function admin_bejelentkezve(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    $st = db()->prepare('SELECT id, felhasznalo, utolso_belepes FROM adminok WHERE id = ?');
    $st->execute([(int)$_SESSION['admin_id']]);
    return $st->fetch() ?: null;
}

function admin_van(): bool
{
    return (int)db()->query('SELECT COUNT(*) FROM adminok')->fetchColumn() > 0;
}

function kliens_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '?'), 0, 64);
}

function belepes_tiltva(): bool
{
    $hatar = date('Y-m-d H:i:s', time() - BELEPES_ABLAK_PERC * 60);
    $st = db()->prepare('SELECT COUNT(*) FROM belepesi_kiserletek WHERE ip = ? AND ido > ? AND sikeres = 0');
    $st->execute([kliens_ip(), $hatar]);
    return (int)$st->fetchColumn() >= BELEPES_MAX_HIBA;
}

function admin_belep(string $felhasznalo, string $jelszo): bool
{
    $st = db()->prepare('SELECT * FROM adminok WHERE felhasznalo = ?');
    $st->execute([$felhasznalo]);
    $a = $st->fetch();
    // időzítéses támadás ellen akkor is ellenőrzünk egy hash-t, ha nincs ilyen felhasználó
    $hash = $a['jelszo_hash'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    $ok = password_verify($jelszo, $hash) && $a;
    db()->prepare('INSERT INTO belepesi_kiserletek (ip, ido, sikeres) VALUES (?,?,?)')->execute([kliens_ip(), most(), $ok ? 1 : 0]);
    // régi bejegyzések takarítása
    db()->prepare('DELETE FROM belepesi_kiserletek WHERE ido < ?')->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]);
    if (!$ok) {
        return false;
    }
    if (password_needs_rehash($a['jelszo_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE adminok SET jelszo_hash = ? WHERE id = ?')->execute([password_hash($jelszo, PASSWORD_DEFAULT), $a['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$a['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['elozo_belepes'] = $a['utolso_belepes'];
    db()->prepare('UPDATE adminok SET utolso_belepes = ? WHERE id = ?')->execute([most(), $a['id']]);
    return true;
}

function admin_kilep(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function admin_letrehoz(string $felhasznalo, string $jelszo): void
{
    db()->prepare('INSERT INTO adminok (felhasznalo, jelszo_hash, letrehozva) VALUES (?,?,?)')
        ->execute([$felhasznalo, password_hash($jelszo, PASSWORD_DEFAULT), most()]);
}

function admin_jelszo_csere(int $id, string $uj): void
{
    db()->prepare('UPDATE adminok SET jelszo_hash = ? WHERE id = ?')->execute([password_hash($uj, PASSWORD_DEFAULT), $id]);
}

function jelszo_hiba(string $jelszo, string $ujra): ?string
{
    if (mb_strlen($jelszo) < 10) return 'A jelszó legalább 10 karakter legyen.';
    if ($jelszo !== $ujra) return 'A két jelszó nem egyezik.';
    return null;
}

function csrf_mezo(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf'] ?? '') . '">';
}

function csrf_ellenoriz(): void
{
    $kuldott = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $kuldott)) {
        faro_fatal('A munkamenet lejárt, kérlek, töltsd újra az oldalt.', 400);
    }
}
