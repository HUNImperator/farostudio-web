<?php
defined('FARO') or exit;

/**
 * Blog („Gondolatok, blogok”): cikkek, témák, képek, HTML-tisztítás és megjelenítés.
 *
 * A cikk szövege tisztított HTML, csak ezekkel az elemekkel:
 *   p, h2, h3, strong, em, a[href], br, blockquote, ul, ol, li, hr,
 *   <figure class="kep" data-kep="KULCS"><img alt="…"><figcaption>…</figcaption></figure>
 *   <figure class="video" data-yt="YOUTUBE-ID"><figcaption>…</figcaption></figure>
 * A képek a feltoltes/blog/ mappában vannak, több méretben (JPG + WebP).
 */

const BLOG_KEZDOKEP_MERETEK = [480, 960, 1600];
const BLOG_KEP_MERETEK = [720, 1400];
const BLOG_KEP_MAX_BAJT = 15 * 1024 * 1024;
const BLOG_KEP_MINTA = '/^[kb]-[a-f0-9]{12}$/';
const BLOG_YT_MINTA = '/^[A-Za-z0-9_-]{11}$/';

/* ---------- mappák ---------- */
function blog_mappa(): string
{
    $m = FARO_PUBLIC . '/feltoltes/blog';
    if (!is_dir($m)) {
        @mkdir($m, 0755, true);
        // a feltöltési mappában semmilyen program nem futhat
        @file_put_contents(dirname($m) . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh|htm|html|js)$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</FilesMatch>\n");
    }
    return $m;
}

function blog_fajl_url(string $gyoker, string $fajl): string
{
    return $gyoker . 'feltoltes/blog/' . $fajl;
}

/* ---------- témák ---------- */
function blog_temak(): array
{
    static $t = null;
    if ($t === null) {
        $t = [];
        foreach (db()->query('SELECT * FROM blog_temak ORDER BY sorrend, nev')->fetchAll() as $r) {
            $t[(int)$r['id']] = $r;
        }
    }
    return $t;
}

function blog_tema(?int $id): ?array
{
    return $id ? (blog_temak()[$id] ?? null) : null;
}

/* ---------- cikkek ---------- */
function blog_cikk(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM blog_cikkek WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function blog_cikk_slug(string $slug): ?array
{
    $st = db()->prepare('SELECT * FROM blog_cikkek WHERE slug = ?');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

/** Megjelent-e már (közzétett és a megjelenés ideje elmúlt). */
function blog_el(array $c): bool
{
    return $c['allapot'] === 'kozzeteve' && $c['megjelenes'] <= most();
}

/** A megjelent cikkek, a legfrissebb elöl. */
function blog_megjelent(int $limit = 0, ?int $kiveveId = null): array
{
    $sql = "SELECT * FROM blog_cikkek WHERE allapot = 'kozzeteve' AND megjelenes <= ?" . ($kiveveId ? ' AND id <> ' . (int)$kiveveId : '') . ' ORDER BY megjelenes DESC, id DESC';
    if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;
    $st = db()->prepare($sql);
    $st->execute([most()]);
    return $st->fetchAll();
}

/** Hasonló írások: előbb az azonos témájúak, aztán a legfrissebbek. */
function blog_hasonlok(array $c, int $db = 3): array
{
    $ki = [];
    foreach (blog_megjelent(0, (int)$c['id']) as $m) {
        if ($c['tema_id'] && (int)$m['tema_id'] === (int)$c['tema_id']) $ki[] = $m;
    }
    foreach (blog_megjelent(0, (int)$c['id']) as $m) {
        if (count($ki) >= $db) break;
        if (!in_array($m, $ki, true)) $ki[] = $m;
    }
    return array_slice($ki, 0, $db);
}

const BLOG_HONAPOK = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];

function blog_datum(string $d, bool $nap = true): string
{
    $t = strtotime($d);
    return date('Y', $t) . '. ' . BLOG_HONAPOK[(int)date('n', $t) - 1] . ($nap ? ' ' . (int)date('j', $t) . '.' : '');
}

/** Webcím a címből (ékezetek nélkül, kötőjelekkel), egyedire igazítva. */
function blog_slug(string $szoveg, ?int $sajatId = null): string
{
    $s = mb_strtolower(trim($szoveg));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u', 'ä' => 'a', 'ß' => 'ss']);
    if (function_exists('iconv')) {
        $s = (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    }
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    $s = substr($s, 0, 80);
    $s = rtrim($s, '-') ?: 'iras';
    $alap = $s;
    for ($i = 2; ; $i++) {
        $van = blog_cikk_slug($s);
        if (!$van || (int)$van['id'] === (int)$sajatId) return $s;
        $s = $alap . '-' . $i;
    }
}

function blog_olvasasi_ido(string $html): int
{
    $szavak = str_word_count(strip_tags(str_replace('<', ' <', $html)), 0, 'áéíóöőúüűÁÉÍÓÖŐÚÜŰ');
    return max(1, (int)round($szavak / 200));
}

/* =========================================================
   Képek
   ========================================================= */
function blog_kep(string $kulcs): ?array
{
    static $cache = [];
    if (!array_key_exists($kulcs, $cache)) {
        $st = db()->prepare('SELECT * FROM blog_kepek WHERE kulcs = ?');
        $st->execute([$kulcs]);
        $r = $st->fetch() ?: null;
        if ($r) $r['meretek'] = array_map('intval', explode(',', $r['meretek']));
        $cache[$kulcs] = $r;
    }
    return $cache[$kulcs];
}

/**
 * Feltöltött kép feldolgozása: tájolás javítása, több méret JPG-ben és WebP-ben.
 * @param string $fajta 'kezdo' (kezdőkép) vagy 'kep' (a szövegbe)
 * @return array a blog_kepek sor
 */
function blog_kep_ment(string $fajl, string $fajta): array
{
    if (!function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('A szerveren nincs képfeldolgozó (GD) bővítmény.');
    }
    if (filesize($fajl) > BLOG_KEP_MAX_BAJT) {
        throw new RuntimeException('A kép legfeljebb 15 MB lehet.');
    }
    $info = @getimagesize($fajl);
    $tipusok = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_GIF => 'imagecreatefromgif'];
    if (defined('IMAGETYPE_WEBP') && function_exists('imagecreatefromwebp')) $tipusok[IMAGETYPE_WEBP] = 'imagecreatefromwebp';
    if (!$info || !isset($tipusok[$info[2]])) {
        throw new RuntimeException('Ez nem JPG, PNG, WebP vagy GIF kép.');
    }
    if ($info[0] * $info[1] > 40000000) {
        throw new RuntimeException('A kép túl nagy felbontású (legfeljebb kb. 40 megapixel).');
    }
    @ini_set('memory_limit', '512M');
    $kep = @$tipusok[$info[2]]($fajl);
    if (!$kep) throw new RuntimeException('A képet nem sikerült beolvasni.');

    // telefonos fotók: EXIF szerinti forgatás
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($fajl);
        $o = (int)($exif['Orientation'] ?? 1);
        if (in_array($o, [3, 6, 8], true)) {
            $kep = imagerotate($kep, [3 => 180, 6 => -90, 8 => 90][$o], 0);
        }
    }
    $w = imagesx($kep);
    $h = imagesy($kep);
    $kulcs = ($fajta === 'kezdo' ? 'k' : 'b') . '-' . bin2hex(random_bytes(6));
    $celok = $fajta === 'kezdo' ? BLOG_KEZDOKEP_MERETEK : BLOG_KEP_MERETEK;
    $meretek = [];
    foreach ($celok as $m) {
        $meretek[] = min($m, $w);
    }
    $meretek = array_values(array_unique($meretek));
    sort($meretek);
    $webp = function_exists('imagewebp');
    $mappa = blog_mappa();
    foreach ($meretek as $mw) {
        $mh = (int)round($h * $mw / $w);
        $uj = imagecreatetruecolor($mw, $mh);
        imagefill($uj, 0, 0, imagecolorallocate($uj, 255, 255, 255));   // átlátszó PNG: fehér háttér
        imagecopyresampled($uj, $kep, 0, 0, 0, 0, $mw, $mh, $w, $h);
        imageinterlace($uj, true);
        imagejpeg($uj, "$mappa/$kulcs-$mw.jpg", 82);
        if ($webp) imagewebp($uj, "$mappa/$kulcs-$mw.webp", 80);
        imagedestroy($uj);
    }
    imagedestroy($kep);
    db()->prepare('INSERT INTO blog_kepek (kulcs, cikk_id, szel, mag, meretek, webp, letrehozva) VALUES (?,?,?,?,?,?,?)')
        ->execute([$kulcs, null, $w, $h, implode(',', $meretek), $webp ? 1 : 0, most()]);
    return blog_kep($kulcs);
}

/** <picture> a feltöltött képhez. */
function blog_kep_html(string $kulcs, string $gyoker, string $alt, string $sizes, string $osztaly = '', bool $lusta = true): string
{
    $k = blog_kep($kulcs);
    if (!$k) return '';
    $leg = max($k['meretek']);
    $mag = (int)round($k['mag'] * $leg / $k['szel']);
    $srcset = function (string $kit) use ($k, $kulcs, $gyoker) {
        return implode(', ', array_map(function ($m) use ($kulcs, $gyoker, $kit) {
            return blog_fajl_url($gyoker, "$kulcs-$m.$kit") . " {$m}w";
        }, $k['meretek']));
    };
    $kozep = $k['meretek'][(int)floor((count($k['meretek']) - 1) / 2)];
    return '<picture>'
        . ($k['webp'] ? '<source type="image/webp" srcset="' . h($srcset('webp')) . '" sizes="' . h($sizes) . '">' : '')
        . '<img src="' . h(blog_fajl_url($gyoker, "$kulcs-$kozep.jpg")) . '" srcset="' . h($srcset('jpg')) . '" sizes="' . h($sizes) . '"'
        . ' width="' . $leg . '" height="' . $mag . '" alt="' . h($alt) . '"' . ($osztaly !== '' ? ' class="' . h($osztaly) . '"' : '')
        . ($lusta ? ' loading="lazy" decoding="async"' : '') . '></picture>';
}

/** A YouTube-videó előnézeti képe a saját tárhelyre (a szerver tölti le, ha tudja). */
function blog_yt_kep(string $id): bool
{
    if (!preg_match(BLOG_YT_MINTA, $id)) return false;
    $cel = blog_mappa() . "/yt-$id.jpg";
    if (is_file($cel)) return true;
    if (!function_exists('curl_init')) return false;
    foreach (['maxresdefault', 'hqdefault'] as $q) {
        $ch = curl_init("https://i.ytimg.com/vi/$id/$q.jpg");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true]);
        $adat = curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($kod === 200 && $adat && ($kep = @imagecreatefromstring($adat))) {
            // 16:9-re vágva (a hqdefault fekete sávjai nélkül), 800 px szélesre
            $w = imagesx($kep);
            $h = imagesy($kep);
            $vh = (int)round($w * 9 / 16);
            $y = max(0, (int)(($h - $vh) / 2));
            $uj = imagecreatetruecolor(800, 450);
            imagecopyresampled($uj, $kep, 0, 0, 0, $y, 800, 450, $w, min($vh, $h));
            imagejpeg($uj, $cel, 80);
            imagedestroy($uj);
            imagedestroy($kep);
            return true;
        }
    }
    return false;
}

/* =========================================================
   HTML-tisztítás (szerkesztőből és régi cikkekből érkező szöveg)
   ========================================================= */
function blog_tisztit(string $html): string
{
    $be = new DOMDocument();
    $regi = libxml_use_internal_errors(true);
    $be->loadHTML('<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($regi);
    $body = $be->getElementsByTagName('body')->item(0);
    $ki = new DOMDocument('1.0', 'UTF-8');
    $gyoker = $ki->createElement('div');
    $ki->appendChild($gyoker);
    if ($body) {
        $p = null;
        foreach (iterator_to_array($body->childNodes) as $n) {
            blog_t_blokk($n, $gyoker, $ki, $p);
        }
    }
    blog_t_uresek($gyoker);
    // egymást követő idézetek összevonása (a szerkesztő minden Enterre új idézetet nyit)
    foreach (iterator_to_array($gyoker->childNodes) as $c) {
        $elozo = $c->previousSibling;
        while ($elozo instanceof DOMText && trim($elozo->data) === '') $elozo = $elozo->previousSibling;
        if ($c instanceof DOMElement && $c->tagName === 'blockquote' && $elozo instanceof DOMElement && $elozo->tagName === 'blockquote') {
            while ($c->firstChild) $elozo->appendChild($c->firstChild);
            $gyoker->removeChild($c);
        }
    }
    $s = '';
    foreach ($gyoker->childNodes as $c) {
        $s .= $ki->saveHTML($c) . "\n";
    }
    return trim($s);
}

const BLOG_T_BLOKK = ['p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'center', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'blockquote', 'ul', 'ol', 'li', 'hr', 'figure', 'img', 'iframe', 'table', 'tbody', 'thead', 'tr', 'td', 'th', 'pre', 'dl', 'dt', 'dd'];
const BLOG_T_TILTOTT = ['script', 'style', 'noscript', 'template', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'video', 'audio', 'canvas', 'head', 'title', 'meta', 'link'];

function blog_t_van_blokk(DOMNode $n): bool
{
    foreach ($n->childNodes as $c) {
        if ($c instanceof DOMElement && in_array(strtolower($c->tagName), BLOG_T_BLOKK, true)) return true;
    }
    return false;
}

/** Blokkszintű csomópont feldolgozása; $p: a laza szövegnek nyitott bekezdés. */
function blog_t_blokk(DOMNode $n, DOMElement $szulo, DOMDocument $ki, ?DOMElement &$p): void
{
    if ($n instanceof DOMText) {
        if (trim(str_replace("\xC2\xA0", ' ', $n->data)) === '' && !$p) return;
        if (!$p) { $p = $ki->createElement('p'); $szulo->appendChild($p); }
        $p->appendChild($ki->createTextNode(preg_replace('/\s+/u', ' ', $n->data)));
        return;
    }
    if (!$n instanceof DOMElement) return;
    $tag = strtolower($n->tagName);
    if (in_array($tag, BLOG_T_TILTOTT, true)) return;

    if (!in_array($tag, BLOG_T_BLOKK, true)) {
        // soron belüli elem a blokk szintjén: bekezdésbe kerül
        if ($tag === 'br') { $p = null; return; }
        if (!$p) { $p = $ki->createElement('p'); $szulo->appendChild($p); }
        blog_t_inline($n, $p, $ki);
        return;
    }
    $p = null;
    switch ($tag) {
        case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
            $e = $ki->createElement(in_array($tag, ['h1', 'h2'], true) ? 'h2' : 'h3');
            blog_t_gyerekek_inline($n, $e, $ki);
            $szulo->appendChild($e);
            return;
        case 'blockquote':
            $e = $ki->createElement('blockquote');
            $belso = null;
            foreach (iterator_to_array($n->childNodes) as $c) blog_t_blokk($c, $e, $ki, $belso);
            $szulo->appendChild($e);
            return;
        case 'ul': case 'ol':
            $e = $ki->createElement($tag);
            foreach (iterator_to_array($n->childNodes) as $c) {
                if ($c instanceof DOMText && trim($c->data) === '') continue;
                $li = $ki->createElement('li');
                if ($c instanceof DOMElement && strtolower($c->tagName) === 'li') {
                    foreach (iterator_to_array($c->childNodes) as $cc) {
                        if ($cc instanceof DOMElement && in_array(strtolower($cc->tagName), ['ul', 'ol'], true)) {
                            $dummy = null;
                            blog_t_blokk($cc, $li, $ki, $dummy);
                        } else {
                            blog_t_inline($cc, $li, $ki);
                        }
                    }
                } else {
                    blog_t_inline($c, $li, $ki);
                }
                $e->appendChild($li);
            }
            $szulo->appendChild($e);
            return;
        case 'li':
            $e = $ki->createElement('p');
            blog_t_gyerekek_inline($n, $e, $ki);
            $szulo->appendChild($e);
            return;
        case 'hr':
            $szulo->appendChild($ki->createElement('hr'));
            return;
        case 'figure':
            blog_t_figure($n, $szulo, $ki);
            return;
        case 'img':
            $kulcs = $n->getAttribute('data-kep');
            if (preg_match(BLOG_KEP_MINTA, $kulcs)) {
                blog_t_kep($kulcs, $n->getAttribute('alt'), null, $szulo, $ki);
            }
            return;
        case 'iframe':
            if (preg_match('~(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?v=)|youtu\.be/)([A-Za-z0-9_-]{11})~', $n->getAttribute('src'), $m)) {
                blog_t_video($m[1], null, $szulo, $ki);
            }
            return;
        default:
            // p, div, section, td… : ha van benne blokk, kibontjuk; különben egy bekezdés
            if (blog_t_van_blokk($n)) {
                $belso = null;
                foreach (iterator_to_array($n->childNodes) as $c) blog_t_blokk($c, $szulo, $ki, $belso);
            } else {
                $e = $ki->createElement('p');
                blog_t_gyerekek_inline($n, $e, $ki);
                $szulo->appendChild($e);
            }
    }
}

function blog_t_gyerekek_inline(DOMNode $n, DOMElement $cel, DOMDocument $ki): void
{
    foreach (iterator_to_array($n->childNodes) as $c) blog_t_inline($c, $cel, $ki);
}

function blog_t_inline(DOMNode $n, DOMElement $cel, DOMDocument $ki): void
{
    if ($n instanceof DOMText) {
        $cel->appendChild($ki->createTextNode(preg_replace('/[ \t\r\n]+/u', ' ', str_replace("\xC2\xA0", ' ', $n->data))));
        return;
    }
    if (!$n instanceof DOMElement) return;
    $tag = strtolower($n->tagName);
    if (in_array($tag, BLOG_T_TILTOTT, true) || $tag === 'img' || $tag === 'iframe') return;
    switch ($tag) {
        case 'strong': case 'b':
            $e = $ki->createElement('strong');
            break;
        case 'em': case 'i':
            $e = $ki->createElement('em');
            break;
        case 'br':
            $cel->appendChild($ki->createElement('br'));
            return;
        case 'a':
            $href = trim($n->getAttribute('href'));
            $sema = strtolower((string)parse_url($href, PHP_URL_SCHEME));
            $jo = $href !== '' && strpos($href, '//') !== 0 && (in_array($sema, ['http', 'https', 'mailto', 'tel'], true) || ($sema === '' && !preg_match('/^\s*(javascript|data|vbscript):/i', $href)));
            if (!$jo) { blog_t_gyerekek_inline($n, $cel, $ki); return; }
            $e = $ki->createElement('a');
            $e->setAttribute('href', $href);
            if (in_array($sema, ['http', 'https'], true) && !stat_sajat_host_blog((string)parse_url($href, PHP_URL_HOST))) {
                $e->setAttribute('target', '_blank');
                $e->setAttribute('rel', 'noopener');
            }
            break;
        default:
            // span, u, font, mark, blokkelem soron belül… : csak a tartalma marad
            if (in_array($tag, ['p', 'div', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote']) && $cel->hasChildNodes()) {
                $cel->appendChild($ki->createElement('br'));
            }
            blog_t_gyerekek_inline($n, $cel, $ki);
            return;
    }
    blog_t_gyerekek_inline($n, $e, $ki);
    if ($e->hasChildNodes()) $cel->appendChild($e);
}

function stat_sajat_host_blog(string $host): bool
{
    $host = strtolower(preg_replace('/^www\./i', '', $host));
    return $host === 'farostudio.hu' || substr($host, -14) === '.farostudio.hu';
}

function blog_t_figure(DOMElement $n, DOMElement $szulo, DOMDocument $ki): void
{
    $felirat = null;
    foreach ($n->getElementsByTagName('figcaption') as $fc) { $felirat = $fc; break; }
    $yt = $n->getAttribute('data-yt');
    if (preg_match(BLOG_YT_MINTA, $yt)) {
        blog_t_video($yt, $felirat, $szulo, $ki);
        return;
    }
    $kulcs = $n->getAttribute('data-kep');
    $alt = '';
    foreach ($n->getElementsByTagName('img') as $img) {
        if (!preg_match(BLOG_KEP_MINTA, $kulcs)) $kulcs = $img->getAttribute('data-kep');
        $alt = $img->getAttribute('alt');
        break;
    }
    if (preg_match(BLOG_KEP_MINTA, $kulcs)) {
        blog_t_kep($kulcs, $alt, $felirat, $szulo, $ki);
    }
}

function blog_t_felirat(?DOMElement $felirat, DOMElement $fig, DOMDocument $ki): string
{
    if (!$felirat) return '';
    $fc = $ki->createElement('figcaption');
    blog_t_gyerekek_inline($felirat, $fc, $ki);
    $szoveg = trim(str_replace("\xC2\xA0", ' ', $fc->textContent));
    if ($szoveg !== '') $fig->appendChild($fc);
    return $szoveg;
}

function blog_t_kep(string $kulcs, string $alt, ?DOMElement $felirat, DOMElement $szulo, DOMDocument $ki): void
{
    $fig = $ki->createElement('figure');
    $fig->setAttribute('class', 'kep');
    $fig->setAttribute('data-kep', $kulcs);
    $img = $ki->createElement('img');
    $fig->appendChild($img);
    $szoveg = blog_t_felirat($felirat, $fig, $ki);
    $img->setAttribute('alt', mb_substr(trim($alt) !== '' ? trim($alt) : $szoveg, 0, 200));
    $szulo->appendChild($fig);
}

function blog_t_video(string $id, ?DOMElement $felirat, DOMElement $szulo, DOMDocument $ki): void
{
    $fig = $ki->createElement('figure');
    $fig->setAttribute('class', 'video');
    $fig->setAttribute('data-yt', $id);
    blog_t_felirat($felirat, $fig, $ki);
    $szulo->appendChild($fig);
}

/** Üres bekezdések, címek, listaelemek eltávolítása; a szöveg eleji/végi szóközök levágása. */
function blog_t_uresek(DOMElement $gyoker): void
{
    foreach (iterator_to_array($gyoker->childNodes) as $c) {
        if (!$c instanceof DOMElement) continue;
        $tag = $c->tagName;
        if (in_array($tag, ['blockquote', 'ul', 'ol', 'li'], true)) blog_t_uresek($c);
        if (in_array($tag, ['p', 'h2', 'h3', 'blockquote', 'ul', 'ol', 'li'], true)) {
            // levágjuk a bekezdés végi sortöréseket
            while ($c->lastChild instanceof DOMElement && $c->lastChild->tagName === 'br') $c->removeChild($c->lastChild);
            while ($c->firstChild instanceof DOMElement && $c->firstChild->tagName === 'br') $c->removeChild($c->firstChild);
            if (trim(str_replace("\xC2\xA0", ' ', $c->textContent)) === '' && !$c->getElementsByTagName('figure')->length) {
                $gyoker->removeChild($c);
                continue;
            }
            if ($c->firstChild instanceof DOMText) $c->firstChild->data = ltrim($c->firstChild->data);
            if ($c->lastChild instanceof DOMText) $c->lastChild->data = rtrim($c->lastChild->data);
        }
    }
}

/* =========================================================
   Megjelenítés
   ========================================================= */
/** A tárolt szöveg megjelenítése: képek több méretben, videók kattintásra. */
function blog_megjelenit(string $html, string $gyoker): string
{
    $html = preg_replace_callback('~<figure class="kep" data-kep="([a-z0-9-]+)"><img alt="([^"]*)">~', function ($m) use ($gyoker) {
        return '<figure class="kep" data-kep="' . $m[1] . '">' . blog_kep_html($m[1], $gyoker, html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), '(max-width: 760px) 100vw, 720px');
    }, $html);
    $html = preg_replace_callback('~<figure class="video" data-yt="([A-Za-z0-9_-]{11})">~', function ($m) use ($gyoker) {
        $kep = is_file(blog_mappa() . '/yt-' . $m[1] . '.jpg') ? '<img src="' . h(blog_fajl_url($gyoker, 'yt-' . $m[1] . '.jpg')) . '" width="800" height="450" alt="" loading="lazy">' : '';
        return '<figure class="video" data-yt="' . $m[1] . '"><button class="yt" type="button" data-yt="' . $m[1] . '" data-cim="Videó" aria-label="Videó lejátszása">'
            . $kep . '<span class="yt__play" aria-hidden="true"></span></button>';
    }, $html);
    return $html;
}

/** A szerkesztőbe töltött változat: a képek előnézettel, a beágyazások nem szerkeszthetők. */
function blog_szerkesztohoz(string $html, string $gyoker): string
{
    $html = preg_replace_callback('~<figure class="kep" data-kep="([a-z0-9-]+)"><img alt="([^"]*)">~', function ($m) use ($gyoker) {
        $k = blog_kep($m[1]);
        $src = $k ? blog_fajl_url($gyoker, $m[1] . '-' . max($k['meretek']) . '.jpg') : '';
        return '<figure class="kep" data-kep="' . $m[1] . '" contenteditable="false"><img src="' . h($src) . '" alt="' . $m[2] . '">';
    }, $html);
    $html = preg_replace('~<figure class="video" data-yt="([A-Za-z0-9_-]{11})">~', '<figure class="video" data-yt="$1" contenteditable="false"><img src="https://i.ytimg.com/vi/$1/hqdefault.jpg" alt="">', $html);
    // a képaláírás szerkeszthető marad
    return str_replace('<figcaption>', '<figcaption contenteditable="true">', $html);
}

/* =========================================================
   A régi oldal cikkeinek áthozása (egyszer, az adminból)
   ========================================================= */
function blog_regi_cikkek(): array
{
    $adat = json_decode((string)@file_get_contents(FARO_APP . '/blog_regi.json'), true);
    return is_array($adat) ? $adat : [];
}

function blog_regi_hianyzik(): array
{
    return array_values(array_filter(blog_regi_cikkek(), function ($c) { return !blog_cikk_slug($c['slug']); }));
}

/** A régi cikk 600×300-as kezdőképe a csomagban (_app/blog_regi/), ha van. */
function blog_regi_kep_fajl(string $slug): ?string
{
    $f = FARO_APP . '/blog_regi/' . $slug . '-600x300.jpg';
    return is_file($f) ? $f : null;
}

/** Már áthozott régi cikkek, amelyeknek még a régi oldalról letöltött kezdőképük van. */
function blog_regi_kepcsere_kell(): array
{
    $lista = [];
    foreach (blog_regi_cikkek() as $c) {
        if (!blog_regi_kep_fajl($c['slug'])) continue;
        $cikk = blog_cikk_slug($c['slug']);
        if (!$cikk) continue;
        $k = $cikk['kezdokep'] ? blog_kep((string)$cikk['kezdokep']) : null;
        if (!$cikk['kezdokep'] || ($k && $k['szel'] . 'x' . $k['mag'] === ($c['regi_meret'] ?? ''))) {
            $lista[] = ['cikk' => $cikk, 'regi' => $c];
        }
    }
    return $lista;
}

/** Az áthozott régi cikkek kezdőképét lecseréli a 600×300-as változatra. */
function blog_regi_kepcsere(): array
{
    $uzenet = [];
    foreach (blog_regi_kepcsere_kell() as $t) {
        try {
            $kulcs = blog_kep_ment(blog_regi_kep_fajl($t['regi']['slug']), 'kezdo')['kulcs'];
            db()->prepare('UPDATE blog_kepek SET cikk_id = ? WHERE kulcs = ?')->execute([(int)$t['cikk']['id'], $kulcs]);
            db()->prepare('UPDATE blog_cikkek SET kezdokep = ?, frissitve = ? WHERE id = ?')->execute([$kulcs, most(), (int)$t['cikk']['id']]);
            $uzenet[] = '„' . $t['cikk']['cim'] . '” kezdőképe lecserélve.';
        } catch (Throwable $e) {
            $uzenet[] = '„' . $t['cikk']['cim'] . '”: ' . $e->getMessage();
        }
    }
    return $uzenet;
}

function blog_regi_import(): array
{
    $uzenet = [];
    $temak = [];
    foreach (blog_temak() as $t) $temak[$t['kulcs']] = (int)$t['id'];
    foreach (blog_regi_hianyzik() as $c) {
        $kezdo = null;
        $kepUzenet = '';
        $helyi = blog_regi_kep_fajl($c['slug']);
        if ($helyi) {
            try {
                $kezdo = blog_kep_ment($helyi, 'kezdo')['kulcs'];
            } catch (Throwable $e) {
                $kepUzenet = ' (a kezdőkép nem jött át: ' . $e->getMessage() . ')';
            }
        } elseif (!empty($c['kep']) && function_exists('curl_init')) {
            $tmp = tempnam(sys_get_temp_dir(), 'blog');
            $ch = curl_init($c['kep']);
            $fp = fopen($tmp, 'wb');
            curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'FARO blog import']);
            $ok = curl_exec($ch) && in_array((int)curl_getinfo($ch, CURLINFO_HTTP_CODE), [200, 0], true);   // 0: helyi fájl (teszt)
            curl_close($ch);
            fclose($fp);
            clearstatcache();
            if ($ok && filesize($tmp) > 0) {
                try {
                    $kezdo = blog_kep_ment($tmp, 'kezdo')['kulcs'];
                } catch (Throwable $e) {
                    $kepUzenet = ' (a kezdőkép nem jött át: ' . $e->getMessage() . ')';
                }
            } else {
                $kepUzenet = ' (a kezdőképet nem sikerült letölteni – az adminban feltölthető)';
            }
            @unlink($tmp);
        }
        $tartalom = blog_tisztit($c['html']);
        db()->prepare('INSERT INTO blog_cikkek (slug, cim, bevezeto, tartalom, tema_id, kezdokep, kezdokep_alt, meta_leiras, allapot, megjelenes, olvasasi_ido, letrehozva, frissitve) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$c['slug'], $c['cim'], $c['bevezeto'], $tartalom, $temak[$c['tema']] ?? null, $kezdo, $c['kep_alt'] ?? '', '', 'kozzeteve', $c['datum'], blog_olvasasi_ido($tartalom), most(), most()]);
        if ($kezdo) {
            db()->prepare('UPDATE blog_kepek SET cikk_id = ? WHERE kulcs = ?')->execute([(int)db()->lastInsertId(), $kezdo]);
        }
        $uzenet[] = '„' . $c['cim'] . '” áthozva' . $kepUzenet . '.';
    }
    return $uzenet;
}
