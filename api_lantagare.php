<?php
/**
 * api_lantagare.php
 * Läser ut alla låntagare som JSON, inklusive den kombinerade (person +
 * grupp) giltigheten - EffektivtAvstangd/EffektivtGiltigTill.
 *
 * Autentisering: se api_nyckelsystem.php.
 */
require_once 'config.php';

api_krav_giltigt_losenord();

$rader = $db->query(
    'SELECT l.Namn, l.Telefonnummer, l.Mejl, l.Personnummer,
        g.Namn AS Lantagargrupp,
        l.InternKontaktperson, l.GiltigTill, l.Avstangd, l.Fritext,
        g.Avstangd AS GruppAvstangd, g.GiltigTill AS GruppGiltigTill,
        l.Skapad, l.Uppdaterad
     FROM nyko_lantagare l
     LEFT JOIN nyko_lantagargrupp g ON g.ID = l.LantagargruppID
     ORDER BY l.Namn'
)->fetch_all(MYSQLI_ASSOC);

foreach ($rader as &$rad) {
    $kombinerat = kombinera_giltighet((bool)$rad['Avstangd'], $rad['GiltigTill'], (bool)$rad['GruppAvstangd'], $rad['GruppGiltigTill']);
    $rad['Avstangd'] = (bool)$rad['Avstangd'];
    $rad['EffektivtAvstangd'] = $kombinerat['avstangd'];
    $rad['EffektivtGiltigTill'] = $kombinerat['giltig_till'];
    unset($rad['GruppAvstangd'], $rad['GruppGiltigTill']);
}
unset($rad);

api_svara(['lantagare' => $rader]);
