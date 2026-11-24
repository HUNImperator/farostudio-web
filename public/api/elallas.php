<?php
/**
 * POST api/elallas.php – elállási nyilatkozat (elallas.html, 45/2014. Korm. rendelet 22. § (1a)–(1c)).
 * - visszaigazolás a fogyasztónak (tartós adathordozó: e-mail) a nyilatkozat tartalmával és elküldésének idejével;
 * - értesítés az admin címre (válaszcím: a fogyasztó), a rendelés megkeresésével;
 * - ha a rendelés megvan: bejegyzés a rendelés naplójába.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    urlap_valasz(['hiba' => 'Csak POST kérés fogadható.'], 405);
}
$be = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($be)) {
    urlap_valasz(['hiba' => 'Hibás kérés.'], 400);
}
// robotcsapda: a rejtett mezőt ember nem tölti ki (időkorlát itt nincs, hogy valódi nyilatkozat ne vesszen el)
if (!empty($be['weboldal'])) {
    urlap_valasz(['ok' => true]);
}

$mezo = function (string $k, int $max, bool $tobbsoros = false) use ($be): string {
    $v = isset($be[$k]) && is_scalar($be[$k]) ? trim((string)$be[$k]) : '';
    $v = preg_replace($tobbsoros ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $tobbsoros ? '' : ' ', $v);
    return mb_substr((string)$v, 0, $max);
};
$a = [
    'rendelesszam' => $mezo('rendelesszam', 200),
    'nev'          => $mezo('nev', 150),
    'email'        => $mezo('email', 190),
    'terjedelem'   => $mezo('terjedelem', 40),
    'tetelek'      => $mezo('tetelek', 1000, true),
    'megjegyzes'   => $mezo('megjegyzes', 1000, true),
];
if (!in_array($a['terjedelem'], ['A teljes rendelés', 'A rendelés egyes tételei'], true)) {
    $a['terjedelem'] = '';
}
if ($a['terjedelem'] !== 'A rendelés egyes tételei') {
    $a['tetelek'] = '';
}

$hibak = [];
if ($a['rendelesszam'] === '') $hibak[] = 'Hiányzik a rendelésszám.';
if ($a['nev'] === '') $hibak[] = 'Hiányzik a név.';
if ($a['email'] === '') $hibak[] = 'Hiányzik az e-mail-cím.';
elseif (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) $hibak[] = 'Érvénytelen e-mail-cím.';
if ($a['terjedelem'] === '') $hibak[] = 'Válaszd ki, mire vonatkozik az elállás.';
elseif ($a['terjedelem'] === 'A rendelés egyes tételei' && $a['tetelek'] === '') $hibak[] = 'Írd le, mely tételekre vonatkozik az elállás.';
if ($hibak) {
    urlap_valasz(['hiba' => implode(' ', $hibak)], 422);
}

$most = most();
$ido = video_datum($most) . ' ' . date('H:i', strtotime($most));

// a rendelés megkeresése (a mező szabad szöveg is lehet, ezért a FARO-ÉÉÉÉ-NNNN mintát keressük benne)
$rendeles = null;
if (preg_match('/FARO-\d{4}-\d{3,}/i', $a['rendelesszam'], $m)) {
    $st = db()->prepare('SELECT id, rendelesszam, vevo_email, vevo_nev, letrehozva FROM rendelesek WHERE rendelesszam = ?');
    $st->execute([strtoupper($m[0])]);
    $rendeles = $st->fetch() ?: null;
}
$emailEgyezik = $rendeles && mb_strtolower($rendeles['vevo_email']) === mb_strtolower($a['email']);

$sorok = [
    'Nyilatkozat'                 => 'Elállok az alábbi szerződéstől.',
    'Rendelésszám / a szerződés azonosítása' => $a['rendelesszam'],
    'Az elállás terjedelme'       => $a['terjedelem'] . ($a['tetelek'] !== '' ? ":\n" . $a['tetelek'] : ''),
    'Név'                         => $a['nev'],
    'Visszaigazolás e-mail-címe'  => $a['email'],
    'Megjegyzés'                  => $a['megjegyzes'] !== '' ? $a['megjegyzes'] : '—',
    'A nyilatkozat elküldésének ideje' => $ido,
];
$tablaHtml = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:15px;margin:0 0 18px">';
$tablaSzoveg = '';
foreach ($sorok as $c => $v) {
    $tablaHtml .= '<tr><td style="padding:6px 14px 6px 0;color:#6e6a66;vertical-align:top;width:40%">' . h($c) . '</td><td style="padding:6px 0;vertical-align:top">' . nl2br(h($v)) . '</td></tr>';
    $tablaSzoveg .= $c . ': ' . $v . "\n";
}
$tablaHtml .= '</table>';

$cfg = faro_config();
$alap = rtrim($cfg['site_url'], '/');

// 1. visszaigazolás a fogyasztónak
$vHtml = '<p>Kedves ' . h($a['nev']) . '!</p>'
    . '<p>Ezúton visszaigazolom, hogy elállási nyilatkozatod <strong>' . h($ido) . '</strong>-kor beérkezett a farostudio.hu oldalon. A nyilatkozat tartalma:</p>'
    . $tablaHtml
    . '<p><strong>A további teendők</strong></p>'
    . '<p>Könyvek esetén kérlek, a könyveket a nyilatkozatodtól számított 14 napon belül küldd vissza erre a címre: Csengeri Zsuzsa, 2310 Szigetszentmiklós, Szent Miklós útja 18/A/1. (utánvétes csomagot nem tudok átvenni). A visszaküldés költsége téged terhel.</p>'
    . '<p>A kifizetett összeget – könyveknél a szállítási díjjal együtt – a nyilatkozatod beérkezésétől számított 14 napon belül visszatérítem, ugyanazzal a fizetési móddal, amellyel fizettél. Könyveknél a visszatérítést addig visszatarthatom, amíg a könyveket vissza nem kaptam, vagy nem igazolod, hogy visszaküldted őket.</p>'
    . '<p>Ha a rendelés egy része nem tartozik az elállási jog alá (pl. névre szólóan dedikált példány vagy már megnyílt videós hozzáférés), erről külön e-mailben tájékoztatlak. Részletek: <a href="' . h($alap) . '/aszf.html#elallas">ÁSZF 10–11. pont</a>.</p>'
    . '<p>Szeretettel:<br>Csengeri Zsuzsa</p>' . level_jogi_lab();
$vSzoveg = "Kedves {$a['nev']}!\n\nEzúton visszaigazolom, hogy elállási nyilatkozatod $ido-kor beérkezett a farostudio.hu oldalon. A nyilatkozat tartalma:\n\n"
    . $tablaSzoveg
    . "\nA további teendők\n\nKönyvek esetén kérlek, a könyveket a nyilatkozatodtól számított 14 napon belül küldd vissza erre a címre: Csengeri Zsuzsa, 2310 Szigetszentmiklós, Szent Miklós útja 18/A/1. (utánvétes csomagot nem tudok átvenni). A visszaküldés költsége téged terhel.\n\n"
    . "A kifizetett összeget – könyveknél a szállítási díjjal együtt – a nyilatkozatod beérkezésétől számított 14 napon belül visszatérítem, ugyanazzal a fizetési móddal, amellyel fizettél. Könyveknél a visszatérítést addig visszatarthatom, amíg a könyveket vissza nem kaptam, vagy nem igazolod, hogy visszaküldted őket.\n\n"
    . "Ha a rendelés egy része nem tartozik az elállási jog alá (pl. névre szólóan dedikált példány vagy már megnyílt videós hozzáférés), erről külön e-mailben tájékoztatlak. Részletek: $alap/aszf.html#elallas\n\n"
    . "Szeretettel:\nCsengeri Zsuzsa\n\n" . level_jogi_lab(false);
$okVevo = level_kuld($a['email'], $a['nev'], 'Elállási nyilatkozatod visszaigazolása – ' . $a['rendelesszam'],
    level_keret('Megkaptam az elállási nyilatkozatodat', $vHtml), $vSzoveg, $cfg['admin_email']);

// 2. értesítés neked
if ($rendeles) {
    $link = $alap . '/admin/?p=rendeles&id=' . (int)$rendeles['id'];
    $talalat = 'A rendelés megvan: ' . $rendeles['rendelesszam'] . ' (' . $rendeles['vevo_nev'] . ', ' . $rendeles['vevo_email'] . ', leadva: ' . video_datum($rendeles['letrehozva']) . ').'
        . ($emailEgyezik ? '' : ' FIGYELEM: a nyilatkozatban megadott e-mail-cím eltér a rendelésétől – ellenőrizd, hogy valóban a vevő küldte-e.');
} else {
    $link = $alap . '/admin/';
    $talalat = 'A megadott azonosító alapján NEM találtam rendelést – keresd meg kézzel, és szükség esetén kérj pontosítást a küldőtől.';
}
$aHtml = '<p>Elállási nyilatkozat érkezett a weboldalon (' . h($ido) . '). Erre a levélre válaszolva közvetlenül a küldőnek írsz.</p>'
    . '<div style="background:#f0e5db;padding:14px 16px;margin:0 0 16px">' . h($talalat) . '</div>'
    . $tablaHtml
    . '<p>' . ($okVevo ? 'A visszaigazolást elküldtem a küldőnek.' : '<strong>A visszaigazolás küldése NEM sikerült</strong> – kérlek, igazold vissza kézzel!') . '</p>'
    . '<p>Teendők: a visszatérítés a nyilatkozat beérkezésétől számított 14 napon belül esedékes (könyveknél visszatartható a könyvek visszaérkezéséig). Ha a rendelés egy része nem állható el (névre szóló dedikáció, már megnyílt videós hozzáférés), erről írj a vevőnek.</p>'
    . '<p><a href="' . h($link) . '" style="display:inline-block;background:#111;color:#fff;padding:12px 20px;text-decoration:none">Megnyitás az adminban</a></p>';
$aSzoveg = "Elállási nyilatkozat érkezett a weboldalon ($ido).\n\n$talalat\n\n" . $tablaSzoveg . "\n"
    . ($okVevo ? 'A visszaigazolást elküldtem a küldőnek.' : 'A visszaigazolás küldése NEM sikerült – kérlek, igazold vissza kézzel!') . "\n\n$link\n";
$okAdmin = level_kuld($cfg['admin_email'], 'Csengeri Zsuzsa', 'Elállási nyilatkozat – ' . ($rendeles['rendelesszam'] ?? $a['rendelesszam']),
    level_keret('Elállási nyilatkozat', $aHtml), $aSzoveg, $a['email']);

// 3. napló a rendeléshez
if ($rendeles) {
    naplo((int)$rendeles['id'], 'ELÁLLÁSI NYILATKOZAT érkezett a weboldalon (' . $ido . '): ' . $a['terjedelem']
        . ($a['tetelek'] !== '' ? ' – ' . $a['tetelek'] : '') . '. Küldő: ' . $a['nev'] . ', ' . $a['email']
        . ($emailEgyezik ? '' : ' (eltér a rendelés e-mail-címétől)') . '. Visszaigazolás: ' . ($okVevo ? 'elküldve' : 'NEM sikerült') . '.', 'vevő');
}

if (!$okVevo && !$okAdmin) {
    urlap_valasz(['hiba' => 'A nyilatkozat küldése most nem sikerült.'], 502);
}
urlap_valasz([
    'ok' => true,
    'uzenet' => $okVevo
        ? 'A nyilatkozatod ' . $ido . '-kor érkezett meg. A visszaigazolást a tartalmával együtt elküldtem a(z) ' . $a['email'] . ' címre.'
        : 'A nyilatkozatod ' . $ido . '-kor érkezett meg, de a visszaigazoló e-mailt most nem sikerült elküldeni – hamarosan kézzel visszaigazolom.',
]);
