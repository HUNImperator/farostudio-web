<?php
defined('FARO') or exit;

use PHPMailer\PHPMailer\PHPMailer;

require_once FARO_APP . '/vendor/PHPMailer/Exception.php';
require_once FARO_APP . '/vendor/PHPMailer/PHPMailer.php';
require_once FARO_APP . '/vendor/PHPMailer/SMTP.php';

/**
 * Levél küldése az info@farostudio.hu fiókból, hitelesített SMTP-vel.
 * Helyi teszteléskor (mail_mode = log) a levél egy naplófájlba kerül.
 */
function level_kuld(string $cimzett, string $cimzettNev, string $targy, string $html, string $szoveg, ?string $valaszCim = null): bool
{
    $cfg = faro_config();
    if (($cfg['mail_mode'] ?? 'smtp') === 'log') {
        $sor = "=== " . most() . " → $cimzett ($cimzettNev)\nTárgy: $targy\n\n$szoveg\n\n";
        file_put_contents($cfg['mail_log'], $sor, FILE_APPEND);
        return true;
    }
    $s = $cfg['smtp'] ?? null;
    if (!$s) {
        error_log('[FARO] Nincs SMTP-beállítás, a levél nem ment el: ' . $targy);
        return false;
    }
    $m = new PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host       = $s['host'];
        $m->Port       = (int)($s['port'] ?? 465);
        $m->SMTPAuth   = true;
        $m->Username   = $s['user'];
        $m->Password   = $s['pass'];
        $m->SMTPSecure = ($s['secure'] ?? 'ssl') === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $m->CharSet    = 'UTF-8';
        $m->Encoding   = 'base64';
        $m->setFrom($s['from'] ?? $s['user'], $s['fromName'] ?? 'Csengeri Zsuzsa');
        $m->addAddress($cimzett, $cimzettNev);
        if ($valaszCim) {
            $m->addReplyTo($valaszCim);
        }
        $m->Subject = $targy;
        $m->isHTML(true);
        $m->Body    = $html;
        $m->AltBody = $szoveg;
        $m->send();
        return true;
    } catch (Throwable $e) {
        error_log('[FARO] Levélküldési hiba (' . $targy . '): ' . $m->ErrorInfo);
        return false;
    }
}

/* ---------- közös levélkeret ---------- */
function level_keret(string $cim, string $tartalom): string
{
    return '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
        . '<body style="margin:0;background:#f0e5db;font-family:Arial,Helvetica,sans-serif;color:#2b2b2b">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0e5db;padding:24px 0"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff">'
        . '<tr><td style="padding:28px 32px 8px;border-top:4px solid #111111">'
        . '<div style="font-size:22px;letter-spacing:6px;color:#111111;font-weight:bold">FARO</div>'
        . '<div style="font-size:11px;letter-spacing:3px;color:#6e6a66;text-transform:uppercase">Csengeri Zsuzsa</div></td></tr>'
        . '<tr><td style="padding:16px 32px 8px"><h1 style="font-size:22px;font-weight:normal;color:#111111;margin:0 0 12px">' . h($cim) . '</h1>'
        . $tartalom . '</td></tr>'
        . '<tr><td style="padding:20px 32px 28px;font-size:12px;color:#6e6a66;border-top:1px solid #eee">'
        . 'Csengeri Zsuzsa e.v. · 2310 Szigetszentmiklós, Szent Miklós útja 18/A/1. · '
        . '<a href="mailto:info@farostudio.hu" style="color:#6e6a66">info@farostudio.hu</a> · +36 30 735 8092</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function level_tetelek_html(array $r): string
{
    $sorok = '';
    foreach ($r['tetelek'] as $t) {
        $sorok .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eee">' . h($t['cim']) . ' × ' . (int)$t['db']
            . ($t['dedikalas'] !== '' && $t['dedikalas'] !== null ? '<br><span style="color:#6e6a66;font-size:13px">Dedikálás: ' . h($t['dedikalas']) . '</span>' : '')
            . '</td><td align="right" style="padding:8px 0;border-bottom:1px solid #eee;white-space:nowrap">' . h(ft($t['egysegar'] * $t['db'])) . '</td></tr>';
    }
    $sorok .= '<tr><td style="padding:8px 0">Foxpost csomagautomata</td><td align="right" style="padding:8px 0">' . h(ft((int)$r['szallitas'])) . '</td></tr>';
    $sorok .= '<tr><td style="padding:10px 0;border-top:2px solid #111;font-size:17px"><strong>Összesen</strong></td>'
        . '<td align="right" style="padding:10px 0;border-top:2px solid #111;font-size:17px"><strong>' . h(ft((int)$r['vegosszeg'])) . '</strong></td></tr>';
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:15px;margin:8px 0 18px">' . $sorok . '</table>';
}

function level_tetelek_szoveg(array $r): string
{
    $s = '';
    foreach ($r['tetelek'] as $t) {
        $s .= '- ' . $t['cim'] . ' × ' . $t['db'] . ': ' . ft($t['egysegar'] * $t['db']) . "\n";
        if ($t['dedikalas']) {
            $s .= '  Dedikálás: ' . $t['dedikalas'] . "\n";
        }
    }
    $s .= '- Foxpost csomagautomata: ' . ft((int)$r['szallitas']) . "\n";
    $s .= 'Összesen: ' . ft((int)$r['vegosszeg']) . "\n";
    return $s;
}

/* ---------- vevőnek: rendelés visszaigazolása ---------- */
function level_vevo_visszaigazolas(array $r): bool
{
    $cfg = faro_config();
    $b = $cfg['bank'];
    $nev = h($r['vevo_nev']);
    $html = '<p>Kedves ' . $nev . '!</p><p>Köszönöm a rendelésed! A rendelésszámod: <strong>' . h($r['rendelesszam']) . '</strong>.</p>'
        . level_tetelek_html($r)
        . '<p style="margin:0 0 4px"><strong>Foxpost csomagautomata</strong></p><p style="margin:0 0 18px">' . h($r['apt_nev']) . '<br>' . h($r['apt_cim']) . '</p>';
    $szoveg = "Kedves {$r['vevo_nev']}!\n\nKöszönöm a rendelésed! A rendelésszámod: {$r['rendelesszam']}.\n\n"
        . level_tetelek_szoveg($r) . "\nFoxpost csomagautomata: {$r['apt_nev']}, {$r['apt_cim']}\n\n";

    if ($r['fizetes'] === 'atutalas') {
        $hatarido = $cfg['atutalas_hatarido_nap'] ? ' ' . (int)$cfg['atutalas_hatarido_nap'] . ' napon belül' : '';
        $html .= '<div style="background:#f0e5db;padding:16px 18px;margin:0 0 18px"><p style="margin:0 0 8px"><strong>Fizetési adatok (banki átutalás)</strong></p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:15px">'
            . '<tr><td style="padding:2px 14px 2px 0;color:#6e6a66">Kedvezményezett</td><td>' . h($b['kedvezmenyezett']) . '</td></tr>'
            . '<tr><td style="padding:2px 14px 2px 0;color:#6e6a66">Bank</td><td>' . h($b['bank']) . '</td></tr>'
            . '<tr><td style="padding:2px 14px 2px 0;color:#6e6a66">Számlaszám</td><td><strong>' . h($b['szamlaszam']) . '</strong></td></tr>'
            . '<tr><td style="padding:2px 14px 2px 0;color:#6e6a66">Összeg</td><td><strong>' . h(ft((int)$r['vegosszeg'])) . '</strong></td></tr>'
            . '<tr><td style="padding:2px 14px 2px 0;color:#6e6a66">Közlemény</td><td><strong>' . h($r['rendelesszam']) . '</strong></td></tr>'
            . '</table></div>'
            . '<p>Kérlek, az összeget' . h($hatarido) . ' utald át, a közleményben a rendelésszámmal. Amint megérkezik, dedikálom és feladom a könyveket, a számlát pedig e-mailben küldöm.</p>';
        $szoveg .= "Fizetési adatok (banki átutalás)\nKedvezményezett: {$b['kedvezmenyezett']}\nBank: {$b['bank']}\nSzámlaszám: {$b['szamlaszam']}\n"
            . 'Összeg: ' . ft((int)$r['vegosszeg']) . "\nKözlemény: {$r['rendelesszam']}\n\n"
            . "Kérlek, az összeget{$hatarido} utald át, a közleményben a rendelésszámmal. Amint megérkezik, dedikálom és feladom a könyveket, a számlát pedig e-mailben küldöm.\n\n";
    } else {
        $html .= '<p>A fizetés beérkezése után dedikálom és feladom a könyveket, a számlát e-mailben küldöm.</p>';
        $szoveg .= "A fizetés beérkezése után dedikálom és feladom a könyveket, a számlát e-mailben küldöm.\n\n";
    }
    $html .= '<p>Ha kérdésed van, válaszolj erre a levélre.</p><p>Szeretettel:<br>Csengeri Zsuzsa</p>';
    $szoveg .= "Ha kérdésed van, válaszolj erre a levélre.\n\nSzeretettel:\nCsengeri Zsuzsa\n";

    return level_kuld($r['vevo_email'], $r['vevo_nev'], 'Rendelésed visszaigazolása – ' . $r['rendelesszam'],
        level_keret('Köszönöm a rendelésed!', $html), $szoveg);
}

/* ---------- neked: értesítés új rendelésről ---------- */
function level_admin_uj_rendeles(array $r): bool
{
    $cfg = faro_config();
    $link = rtrim($cfg['site_url'], '/') . '/admin/?p=rendeles&id=' . (int)$r['id'];
    $html = '<p>Új rendelés érkezett: <strong>' . h($r['rendelesszam']) . '</strong> – ' . h(FIZETESI_MODOK[$r['fizetes']]) . ', ' . h(ft((int)$r['vegosszeg'])) . '.</p>'
        . level_tetelek_html($r)
        . '<p><strong>Vevő:</strong> ' . h($r['vevo_nev']) . ' · ' . h($r['vevo_email']) . ' · ' . h($r['vevo_telefon']) . '<br>'
        . '<strong>Automata:</strong> ' . h($r['apt_nev']) . ', ' . h($r['apt_cim']) . '</p>'
        . ($r['megjegyzes'] ? '<p><strong>Megjegyzés:</strong> ' . nl2br(h($r['megjegyzes'])) . '</p>' : '')
        . '<p><a href="' . h($link) . '" style="display:inline-block;background:#111;color:#fff;padding:12px 20px;text-decoration:none">Megnyitás az adminban</a></p>';
    $szoveg = "Új rendelés: {$r['rendelesszam']} – " . FIZETESI_MODOK[$r['fizetes']] . ', ' . ft((int)$r['vegosszeg']) . "\n\n"
        . level_tetelek_szoveg($r) . "\nVevő: {$r['vevo_nev']}, {$r['vevo_email']}, {$r['vevo_telefon']}\n"
        . "Automata: {$r['apt_nev']}, {$r['apt_cim']}\n" . ($r['megjegyzes'] ? "Megjegyzés: {$r['megjegyzes']}\n" : '') . "\n$link\n";
    return level_kuld($cfg['admin_email'], 'FARO', 'Új rendelés: ' . $r['rendelesszam'] . ' (' . ft((int)$r['vegosszeg']) . ')',
        level_keret('Új rendelés', $html), $szoveg, $r['vevo_email']);
}
