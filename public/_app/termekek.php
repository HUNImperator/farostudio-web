<?php
defined('FARO') or exit;

/**
 * Termékek és szállítási díj. Egyelőre a weboldal termékadat-fájljából
 * (assets/data/termekek.json) olvassuk, így a böngésző és a szerver
 * ugyanabból az adatból dolgozik. A végösszeget mindig a szerver
 * számolja – a böngészőből érkező árakat nem fogadjuk el.
 */
function katalogus(): array
{
    static $k = null;
    if ($k !== null) {
        return $k;
    }
    $json = file_get_contents(FARO_PUBLIC . '/assets/data/termekek.json');
    $adat = json_decode((string)$json, true);
    if (!is_array($adat) || empty($adat['termekek'])) {
        faro_fatal('A termékadatok nem olvashatók.');
    }
    $termekek = [];
    foreach ($adat['termekek'] as $t) {
        $termekek[$t['id']] = $t;
    }
    $k = ['szallitas' => $adat['szallitas'], 'termekek' => $termekek];
    return $k;
}

function termek(string $id): ?array
{
    return katalogus()['termekek'][$id] ?? null;
}
