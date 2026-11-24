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
        $t['tipus'] = $t['tipus'] ?? 'konyv';
        $termekek[$t['id']] = $t;
        // minden videósorozathoz tartozik egy „hosszabbítás” tétel (+90 nap, ugyanazon az áron)
        if ($t['tipus'] === 'video') {
            $termekek[$t['id'] . '-hosszabbitas'] = [
                'id'       => $t['id'] . '-hosszabbitas',
                'tipus'    => 'hosszabbitas',
                'sorozat'  => $t['id'],
                'modul'    => $t['modul'],
                'cim'      => $t['rovidCim'] . ' – hosszabbítás (+' . (int)($adat['videoHozzaferesNap'] ?? 90) . ' nap)',
                'ar'       => $t['ar'],
                'elerheto' => !empty($t['elerheto']),
            ];
        }
    }
    $k = ['szallitas' => $adat['szallitas'], 'termekek' => $termekek, 'video_nap' => (int)($adat['videoHozzaferesNap'] ?? 90)];
    return $k;
}

/** Rövid leírás a tételhez (levelekben, adminban): könyvnél üres. */
function tetel_leiras(array $t): string
{
    $tipus = $t['tipus'] ?? 'konyv';
    if ($tipus === 'konyv') return '';
    if ($tipus === 'program') {
        $k = termek((string)($t['termek_id'] ?? $t['id'] ?? ''));
        return (string)($k['hozzaferes'] ?? 'hozzáférés a programhoz');
    }
    return video_nap() . ' napos online hozzáférés';
}

/** A videósorozatok (a katalógus sorrendjében). */
function video_sorozatok(): array
{
    return array_values(array_filter(katalogus()['termekek'], function ($t) { return $t['tipus'] === 'video'; }));
}

function video_sorozat(string $id): ?array
{
    $t = termek($id);
    return $t && $t['tipus'] === 'video' ? $t : null;
}

function termek(string $id): ?array
{
    return katalogus()['termekek'][$id] ?? null;
}
