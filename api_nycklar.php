<?php
/**
 * api_nycklar.php
 * Läser ut alla nycklar som JSON, med beräknad status (Hemma/Utlånad/Tappad)
 * och var nyckeln bor (knippa eller nyckelskåp).
 *
 * Autentisering: se api_nyckelsystem.php.
 */
require_once 'config.php';

api_krav_giltigt_losenord();

$rader = $db->query(
    "SELECT n.NyckelID, n.Funktion, n.Placering,
        ny.NyckelsystemID AS Nyckelsystem,
        k.Namn AS Knippa,
        s1.Namn AS KnippaNyckelskap,
        s2.Namn AS DirektNyckelskap,
        n.Forlorad,
        EXISTS (SELECT 1 FROM nyko_lanrad lr WHERE lr.NyckelID = n.ID AND lr.Status = 'Utlanad') AS Utlanad,
        n.Skapad, n.Uppdaterad
     FROM nyko_nyckel n
     LEFT JOIN nyko_nyckelsystem ny ON ny.ID = n.NyckelsystemID
     LEFT JOIN nyko_knippa k ON k.ID = n.KnippaID
     LEFT JOIN nyko_nyckelskap s1 ON s1.ID = k.NyckelskapID
     LEFT JOIN nyko_nyckelskap s2 ON s2.ID = n.NyckelskapID
     ORDER BY n.NyckelID"
)->fetch_all(MYSQLI_ASSOC);

foreach ($rader as &$rad) {
    if ($rad['Forlorad']) {
        $rad['Status'] = 'Tappad';
    } else {
        $rad['Status'] = $rad['Utlanad'] ? 'Utlanad' : 'Hemma';
    }
    $rad['BorI'] = $rad['Knippa']
        ? ['typ' => 'knippa', 'namn' => $rad['Knippa'], 'nyckelskap' => $rad['KnippaNyckelskap']]
        : ['typ' => 'nyckelskap', 'namn' => $rad['DirektNyckelskap']];
    unset($rad['Knippa'], $rad['KnippaNyckelskap'], $rad['DirektNyckelskap'], $rad['Utlanad']);
    $rad['Forlorad'] = (bool)$rad['Forlorad'];
}
unset($rad);

api_svara(['nycklar' => $rader]);
