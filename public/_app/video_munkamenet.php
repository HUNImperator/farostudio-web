<?php
defined('FARO') or exit;

/**
 * A videós oldal (/videok/) belépése.
 * - Egyszerre egy munkamenet felhasználónként: új belépéskor a korábbi eszköz kilép.
 * - A süti csak a /videok/ útvonalon él, HttpOnly, SameSite=Lax, HTTPS-en Secure.
 * - Tétlenség után (VIDEO_MUNKAMENET_ORA) újra be kell lépni.
 * - Belépési próbálkozások: 15 percenként legfeljebb 10 hibás IP-nként.
 */
require_once FARO_APP . '/auth.php';   // kliens_ip()

const VIDEO_SUTI = 'FAROVIDEO';
const VIDEO_MUNKAMENET_ORA = 12;
const VIDEO_MAX_HIBA = 10;

function video_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

function video_suti_beallit(string $ertek, int $lejar): void
{
    setcookie(VIDEO_SUTI, $ertek, [
        'expires'  => $lejar,
        'path'     => '/videok/',
        'secure'   => video_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * A bejelentkezett videós felhasználó, vagy null.
 * $masholBelepett: true, ha volt süti, de közben másik eszközről beléptek.
 */
function video_bejelentkezve(?bool &$masholBelepett = null): ?array
{
    $masholBelepett = false;
    $suti = (string)($_COOKIE[VIDEO_SUTI] ?? '');
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $suti, $m)) {
        return null;
    }
    $f = video_felhasznalo((int)$m[1]);
    if (!$f || !$f['munkamenet'] || !hash_equals($f['munkamenet'], hash('sha256', $m[2]))) {
        $masholBelepett = (bool)$f;
        return null;
    }
    if (!$f['munkamenet_ido'] || time() - strtotime($f['munkamenet_ido']) > VIDEO_MUNKAMENET_ORA * 3600) {
        return null;
    }
    // aktivitás frissítése legfeljebb 5 percenként
    if (time() - strtotime($f['munkamenet_ido']) > 300) {
        db()->prepare('UPDATE video_felhasznalok SET munkamenet_ido = ? WHERE id = ?')->execute([most(), $f['id']]);
    }
    return $f;
}

function video_belep(string $email, string $jelszo): ?array
{
    $f = video_felhasznalo_email(trim($email));
    $ok = $f && password_verify($jelszo, $f['jelszo_hash']);
    db()->prepare('INSERT INTO belepesi_kiserletek (ip, ido, sikeres) VALUES (?,?,?)')->execute(['v:' . kliens_ip(), most(), $ok ? 1 : 0]);
    if (!$ok) {
        return null;
    }
    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE video_felhasznalok SET munkamenet = ?, munkamenet_ido = ?, utolso_belepes = ? WHERE id = ?')
        ->execute([hash('sha256', $token), most(), most(), $f['id']]);
    if (password_needs_rehash($f['jelszo_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE video_felhasznalok SET jelszo_hash = ? WHERE id = ?')->execute([password_hash($jelszo, PASSWORD_DEFAULT), $f['id']]);
    }
    video_suti_beallit($f['id'] . '.' . $token, 0);
    return $f;
}

function video_kilep(?array $f): void
{
    if ($f) {
        db()->prepare('UPDATE video_felhasznalok SET munkamenet = NULL WHERE id = ?')->execute([$f['id']]);
    }
    video_suti_beallit('', time() - 3600);
}

function video_belepes_tiltva(): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM belepesi_kiserletek WHERE ip = ? AND sikeres = 0 AND ido > ?');
    $st->execute(['v:' . kliens_ip(), date('Y-m-d H:i:s', time() - 15 * 60)]);
    return (int)$st->fetchColumn() >= VIDEO_MAX_HIBA;
}

/** CSRF a videós oldal űrlapjaihoz: a munkamenet-süti titkából képzett jel. */
function video_csrf(): string
{
    return hash_hmac('sha256', 'video-csrf', (string)($_COOKIE[VIDEO_SUTI] ?? 'vendeg') . (faro_config()['setup_token'] ?? 'faro'));
}

function video_csrf_ok(): bool
{
    return hash_equals(video_csrf(), (string)($_POST['csrf'] ?? ''));
}

/** Van-e élő hozzáférése a felhasználónak az adott sorozathoz. */
function video_nezheti(array $f, string $sorozat): ?array
{
    $h = video_hozzaferes_email_sorozat($f['email'], $sorozat);
    return $h && video_allapot($h) === 'aktiv' ? $h : null;
}

/** A videók mappája – a webgyökéren kívül (alapból: /home/<felhasználó>/faro-videok). */
function video_mappa(): string
{
    return rtrim(faro_config()['video_mappa'] ?? (faro_kulso_mappa() . '/faro-videok'), '/');
}
