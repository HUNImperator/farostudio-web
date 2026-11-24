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

## Látogatottságmérés (saját, süti nélkül)

- Böngésző: `public/assets/js/meres.js` (minden oldalon) → `public/api/meres.php` → `_app/statisztika.php`.
- Nincs süti, az IP-cím nem tárolódik: a napi egyedi látogatót egy naponta cserélődő titkos kulccsal képzett HMAC-kód jelöli (`stat_napi_latogato`, `stat_so`), ezek a nap lezárásakor törlődnek (az első mai kéréskor és a napi feladatban). Minden más összesített napi számláló (`stat_szamlalo`).
- Események: `kosarba:<termék>`, `urlap:<oldal>`, `video:<sorozat>`, `kattintas:email|telefon` (`window.FaroMeres.esemeny()`).
- Nem számít bele: robotok, „Ne kövess” / GPC, és az admin böngészője (az admin oldal `localStorage: faro-nem-szamol` jelzőt tesz; a Statisztika oldal alján visszakapcsolható).
- Admin → Statisztika (`_app/admin_statisztika.php`, `admin/statisztika.js`): időszakválasztó, mutatók az előző időszakhoz képest, napi/heti grafikon, oldalak, források, hivatkozók, kampányok, eszközök, böngészők, rendszerek, belépő oldalak, napszak, hét napjai, vásárlási lépések, űrlapok, videólejátszások.

## Személyiség-elemző módszer segítők részére (kézi teljesítés)

- Oldal: `public/szemelyiseg-elemzo-modszer.html` (az Informális Kineziológia „Részletek és megrendelés” gombja ide visz); 1 ajánló + 6 háttér-videó YouTube-ról, kattintásra töltődik be (`assets/js/yt.js`, youtube-nocookie), az előnézeti képek saját tárhelyről (`assets/img/yt-*.jpg`).
- Termék: `termekek.json` → `szemelyiseg-elemzo`, `tipus: "program"`, 50 000 Ft, `hozzaferes: "1 éves hozzáférés a programhoz"`. Nincs Foxpost, nincs modul/igazolás; a digitális tartalom nyilatkozata kötelező.
- A rendelés nem teljesül és nem mondódik le automatikusan: fizetés után az adminban emlékeztető + „Belépési link elküldve → Teljesítve” gomb.

## Blog (Gondolatok, blogok)

- Nyilvános oldalak: `public/gondolatok/index.php` (lista témakör-szűrővel; `gondolatok/<webcím>` = cikk, a `gondolatok/.htaccess` irányítja ide). A régi `gondolatok.html` átirányít ide. Közös fejléc/lábléc: `_app/sablon.php`; cikkoldal: `_app/blog_oldal.php`.
- Kezdőlap: a Gondolatok csempén a témakörök és a legutóbbi 3 írás (`api/blog.php` + `assets/js/blog-csempe.js`).
- Admin → Blog (`_app/admin_blog.php`, `admin/blog-szerkeszto.js`): lista, saját szerkesztő (bekezdés, alcím, félkövér, dőlt, idézet, listák, link, kép feltöltéssel és képaláírással, YouTube-videó, elválasztó; beillesztés Wordből tisztítva; kép behúzása/bemásolása), kezdőkép, témakör, webcím, leírás, piszkozat / közzététel / időzítés, automatikus mentés piszkozatnál, előnézet, törlés; Témakörök oldal (bővíthető).
- Tárolás: `blog_cikkek`, `blog_temak`, `blog_kepek` táblák; a képek a `feltoltes/blog/` mappába kerülnek (nincs a repóban, a deploy nem írja felül), több méretben JPG + WebP; a YouTube-előnézetet a szerver letölti mentéskor.
- A szöveget a szerver mentéskor mindig megtisztítja (`blog_tisztit`, engedélyezett elemek listája).
- A 3 régi cikk: `_app/blog_regi.json`; az adminban a Blog oldalon „Áthozom őket” gomb (a kezdőképeket a szerver tölti le a régi oldalról).
- Élesítéskor a régi címek átirányítása a webgyökér `.htaccess`-ébe (a meglévő sorok elé):
  ```
  RewriteEngine On
  RewriteRule ^(a-kezelhetetlen-gyermek|mindig-jo-voltam-es-megis|most-akkor-fojtsam-el-az-erzelmeimet)/?$ /gondolatok/$1 [R=301,L]
  ```
