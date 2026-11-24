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
- Bankkártya: Stripe Checkout (`_app/stripe.php`), a fizetésről a `public/api/stripe-webhook.php` értesít.
- Számla: Számlázz.hu Számla Agent (`_app/szamlazz.php`). Amikor a rendelés Fizetve lesz (Stripe-webhook, kézi Stripe-lekérdezés vagy az admin „Fizetve” gombja), automatikusan elkészül az e-számla (alanyi adómentes, ÁFA-kulcs: AAM), és a Számlázz.hu e-mailben elküldi a vevőnek. Az adminban kézzel újra lehet próbálni, és sztornózni lehet. Beállítás: `faro-config.php` → `szamlazz` → `agent_kulcs`.

## One Brain® videósorozatok

- Termékek: `public/assets/data/termekek.json` (`tipus: "video"`, ár, videócímek); minden sorozathoz automatikusan tartozik egy „hosszabbítás (+90 nap)” tétel.
- Oldalak: `oktato-videok.html` (a 6 sorozat), `videok/` (belépés, saját sorozatok, lejátszó), `videok/media.php` (a videók kiszolgálása csak jogosultnak).
- Rendelés: videósorozatnál kötelező az elvégzett modulok megjelölése és az igazolás feltöltése (JPG/PNG/WEBP/PDF, max. 3 fájl, 10 MB). A fájlok a webgyökéren kívülre kerülnek (`/home/whiteman/faro-feltoltesek`), és csatolmányként mennek az info@ címre.
- Admin → Felhasználók: jóváhagyás (jelszó-generálás, levél), elutasítás (Stripe-visszatérítés, sztornó), kézi hosszabbítás, új jelszó.
- Videók: titkosított HLS (AES-128) a `/home/whiteman/faro-videok/one-brain-N/` mappákban (`kulcs.key`, `NN/index.m3u8`, `NN/d0000.ts` …). Átalakítás: `eszkozok/hls-atalakito.ps1` (Windows, ffmpeg).
- Lejátszó: hls.js 1.6.13 (`public/assets/vendor/hls/`, Apache 2.0), a böngésző saját vezérlőivel; vízjel (a néző e-mail-címe), egy munkamenet felhasználónként.
- Napi feladat (cPanel → Cron Jobs, naponta): `php /home/whiteman/test.farostudio.hu/_app/napi.php` – figyelmeztetés 10 nappal lejárat előtt, lejárat, adattakarítás egy év után.
