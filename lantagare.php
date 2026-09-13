<?php
require_once 'config.php';

$sidtitel = 'Låntagare';
$fi = hamta_faltinstallningar('Lantagare');

// -----------------------------------------------------------------
// Hantera POST (kräver inloggning)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifiera();
    krav_inloggning();

    $action = $_POST['action'] ?? '';

    if ($action === 'ta_bort') {
        $id = (int)($_POST['id'] ?? 0);
        $resultat = sakert_ta_bort('nyko_lantagare', $id);
        satt_flash($resultat['ok'] ? 'ok' : 'fel', $resultat['meddelande']);
        header('Location: lantagare.php');
        exit;
    }

    if ($action === 'spara') {
        $id = (int)($_POST['id'] ?? 0);

        $namn = p('Namn');
        $telefonnummer = p('Telefonnummer');
        $mejl = p('Mejl');
        $personnummer = p_null('Personnummer');
        $lantagargruppid = p_null('LantagargruppID');
        $internkontaktperson = p_null('InternKontaktperson');
        $giltigtill = p_null('GiltigTill');
        $avstangd = isset($_POST['Avstangd']) ? 1 : 0;
        $fritext = p_null('Fritext');

        $fel = [];
        if (!empty($fi['Namn']) && $namn === '') {
            $fel[] = 'Namn måste fyllas i.';
        }
        if (!empty($fi['Telefonnummer']) && $telefonnummer === '') {
            $fel[] = 'Telefonnummer måste fyllas i.';
        }
        if (!empty($fi['Mejl']) && $mejl === '') {
            $fel[] = 'Mejl måste fyllas i.';
        }
        if ($mejl !== '' && !filter_var($mejl, FILTER_VALIDATE_EMAIL)) {
            $fel[] = 'Mejladressen ser inte ut att vara giltig.';
        }
        if ($giltigtill !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $giltigtill)) {
            $fel[] = 'Giltig till måste vara ett giltigt datum.';
        }

        if ($fel) {
            satt_flash('fel', implode(' ', $fel));
            header('Location: lantagare.php' . ($id ? '?redigera=' . $id : '?redigera=ny'));
            exit;
        }

        // OBS: låntagare slås aldrig ihop automatiskt på namn - olika personer kan
        // heta samma sak, så varje "Lägg till" skapar alltid en ny post. Vill man
        // uppdatera en befintlig låntagare används "Redigera" på den raden istället.
        if ($id > 0) {
            $resultat = uppdatera_post('nyko_lantagare', $id, [
                'Namn' => $namn,
                'Telefonnummer' => $telefonnummer,
                'Mejl' => $mejl,
                'Personnummer' => $personnummer,
                'LantagargruppID' => $lantagargruppid,
                'InternKontaktperson' => $internkontaktperson,
                'GiltigTill' => $giltigtill,
                'Avstangd' => (string)$avstangd,
                'Fritext' => $fritext,
            ]);
            satt_flash($resultat['ok'] ? 'ok' : 'fel', $resultat['ok'] ? 'Låntagaren uppdaterades.' : $resultat['fel']);
        } else {
            try {
                $stmt = $db->prepare('INSERT INTO nyko_lantagare (Namn, Telefonnummer, Mejl, Personnummer, LantagargruppID, InternKontaktperson, GiltigTill, Avstangd, Fritext) VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->bind_param('sssssssis', $namn, $telefonnummer, $mejl, $personnummer, $lantagargruppid, $internkontaktperson, $giltigtill, $avstangd, $fritext);
                $stmt->execute();
                $stmt->close();
                satt_flash('ok', 'Ny låntagare tillagd.');
            } catch (mysqli_sql_exception $e) {
                satt_flash('fel', 'Kunde inte spara låntagaren: ' . $e->getMessage());
            }
        }
        header('Location: lantagare.php');
        exit;
    }
}

// -----------------------------------------------------------------
// Data för formulär vid redigering
// -----------------------------------------------------------------
$redigerar = null;
if (inloggad() && isset($_GET['redigera']) && $_GET['redigera'] !== 'ny') {
    $redigeraId = (int)$_GET['redigera'];
    $stmt = $db->prepare('SELECT * FROM nyko_lantagare WHERE ID = ?');
    $stmt->bind_param('i', $redigeraId);
    $stmt->execute();
    $redigerar = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$visaformular = inloggad() && isset($_GET['redigera']);

$grupp_lista = $db->query('SELECT ID, Namn FROM nyko_lantagargrupp ORDER BY Namn')->fetch_all(MYSQLI_ASSOC);

// -----------------------------------------------------------------
// Sök, filtrera, sortera
// -----------------------------------------------------------------
$sokterm = trim($_GET['sok'] ?? '');
$filter_grupp = (int)($_GET['grupp'] ?? 0);

$sorteringsfalt = [
    'Namn' => 'l.Namn',
    'Telefonnummer' => 'l.Telefonnummer',
    'Mejl' => 'l.Mejl',
    'GruppNamn' => 'g.Namn',
];
[$sql_sortering, $riktning, $vald_sortnyckel] = sakerstall_sortering($sorteringsfalt, 'Namn');

// Personnummer räknas inte in i sökningen om man inte är inloggad - annars
// går det att pröva sig fram till ett dolt personnummer via sökfältet.
$sokkolumner = ['l.Namn', 'l.Telefonnummer', 'l.Mejl', 'g.Namn'];
if (inloggad()) {
    $sokkolumner[] = 'l.Personnummer';
}

$villkor = ['1=1'];
$typer = '';
$varden = [];

$sok = sok_villkor($sokkolumner, $sokterm);
if ($sok['sql'] !== '') {
    $villkor[] = $sok['sql'];
    $typer .= $sok['typer'];
    $varden = array_merge($varden, $sok['varden']);
}
if ($filter_grupp > 0) {
    $villkor[] = 'l.LantagargruppID = ?';
    $typer .= 'i';
    $varden[] = $filter_grupp;
}

$stmt = $db->prepare(
    'SELECT l.*, g.Namn AS GruppNamn, g.Avstangd AS GruppAvstangd, g.GiltigTill AS GruppGiltigTill
     FROM nyko_lantagare l
     LEFT JOIN nyko_lantagargrupp g ON g.ID = l.LantagargruppID
     WHERE ' . implode(' AND ', $villkor) . " ORDER BY $sql_sortering $riktning"
);
if ($varden) {
    $stmt->bind_param($typer, ...$varden);
}
$stmt->execute();
$lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($lista as &$rad) {
    $kombinerat = kombinera_giltighet((bool)$rad['Avstangd'], $rad['GiltigTill'], (bool)$rad['GruppAvstangd'], $rad['GruppGiltigTill']);
    $giltighet_utgangen = $kombinerat['giltig_till'] !== null && $kombinerat['giltig_till'] < date('Y-m-d');
    if ($kombinerat['avstangd']) {
        $rad['StatusText'] = 'Avstängd' . ((int)$rad['Avstangd'] === 0 ? ' (via grupp)' : '');
    } elseif ($giltighet_utgangen) {
        $rad['StatusText'] = 'Giltighet utgången (' . $kombinerat['giltig_till'] . ')';
    } elseif ($kombinerat['giltig_till']) {
        $rad['StatusText'] = 'Giltig till ' . $kombinerat['giltig_till'];
    } else {
        $rad['StatusText'] = 'Godkänd';
    }
    $rad['RadVarning'] = $kombinerat['avstangd'] || $giltighet_utgangen;
}
unset($rad);

if (($_GET['export'] ?? '') === 'csv') {
    // Personnummer räknas som känsligt och tas bort ur exporten om man inte är inloggad,
    // precis som det maskeras i själva listan.
    $kolumner = ['Namn', 'Telefonnummer', 'Mejl'];
    $falt = ['Namn', 'Telefonnummer', 'Mejl'];
    if (inloggad()) {
        $kolumner[] = 'Personnummer';
        $falt[] = 'Personnummer';
    }
    $kolumner = array_merge($kolumner, ['Låntagargrupp', 'Status']);
    $falt = array_merge($falt, ['GruppNamn', 'StatusText']);
    exportera_csv('nyckelkoll-lantagare.csv', $kolumner, $lista, $falt);
}

$per_sida = 50;
$totalt_antal = count($lista);
$lista_sida = array_slice($lista, (hamta_sida() - 1) * $per_sida, $per_sida);

require 'includes/header.php';
?>

<h1>Låntagare</h1>
<?php visa_flash(); ?>

<?php if (inloggad()): ?>
    <p><a href="lantagare.php?redigera=ny" class="btn">+ Ny låntagare</a></p>
<?php endif; ?>

<form method="get" class="filterrad">
    <input type="hidden" name="sortera" value="<?= h($vald_sortnyckel) ?>">
    <input type="hidden" name="riktning" value="<?= h(strtolower($riktning)) ?>">
    <div>
        <label for="sok">Sök</label>
        <input type="text" id="sok" name="sok" value="<?= h($sokterm) ?>" placeholder="Namn, telefon, mejl...">
    </div>
    <div>
        <label for="grupp">Låntagargrupp</label>
        <select id="grupp" name="grupp">
            <option value="">-- alla --</option>
            <?php foreach ($grupp_lista as $grupp): ?>
                <option value="<?= (int)$grupp['ID'] ?>" <?= $filter_grupp === (int)$grupp['ID'] ? 'selected' : '' ?>><?= h($grupp['Namn']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-liten">Filtrera</button>
    <?php if ($sokterm !== '' || $filter_grupp > 0): ?>
        <a href="lantagare.php" class="btn btn-liten">Rensa</a>
    <?php endif; ?>
</form>

<p class="filter-antal">
    <?= visar_antal_text($totalt_antal, $per_sida, 'låntagare') ?>
    &nbsp;<a href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Exportera CSV</a>
</p>

<table>
    <thead>
        <tr>
            <th><?= sorteringshuvud('Namn', 'Namn') ?></th>
            <th><?= sorteringshuvud('Telefonnummer', 'Telefonnummer') ?></th>
            <th><?= sorteringshuvud('Mejl', 'Mejl') ?></th>
            <th>Personnummer</th>
            <th><?= sorteringshuvud('GruppNamn', 'Grupp') ?></th>
            <th>Status</th>
            <?php if (inloggad()): ?><th>Åtgärd</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (!$lista_sida): ?>
            <tr><td colspan="7"><?= ($sokterm !== '' || $filter_grupp > 0) ? 'Inga låntagare matchar sökningen/filtret.' : 'Inga låntagare registrerade ännu.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($lista_sida as $rad): ?>
            <tr class="<?= $rad['RadVarning'] ? 'rad-varning' : '' ?>">
                <td><?= h($rad['Namn']) ?></td>
                <td><?= h($rad['Telefonnummer']) ?></td>
                <td><?= h($rad['Mejl']) ?></td>
                <td><?= dolj_om_utloggad($rad['Personnummer']) ?></td>
                <td><?= h($rad['GruppNamn']) ?></td>
                <td><?= h($rad['StatusText']) ?></td>
                <?php if (inloggad()): ?>
                <td>
                    <a href="lantagare.php?redigera=<?= (int)$rad['ID'] ?>">Redigera</a>
                    &nbsp;
                    <form method="post" style="display:inline" onsubmit="return confirm('Ta bort denna låntagare?');">
                        <?= csrf_falt() ?>
                        <input type="hidden" name="action" value="ta_bort">
                        <input type="hidden" name="id" value="<?= (int)$rad['ID'] ?>">
                        <button type="submit" class="btn btn-liten btn-fara">Ta bort</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?= sidbladdring($totalt_antal, $per_sida) ?>

<?php if ($visaformular): ?>
    <h2><?= $redigerar ? 'Redigera låntagare' : 'Ny låntagare' ?></h2>
    <?php if (!$redigerar): ?>
        <p class="hjalptext">Låntagare slås aldrig ihop automatiskt (olika personer kan heta samma sak) - "Lägg till" skapar alltid en ny post. Vill du ändra en befintlig låntagare, använd "Redigera" på den raden i listan istället.</p>
    <?php endif; ?>
    <form method="post" class="formular">
        <?= csrf_falt() ?>
        <input type="hidden" name="action" value="spara">
        <input type="hidden" name="id" value="<?= $redigerar ? (int)$redigerar['ID'] : 0 ?>">

        <label for="Namn">Namn<?= kravstjarna($fi, 'Namn') ?></label>
        <input type="text" id="Namn" name="Namn" value="<?= h($redigerar['Namn'] ?? '') ?>" <?= kravattribut($fi, 'Namn') ?>>

        <label for="Telefonnummer">Telefonnummer<?= kravstjarna($fi, 'Telefonnummer') ?></label>
        <input type="tel" id="Telefonnummer" name="Telefonnummer" value="<?= h($redigerar['Telefonnummer'] ?? '') ?>" <?= kravattribut($fi, 'Telefonnummer') ?>>

        <label for="Mejl">Mejl<?= kravstjarna($fi, 'Mejl') ?></label>
        <input type="email" id="Mejl" name="Mejl" value="<?= h($redigerar['Mejl'] ?? '') ?>" <?= kravattribut($fi, 'Mejl') ?>>

        <label for="Personnummer">Personnummer</label>
        <input type="text" id="Personnummer" name="Personnummer" value="<?= h($redigerar['Personnummer'] ?? '') ?>">
        <p class="hjalptext">Personnumret visas maskerat (••••••) för den som inte är inloggad.</p>

        <label for="LantagargruppID">Låntagargrupp</label>
        <select id="LantagargruppID" name="LantagargruppID">
            <option value="">-- ingen grupp --</option>
            <?php foreach ($grupp_lista as $grupp): ?>
                <option value="<?= (int)$grupp['ID'] ?>" <?= (isset($redigerar['LantagargruppID']) && (int)$redigerar['LantagargruppID'] === (int)$grupp['ID']) ? 'selected' : '' ?>>
                    <?= h($grupp['Namn']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="InternKontaktperson">Intern kontaktperson</label>
        <input type="text" id="InternKontaktperson" name="InternKontaktperson" value="<?= h($redigerar['InternKontaktperson'] ?? '') ?>">

        <label for="GiltigTill">Giltig till</label>
        <input type="date" id="GiltigTill" name="GiltigTill" value="<?= h($redigerar['GiltigTill'] ?? '') ?>">
        <p class="hjalptext">Lämna tomt för ingen bortre gräns. Går ut vid midnatt det angivna datumet.</p>

        <label style="font-weight:normal;">
            <input type="checkbox" name="Avstangd" <?= !empty($redigerar['Avstangd']) ? 'checked' : '' ?>>
            Avstängd (kan inte låna ut nycklar till den här låntagaren)
        </label>

        <label for="Fritext">Fritext</label>
        <textarea id="Fritext" name="Fritext" rows="3"><?= h($redigerar['Fritext'] ?? '') ?></textarea>

        <button type="submit" class="btn"><?= $redigerar ? 'Spara ändringar' : 'Lägg till' ?></button>
    </form>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
