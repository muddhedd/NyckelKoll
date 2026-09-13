<?php
require_once 'config.php';

$sidtitel = 'Knippor';
$fi = hamta_faltinstallningar('Knippa');

// -----------------------------------------------------------------
// Hantera POST (kräver inloggning)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifiera();
    krav_inloggning();

    $action = $_POST['action'] ?? '';

    if ($action === 'ta_bort') {
        $id = (int)($_POST['id'] ?? 0);
        $resultat = sakert_ta_bort('nyko_knippa', $id);
        satt_flash($resultat['ok'] ? 'ok' : 'fel', $resultat['meddelande']);
        header('Location: knippor.php');
        exit;
    }

    if ($action === 'spara') {
        $id = (int)($_POST['id'] ?? 0);

        $namn = p('Namn');
        $funktion = p_null('Funktion');
        $placering = p_null('Placering');
        $nyckelskapid = (int)($_POST['NyckelskapID'] ?? 0);

        $fel = [];
        if (!empty($fi['Namn']) && $namn === '') {
            $fel[] = 'Namn måste fyllas i.';
        }
        if ($nyckelskapid <= 0) {
            $fel[] = 'Ett nyckelskåp måste väljas - en knippa måste alltid bo i ett nyckelskåp.';
        }

        if ($fel) {
            satt_flash('fel', implode(' ', $fel));
            header('Location: knippor.php' . ($id ? '?redigera=' . $id : '?redigera=ny'));
            exit;
        }

        if ($id > 0) {
            $resultat = uppdatera_post('nyko_knippa', $id, [
                'Namn' => $namn,
                'Funktion' => $funktion,
                'Placering' => $placering,
                'NyckelskapID' => (string)$nyckelskapid,
            ]);
            satt_flash($resultat['ok'] ? 'ok' : 'fel', $resultat['ok'] ? 'Knippan uppdaterades.' : $resultat['fel']);
        } else {
            // Slå ihop på Namn + NyckelskapID (samma knippenamn i ett annat skåp räknas som en annan knippa)
            $resultat = slaihop_eller_infoga(
                'nyko_knippa',
                ['Namn' => $namn, 'NyckelskapID' => (string)$nyckelskapid],
                [
                    'Namn' => $namn,
                    'Funktion' => $funktion,
                    'Placering' => $placering,
                    'NyckelskapID' => (string)$nyckelskapid,
                ]
            );
            if ($resultat['ok'] && $resultat['skapad']) {
                satt_flash('ok', 'Ny knippa tillagd.');
            } elseif ($resultat['ok']) {
                satt_flash('ok', 'Knippan "' . $namn . '" fanns redan i det valda skåpet - ny information har lagts till på den befintliga posten.');
            } else {
                satt_flash('fel', $resultat['fel']);
            }
        }
        header('Location: knippor.php');
        exit;
    }
}

// -----------------------------------------------------------------
// Data för formulär vid redigering
// -----------------------------------------------------------------
$redigerar = null;
if (inloggad() && isset($_GET['redigera']) && $_GET['redigera'] !== 'ny') {
    $redigeraId = (int)$_GET['redigera'];
    $stmt = $db->prepare('SELECT * FROM nyko_knippa WHERE ID = ?');
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
$filter_skap = (int)($_GET['skap'] ?? 0);
$filter_status = trim($_GET['status'] ?? '');

$sorteringsfalt = [
    'Namn' => 'k.Namn',
    'NyckelskapNamn' => 's.Namn, k.Namn',
    'AntalNycklar' => 'AntalNycklar',
];
[$sql_sortering, $riktning, $vald_sortnyckel] = sakerstall_sortering($sorteringsfalt, 'NyckelskapNamn');

$villkor = ['1=1'];
$typer = '';
$varden = [];

$sok = sok_villkor(['k.Namn', 'k.Funktion', 'k.Placering', 's.Namn'], $sokterm);
if ($sok['sql'] !== '') {
    $villkor[] = $sok['sql'];
    $typer .= $sok['typer'];
    $varden = array_merge($varden, $sok['varden']);
}
if ($filter_skap > 0) {
    $villkor[] = 'k.NyckelskapID = ?';
    $typer .= 'i';
    $varden[] = $filter_skap;
}

$having = '1=1';
if ($filter_status === 'hemma') {
    $having = 'AntalNycklar > 0 AND AntalUtlanade = 0';
} elseif ($filter_status === 'utlanad') {
    $having = 'AntalNycklar > 0 AND AntalUtlanade = AntalNycklar';
} elseif ($filter_status === 'delvis') {
    $having = 'AntalUtlanade > 0 AND AntalUtlanade < AntalNycklar';
} elseif ($filter_status === 'tom') {
    $having = 'AntalNycklar = 0';
}

$stmt = $db->prepare(
    "SELECT k.*, s.Namn AS NyckelskapNamn,
        (SELECT COUNT(*) FROM nyko_nyckel n WHERE n.KnippaID = k.ID) AS AntalNycklar,
        (SELECT COUNT(*) FROM nyko_nyckel n
            WHERE n.KnippaID = k.ID
            AND EXISTS (SELECT 1 FROM nyko_lanrad lr WHERE lr.NyckelID = n.ID AND lr.Status = 'Utlanad')
        ) AS AntalUtlanade
     FROM nyko_knippa k
     JOIN nyko_nyckelskap s ON s.ID = k.NyckelskapID
     WHERE " . implode(' AND ', $villkor) . "
     HAVING $having
     ORDER BY $sql_sortering $riktning"
);
if ($varden) {
    $stmt->bind_param($typer, ...$varden);
}
$stmt->execute();
$lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function knippa_status(int $totalt, int $utlanade): string
{
    if ($totalt === 0) {
        return 'Tom knippa';
    }
    if ($utlanade === 0) {
        return 'Hemma';
    }
    if ($utlanade === $totalt) {
        return 'Utlånad';
    }
    return 'Delvis utlånad (' . $utlanade . ' av ' . $totalt . ')';
}
foreach ($lista as &$rad) {
    $rad['StatusText'] = knippa_status((int)$rad['AntalNycklar'], (int)$rad['AntalUtlanade']);
}
unset($rad);

if (($_GET['export'] ?? '') === 'csv') {
    exportera_csv(
        'nyckelkoll-knippor.csv',
        ['Namn', 'Nyckelskåp', 'Funktion', 'Placering', 'Antal nycklar', 'Status'],
        $lista,
        ['Namn', 'NyckelskapNamn', 'Funktion', 'Placering', 'AntalNycklar', 'StatusText']
    );
}

$per_sida = 50;
$totalt_antal = count($lista);
$lista_sida = array_slice($lista, (hamta_sida() - 1) * $per_sida, $per_sida);

$nyckelskap_lista = $db->query('SELECT ID, Namn FROM nyko_nyckelskap ORDER BY Namn')->fetch_all(MYSQLI_ASSOC);

require 'includes/header.php';
?>

<h1>Knippor</h1>
<?php visa_flash(); ?>

<?php if (inloggad()): ?>
    <p><a href="knippor.php?redigera=ny" class="btn">+ Ny knippa</a></p>
<?php endif; ?>

<form method="get" class="filterrad">
    <input type="hidden" name="sortera" value="<?= h($vald_sortnyckel) ?>">
    <input type="hidden" name="riktning" value="<?= h(strtolower($riktning)) ?>">
    <div>
        <label for="sok">Sök</label>
        <input type="text" id="sok" name="sok" value="<?= h($sokterm) ?>" placeholder="Namn, funktion, placering...">
    </div>
    <div>
        <label for="skap">Nyckelskåp</label>
        <select id="skap" name="skap">
            <option value="">-- alla --</option>
            <?php foreach ($nyckelskap_lista as $skap): ?>
                <option value="<?= (int)$skap['ID'] ?>" <?= $filter_skap === (int)$skap['ID'] ? 'selected' : '' ?>><?= h($skap['Namn']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">-- alla --</option>
            <option value="hemma" <?= $filter_status === 'hemma' ? 'selected' : '' ?>>Hemma</option>
            <option value="delvis" <?= $filter_status === 'delvis' ? 'selected' : '' ?>>Delvis utlånad</option>
            <option value="utlanad" <?= $filter_status === 'utlanad' ? 'selected' : '' ?>>Utlånad</option>
            <option value="tom" <?= $filter_status === 'tom' ? 'selected' : '' ?>>Tom knippa</option>
        </select>
    </div>
    <button type="submit" class="btn btn-liten">Filtrera</button>
    <?php if ($sokterm !== '' || $filter_skap > 0 || $filter_status !== ''): ?>
        <a href="knippor.php" class="btn btn-liten">Rensa</a>
    <?php endif; ?>
</form>

<p class="filter-antal">
    <?= visar_antal_text($totalt_antal, $per_sida, 'knippor') ?>
    &nbsp;<a href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Exportera CSV</a>
</p>

<table>
    <thead>
        <tr>
            <th><?= sorteringshuvud('Namn', 'Namn') ?></th>
            <th><?= sorteringshuvud('NyckelskapNamn', 'Nyckelskåp') ?></th>
            <th>Funktion</th>
            <th>Placering</th>
            <th><?= sorteringshuvud('AntalNycklar', 'Antal nycklar') ?></th>
            <th>Status</th>
            <?php if (inloggad()): ?><th>Åtgärd</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (!$lista_sida): ?>
            <tr><td colspan="7"><?= ($sokterm !== '' || $filter_skap > 0 || $filter_status !== '') ? 'Inga knippor matchar sökningen/filtret.' : 'Inga knippor registrerade ännu.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($lista_sida as $rad): ?>
            <tr>
                <td><?= h($rad['Namn']) ?></td>
                <td><?= h($rad['NyckelskapNamn']) ?></td>
                <td><?= h($rad['Funktion']) ?></td>
                <td><?= h($rad['Placering']) ?></td>
                <td><?= (int)$rad['AntalNycklar'] ?></td>
                <td><?= h($rad['StatusText']) ?></td>
                <?php if (inloggad()): ?>
                <td>
                    <a href="knippor.php?redigera=<?= (int)$rad['ID'] ?>">Redigera</a>
                    &nbsp;
                    <form method="post" style="display:inline" onsubmit="return confirm('Ta bort denna knippa?');">
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
    <h2><?= $redigerar ? 'Redigera knippa' : 'Ny knippa' ?></h2>
    <?php if (!$redigerar): ?>
        <p class="hjalptext">Finns knippan redan (samma Namn i samma nyckelskåp) läggs ingen ny post till - istället fylls ny information i på den befintliga posten.</p>
    <?php endif; ?>
    <?php if (!$nyckelskap_lista): ?>
        <p class="meddelande meddelande-varning">Det finns inga nyckelskåp registrerade än. Skapa ett nyckelskåp först.</p>
    <?php else: ?>
    <form method="post" class="formular">
        <?= csrf_falt() ?>
        <input type="hidden" name="action" value="spara">
        <input type="hidden" name="id" value="<?= $redigerar ? (int)$redigerar['ID'] : 0 ?>">

        <label for="Namn">Namn<?= kravstjarna($fi, 'Namn') ?></label>
        <input type="text" id="Namn" name="Namn" value="<?= h($redigerar['Namn'] ?? '') ?>" <?= kravattribut($fi, 'Namn') ?>>

        <label for="NyckelskapID">Nyckelskåp <span class="faltkrav">*</span></label>
        <select id="NyckelskapID" name="NyckelskapID" required>
            <option value="">-- välj nyckelskåp --</option>
            <?php foreach ($nyckelskap_lista as $skap): ?>
                <option value="<?= (int)$skap['ID'] ?>" <?= (isset($redigerar['NyckelskapID']) && (int)$redigerar['NyckelskapID'] === (int)$skap['ID']) ? 'selected' : '' ?>>
                    <?= h($skap['Namn']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="Funktion">Funktion</label>
        <input type="text" id="Funktion" name="Funktion" value="<?= h($redigerar['Funktion'] ?? '') ?>">

        <label for="Placering">Placering</label>
        <input type="text" id="Placering" name="Placering" value="<?= h($redigerar['Placering'] ?? '') ?>">

        <button type="submit" class="btn"><?= $redigerar ? 'Spara ändringar' : 'Lägg till' ?></button>
    </form>
    <?php endif; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
