<?php
defined('FARO') or exit;

/**
 * Közös kezelő a weboldal űrlapjaihoz (előadás igénylése, mentorálás jelentkezés…).
 * JSON-t fogad, ellenőriz, levelet küld az admin címre (válaszcím: a küldő),
 * és visszaigazolást a küldőnek. Adatbázisba nem ment.
 *
 * $def:
 *   'level_cim'   => 'Új előadás-igénylés',           // a neked küldött levél címe
 *   'targy'       => function (array $a): string {…},  // a neked küldött levél tárgya
 *   'koszono'     => 'Köszönöm a megkeresést! …',      // a küldőnek küldött levél szövege
 *   'koszono_cim' => 'Köszönöm a megkeresést!',
 *   'elofeldolgoz' => function (array $a): array {…}, // opcionális: a levél előtti átalakítás
 *   'mezok'       => [kulcs => ['cimke' => '…', 'max' => 190, 'kotelezo' => 'hibaüzenet',
 *                     'tipus' => 'szoveg'|'email'|'tobbsoros'|'lista'|'valasztas', 'opciok' => […]]]
 */
function urlap_valasz(array $adat, int $kod = 200): void
{
    http_response_code($kod);
    echo json_encode($adat, JSON_UNESCAPED_UNICODE);
    exit;
}

function urlap_kezel(array $def): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        urlap_valasz(['hiba' => 'Csak POST kérés fogadható.'], 405);
    }
    $be = json_decode((string)file_get_contents('php://input', false, null, 0, 40000), true);
    if (!is_array($be)) {
        urlap_valasz(['hiba' => 'Hibás kérés.'], 400);
    }
    // robotcsapda: a rejtett mezőt ember nem tölti ki, és 3 mp alatt nem tölt ki űrlapot
    if (!empty($be['weboldal']) || (int)($be['ido'] ?? 0) < 3) {
        urlap_valasz(['ok' => true]);
    }

    $tisztit = function ($v, int $max, bool $tobbsoros): string {
        $v = is_scalar($v) ? trim((string)$v) : '';
        $v = preg_replace($tobbsoros ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $tobbsoros ? '' : ' ', $v);
        return mb_substr((string)$v, 0, $max);
    };

    $a = [];
    $hibak = [];
    foreach ($def['mezok'] as $k => $m) {
        $tipus = $m['tipus'] ?? 'szoveg';
        $max = $m['max'] ?? 190;
        if ($tipus === 'lista') {
            $lista = isset($be[$k]) && is_array($be[$k]) ? $be[$k] : [];
            $ertekek = [];
            foreach ($lista as $v) {
                $v = $tisztit($v, $max, false);
                if ($v !== '' && (empty($m['opciok']) || in_array($v, $m['opciok'], true))) {
                    $ertekek[] = $v;
                }
            }
            $a[$k] = array_values(array_unique($ertekek));
            $ures = !$a[$k];
        } else {
            $a[$k] = $tisztit($be[$k] ?? '', $max, $tipus === 'tobbsoros');
            if ($tipus === 'valasztas' && $a[$k] !== '' && !empty($m['opciok']) && !in_array($a[$k], $m['opciok'], true)) {
                $a[$k] = '';
            }
            if ($tipus === 'email' && $a[$k] !== '' && !filter_var($a[$k], FILTER_VALIDATE_EMAIL)) {
                $hibak[] = 'Érvénytelen e-mail-cím.';
            }
            $ures = $a[$k] === '';
        }
        if ($ures && !empty($m['kotelezo'])) {
            $hibak[] = $m['kotelezo'];
        }
    }
    if (empty($be['adatkezeles'])) {
        $hibak[] = 'Az adatkezelési tájékoztató elfogadása kötelező.';
    }
    if ($hibak) {
        urlap_valasz(['hiba' => implode(' ', array_unique($hibak))], 422);
    }
    if (isset($def['elofeldolgoz'])) {
        $a = $def['elofeldolgoz']($a);
    }

    $sorokHtml = '';
    $sorokSzoveg = '';
    foreach ($def['mezok'] as $k => $m) {
        if (!empty($m['rejtett_levelben'])) {
            continue;
        }
        $v = is_array($a[$k]) ? implode("\n", $a[$k]) : $a[$k];
        $v = $v !== '' ? $v : '—';
        $sorokHtml .= '<tr><td style="padding:6px 14px 6px 0;color:#6e6a66;vertical-align:top;width:40%">' . h($m['cimke'])
            . '</td><td style="padding:6px 0;vertical-align:top">' . nl2br(h($v)) . '</td></tr>';
        $sorokSzoveg .= $m['cimke'] . ': ' . (is_array($a[$k]) && $a[$k] ? "\n  - " . implode("\n  - ", $a[$k]) : $v) . "\n";
    }
    $tablazat = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:15px;margin:0 0 18px">' . $sorokHtml . '</table>';

    $cfg = faro_config();
    $ok = level_kuld(
        $cfg['admin_email'],
        'Csengeri Zsuzsa',
        $def['targy']($a),
        level_keret($def['level_cim'], '<p>' . h($def['level_cim']) . ' érkezett a weboldalról. Erre a levélre válaszolva közvetlenül a küldőnek írsz.</p>' . $tablazat),
        $def['level_cim'] . " érkezett a weboldalról.\n\n" . $sorokSzoveg,
        $a['email']
    );
    if (!$ok) {
        urlap_valasz(['hiba' => 'Az üzenet küldése most nem sikerült.'], 502);
    }

    level_kuld(
        $a['email'],
        $a['nev'],
        $def['koszono_cim'] . ' – Csengeri Zsuzsa',
        level_keret($def['koszono_cim'], '<p>Kedves ' . h($a['nev']) . '!</p><p>' . h($def['koszono']) . '</p>'
            . '<p style="margin:0 0 6px"><strong>Az elküldött adatok:</strong></p>' . $tablazat
            . '<p>Szeretettel:<br>Csengeri Zsuzsa</p>'),
        "Kedves {$a['nev']}!\n\n{$def['koszono']}\n\nAz elküldött adatok:\n" . $sorokSzoveg . "\nSzeretettel:\nCsengeri Zsuzsa\n",
        $cfg['admin_email']
    );

    urlap_valasz(['ok' => true]);
}
