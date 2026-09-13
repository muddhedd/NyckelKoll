<?php
require_once 'config.php';

$sidtitel = 'Import/Export';

if (!inloggad()) {
    require 'includes/header.php';
    echo '<h1>Import/Export</h1><p class="meddelande meddelande-fel">Du måste vara inloggad för att se den här sidan. <a href="login.php">Logga in</a></p>';
    require 'includes/footer.php';
    exit;
}

// -----------------------------------------------------------------
// CSV-mallar och export - hanteras direkt (skriver fil, avslutar sidan)
// -----------------------------------------------------------------
if (isset($_GET['mall']) && in_array($_GET['mall'], ['nycklar', 'lantagare'], true)) {
    $filnamn = 'nyckelkoll-mall-' . $_GET['mall'] . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filnamn . '"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM så Excel tolkar åäö rätt
    $ut = fopen('php://output', 'w');
    if ($_GET['mall'] === 'nycklar') {
        fputcsv($ut, ['NyckelID', 'Funktion', 'Placering', 'Nyckelsystem', 'Knippa', 'Nyckelskap'], ';');
        fputcsv($ut, ['A123', 'Ytterdörr', 'Storgatan 1', 'LAS-2020', 'Städknippa', ''], ';');
        fputcsv($ut, ['A124', 'Källarförråd', 'Storgatan 1', '', '', 'Huvudskåpet'], ';');
    } else {
        fputcsv($ut, ['Namn', 'Telefonnummer', 'Mejl', 'Personnummer', 'Lantagargrupp'], ';');
        fputcsv($ut, ['Anna Andersson', '070-1234567', 'anna@example.com', '', 'Posten'], ';');
    }
    fclose($ut);
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'utlaningar') {
    $rader = $db->query(
        'SELECT lan.ID, l.Namn AS Lantagare, l.Telefonnummer, g.Namn AS Grupp,
            lan.Utlaningsdatum, lan.SlutdatumGiltighet, lan.Status,
            (SELECT COUNT(*) FROM nyko_lanrad lr WHERE lr.LanID = lan.ID) AS AntalNycklar,
            (SELECT COUNT(*) FROM nyko_lanrad lr WHERE lr.LanID = lan.ID AND lr.Status = \'Aterlamnad\') AS AntalAterlamnade
         FROM nyko_lan lan
         JOIN nyko_lantagare l ON l.ID = lan.LantagareID
         LEFT JOIN nyko_lantagargrupp g ON g.ID = l.LantagargruppID
         WHERE lan.Status != \'Helt aterlamnat\'
         ORDER BY lan.ID'
    )->fetch_all(MYSQLI_ASSOC);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="nyckelkoll-utlaningar.csv"');
    echo "\xEF\xBB\xBF";
    $ut = fopen('php://output', 'w');
    fputcsv($ut, ['Lan-nr', 'Lantagare', 'Telefonnummer', 'Lantagargrupp', 'Utlaningsdatum', 'Slutdatum', 'Status', 'Aterlamnade', 'AntalNycklar'], ';');
    foreach ($rader as $rad) {
        fputcsv($ut, [
            $rad['ID'], $rad['Lantagare'], $rad['Telefonnummer'], $rad['Grupp'],
            $rad['Utlaningsdatum'], $rad['SlutdatumGiltighet'], $rad['Status'],
            $rad['AntalAterlamnade'], $rad['AntalNycklar'],
        ], ';');
    }
    fclose($ut);
    exit;
}

// -----------------------------------------------------------------
// Hjälpfunktioner för CSV-inläsning
// -----------------------------------------------------------------

/** Läser en uppladdad CSV-fil, gissar avgränsare (; eller ,) och tar bort ev. BOM. */
function las_csv_rader(string $innehall): array
{
    $innehall = preg_replace('/^\xEF\xBB\xBF/', '', $innehall);
    $innehall = str_replace(["\r\n", "\r"], "\n", $innehall);
    $rader_text = array_filter(explode("\n", $innehall), fn($r) => trim($r) !== '');

    if (!$rader_text) {
        return ['avgransare' => ';', 'rader' => []];
    }

    $forsta_raden = reset($rader_text);
    $avgransare = substr_count($forsta_raden, ';') >= substr_count($forsta_raden, ',') ? ';' : ',';

    $rader = [];
    foreach ($rader_text as $rad_text) {
        $rader[] = str_getcsv($rad_text, $avgransare);
    }
    return ['avgransare' => $avgransare, 'rader' => $rader];
}

/** Jämför en inläst rubrikrad med den förväntade, oberoende av mellanslag/versaler. */
function rubriker_matchar(array $inlasta, array $forvantade): bool
{
    if (count($inlasta) !== count($forvantade)) {
        return false;
    }
    foreach ($inlasta as $i => $varde) {
        if (strcasecmp(trim($varde), $forvantade[$i]) !== 0) {
            return false;
        }
    }
    return true;
}

// -----------------------------------------------------------------
// Hjälpfunktioner för att slå upp eller skapa kopplade poster vid import
// (medvetet enklare än slaihop_eller_infoga(): dessa poster har bara ett
// identifierande fält att sätta vid nyskapande, inget att slå ihop.)
// -----------------------------------------------------------------

function import_hamta_eller_skapa_nyckelsystem(string $nyckelsystem_id): int
{
    global $db;
    $stmt = $db->prepare('SELECT ID FROM nyko_nyckelsystem WHERE NyckelsystemID = ?');
    $stmt->bind_param('s', $nyckelsystem_id);
    $stmt->execute();
    $rad = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($rad) {
        return (int)$rad['ID'];
    }
    $stmt = $db->prepare("INSERT INTO nyko_nyckelsystem (NyckelsystemID, Servicestation) VALUES (?, '')");
    $stmt->bind_param('s', $nyckelsystem_id);
    $stmt->execute();
    $nytt_id = $db->insert_id;
    $stmt->close();
    return $nytt_id;
}

function import_hamta_eller_skapa_nyckelskap(string $namn): int
{
    global $db;
    $stmt = $db->prepare('SELECT ID FROM nyko_nyckelskap WHERE Namn = ?');
    $stmt->bind_param('s', $namn);
    $stmt->execute();
    $rad = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($rad) {
        return (int)$rad['ID'];
    }
    $stmt = $db->prepare('INSERT INTO nyko_nyckelskap (Namn) VALUES (?)');
    $stmt->bind_param('s', $namn);
    $stmt->execute();
    $nytt_id = $db->insert_id;
    $stmt->close();
    return $nytt_id;
}

/** Slår upp en knippa på namn. Finns den inte skapas den under "Ej tilldelad". */
function import_hamta_eller_skapa_knippa(string $namn): array
{
    global $db;
    $stmt = $db->prepare('SELECT ID FROM nyko_knippa WHERE Namn = ?');
    $stmt->bind_param('s', $namn);
    $stmt->execute();
    $traffar = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($traffar) === 1) {
        return ['id' => (int)$traffar[0]['ID'], 'fel' => null];
    }
    if (count($traffar) > 1) {
        return ['id' => null, 'fel' => 'flera knippor heter "' . $namn . '" i olika skåp - kan inte avgöra vilken'];
    }

    static $ej_tilldelad_id = null;
    if ($ej_tilldelad_id === null) {
        $ej_tilldelad_id = import_hamta_eller_skapa_nyckelskap('Ej tilldelad');
    }
    $stmt = $db->prepare('INSERT INTO nyko_knippa (Namn, NyckelskapID) VALUES (?, ?)');
    $stmt->bind_param('si', $namn, $ej_tilldelad_id);
    $stmt->execute();
    $nytt_id = $db->insert_id;
    $stmt->close();
    return ['id' => $nytt_id, 'fel' => null];
}

function import_hamta_eller_skapa_lantagargrupp(string $namn): int
{
    global $db;
    $stmt = $db->prepare('SELECT ID FROM nyko_lantagargrupp WHERE Namn = ?');
    $stmt->bind_param('s', $namn);
    $stmt->execute();
    $rad = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($rad) {
        return (int)$rad['ID'];
    }
    $stmt = $db->prepare('INSERT INTO nyko_lantagargrupp (Namn) VALUES (?)');
    $stmt->bind_param('s', $namn);
    $stmt->execute();
    $nytt_id = $db->insert_id;
    $stmt->close();
    return $nytt_id;
}

// -----------------------------------------------------------------
// Hantera POST - import
// -----------------------------------------------------------------
$resultat_nycklar = null;
$resultat_lantagare = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifiera();
    krav_inloggning();

    $action = $_POST['action'] ?? '';

    // ---------------------------------------------------------------
    // Import av nycklar
    // ---------------------------------------------------------------
    if ($action === 'importera_nycklar') {
        $forvantat = ['NyckelID', 'Funktion', 'Placering', 'Nyckelsystem', 'Knippa', 'Nyckelskap'];
        $lyckade = 0;
        $fel = [];

        if (empty($_FILES['csv_fil']['tmp_name']) || $_FILES['csv_fil']['error'] !== UPLOAD_ERR_OK) {
            $fel[] = ['rad' => '-', 'meddelande' => 'Ingen fil valdes, eller uppladdningen misslyckades.'];
        } else {
            $data = las_csv_rader(file_get_contents($_FILES['csv_fil']['tmp_name']));
            $rader = $data['rader'];

            if (!$rader || !rubriker_matchar($rader[0], $forvantat)) {
                $fel[] = ['rad' => 1, 'meddelande' => 'Kolumnrubrikerna matchar inte det förväntade formatet. Förväntade: ' . implode(', ', $forvantat) . '.'];
            } else {
                $fi = hamta_faltinstallningar('Nyckel');

                for ($i = 1; $i < count($rader); $i++) {
                    $radnr = $i + 1; // för användaren, rad 1 = rubriker
                    $cellerna = $rader[$i];
                    if (count($cellerna) < count($forvantat)) {
                        $fel[] = ['rad' => $radnr, 'meddelande' => 'Fel antal kolumner.'];
                        continue;
                    }
                    [$nyckelid, $funktion, $placering, $nyckelsystem_txt, $knippa_namn, $nyckelskap_namn] = array_map('trim', $cellerna);

                    if (!empty($fi['NyckelID']) && $nyckelid === '') {
                        $fel[] = ['rad' => $radnr, 'meddelande' => 'Nyckel-ID saknas (obligatoriskt).'];
                        continue;
                    }
                    if ($knippa_namn === '' && $nyckelskap_namn === '') {
                        $fel[] = ['rad' => $radnr, 'meddelande' => 'Varken Knippa eller Nyckelskåp är ifyllt - en av dem måste anges.'];
                        continue;
                    }
                    if ($knippa_namn !== '' && $nyckelskap_namn !== '') {
                        $fel[] = ['rad' => $radnr, 'meddelande' => 'Både Knippa och Nyckelskåp är ifyllda - ange bara en av dem.'];
                        continue;
                    }

                    try {
                        $nyckelsystem_id = $nyckelsystem_txt !== '' ? import_hamta_eller_skapa_nyckelsystem($nyckelsystem_txt) : null;

                        $knippa_id = null;
                        $nyckelskap_id = null;
                        if ($nyckelskap_namn !== '') {
                            $nyckelskap_id = import_hamta_eller_skapa_nyckelskap($nyckelskap_namn);
                        } else {
                            $knippa_resultat = import_hamta_eller_skapa_knippa($knippa_namn);
                            if ($knippa_resultat['fel']) {
                                $fel[] = ['rad' => $radnr, 'meddelande' => $knippa_resultat['fel']];
                                continue;
                            }
                            $knippa_id = $knippa_resultat['id'];
                        }

                        // Slå ihop med befintlig nyckel (samma NyckelID) eller skapa ny -
                        // samma princip som "Lägg till" i nycklar.php.
                        $stmt = $db->prepare('SELECT ID FROM nyko_nyckel WHERE NyckelID = ?');
                        $stmt->bind_param('s', $nyckelid);
                        $stmt->execute();
                        $befintlig = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        $funktion_var = $funktion !== '' ? $funktion : null;
                        $placering_var = $placering !== '' ? $placering : null;
                        $nyckelsystem_bind = $nyckelsystem_id !== null ? (string)$nyckelsystem_id : null;
                        $knippa_bind = $knippa_id !== null ? (string)$knippa_id : null;
                        $nyckelskap_bind = $nyckelskap_id !== null ? (string)$nyckelskap_id : null;

                        if ($befintlig) {
                            $satser = ['KnippaID = ?', 'NyckelskapID = ?'];
                            $typer = 'ss';
                            $varden = [$knippa_bind, $nyckelskap_bind];
                            foreach (['Funktion' => $funktion_var, 'Placering' => $placering_var, 'NyckelsystemID' => $nyckelsystem_bind] as $kolumn => $varde) {
                                if ($varde !== null) {
                                    $satser[] = "$kolumn = ?";
                                    $typer .= 's';
                                    $varden[] = $varde;
                                }
                            }
                            $varden[] = (int)$befintlig['ID'];
                            $typer .= 'i';
                            $stmt2 = $db->prepare('UPDATE nyko_nyckel SET ' . implode(', ', $satser) . ' WHERE ID = ?');
                            $stmt2->bind_param($typer, ...$varden);
                            $stmt2->execute();
                            $stmt2->close();
                        } else {
                            $stmt2 = $db->prepare('INSERT INTO nyko_nyckel (NyckelID, Funktion, Placering, NyckelsystemID, KnippaID, NyckelskapID) VALUES (?,?,?,?,?,?)');
                            $stmt2->bind_param('ssssss', $nyckelid, $funktion_var, $placering_var, $nyckelsystem_bind, $knippa_bind, $nyckelskap_bind);
                            $stmt2->execute();
                            $stmt2->close();
                        }
                        $lyckade++;
                    } catch (mysqli_sql_exception $e) {
                        $fel[] = ['rad' => $radnr, 'meddelande' => 'Databasfel: ' . $e->getMessage()];
                    }
                }
            }
        }
        $resultat_nycklar = ['lyckade' => $lyckade, 'fel' => $fel];
    }

    // ---------------------------------------------------------------
    // Import av låntagare
    // ---------------------------------------------------------------
    if ($action === 'importera_lantagare') {
        $forvantat = ['Namn', 'Telefonnummer', 'Mejl', 'Personnummer', 'Lantagargrupp'];
        $lyckade = 0;
        $fel = [];

        if (empty($_FILES['csv_fil']['tmp_name']) || $_FILES['csv_fil']['error'] !== UPLOAD_ERR_OK) {
            $fel[] = ['rad' => '-', 'meddelande' => 'Ingen fil valdes, eller uppladdningen misslyckades.'];
        } else {
            $data = las_csv_rader(file_get_contents($_FILES['csv_fil']['tmp_name']));
            $rader = $data['rader'];

            if (!$rader || !rubriker_matchar($rader[0], $forvantat)) {
                $fel[] = ['rad' => 1, 'meddelande' => 'Kolumnrubrikerna matchar inte det förväntade formatet. Förväntade: ' . implode(', ', $forvantat) . '.'];
            } else {
                $fi = hamta_faltinstallningar('Lantagare');

                for ($i = 1; $i < count($rader); $i++) {
                    $radnr = $i + 1;
                    $cellerna = $rader[$i];
                    if (count($cellerna) < count($forvantat)) {
                        $fel[] = ['rad' => $radnr, 'meddelande' => 'Fel antal kolumner.'];
                        continue;
                    }
                    [$namn, $telefonnummer, $mejl, $personnummer, $grupp_namn] = array_map('trim', $cellerna);

                    $radfel = [];
                    if (!empty($fi['Namn']) && $namn === '') {
                        $radfel[] = 'Namn saknas';
                    }
                    if (!empty($fi['Telefonnummer']) && $telefonnummer === '') {
                        $radfel[] = 'Telefonnummer saknas';
                    }
                    if (!empty($fi['Mejl']) && $mejl === '') {
                        $radfel[] = 'Mejl saknas';
                    }
                    if ($mejl !== '' && !filter_var($mejl, FILTER_VALIDATE_EMAIL)) {
                        $radfel[] = 'Mejladressen är ogiltig';
                    }
                    if ($radfel) {
                        $fel[] = ['rad' => $radnr, 'meddelande' => implode(', ', $radfel) . ' (obligatoriskt).'];
                        continue;
                    }

                    try {
                        $grupp_id = $grupp_namn !== '' ? import_hamta_eller_skapa_lantagargrupp($grupp_namn) : null;
                        $personnummer_var = $personnummer !== '' ? $personnummer : null;
                        $grupp_bind = $grupp_id !== null ? (string)$grupp_id : null;

                        // Låntagare slås aldrig ihop automatiskt (se lantagare.php) - varje
                        // rad i importfilen skapar alltid en ny låntagare.
                        $stmt = $db->prepare('INSERT INTO nyko_lantagare (Namn, Telefonnummer, Mejl, Personnummer, LantagargruppID) VALUES (?,?,?,?,?)');
                        $stmt->bind_param('sssss', $namn, $telefonnummer, $mejl, $personnummer_var, $grupp_bind);
                        $stmt->execute();
                        $stmt->close();
                        $lyckade++;
                    } catch (mysqli_sql_exception $e) {
                        $fel[] = ['rad' => $radnr, 'meddelande' => 'Databasfel: ' . $e->getMessage()];
                    }
                }
            }
        }
        $resultat_lantagare = ['lyckade' => $lyckade, 'fel' => $fel];
    }
}

require 'includes/header.php';
?>

<h1>Import/Export</h1>
<?php visa_flash(); ?>

<h2>Importera nycklar</h2>
<p class="hjalptext">
    Kolumner, i exakt denna ordning: <strong>NyckelID; Funktion; Placering; Nyckelsystem; Knippa; Nyckelskap</strong>.<br>
    NyckelID är obligatoriskt (om inte avstängt under Inställningar). Ange antingen <strong>Knippa</strong> eller
    <strong>Nyckelskåp</strong> - aldrig båda, aldrig inget. Nyckelsystem, Knippa och Nyckelskåp anges med namn/ID som
    text; finns de inte sedan tidigare skapas de automatiskt (med resten av sina fält tomma). Skapas en ny knippa som
    inte redan finns hamnar den i ett nyckelskåp som heter <strong>"Ej tilldelad"</strong> (skapas automatiskt vid
    behov) - du får flytta den till rätt skåp efteråt i <a href="knippor.php">Knippor</a>. Finns nyckeln redan
    (samma NyckelID) uppdateras den istället för att dubbleras, precis som vid manuell inmatning.<br>
    <a href="import_export.php?mall=nycklar">Ladda ner CSV-mall</a>
</p>
<form method="post" enctype="multipart/form-data" class="formular formular-smal">
    <?= csrf_falt() ?>
    <input type="hidden" name="action" value="importera_nycklar">
    <label for="csv_fil_nycklar">CSV-fil</label>
    <input type="file" id="csv_fil_nycklar" name="csv_fil" accept=".csv" required>
    <button type="submit" class="btn">Importera nycklar</button>
</form>

<?php if ($resultat_nycklar): ?>
    <p class="meddelande <?= $resultat_nycklar['fel'] ? 'meddelande-varning' : 'meddelande-ok' ?>">
        <?= (int)$resultat_nycklar['lyckade'] ?> rad(er) importerades.
        <?= $resultat_nycklar['fel'] ? count($resultat_nycklar['fel']) . ' rad(er) kunde inte importeras, se nedan.' : '' ?>
    </p>
    <?php if ($resultat_nycklar['fel']): ?>
        <table>
            <thead><tr><th>Rad</th><th>Fel</th></tr></thead>
            <tbody>
                <?php foreach ($resultat_nycklar['fel'] as $f): ?>
                    <tr><td><?= h((string)$f['rad']) ?></td><td><?= h($f['meddelande']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>

<h2>Importera låntagare</h2>
<p class="hjalptext">
    Kolumner, i exakt denna ordning: <strong>Namn; Telefonnummer; Mejl; Personnummer; Lantagargrupp</strong>.<br>
    Namn/Telefonnummer/Mejl är obligatoriska (om inte avstängt under Inställningar). Lantagargrupp anges med
    gruppens namn som text; finns den inte sedan tidigare skapas den automatiskt (med resten av sina fält tomma).
    Går bra att lämna tom om låntagaren inte tillhör någon grupp. <strong>OBS:</strong> varje rad skapar alltid en
    ny låntagare - låntagare slås aldrig ihop automatiskt (två olika personer kan heta samma sak).<br>
    <a href="import_export.php?mall=lantagare">Ladda ner CSV-mall</a>
</p>
<form method="post" enctype="multipart/form-data" class="formular formular-smal">
    <?= csrf_falt() ?>
    <input type="hidden" name="action" value="importera_lantagare">
    <label for="csv_fil_lantagare">CSV-fil</label>
    <input type="file" id="csv_fil_lantagare" name="csv_fil" accept=".csv" required>
    <button type="submit" class="btn">Importera låntagare</button>
</form>

<?php if ($resultat_lantagare): ?>
    <p class="meddelande <?= $resultat_lantagare['fel'] ? 'meddelande-varning' : 'meddelande-ok' ?>">
        <?= (int)$resultat_lantagare['lyckade'] ?> rad(er) importerades.
        <?= $resultat_lantagare['fel'] ? count($resultat_lantagare['fel']) . ' rad(er) kunde inte importeras, se nedan.' : '' ?>
    </p>
    <?php if ($resultat_lantagare['fel']): ?>
        <table>
            <thead><tr><th>Rad</th><th>Fel</th></tr></thead>
            <tbody>
                <?php foreach ($resultat_lantagare['fel'] as $f): ?>
                    <tr><td><?= h((string)$f['rad']) ?></td><td><?= h($f['meddelande']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>

<h2>Exportera</h2>
<p><a href="import_export.php?export=utlaningar" class="btn">Exportera utlåningar (CSV)</a></p>
<p class="hjalptext">Exporterar alla rader som just nu visas i Aktuella utlåningar (allt utom helt återlämnade lån).</p>

<?php require 'includes/footer.php'; ?>
