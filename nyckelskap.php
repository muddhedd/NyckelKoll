<?php
require_once 'config.php';

$sidtitel = 'Nyckelskåp';
$fi = hamta_faltinstallningar('Nyckelskap');

// -----------------------------------------------------------------
// Hantera POST (kräver inloggning)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifiera();
    krav_inloggning();

    $action = $_POST['action'] ?? '';

    if ($action === 'ta_bort') {
        $id = (int)($_POST['id'] ?? 0);
        $resultat = sakert_ta_bort('nyko_nyckelskap', $id);
        satt_flash($resultat['ok'] ? 'ok' : 'fel', $resultat['meddelande']);
        header('Location: nyckelskap.php');
        exit;
    }

    if ($action === 'spara') {
        $id = (int)($_POST['id'] ?? 0);

        $namn = p('Namn');
        $placering = p_null('Placering');
        $funktion = p_null('Funktion');
        $kod = p_null('Kod');

        $fel = [];
        if (!empty($fi['Namn']) && $namn === '') {
            $fel[] = 'Namn måste fyllas i.';
        }

        if ($fel) {
            satt_flash('fel', implode(' ', $fel));
            header('Location: nyckelskap.php' . ($id ? '?redigera=' . $id : '?redigera=ny'));
            exit;
        }

        if ($id > 0) {
            $resultat = uppdatera_post('nyko_nyckelskap', $id, [
                'Namn' => $namn,
                'Placering' => $placering,
                'Funktion' => $funktion,
                'Kod' => $kod,
            ]);
            satt_flash($resultat['ok'] ? 'ok' : 'fel', $resultat['ok'] ? 'Nyckelskåpet uppdaterades.' : $resultat['fel']);
        } else {
            $resultat = slaihop_eller_infoga(
                'nyko_nyckelskap',
                ['Namn' => $namn],
                [
                    'Namn' => $namn,
                    'Placering' => $placering,
                    'Funktion' => $funktion,
                    'Kod' => $kod,
                ]
            );
            if ($resultat['ok'] && $resultat['skapad']) {
                satt_flash('ok', 'Nytt nyckelskåp tillagt.');
            } elseif ($resultat['ok']) {
                satt_flash('ok', 'Nyckelskåpet "' . $namn . '" fanns redan - ny information har lagts till på den befintliga posten.');
            } else {
                satt_flash('fel', $resultat['fel']);
            }
        }
        header('Location: nyckelskap.php');
        exit;
    }
}

// -----------------------------------------------------------------
// Data för formulär vid redigering
// -----------------------------------------------------------------
$redigerar = null;
if (inloggad() && isset($_GET['redigera']) && $_GET['redigera'] !== 'ny') {
    $redigeraId = (int)$_GET['redigera'];
    $stmt = $db->prepare('SELECT * FROM nyko_nyckelskap WHERE ID = ?');
    $stmt->bind_param('i', $redigeraId);
    $stmt->execute();
    $redigerar = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$visaformular = inloggad() && isset($_GET['redigera']);

// -----------------------------------------------------------------
// Sök, filtrera, sortera
// -----------------------------------------------------------------
$sokterm = trim($_GET['sok'] ?? '');
$filter_funktion = trim($_GET['funktion'] ?? '');

$sorteringsfalt = [
    'Namn' => 's.Namn',
    'Placering' => 's.Placering',
    'Funktion' => 's.Funktion',
];
[$sql_sortering, $riktning, $vald_sortnyckel] = sakerstall_sortering($sorteringsfalt, 'Namn');

// Kod räknas inte in i sökningen om man inte är inloggad - annars går det
// att avslöja en dold kod genom att pröva sig fram via sökfältet.
$sokkolumner = ['s.Namn', 's.Placering', 's.Funktion'];
if (inloggad()) {
    $sokkolumner[] = 's.Kod';
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
if ($filter_funktion !== '') {
    $villkor[] = 's.Funktion = ?';
    $typer .= 's';
    $varden[] = $filter_funktion;
}

$stmt = $db->prepare(
    'SELECT s.*,
        (SELECT COUNT(*) FROM nyko_knippa k WHERE k.NyckelskapID = s.ID) AS AntalKnippor,
        (SELECT COUNT(*) FROM nyko_nyckel n WHERE n.NyckelskapID = s.ID) AS AntalDirektaNycklar
     FROM nyko_nyckelskap s
     WHERE ' . implode(' AND ', $villkor) . " ORDER BY $sql_sortering $riktning"
);
if ($varden) {
    $stmt->bind_param($typer, ...$varden);
}
$stmt->execute();
$lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (($_GET['export'] ?? '') === 'csv') {
    $kolumner = ['Namn', 'Placering', 'Funktion'];
    $falt = ['Namn', 'Placering', 'Funktion'];
    if (inloggad()) {
        $kolumner[] = 'Kod';
        $falt[] = 'Kod';
    }
    $kolumner[] = 'Antal knippor';
    $kolumner[] = 'Antal lösa nycklar';
    $falt[] = 'AntalKnippor';
    $falt[] = 'AntalDirektaNycklar';
    exportera_csv('nyckelkoll-nyckelskap.csv', $kolumner, $lista, $falt);
}

$per_sida = 50;
$totalt_antal = count($lista);
$lista_sida = array_slice($lista, (hamta_sida() - 1) * $per_sida, $per_sida);

$funktioner = $db->query(
    "SELECT DISTINCT Funktion FROM nyko_nyckelskap WHERE Funktion IS NOT NULL AND Funktion <> '' ORDER BY Funktion"
)->fetch_all(MYSQLI_ASSOC);

require 'includes/header.php';
?>

<h1>Nyckelskåp</h1>
<?php visa_flash(); ?>

<?php if (inloggad()): ?>
    <p><a href="nyckelskap.php?redigera=ny" class="btn">+ Nytt nyckelskåp</a></p>
<?php endif; ?>

<form method="get" class="filterrad">
    <input type="hidden" name="sortera" value="<?= h($vald_sortnyckel) ?>">
    <input type="hidden" name="riktning" value="<?= h(strtolower($riktning)) ?>">
    <div>
        <label for="sok">Sök</label>
        <input type="text" id="sok" name="sok" value="<?= h($sokterm) ?>" placeholder="Namn, placering...">
    </div>
    <div>
        <label for="funktion">Funktion</label>
        <select id="funktion" name="funktion">
            <option value="">-- alla --</option>
            <?php foreach ($funktioner as $f): ?>
                <option value="<?= h($f['Funktion']) ?>" <?= $filter_funktion === $f['Funktion'] ? 'selected' : '' ?>><?= h($f['Funktion']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-liten">Filtrera</button>
    <?php if ($sokterm !== '' || $filter_funktion !== ''): ?>
        <a href="nyckelskap.php" class="btn btn-liten">Rensa</a>
    <?php endif; ?>
</form>

<p class="filter-antal">
    <?= visar_antal_text($totalt_antal, $per_sida, 'nyckelskåp') ?>
    &nbsp;<a href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Exportera CSV</a>
</p>

<table>
    <thead>
        <tr>
            <th><?= sorteringshuvud('Namn', 'Namn') ?></th>
            <th><?= sorteringshuvud('Placering', 'Placering') ?></th>
            <th><?= sorteringshuvud('Funktion', 'Funktion') ?></th>
            <th>Kod</th>
            <th>Innehåll</th>
            <?php if (inloggad()): ?><th>Åtgärd</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (!$lista_sida): ?>
            <tr><td colspan="6"><?= ($sokterm !== '' || $filter_funktion !== '') ? 'Inga nyckelskåp matchar sökningen/filtret.' : 'Inga nyckelskåp registrerade ännu.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($lista_sida as $rad): ?>
            <tr>
                <td><?= h($rad['Namn']) ?></td>
                <td><?= h($rad['Placering']) ?></td>
                <td><?= h($rad['Funktion']) ?></td>
                <td><?= dolj_om_utloggad($rad['Kod']) ?></td>
                <td><?= (int)$rad['AntalKnippor'] ?> knippor, <?= (int)$rad['AntalDirektaNycklar'] ?> lösa nycklar</td>
                <?php if (inloggad()): ?>
                <td>
                    <a href="nyckelskap.php?redigera=<?= (int)$rad['ID'] ?>">Redigera</a>
                    &nbsp;
                    <form method="post" style="display:inline" onsubmit="return confirm('Ta bort detta nyckelskåp?');">
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
    <h2><?= $redigerar ? 'Redigera nyckelskåp' : 'Nytt nyckelskåp' ?></h2>
    <?php if (!$redigerar): ?>
        <p class="hjalptext">Finns nyckelskåpet redan (samma Namn) läggs ingen ny post till - istället fylls ny information i på den befintliga posten. Tomma fält skriver inte över befintlig information.</p>
    <?php endif; ?>
    <form method="post" class="formular">
        <?= csrf_falt() ?>
        <input type="hidden" name="action" value="spara">
        <input type="hidden" name="id" value="<?= $redigerar ? (int)$redigerar['ID'] : 0 ?>">

        <label for="Namn">Namn<?= kravstjarna($fi, 'Namn') ?></label>
        <input type="text" id="Namn" name="Namn" value="<?= h($redigerar['Namn'] ?? '') ?>" <?= kravattribut($fi, 'Namn') ?>>

        <label for="Placering">Placering</label>
        <input type="text" id="Placering" name="Placering" value="<?= h($redigerar['Placering'] ?? '') ?>">

        <label for="Funktion">Funktion</label>
        <input type="text" id="Funktion" name="Funktion" value="<?= h($redigerar['Funktion'] ?? '') ?>">

        <label for="Kod">Kod</label>
        <input type="text" id="Kod" name="Kod" value="<?= h($redigerar['Kod'] ?? '') ?>">
        <p class="hjalptext">Koden visas maskerad (••••••) för den som inte är inloggad.</p>

        <button type="submit" class="btn"><?= $redigerar ? 'Spara ändringar' : 'Lägg till' ?></button>
    </form>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
