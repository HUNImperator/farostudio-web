<?php
/**
 * Nem létező címek kezelése (a gyökér .htaccess irányít ide).
 *  1. A régi (WordPress) oldal címei → 301-es átirányítás az új aloldalra.
 *  2. Régi blogcikk, amely már az új blogban is megvan → /gondolatok/<webcím>.
 *  3. Minden más: barátságos 404-es oldal.
 */
require __DIR__ . '/_app/bootstrap.php';
require_once FARO_APP . '/blog.php';
require_once FARO_APP . '/sablon.php';

$ut = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$ut = strtolower(trim(rawurldecode($ut), '/'));

const REGI_CIMEK = [
    // oldalak
    'csengeri-zsuzsa-rolam'  => 'rolam.html',
    'csengeri-zsuzsa'        => 'rolam.html',
    'szakmai-mentoralas'     => 'mentoralas.html',
    'informalis-kineziologia-online-szemelyes' => 'informalis-kineziologia.html',
    'one-brain-kineziologia-szemelyes' => 'informalis-kineziologia.html',
    'emotion-code'           => 'informalis-kineziologia.html',
    'stresszoldas-online'    => 'informalis-kineziologia.html',
    'kineziologia-one-brain' => 'oktato-videok.html',
    'bio-narrativa-amikor-a-tested-es-a-torteneted-vegre-egy-nyelvet-beszel' => 'bio-narrativa.html',
    'adatkezelesi-tajekoztato' => 'adatkezeles.html',
    'kapcsolat'              => 'impresszum.html',
    'bihari-berta'           => '',
    'szarka-gyongyi'         => '',
    // webáruház
    'uzlet'                  => 'konyvek.html',
    'termekek'               => 'konyvek.html',
    'kosar'                  => 'rendeles.html',
    'penztar'                => 'rendeles.html',
    'fiokom'                 => 'videok/',
    'termekkategoria/konyvek' => 'konyvek.html',
    'termekkategoria/egyeb'  => 'oktato-videok.html',
    'termekkategoria/informalis-kineziologia' => 'szemelyiseg-elemzo-modszer.html',
    'termek/csengeri-zsuzsa-utkozben'        => 'konyvek.html#utkozben',
    'termek/csengeri-zsuzsa-a-hetedik-napon' => 'konyvek.html#a-hetedik-napon',
    'termek/csengeri-zsuzsa-az-elet-fonala'  => 'konyvek.html#az-elet-fonala',
    'termek/csengeri-zsuzsa-a-gyogyito'      => 'konyvek.html#a-gyogyito',
    'termek/szemelyiseg-elemzo-modszer-segitok-reszere' => 'szemelyiseg-elemzo-modszer.html',
    // blog
    'csengeri-zsuzsa-blog'   => 'gondolatok/',
    'sitemap_index.xml'      => 'sitemap.xml',
    'wp-sitemap.xml'         => 'sitemap.xml',
];

/** A régi oldal blogcikkei (amelyek még nincsenek áthozva, a blog főoldalára visznek). */
const REGI_CIKKEK = [
    'ti-kerdeztetek-sorozat-2', 'ti-kerdeztetek-sorozat1', 'ti-kerdeztetek-sorozat3',
    'mi-az-izomteszteles-s-miert-van-erre-szukseg', 'most-akkor-fojtsam-el-az-erzelmeimet',
    'allergia-mint-a-test-elme-lelek-diszharmoniaja', 'te-mindig-jol-vagy', 'mindig-jo-voltam-es-megis',
    'kerdesek-es-valaszok-a-kineziologia-modszerevel-kapcsolatban', 'ne-legy-onmagad',
    'mi-a-garancia-arra-hogy-elmulnak-a-tuneteim', 'szakralis-utazasaim-bali-1-5',
    'szakralis-utazasaim-bali-6-10-resz', 'ne-elj', 'a-kezelhetetlen-gyermek', 'szeretet-elvaras-csalodas',
    'rossz-kapcsolatban-vergodve', 'a-jo-ember', 'soulfie-vagyis-lelekkep', 'ne-legy-gyerek',
];

function regi_cel(string $ut): ?string
{
    if (array_key_exists($ut, REGI_CIMEK)) return REGI_CIMEK[$ut];
    if (preg_match('#^termek/one-brain-#', $ut)) return 'oktato-videok.html';
    if (preg_match('#^(category|tag|author)/#', $ut)) return 'gondolatok/';
    if (preg_match('#^[a-z0-9-]+$#', $ut)) {
        try {
            $c = blog_cikk_slug($ut);
            if ($c && blog_el($c)) return 'gondolatok/' . $ut;
        } catch (Throwable $e) {
            // adatbázis nélkül is működjön a többi átirányítás
        }
        if (in_array($ut, REGI_CIKKEK, true)) return 'gondolatok/';
    }
    return null;
}

$cel = $ut === '' ? null : regi_cel($ut);
if ($cel !== null) {
    header('Location: /' . $cel, true, 301);
    exit;
}

http_response_code(404);
header('X-Robots-Tag: noindex');
oldal_fej(['cim' => 'Az oldal nem található – Csengeri Zsuzsa | FARO', 'leiras' => '', 'gy' => '/', 'noindex' => true]); ?>
    <section>
      <div class="container" style="max-width:720px;padding:clamp(70px,9vw,120px) var(--gutter);text-align:center">
        <p class="section-eyebrow">404</p>
        <h1 class="section-title">Ez az oldal nem található</h1>
        <p>Lehet, hogy a cím elírás, vagy az oldal a weboldal megújulásakor új helyre került.</p>
        <p style="margin-top:28px"><a class="btn btn--dark" href="/">Tovább a kezdőlapra&nbsp;<span class="btn__arrow" aria-hidden="true">→</span></a></p>
        <p style="margin-top:18px"><a href="/gondolatok/">Gondolatok, blogok</a> · <a href="/konyvek.html">Írások és regények</a> · <a href="/impresszum.html">Elérhetőség</a></p>
      </div>
    </section>
<?php oldal_lab(['gy' => '/']);
