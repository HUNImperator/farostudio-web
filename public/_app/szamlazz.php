<?php
defined('FARO') or exit;

/**
 * Számlázz.hu – Számla Agent
 * Számla kiállítása, amikor a rendelés „Fizetve” lesz, és sztornó az adminból.
 * A számlát a Számlázz.hu e-mailben küldi el a vevőnek.
 *
 * Beállítás (faro-config.php):
 *   'szamlazz' => [
 *       'agent_kulcs' => '…',      // Számla Agent kulcs
 *       'eszamla'     => true,     // elektronikus számla (e-számla)
 *   ],
 *
 * Csengeri Zsuzsa alanyi adómentes: minden tétel ÁFA-kulcsa „AAM”,
 * ÁFA-érték 0, bruttó = nettó.
 * A mezők sorrendje kötött (Számlázz.hu XSD: xmlszamla, xmlszamlast).
 */

const SZAMLAZZ_URL = 'https://www.szamlazz.hu/szamla/';

function szamlazz_beallitva(): bool
{
    return !empty(faro_config()['szamlazz']['agent_kulcs']);
}

/** Kérés küldése a Számla Agentnek (multipart, egy XML-fájllal). */
function szamlazz_kuld(string $mezo, string $xml): array
{
    $cfg = faro_config()['szamlazz'];
    $url = $cfg['api_url'] ?? SZAMLAZZ_URL;
    $tmp = tempnam(sys_get_temp_dir(), 'szla');
    file_put_contents($tmp, $xml);
    $fejlecek = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [$mezo => new CURLFile($tmp, 'text/xml', 'szamla.xml')],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => function ($ch, $sor) use (&$fejlecek) {
            $p = explode(':', $sor, 2);
            if (count($p) === 2) {
                $fejlecek[strtolower(trim($p[0]))] = trim($p[1]);
            }
            return strlen($sor);
        },
    ]);
    $torzs = curl_exec($ch);
    $hiba = curl_error($ch);
    $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    if ($torzs === false) {
        throw new RuntimeException('A Számlázz.hu nem érhető el: ' . $hiba);
    }
    if (!empty($fejlecek['szlahu_error_code']) || strpos((string)$torzs, '[ERR]') === 0) {
        $uz = isset($fejlecek['szlahu_error']) ? urldecode($fejlecek['szlahu_error']) : trim(substr((string)$torzs, 0, 300));
        throw new RuntimeException('Számlázz.hu hiba (' . ($fejlecek['szlahu_error_code'] ?? $kod) . '): ' . $uz);
    }
    if (empty($fejlecek['szlahu_szamlaszam'])) {
        throw new RuntimeException('A Számlázz.hu válaszában nincs számlaszám (HTTP ' . $kod . ').');
    }
    return [
        'szamlaszam' => urldecode($fejlecek['szlahu_szamlaszam']),
        'brutto'     => $fejlecek['szlahu_bruttovegosszeg'] ?? null,
    ];
}

/* ---------- XML-segéd: elemek a megadott sorrendben, üreseket kihagyva ---------- */
function szamlazz_elemek(XMLWriter $w, array $mezok): void
{
    foreach ($mezok as $nev => $ertek) {
        if ($ertek === null || $ertek === '') {
            continue;
        }
        if (is_bool($ertek)) {
            $ertek = $ertek ? 'true' : 'false';
        }
        $w->writeElement($nev, (string)$ertek);
    }
}

function szamlazz_szamla_xml(array $r): string
{
    $cfg = faro_config();
    $b = $cfg['bank'];
    $ma = date('Y-m-d');
    $kartya = $r['fizetes'] === 'kartya';

    $w = new XMLWriter();
    $w->openMemory();
    $w->startDocument('1.0', 'UTF-8');
    $w->startElement('xmlszamla');
    $w->writeAttribute('xmlns', 'http://www.szamlazz.hu/xmlszamla');
    $w->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
    $w->writeAttribute('xsi:schemaLocation', 'http://www.szamlazz.hu/xmlszamla https://www.szamlazz.hu/szamla/docs/xsds/agent/xmlszamla.xsd');

    $w->startElement('beallitasok');
    szamlazz_elemek($w, [
        'szamlaagentkulcs' => $cfg['szamlazz']['agent_kulcs'],
        'eszamla'          => (bool)($cfg['szamlazz']['eszamla'] ?? true),
        'szamlaLetoltes'   => false,
        'valaszVerzio'     => 1,
        'szamlaKulsoAzon'  => $r['rendelesszam'],
    ]);
    $w->endElement();

    $w->startElement('fejlec');
    szamlazz_elemek($w, [
        'keltDatum'             => $ma,
        'teljesitesDatum'       => $ma,
        'fizetesiHataridoDatum' => $ma,
        'fizmod'                => $kartya ? 'Bankkártya' : 'Átutalás',
        'penznem'               => 'Ft',
        'szamlaNyelve'          => 'hu',
        'megjegyzes'            => 'Rendelésszám: ' . $r['rendelesszam'] . '.' . ($r['apt_nev'] !== '' ? ' Szállítás Foxpost csomagautomatába: ' . $r['apt_nev'] . '.' : ''),
        'rendelesSzam'          => $r['rendelesszam'],
        'fizetve'               => true,
    ]);
    $w->endElement();

    $w->startElement('elado');
    szamlazz_elemek($w, [
        'bank'           => $b['bank'],
        'bankszamlaszam' => $b['szamlaszam'],
        'emailReplyto'   => $cfg['admin_email'],
        'emailTargy'     => 'Számla – ' . $r['rendelesszam'],
        'emailSzoveg'    => "Kedves {$r['vevo_nev']}!\n\nKöszönöm a rendelésed! Mellékelten küldöm a(z) {$r['rendelesszam']} rendelés számláját.\n\nSzeretettel:\nCsengeri Zsuzsa",
    ]);
    $w->endElement();

    $vanAdoszam = $r['szamla_adoszam'] !== '';
    $w->startElement('vevo');
    szamlazz_elemek($w, [
        'nev'         => $r['szamla_nev'],
        'irsz'        => $r['szamla_irsz'],
        'telepules'   => $r['szamla_telepules'],
        'cim'         => $r['szamla_cim'],
        'email'       => $r['vevo_email'],
        'sendEmail'   => true,
        'adoalany'    => $vanAdoszam ? 1 : -1,
        'adoszam'     => $vanAdoszam ? $r['szamla_adoszam'] : null,
        'telefonszam' => $r['vevo_telefon'],
    ]);
    $w->endElement();

    $w->startElement('tetelek');
    $sorok = [];
    foreach ($r['tetelek'] as $t) {
        $nev = ($t['tipus'] ?? 'konyv') === 'konyv'
            ? 'Csengeri Zsuzsa: ' . $t['cim'] . (trim((string)($t['dedikalas'] ?? '')) !== '' ? ' (dedikált példány)' : '')
            : $t['cim'] . ' – online videós hozzáférés';
        $sorok[] = [$nev, (int)$t['db'], (int)$t['egysegar']];
    }
    if ((int)$r['szallitas'] > 0) {
        $sorok[] = ['Szállítás – Foxpost csomagautomata', 1, (int)$r['szallitas']];
    }
    foreach ($sorok as [$megnevezes, $db, $ar]) {
        $w->startElement('tetel');
        szamlazz_elemek($w, [
            'megnevezes'       => $megnevezes,
            'mennyiseg'        => $db,
            'mennyisegiEgyseg' => 'db',
            'nettoEgysegar'    => $ar,
            'afakulcs'         => 'AAM',
            'nettoErtek'       => $ar * $db,
            'afaErtek'         => '0',
            'bruttoErtek'      => $ar * $db,
        ]);
        $w->endElement();
    }
    $w->endElement();

    $w->endElement();
    $w->endDocument();
    return $w->outputMemory();
}

function szamlazz_sztorno_xml(array $r): string
{
    $cfg = faro_config();
    $w = new XMLWriter();
    $w->openMemory();
    $w->startDocument('1.0', 'UTF-8');
    $w->startElement('xmlszamlast');
    $w->writeAttribute('xmlns', 'http://www.szamlazz.hu/xmlszamlast');
    $w->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
    $w->writeAttribute('xsi:schemaLocation', 'http://www.szamlazz.hu/xmlszamlast https://www.szamlazz.hu/szamla/docs/xsds/agentst/xmlszamlast.xsd');
    $w->startElement('beallitasok');
    szamlazz_elemek($w, [
        'szamlaagentkulcs' => $cfg['szamlazz']['agent_kulcs'],
        'eszamla'          => (bool)($cfg['szamlazz']['eszamla'] ?? true),
        'szamlaLetoltes'   => false,
        'valaszVerzio'     => 1,
    ]);
    $w->endElement();
    $w->startElement('fejlec');
    szamlazz_elemek($w, [
        'szamlaszam' => $r['szamlaszam'],
        'keltDatum'  => date('Y-m-d'),
        'tipus'      => 'SS',
    ]);
    $w->endElement();
    $w->startElement('elado');
    szamlazz_elemek($w, [
        'emailReplyto' => $cfg['admin_email'],
        'emailTargy'   => 'Sztornó számla – ' . $r['rendelesszam'],
        'emailSzoveg'  => "Kedves {$r['vevo_nev']}!\n\nA(z) {$r['rendelesszam']} rendeléshez tartozó számlát sztornóztam, mellékelten küldöm a sztornó számlát.\n\nSzeretettel:\nCsengeri Zsuzsa",
    ]);
    $w->endElement();
    $w->startElement('vevo');
    szamlazz_elemek($w, ['email' => $r['vevo_email']]);
    $w->endElement();
    $w->endElement();
    $w->endDocument();
    return $w->outputMemory();
}

/** Számla kiállítása egy fizetett rendeléshez. Visszaad: rövid eredmény szöveg. */
function szamlazz_szamla_kiallit(array $r, string $szerzo): string
{
    $id = (int)$r['id'];
    if (!szamlazz_beallitva()) {
        return 'A Számlázz.hu nincs beállítva, számla nem készült.';
    }
    if (!empty($r['szamlaszam'])) {
        return 'Ehhez a rendeléshez már van számla: ' . $r['szamlaszam'] . '.';
    }
    if (empty($r['fizetve_ekkor'])) {
        return 'Számla csak fizetett rendeléshez készülhet.';
    }
    if ($r['allapot'] === 'lemondva') {
        return 'Lemondott rendeléshez nem készül számla.';
    }
    try {
        $v = szamlazz_kuld('action-xmlagentxmlfile', szamlazz_szamla_xml($r));
    } catch (Throwable $e) {
        naplo($id, 'A számla kiállítása NEM sikerült: ' . $e->getMessage(), $szerzo);
        return 'A számla kiállítása nem sikerült: ' . $e->getMessage();
    }
    db()->prepare('UPDATE rendelesek SET szamlaszam = ?, frissitve = ? WHERE id = ?')->execute([$v['szamlaszam'], most(), $id]);
    naplo($id, 'Számla kiállítva: ' . $v['szamlaszam'] . ' (a Számlázz.hu e-mailben elküldte a vevőnek).', $szerzo);
    return 'Számla kiállítva: ' . $v['szamlaszam'] . '.';
}

function szamlazz_sztorno(array $r, string $szerzo): string
{
    $id = (int)$r['id'];
    if (empty($r['szamlaszam'])) {
        return 'Ehhez a rendeléshez nincs számla.';
    }
    if (!empty($r['sztorno_szamlaszam'])) {
        return 'A számla már sztornózva van: ' . $r['sztorno_szamlaszam'] . '.';
    }
    try {
        $v = szamlazz_kuld('action-szamla_agent_st', szamlazz_sztorno_xml($r));
    } catch (Throwable $e) {
        naplo($id, 'A számla sztornózása NEM sikerült: ' . $e->getMessage(), $szerzo);
        return 'A sztornózás nem sikerült: ' . $e->getMessage();
    }
    db()->prepare('UPDATE rendelesek SET sztorno_szamlaszam = ?, frissitve = ? WHERE id = ?')->execute([$v['szamlaszam'], most(), $id]);
    naplo($id, 'Számla sztornózva: ' . $r['szamlaszam'] . ' → sztornó számla: ' . $v['szamlaszam'] . '.', $szerzo);
    return 'Sztornó számla kiállítva: ' . $v['szamlaszam'] . '.';
}
