<?php
require_once 'config.php';

$sidtitel = 'Nyckelsystem';
$fi = hamta_faltinstallningar('Nyckelsystem');

// -----------------------------------------------------------------
// Hantera POST (kräver inloggning)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifiera();
    krav_inloggning();

    $action = $_POST['action'] ?? '';

    if ($action === 'ta_bort') {
        $id = (int)($_POST['id'] ?? 0);
        $resultat = sakert_ta_bort('nyko_nyckelsystem', $id);
        satt_flash($resultat['ok'] ? 'ok' : 'fel', $resultat['meddelande']);
        header('Location: nyckelsystem.php');
        exit;
    }

    if ($action === 'hamta_fastighetsteknik') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT Fastighetsnummer FROM nyko_nyckelsystem WHERE ID = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rad = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$rad || !$rad['Fastighetsnummer']) {
            satt_flash('fel', 'Inget fastighetsnummer är ifyllt på det här nyckelsystemet.');
        } else {
            $resultat = hamta_fran_fastighetsteknik($rad['Fastighetsnummer']);
            if ($resultat['ok']) {
                $stmt = $db->prepare('UPDATE nyko_nyckelsystem SET FastighetsInfo = ? WHERE ID = ?');
                $stmt->bind_param('si', $resultat['info'], $id);
                $stmt->execute();
                $stmt->close();
                satt_flash('ok', 'Fastighetsinformation hämtad och sparad.');
            } else {
                satt_flash('fel', $resultat['fel']);
            }
        }
        header('Location: nyckelsystem.php?redigera=' . $id);
        exit;
    }

    if ($action === 'spara') {
        $id = (int)($_POST['id'] ?? 0);

        $nyckelsystemid = p('NyckelsystemID');
        $installationsar = p_null('Installationsar');
        $garantitid = p_null('Garantitid');
        $garantipart = p_null('Garantipart');
        $servicestation = p('Servicestation');
        $forvantadlivslangd = p_null('ForvantadLivslangd');
        $placering = p_null('Placering');
        $fastighetsnummer = p_null('Fastighetsnummer');

        $fel = [];
        if (!empty($fi['NyckelsystemID']) && $nyckelsystemid === '') {
            $fel[] = 'Nyckelsystem-ID måste fyllas i.';
        }
        if (!empty($fi['Servicestation']) && $servicestation === '') {
            $fel[] = 'Servicestation måste fyllas i.';
        }
        if ($installationsar !== null && !preg_match('/^\d{4}$/', $installationsar)) {
            $fel[] = 'Installationsår måste vara ett fyrsiffrigt årtal.';
        }

        if ($fel) {
            satt_flash('fel', implode(' ', $fel));
            header('Location: nyckelsystem.php' . ($id ? '?redigera=' . $id : '?redigera=ny'));
            exit;
        }

        if ($id > 0) {
            $resultat = uppdatera_post('nyko_nyckelsystem', $id, [
                'NyckelsystemID' => $nyckelsystemid,
                'Installationsar' => $installationsar,
                'Garantitid' => $garantitid,
                'Garantipart' => $garantipart,
                'Servicestation' => $servicestation,
                'ForvantadLivslangd' => $forvantadlivslangd,
                'Placering' => $placering,
                'Fastighetsnummer' => $fastighetsnummer,
            ]);
            satt_flash($resultat['ok'] ? 'ok' : 'fel', $resultat['ok'] ? 'Nyckelsystemet uppdaterades.' : $resultat['fel']);
        } else {
            $resultat = slaihop_eller_infoga(
                'nyko_nyckelsystem',
                ['NyckelsystemID' => $nyckelsystemid],
                [
                    'NyckelsystemID' => $nyckelsystemid,
                    'Installationsar' => $installationsar,
                    'Garantitid' => $garantitid,
                    'Garantipart' => $garantipart,
                    'Servicestation' => $servicestation !== '' ? $servicestation : null,
                    'ForvantadLivslangd' => $forvantadlivslangd,
                    'Placering' => $placering,
                    'Fastighetsnummer' => $fastighetsnummer,
                ]
            );
            if ($resultat['ok'] && $resultat['skapad']) {
                satt_flash('ok', 'Nytt nyckelsystem tillagt.');
            } elseif ($resultat['ok']) {
                satt_flash('ok', 'Nyckelsystemet "' . $nyckelsystemid . '" fanns redan - ny information har lagts till på den befintliga posten.');
            } else {
                satt_flash('fel', $resultat['fel']);
            }
        }
        header('Location: nyckelsystem.php');
        exit;
    }
}

// -----------------------------------------------------------------
// Data för formulär vid redigering
// -----------------------------------------------------------------
$redigerar = null;
if (inloggad() && isset($_GET['redigera']) && $_GET['redigera'] !== 'ny') {
    $redigeraId = (int)$_GET['redigera'];
    $stmt = $db->prepare('SELECT * FROM nyko_nyckelsystem WHERE ID = ?');
    $stmt->bind_param('i', $redigeraId);
    $stmt->execute();
    $redigerar = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$visaformular = inloggad() && (isset($_GET['redigera']));

// -----------------------------------------------------------------
// Sök, filtrera, sortera
// -----------------------------------------------------------------
$sokterm = trim($_GET['sok'] ?? '');
$filter_servicestation = trim($_GET['servicestation'] ?? '');

$sorteringsfalt = [
    'NyckelsystemID' => 'NyckelsystemID',
    'Installationsar' => 'Installationsar',
    'Servicestation' => 'Servicestation',
    'Placering' => 'Placering',
];
[$sql_sortering, $riktning, $vald_sortnyckel] = sakerstall_sortering($sorteringsfalt, 'NyckelsystemID');

$villkor = ['1=1'];
$typer = '';
$varden = [];

$sok = sok_villkor(['NyckelsystemID', 'Servicestation', 'Placering', 'Garantipart', 'ForvantadLivslangd', 'Fastighetsnummer'], $sokterm);
if ($sok['sql'] !== '') {
    $villkor[] = $sok['sql'];
    $typer .= $sok['typer'];
    $varden = array_merge($varden, $sok['varden']);
}
if ($filter_servicestation !== '') {
    $villkor[] = 'Servicestation = ?';
    $typer .= 's';
    $varden[] = $filter_servicestation;
}

$stmt = $db->prepare('SELECT * FROM nyko_nyckelsystem WHERE ' . implode(' AND ', $villkor) . " ORDER BY $sql_sortering $riktning");
if ($varden) {
    $stmt->bind_param($typer, ...$varden);
}
$stmt->execute();
$lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (($_GET['export'] ?? '') === 'csv') {
    exportera_csv(
        'nyckelkoll-nyckelsystem.csv',
        ['Nyckelsystem-ID', 'Installationsår', 'Servicestation', 'Placering', 'Garantitid', 'Garantipart', 'Förv. livslängd', 'Fastighetsnummer'],
        $lista,
        ['NyckelsystemID', 'Installationsar', 'Servicestation', 'Placering', 'Garantitid', 'Garantipart', 'ForvantadLivslangd', 'Fastighetsnummer']
    );
}

$per_sida = 50;
$totalt_antal = count($lista);
$lista_sida = array_slice($lista, (hamta_sida() - 1) * $per_sida, $per_sida);

$servicestationer = $db->query(
    "SELECT DISTINCT Servicestation FROM nyko_nyckelsystem WHERE Servicestation IS NOT NULL AND Servicestation <> '' ORDER BY Servicestation"
)->fetch_all(MYSQLI_ASSOC);

$systeminstallningar = hamta_systeminstallningar();
$fastighetsteknik_aktiverad = !empty($systeminstallningar['fastighetsteknik_aktiverad']);

require 'includes/header.php';
?>

<h1>Nyckelsystem</h1>
<?php visa_flash(); ?>

<?php if (inloggad()): ?>
    <p><a href="nyckelsystem.php?redigera=ny" class="btn">+ Nytt nyckelsystem</a></p>
<?php endif; ?>

<form method="get" class="filterrad">
    <input type="hidden" name="sortera" value="<?= h($vald_sortnyckel) ?>">
    <input type="hidden" name="riktning" value="<?= h(strtolower($riktning)) ?>">
    <div>
        <label for="sok">Sök</label>
        <input type="text" id="sok" name="sok" value="<?= h($sokterm) ?>" placeholder="Nyckelsystem-ID, plats...">
    </div>
    <div>
        <label for="servicestation">Servicestation</label>
        <select id="servicestation" name="servicestation">
            <option value="">-- alla --</option>
            <?php foreach ($servicestationer as $s): ?>
                <option value="<?= h($s['Servicestation']) ?>" <?= $filter_servicestation === $s['Servicestation'] ? 'selected' : '' ?>><?= h($s['Servicestation']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-liten">Filtrera</button>
    <?php if ($sokterm !== '' || $filter_servicestation !== ''): ?>
        <a href="nyckelsystem.php" class="btn btn-liten">Rensa</a>
    <?php endif; ?>
</form>

<p class="filter-antal">
    <?= visar_antal_text($totalt_antal, $per_sida, 'nyckelsystem') ?>
    &nbsp;<a href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Exportera CSV</a>
</p>

<table>
    <thead>
        <tr>
            <th><?= sorteringshuvud('NyckelsystemID', 'Nyckelsystem-ID') ?></th>
            <th><?= sorteringshuvud('Installationsar', 'Installationsår') ?></th>
            <th><?= sorteringshuvud('Servicestation', 'Servicestation') ?></th>
            <th><?= sorteringshuvud('Placering', 'Placering') ?></th>
            <th>Garantitid</th>
            <th>Garantipart</th>
            <th>Förv. livslängd</th>
            <th>Fastighetsnummer</th>
            <?php if (inloggad()): ?><th>Åtgärd</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (!$lista_sida): ?>
            <tr><td colspan="9"><?= ($sokterm !== '' || $filter_servicestation !== '') ? 'Inga nyckelsystem matchar sökningen/filtret.' : 'Inga nyckelsystem registrerade ännu.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($lista_sida as $rad): ?>
            <tr>
                <td><?= h($rad['NyckelsystemID']) ?></td>
                <td><?= h($rad['Installationsar']) ?></td>
                <td><?= h($rad['Servicestation']) ?></td>
                <td><?= h($rad['Placering']) ?></td>
                <td><?= h($rad['Garantitid']) ?></td>
                <td><?= h($rad['Garantipart']) ?></td>
                <td><?= h($rad['ForvantadLivslangd']) ?></td>
                <td><?= h($rad['Fastighetsnummer']) ?></td>
                <?php if (inloggad()): ?>
                <td>
                    <a href="nyckelsystem.php?redigera=<?= (int)$rad['ID'] ?>">Redigera</a>
                    &nbsp;
                    <form method="post" style="display:inline" onsubmit="return confirm('Ta bort detta nyckelsystem?');">
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
    <h2><?= $redigerar ? 'Redigera nyckelsystem' : 'Nytt nyckelsystem' ?></h2>
    <?php if (!$redigerar): ?>
        <p class="hjalptext">Finns nyckelsystemet redan (samma Nyckelsystem-ID) läggs ingen ny post till - istället fylls ny information i på den befintliga posten. Tomma fält skriver inte över befintlig information.</p>
    <?php endif; ?>
    <form method="post" class="formular">
        <?= csrf_falt() ?>
        <input type="hidden" name="action" value="spara">
        <input type="hidden" name="id" value="<?= $redigerar ? (int)$redigerar['ID'] : 0 ?>">

        <label for="NyckelsystemID">Nyckelsystem-ID<?= kravstjarna($fi, 'NyckelsystemID') ?></label>
        <input type="text" id="NyckelsystemID" name="NyckelsystemID" value="<?= h($redigerar['NyckelsystemID'] ?? '') ?>" <?= kravattribut($fi, 'NyckelsystemID') ?>>

        <label for="Installationsar">Installationsår</label>
        <input type="text" id="Installationsar" name="Installationsar" inputmode="numeric" placeholder="ÅÅÅÅ" value="<?= h($redigerar['Installationsar'] ?? '') ?>">

        <label for="Servicestation">Servicestation<?= kravstjarna($fi, 'Servicestation') ?></label>
        <input type="text" id="Servicestation" name="Servicestation" value="<?= h($redigerar['Servicestation'] ?? '') ?>" <?= kravattribut($fi, 'Servicestation') ?>>

        <label for="Garantitid">Garantitid</label>
        <input type="text" id="Garantitid" name="Garantitid" value="<?= h($redigerar['Garantitid'] ?? '') ?>">

        <label for="Garantipart">Garantipart</label>
        <input type="text" id="Garantipart" name="Garantipart" value="<?= h($redigerar['Garantipart'] ?? '') ?>">

        <label for="ForvantadLivslangd">Förväntad teknisk livslängd</label>
        <input type="text" id="ForvantadLivslangd" name="ForvantadLivslangd" value="<?= h($redigerar['ForvantadLivslangd'] ?? '') ?>">

        <label for="Placering">Placering (kvarter/område/adress)</label>
        <input type="text" id="Placering" name="Placering" value="<?= h($redigerar['Placering'] ?? '') ?>">

        <label for="Fastighetsnummer">Fastighetsnummer</label>
        <input type="text" id="Fastighetsnummer" name="Fastighetsnummer" value="<?= h($redigerar['Fastighetsnummer'] ?? '') ?>">
        <p class="hjalptext">Kopplas mot en framtida "Förvaltning av Fastighetsteknik" för att automatiskt hämta fastighetsinformation.</p>

        <?php if ($redigerar && $redigerar['FastighetsInfo']): ?>
            <label>Fastighetsinformation (senast hämtad)</label>
            <p><?= nl2br(h($redigerar['FastighetsInfo'])) ?></p>
        <?php endif; ?>

        <button type="submit" class="btn"><?= $redigerar ? 'Spara ändringar' : 'Lägg till' ?></button>
    </form>

    <?php if ($redigerar && $fastighetsteknik_aktiverad): ?>
        <form method="post" style="margin-top:12px;">
            <?= csrf_falt() ?>
            <input type="hidden" name="action" value="hamta_fastighetsteknik">
            <input type="hidden" name="id" value="<?= (int)$redigerar['ID'] ?>">
            <button type="submit" class="btn btn-liten">Hämta från Fastighetsteknik</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
