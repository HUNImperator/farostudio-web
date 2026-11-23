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
