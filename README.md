# farostudio-web

Az új farostudio.hu weboldal forrása.

- `public/` – a kiszolgált fájlok (csak ez kerül a szerverre)
- `.cpanel.yml` – cPanel Git Version Control telepítési szabály

Telepítés: cPanel → Git™ Version Control → Manage → Pull or Deploy →
„Update from Remote”, majd „Deploy HEAD Commit”.

Tesztcím: https://test.farostudio.hu (noindex, robots.txt tiltja az indexelést).

## Élesítés előtt
- Az oldalakból törölni a `<meta name="robots" content="noindex, nofollow">` sort, és a `public/robots.txt`-t engedélyezőre cserélni.
- A `.cpanel.yml`-ben a DEPLOYPATH-ot átírni a végleges mappára.

## Betűtípusok
Oswald és Open Sans saját tárhelyről (`public/assets/fonts`, OFL licenc), latin + magyar karakterkészletre szűkítve.

## Rendelési rendszer (háttér)

- `public/api/rendeles.php` – a rendelési oldal ide küldi a rendelést (JSON).
- `public/admin/` – admin felület (rendelések kezelése), első indításkor admin felhasználó létrehozása a `setup_token` kóddal.
- `public/_app/` – közös PHP-kód (kívülről nem érhető el, `.htaccess`), benne a PHPMailer (LGPL).
- Beállítások: a webgyökéren **kívül**, `/home/whiteman/faro-config.php` (minta: `faro-config.minta.php`). Jelszavak és kulcsok soha nem kerülnek a GitHubra.
- Adatbázis: MySQL (`whiteman_faro_rendeles`); a táblákat az első kérés automatikusan létrehozza.
- Követelmény: PHP 7.3 vagy újabb, PDO MySQL.
- Termékadatok és szállítási díj: `public/assets/data/termekek.json` – az árakat a szerver is innen veszi.
