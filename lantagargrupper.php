<?php
require_once 'config.php';

$sidtitel = 'Låntagargrupper';
$fi = hamta_faltinstallningar('Lantagargrupp');

// -----------------------------------------------------------------
// Hantera POST (kräver inloggning)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifiera();
    krav_inloggning();

    $action = $_POST['action'] ?? '';

    if ($action === 'ta_bort') {
        $id = (int)($_POST['id'] ?? 0);
        $resultat = sakert_ta_bort('nyko_lantagargrupp', $id);
        satt_flash($resultat['ok'] ? 'ok' : 'fel', $resultat['meddelande']);
        header('Location: lantagargrupper.php');
        exit;
    }

    if ($action === 'hamta_avtalskatalog') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT Avtalsnummer FROM nyko_lantagargrupp WHERE ID = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rad = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$rad || !$rad['Avtalsnummer']) {
            satt_flash('fel', 'Inget avtalsnummer är ifyllt på den här gruppen.');
        } else {
            $resultat = hamta_giltighet_fran_avtalskatalog($rad['Avtalsnummer']);
            if ($resultat['ok']) {
                $stmt = $db->prepare('UPDATE nyko_lantagargrupp SET GiltigTill = ? WHERE ID = ?');
                $stmt->bind_param('si', $resultat['giltig_till'], $id);
                $stmt->execute();
                $stmt->close();
                satt_flash('ok', 'Giltig till uppdaterades från Avtalskatalogen: ' . $resultat['giltig_till']);
            } else {
                satt_flash('fel', $resultat['fel']);
            }
        }
        header('Location: lantagargrupper.php?redigera=' . $id);
        exit;
    }

    if ($action === 'spara') {
        $id = (int)($_POST['id'] ?? 0);

        $namn = p('Namn');
        $kontaktperson = p_null('Kontaktperson');
        $telefonnummer = p_null('Telefonnummer');
        $organisationsnummer = p_null('Organisationsnummer');
        $internkontaktperson = p_null('InternKontaktperson');
        $giltigtill = p_null('GiltigTill');
        $avstangd = isset($_POST['Avstangd']) ? 1 : 0;
        $fritext = p_null('Fritext');
        $avtalsnummer = p_null('Avtalsnummer');

        $fel = [];
        if (!empty($fi['Namn']) && $namn === '') {
            $fel[] = 'Namn måste fyllas i.';
        }
        if ($giltigtill !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $giltigtill)) {
            $fel[] = 'Giltig till måste vara ett giltigt datum.';
        }

        if ($fel) {
            satt_flash('fel', implode(' ', $fel));
            header('Location: lantagargrupper.php' . ($id ? '?redigera=' . $id : '?redigera=ny'));
            exit;
        }

        if ($id > 0) {
            $resultat = uppdatera_post('nyko_lantagargrupp', $id, [
                'Namn' => $namn,
                'Kontaktperson' => $kontaktperson,
                'Telefonnummer' => $telefonnummer,
                'Organisationsnummer' => $organisationsnummer,
                'InternKontaktperson' => $internkontaktperson,
                'GiltigTill' => $giltigtill,
                'Avstangd' => (string)$avstangd,
                'Fritext' => $fritext,
                'Avtalsnummer' => $avtalsnummer,
            ]);
            satt_flash($resultat['ok'] ? 'ok' : 'fel', $resultat['ok'] ? 'Låntagargruppen uppdaterades.' : $resultat['fel']);
        } else {
            $resultat = slaihop_eller_infoga(
                'nyko_lantagargrupp',
                ['Namn' => $namn],
                [
                    'Namn' => $namn,
                    'Kontaktperson' => $kontaktperson,
                    'Telefonnummer' => $telefonnummer,
                    'Organisationsnummer' => $organisationsnummer,
                    'InternKontaktperson' => $internkontaktperson,
                    'GiltigTill' => $giltigtill,
                    'Avstangd' => (string)$avstangd,
                    'Fritext' => $fritext,
                    'Avtalsnummer' => $avtalsnummer,
                ]
            );
            if ($resultat['ok'] && $resultat['skapad']) {
                satt_flash('ok', 'Ny låntagargrupp tillagd.');
            } elseif ($resultat['ok']) {
                satt_flash('ok', 'Låntagargruppen "' . $namn . '" fanns redan - ny information har lagts till på den befintliga posten.');
            } else {
                satt_flash('fel', $resultat['fel']);
            }
        }
        header('Location: lantagargrupper.php');
        exit;
    }
}

// -----------------------------------------------------------------
// Data för formulär vid redigering
// -----------------------------------------------------------------
$redigerar = null;
if (inloggad() && isset($_GET['redigera']) && $_GET['redigera'] !== 'ny') {
    $redigeraId = (int)$_GET['redigera'];
    $stmt = $db->prepare('SELECT * FROM nyko_lantagargrupp WHERE ID = ?');
    $stmt->bind_param('i', $redigeraId);
    $stmt->execute();
    $redigerar = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$visaformular = inloggad() && isset($_GET['redigera']);

// -----------------------------------------------------------------
// Sök, sortera
// -----------------------------------------------------------------
$sokterm = trim($_GET['sok'] ?? '');

$sorteringsfalt = [
    'Namn' => 'g.Namn',
    'Kontaktperson' => 'g.Kontaktperson',
    'Avtalsnummer' => 'g.Avtalsnummer',
    'AntalLantagare' => 'AntalLantagare',
];
[$sql_sortering, $riktning, $vald_sortnyckel] = sakerstall_sortering($sorteringsfalt, 'Namn');

$villkor = ['1=1'];
$typer = '';
$varden = [];

$sok = sok_villkor(['g.Namn', 'g.Kontaktperson', 'g.Telefonnummer', 'g.Organisationsnummer', 'g.Avtalsnummer'], $sokterm);
if ($sok['sql'] !== '') {
    $villkor[] = $sok['sql'];
    $typer .= $sok['typer'];
    $varden = array_merge($varden, $sok['varden']);
}

$stmt = $db->prepare(
    'SELECT g.*,
        (SELECT COUNT(*) FROM nyko_lantagare l WHERE l.LantagargruppID = g.ID) AS AntalLantagare
     FROM nyko_lantagargrupp g
     WHERE ' . implode(' AND ', $villkor) . " ORDER BY $sql_sortering $riktning"
);
if ($varden) {
    $stmt->bind_param($typer, ...$varden);
}
$stmt->execute();
$lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($lista as &$rad) {
    $giltighet_utgangen = $rad['GiltigTill'] !== null && $rad['GiltigTill'] < date('Y-m-d');
    if ($rad['Avstangd']) {
        $rad['StatusText'] = 'Avstängd';
    } elseif ($giltighet_utgangen) {
        $rad['StatusText'] = 'Giltighet utgången (' . $rad['GiltigTill'] . ')';
    } elseif ($rad['GiltigTill']) {
        $rad['StatusText'] = 'Giltig till ' . $rad['GiltigTill'];
    } else {
        $rad['StatusText'] = 'Godkänd';
    }
    $rad['RadVarning'] = $rad['Avstangd'] || $giltighet_utgangen;
}
unset($rad);

if (($_GET['export'] ?? '') === 'csv') {
    exportera_csv(
        'nyckelkoll-lantagargrupper.csv',
        ['Namn', 'Kontaktperson', 'Telefonnummer', 'Organisationsnummer', 'Avtalsnummer', 'Antal låntagare', 'Status'],
        $lista,
        ['Namn', 'Kontaktperson', 'Telefonnummer', 'Organisationsnummer', 'Avtalsnummer', 'AntalLantagare', 'StatusText']
    );
}

$per_sida = 50;
$totalt_antal = count($lista);
$lista_sida = array_slice($lista, (hamta_sida() - 1) * $per_sida, $per_sida);

$systeminstallningar = hamta_systeminstallningar();
$avtalskatalog_aktiverad = !empty($systeminstallningar['avtalskatalog_aktiverad']);

require 'includes/header.php';
?>

<h1>Låntagargrupper</h1>
<?php visa_flash(); ?>
<p class="hjalptext">Avstängd/Giltig till på en grupp ärvs ner till alla dess låntagare (mest restriktiva av person/grupp gäller). Fritext ärvs inte ner.</p>

<?php if (inloggad()): ?>
    <p><a href="lantagargrupper.php?redigera=ny" class="btn">+ Ny låntagargrupp</a></p>
<?php endif; ?>

<form method="get" class="filterrad">
    <input type="hidden" name="sortera" value="<?= h($vald_sortnyckel) ?>">
    <input type="hidden" name="riktning" value="<?= h(strtolower($riktning)) ?>">
    <div>
        <label for="sok">Sök</label>
        <input type="text" id="sok" name="sok" value="<?= h($sokterm) ?>" placeholder="Namn, kontaktperson, org.nr...">
    </div>
    <button type="submit" class="btn btn-liten">Filtrera</button>
    <?php if ($sokterm !== ''): ?>
        <a href="lantagargrupper.php" class="btn btn-liten">Rensa</a>
    <?php endif; ?>
</form>

<p class="filter-antal">
    <?= visar_antal_text($totalt_antal, $per_sida, 'låntagargrupper') ?>
    &nbsp;<a href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Exportera CSV</a>
</p>

<table>
    <thead>
        <tr>
            <th><?= sorteringshuvud('Namn', 'Namn') ?></th>
            <th><?= sorteringshuvud('Kontaktperson', 'Kontaktperson') ?></th>
            <th>Telefonnummer</th>
            <th>Organisationsnummer</th>
            <th><?= sorteringshuvud('Avtalsnummer', 'Avtalsnummer') ?></th>
            <th><?= sorteringshuvud('AntalLantagare', 'Antal låntagare') ?></th>
            <th>Status</th>
            <?php if (inloggad()): ?><th>Åtgärd</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (!$lista_sida): ?>
            <tr><td colspan="8"><?= $sokterm !== '' ? 'Inga låntagargrupper matchar sökningen.' : 'Inga låntagargrupper registrerade ännu.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($lista_sida as $rad): ?>
            <tr class="<?= $rad['RadVarning'] ? 'rad-varning' : '' ?>">
                <td><?= h($rad['Namn']) ?></td>
                <td><?= h($rad['Kontaktperson']) ?></td>
                <td><?= h($rad['Telefonnummer']) ?></td>
                <td><?= h($rad['Organisationsnummer']) ?></td>
                <td><?= h($rad['Avtalsnummer']) ?></td>
                <td><?= (int)$rad['AntalLantagare'] ?></td>
                <td><?= h($rad['StatusText']) ?></td>
                <?php if (inloggad()): ?>
                <td>
                    <a href="lantagargrupper.php?redigera=<?= (int)$rad['ID'] ?>">Redigera</a>
                    &nbsp;
                    <form method="post" style="display:inline" onsubmit="return confirm('Ta bort denna låntagargrupp?');">
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
    <h2><?= $redigerar ? 'Redigera låntagargrupp' : 'Ny låntagargrupp' ?></h2>
    <?php if (!$redigerar): ?>
        <p class="hjalptext">Finns gruppen redan (samma Namn) läggs ingen ny post till - istället fylls ny information i på den befintliga posten. Tomma fält skriver inte över befintlig information.</p>
    <?php endif; ?>
    <form method="post" class="formular">
        <?= csrf_falt() ?>
        <input type="hidden" name="action" value="spara">
        <input type="hidden" name="id" value="<?= $redigerar ? (int)$redigerar['ID'] : 0 ?>">

        <label for="Namn">Namn<?= kravstjarna($fi, 'Namn') ?></label>
        <input type="text" id="Namn" name="Namn" value="<?= h($redigerar['Namn'] ?? '') ?>" <?= kravattribut($fi, 'Namn') ?>>

        <label for="Kontaktperson">Kontaktperson</label>
        <input type="text" id="Kontaktperson" name="Kontaktperson" value="<?= h($redigerar['Kontaktperson'] ?? '') ?>">

        <label for="Telefonnummer">Telefonnummer</label>
        <input type="tel" id="Telefonnummer" name="Telefonnummer" value="<?= h($redigerar['Telefonnummer'] ?? '') ?>">

        <label for="Organisationsnummer">Organisationsnummer</label>
        <input type="text" id="Organisationsnummer" name="Organisationsnummer" value="<?= h($redigerar['Organisationsnummer'] ?? '') ?>">

        <label for="InternKontaktperson">Intern kontaktperson</label>
        <input type="text" id="InternKontaktperson" name="InternKontaktperson" value="<?= h($redigerar['InternKontaktperson'] ?? '') ?>">

        <label for="Avtalsnummer">Avtalsnummer</label>
        <input type="text" id="Avtalsnummer" name="Avtalsnummer" value="<?= h($redigerar['Avtalsnummer'] ?? '') ?>">
        <p class="hjalptext">Kopplas mot en framtida Avtalskatalog för att automatiskt hämta giltighetstid.</p>

        <label for="GiltigTill">Giltig till</label>
        <input type="date" id="GiltigTill" name="GiltigTill" value="<?= h($redigerar['GiltigTill'] ?? '') ?>">
        <p class="hjalptext">Ärvs ner till alla låntagare i gruppen (mest restriktiva av person/grupp gäller). Lämna tomt för ingen bortre gräns.</p>

        <label style="font-weight:normal;">
            <input type="checkbox" name="Avstangd" <?= !empty($redigerar['Avstangd']) ? 'checked' : '' ?>>
            Avstängd (stänger även av samtliga låntagare i gruppen)
        </label>

        <label for="Fritext">Fritext</label>
        <textarea id="Fritext" name="Fritext" rows="3"><?= h($redigerar['Fritext'] ?? '') ?></textarea>

        <button type="submit" class="btn"><?= $redigerar ? 'Spara ändringar' : 'Lägg till' ?></button>
    </form>

    <?php if ($redigerar && $avtalskatalog_aktiverad): ?>
        <form method="post" style="margin-top:12px;">
            <?= csrf_falt() ?>
            <input type="hidden" name="action" value="hamta_avtalskatalog">
            <input type="hidden" name="id" value="<?= (int)$redigerar['ID'] ?>">
            <button type="submit" class="btn btn-liten">Hämta giltighet från Avtalskatalogen</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
