<?php
/**
 * POST api/informalis-kineziologia-bejelentkezes.php – bejelentkezés az első alkalomra
 * (informalis-kineziologia-bejelentkezes.html). Levél az info@ címre + visszaigazolás a küldőnek.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_app/bootstrap.php';

/** ÉÉÉÉ-HH-NN → „ÉÉÉÉ. HH. NN.”; érvénytelen vagy jövőbeli dátumra üres. */
function szuletesi_datum(string $d): string
{
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d || $dt > new DateTime('today') || (int)$dt->format('Y') < 1900) {
        return '';
    }
    return $dt->format('Y. m. d.');
}

$be = json_decode((string)file_get_contents('php://input', false, null, 0, 40000), true);
$hibasDatum = is_array($be) && !empty($be['szul_datum']) && szuletesi_datum((string)$be['szul_datum']) === '';
$hibasIdo = is_array($be) && !empty($be['szul_ido']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$be['szul_ido']);
if ($hibasDatum || $hibasIdo) {
    header('Content-Type: application/json; charset=utf-8');
    urlap_valasz(['hiba' => $hibasDatum ? 'Érvénytelen születési dátum.' : 'Érvénytelen születési idő.'], 422);
}

urlap_kezel([
    'level_cim'   => 'Új bejelentkezés (informális kineziológia)',
    'targy'       => function (array $a): string { return 'Informális kineziológia – bejelentkezés: ' . $a['nev']; },
    'koszono_cim' => 'Köszönöm!',
    'koszono'     => 'Köszönöm! Hamarosan jelentkezem egy időponttal.',
    'elofeldolgoz' => function (array $a): array {
        if ($a['kinek'] === 'Gyermekemnek' && $a['gyermek_kor'] !== '') {
            $a['kinek'] = 'Gyermekemnek (életkora: ' . $a['gyermek_kor'] . ')';
        }
        $a['szul_datum'] = szuletesi_datum($a['szul_datum']);
        return $a;
    },
    'mezok'       => [
        'nev'         => ['cimke' => 'Név', 'max' => 150, 'kotelezo' => 'Hiányzik a név.'],
        'email'       => ['cimke' => 'E-mail-cím', 'tipus' => 'email', 'kotelezo' => 'Hiányzik az e-mail-cím.'],
        'telefon'     => ['cimke' => 'Telefonszám', 'max' => 30, 'kotelezo' => 'Hiányzik a telefonszám.'],
        'kinek'       => ['cimke' => 'Kinek szól az időpont', 'tipus' => 'valasztas', 'opciok' => ['Magamnak', 'Gyermekemnek', 'Másnak']],
        'gyermek_kor' => ['cimke' => 'Gyermek életkora', 'max' => 30, 'rejtett_levelben' => true],
        'forma'       => ['cimke' => 'Személyesen vagy online', 'tipus' => 'valasztas', 'opciok' => ['Személyesen', 'Online']],
        'raer'        => ['cimke' => 'Alkalmas időpont'],
        'szul_datum'  => ['cimke' => 'Születési dátum', 'max' => 10, 'kotelezo' => 'Hiányzik a születési dátum.'],
        'szul_ido'    => ['cimke' => 'Születési idő', 'max' => 5, 'kotelezo' => 'Hiányzik a születési idő.'],
        'szul_hely'   => ['cimke' => 'Születési hely', 'kotelezo' => 'Hiányzik a születési hely.'],
    ],
]);
