<?php
/**
 * api_utlaningar.php
 * Läser ut alla aktuella utlåningar (allt utom helt återlämnade) som JSON.
 *
 * Autentisering: se api_nyckelsystem.php.
 */
require_once 'config.php';

api_krav_giltigt_losenord();

$rader = $db->query(
    "SELECT lan.ID AS LanID, l.Namn AS Lantagare, l.Telefonnummer, g.Namn AS Lantagargrupp,
        lan.Utlaningsdatum, lan.SlutdatumGiltighet, lan.Status,
        (SELECT COUNT(*) FROM nyko_lanrad lr WHERE lr.LanID = lan.ID) AS AntalNycklar,
        (SELECT COUNT(*) FROM nyko_lanrad lr WHERE lr.LanID = lan.ID AND lr.Status = 'Aterlamnad') AS AntalAterlamnade
     FROM nyko_lan lan
     JOIN nyko_lantagare l ON l.ID = lan.LantagareID
     LEFT JOIN nyko_lantagargrupp g ON g.ID = l.LantagargruppID
     WHERE lan.Status != 'Helt aterlamnat'
     ORDER BY lan.ID"
)->fetch_all(MYSQLI_ASSOC);

api_svara(['utlaningar' => $rader]);
