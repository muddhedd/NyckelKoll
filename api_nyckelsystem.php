<?php
/**
 * api_nyckelsystem.php
 * Läser ut alla nyckelsystem som JSON.
 *
 * Autentisering: skicka det delade API-lösenordet antingen som headern
 * "X-Api-Key: ditt-losenord" eller som query-parametern ?losenord=...
 *
 * Exempel: GET /api_nyckelsystem.php  (med headern satt)
 *          GET /api_nyckelsystem.php?losenord=ditt-losenord
 */
require_once 'config.php';

api_krav_giltigt_losenord();

$rader = $db->query(
    'SELECT NyckelsystemID, Installationsar, Garantitid, Garantipart, Servicestation,
        ForvantadLivslangd, Placering, Fastighetsnummer, FastighetsInfo, Skapad, Uppdaterad
     FROM nyko_nyckelsystem
     ORDER BY NyckelsystemID'
)->fetch_all(MYSQLI_ASSOC);

api_svara(['nyckelsystem' => $rader]);
