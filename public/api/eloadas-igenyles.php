<?php
/**
 * POST api/eloadas-igenyles.php – előadás igénylése (eloadas-igenyles.html).
 * Levelet küld az info@ címre (válaszcím: a küldő), és visszaigazolást a küldőnek.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function valasz(array $adat, int $kod = 200): void
{
    http_response_code($kod);
    echo json_encode($adat, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    valasz(['hiba' => 'Csak POST kérés fogadható.'], 405);
}
$be = json_decode((string)file_get_contents('php://input', false, null, 0, 30000), true);
if (!is_array($be)) {
    valasz(['hiba' => 'Hibás kérés.'], 400);
}
// robotcsapda: a rejtett mezőt ember nem tölti ki, és 3 mp alatt nem tölt ki űrlapot
if (!empty($be['weboldal']) || (int)($be['ido'] ?? 0) < 3) {
    valasz(['ok' => true]);
}

$s = function (string $kulcs, int $max = 190, bool $tobbsoros = false) use ($be): string {
    $v = isset($be[$kulcs]) && is_scalar($be[$kulcs]) ? trim((string)$be[$kulcs]) : '';
    $v = preg_replace($tobbsoros ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $tobbsoros ? '' : ' ', $v);
    return mb_substr((string)$v, 0, $max);
};
$a = [
    'nev'       => $s('nev', 150),
    'intezmeny' => $s('intezmeny'),
    'email'     => $s('email'),
    'telefon'   => $s('telefon', 30),
    'helyszin'  => $s('helyszin'),
    'alkalom'   => $s('alkalom', 60),
    'tema'      => $s('tema'),
    'idopont'   => $s('idopont'),
    'letszam'   => $s('letszam'),
    'uzenet'    => $s('uzenet', 3000, true),
];
$hibak = [];
if ($a['nev'] === '') $hibak[] = 'Hiányzik a név.';
if ($a['intezmeny'] === '') $hibak[] = 'Hiányzik az intézmény / közösség neve.';
if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) $hibak[] = 'Érvénytelen e-mail-cím.';
if ($a['helyszin'] === '') $hibak[] = 'Hiányzik a település, helyszín.';
if (empty($be['adatkezeles'])) $hibak[] = 'Az adatkezelési tájékoztató elfogadása kötelező.';
if ($hibak) {
    valasz(['hiba' => implode(' ', $hibak)], 422);
}

$cimkek = [
    'nev'       => 'Név',
    'intezmeny' => 'Intézmény / közösség',
    'email'     => 'E-mail-cím',
    'telefon'   => 'Telefonszám',
    'helyszin'  => 'Település, helyszín',
    'alkalom'   => 'Alkalom',
    'tema'      => 'Téma',
    'idopont'   => 'Tervezett időpont / időszak',
    'letszam'   => 'Várható létszám és közönség',
    'uzenet'    => 'Üzenet, egyéb kérés',
];
$sorokHtml = '';
$sorokSzoveg = '';
foreach ($cimkek as $k => $c) {
    $v = $a[$k] !== '' ? $a[$k] : '—';
    $sorokHtml .= '<tr><td style="padding:6px 14px 6px 0;color:#6e6a66;vertical-align:top;white-space:nowrap">' . h($c)
        . '</td><td style="padding:6px 0;vertical-align:top">' . nl2br(h($v)) . '</td></tr>';
    $sorokSzoveg .= $c . ': ' . $v . "\n";
}
$tablazat = '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:15px;margin:0 0 18px">' . $sorokHtml . '</table>';

$cfg = faro_config();
$ok = level_kuld(
    $cfg['admin_email'],
    'Csengeri Zsuzsa',
    'Előadás-igénylés – ' . $a['intezmeny'],
    level_keret('Új előadás-igénylés', '<p>Új előadás-igénylés érkezett a weboldalról. Erre a levélre válaszolva közvetlenül a küldőnek írsz.</p>' . $tablazat),
    "Új előadás-igénylés érkezett a weboldalról.\n\n" . $sorokSzoveg,
    $a['email']
);
if (!$ok) {
    valasz(['hiba' => 'Az üzenet küldése most nem sikerült.'], 502);
}

level_kuld(
    $a['email'],
    $a['nev'],
    'Köszönöm a megkeresést! – Csengeri Zsuzsa',
    level_keret('Köszönöm a megkeresést!', '<p>Kedves ' . h($a['nev']) . '!</p><p>Köszönöm a megkeresést! Hamarosan jelentkezem, hogy egyeztessük a részleteket.</p>'
        . '<p style="margin:0 0 6px"><strong>Az elküldött adatok:</strong></p>' . $tablazat
        . '<p>Szeretettel:<br>Csengeri Zsuzsa</p>'),
    "Kedves {$a['nev']}!\n\nKöszönöm a megkeresést! Hamarosan jelentkezem, hogy egyeztessük a részleteket.\n\nAz elküldött adatok:\n" . $sorokSzoveg . "\nSzeretettel:\nCsengeri Zsuzsa\n",
    $cfg['admin_email']
);

valasz(['ok' => true]);
