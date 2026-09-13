<?php
require_once 'config.php';

$sidtitel = 'Nycklar';
$fi = hamta_faltinstallningar('Nyckel');

// -----------------------------------------------------------------
// Hantera POST (kräver inloggning)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifiera();
    krav_inloggning();

    $action = $_POST['action'] ?? '';

    if ($action === 'ta_bort') {
        $id = (int)($_POST['id'] ?? 0);
        $resultat = sakert_ta_bort('nyko_nyckel', $id);
        satt_flash($resultat['ok'] ? 'ok' : 'fel', $resultat['meddelande']);
        header('Location: nycklar.php');
        exit;
    }

    if ($action === 'markera_tappad') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('UPDATE nyko_nyckel SET Forlorad = 1 WHERE ID = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        satt_flash('ok', 'Nyckeln markerades som tappad.');
        header('Location: nycklar.php?redigera=' . $id);
        exit;
    }

    if ($action === 'aterfunnen') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('UPDATE nyko_nyckel SET Forlorad = 0 WHERE ID = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        satt_flash('ok', 'Nyckeln markerades som återfunnen (Hemma).');
        header('Location: nycklar.php?redigera=' . $id);
        exit;
    }

    if ($action === 'spara') {
        $id = (int)($_POST['id'] ?? 0);

        $nyckelid = p('NyckelID');
        $funktion = p_null('Funktion');
        $placering = p_null('Placering');
        $nyckelsystemid = p_null('NyckelsystemID');

        $platstyp = $_POST['plats_typ'] ?? '';
        $knippaid = null;
        $nyckelskapid = null;

        $fel = [];
        if (!empty($fi['NyckelID']) && $nyckelid === '') {
            $fel[] = 'Nyckel-ID måste fyllas i.';
        }
        if ($platstyp === 'knippa') {
            $knippaid = (int)($_POST['KnippaID'] ?? 0);
            if ($knippaid <= 0) {
                $fel[] = 'Välj vilken knippa nyckeln sitter i.';
            }
        } elseif ($platstyp === 'skap') {
            $nyckelskapid = (int)($_POST['NyckelskapID'] ?? 0);
            if ($nyckelskapid <= 0) {
                $fel[] = 'Välj vilket nyckelskåp nyckeln bor i.';
            }
        } else {
            $fel[] = 'Ange om nyckeln bor i en knippa eller direkt i ett nyckelskåp.';
        }

        if ($fel) {
            satt_flash('fel', implode(' ', $fel));
            header('Location: nycklar.php' . ($id ? '?redigera=' . $id : '?redigera=ny'));
            exit;
        }

        $knippaid_bind = $knippaid !== null ? (string)$knippaid : null;
        $nyckelskapid_bind = $nyckelskapid !== null ? (string)$nyckelskapid : null;

        if ($id > 0) {
            // Redigera - alla falt satts direkt, inklusive plats (aldrig "ror inte")
            $resultat = uppdatera_post('nyko_nyckel', $id, [
                'NyckelID' => $nyckelid,
                'Funktion' => $funktion,
                'Placering' => $placering,
                'NyckelsystemID' => $nyckelsystemid,
                'KnippaID' => $knippaid_bind,
                'NyckelskapID' => $nyckelskapid_bind,
            ]);
            satt_flash($resultat['ok'] ? 'ok' : 'fel', $resultat['ok'] ? 'Nyckeln uppdaterades.' : $resultat['fel']);
        } else {
            // Lagg till - slå ihop pa NyckelID om den redan finns. Platsen (knippa/skap)
            // satts alltid explicit utifran formularet (det ar strukturellt, aldrig "ror inte"),
            // medan Funktion/Placering/Nyckelsystem bara fylls i om de faktiskt skickades.
            $stmt = $db->prepare('SELECT ID FROM nyko_nyckel WHERE NyckelID = ?');
            $stmt->bind_param('s', $nyckelid);
            $stmt->execute();
            $befintlig = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            try {
                if ($befintlig) {
                    $nyckelDbId = (int)$befintlig['ID'];
                    $satser = ['KnippaID = ?', 'NyckelskapID = ?'];
                    $typer = 'ss';
                    $varden = [$knippaid_bind, $nyckelskapid_bind];
                    foreach (['Funktion' => $funktion, 'Placering' => $placering, 'NyckelsystemID' => $nyckelsystemid] as $kolumn => $varde) {
                        if ($varde !== null) {
                            $satser[] = "$kolumn = ?";
                            $typer .= 's';
                            $varden[] = $varde;
                        }
                    }
                    $varden[] = $nyckelDbId;
                    $typer .= 'i';
                    $stmt2 = $db->prepare('UPDATE nyko_nyckel SET ' . implode(', ', $satser) . ' WHERE ID = ?');
                    $stmt2->bind_param($typer, ...$varden);
                    $stmt2->execute();
                    $stmt2->close();
                    satt_flash('ok', 'Nyckeln "' . $nyckelid . '" fanns redan - ny information (inklusive plats) har uppdaterats på den befintliga posten.');
                } else {
                    $stmt3 = $db->prepare('INSERT INTO nyko_nyckel (NyckelID, Funktion, Placering, NyckelsystemID, KnippaID, NyckelskapID) VALUES (?,?,?,?,?,?)');
                    $stmt3->bind_param('ssssss', $nyckelid, $funktion, $placering, $nyckelsystemid, $knippaid_bind, $nyckelskapid_bind);
                    $stmt3->execute();
                    $stmt3->close();
                    satt_flash('ok', 'Ny nyckel tillagd.');
                }
            } catch (mysqli_sql_exception $e) {
                satt_flash('fel', 'Kunde inte spara nyckeln: ' . $e->getMessage());
            }
        }
        header('Location: nycklar.php');
        exit;
    }
}

// -----------------------------------------------------------------
// Data för formulär vid redigering
// -----------------------------------------------------------------
$redigerar = null;
if (inloggad() && isset($_GET['redigera']) && $_GET['redigera'] !== 'ny') {
    $redigeraId = (int)$_GET['redigera'];
    $stmt = $db->prepare('SELECT * FROM nyko_nyckel WHERE ID = ?');
    $stmt->bind_param('i', $redigeraId);
    $stmt->execute();
    $redigerar = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$visaformular = inloggad() && isset($_GET['redigera']);

$nyckelsystem_lista = $db->query('SELECT ID, NyckelsystemID FROM nyko_nyckelsystem ORDER BY NyckelsystemID')->fetch_all(MYSQLI_ASSOC);
$knippa_lista = $db->query(
    'SELECT k.ID, k.Namn, s.Namn AS SkapNamn FROM nyko_knippa k JOIN nyko_nyckelskap s ON s.ID = k.NyckelskapID ORDER BY s.Namn, k.Namn'
)->fetch_all(MYSQLI_ASSOC);
$nyckelskap_lista = $db->query('SELECT ID, Namn FROM nyko_nyckelskap ORDER BY Namn')->fetch_all(MYSQLI_ASSOC);

$formular_platstyp = 'skap';
if ($redigerar && $redigerar['KnippaID']) {
    $formular_platstyp = 'knippa';
}

// -----------------------------------------------------------------
// Sök, filtrera, sortera
// -----------------------------------------------------------------
$sokterm = trim($_GET['sok'] ?? '');
$filter_nyckelsystem = (int)($_GET['nyckelsystem'] ?? 0);
$filter_plats = trim($_GET['plats'] ?? '');
$filter_status = trim($_GET['status'] ?? '');

$sorteringsfalt = [
    'NyckelID' => 'n.NyckelID',
    'Funktion' => 'n.Funktion',
    'Placering' => 'n.Placering',
    'NyckelsystemNamn' => 'ny.NyckelsystemID',
];
[$sql_sortering, $riktning, $vald_sortnyckel] = sakerstall_sortering($sorteringsfalt, 'NyckelID');

$villkor = ['1=1'];
$typer = '';
$varden = [];

$sok = sok_villkor(['n.NyckelID', 'n.Funktion', 'n.Placering', 'ny.NyckelsystemID', 'k.Namn', 's1.Namn', 's2.Namn'], $sokterm);
if ($sok['sql'] !== '') {
    $villkor[] = $sok['sql'];
    $typer .= $sok['typer'];
    $varden = array_merge($varden, $sok['varden']);
}
if ($filter_nyckelsystem > 0) {
    $villkor[] = 'n.NyckelsystemID = ?';
    $typer .= 'i';
    $varden[] = $filter_nyckelsystem;
}
if ($filter_plats === 'knippa') {
    $villkor[] = 'n.KnippaID IS NOT NULL';
} elseif ($filter_plats === 'skap') {
    $villkor[] = 'n.NyckelskapID IS NOT NULL';
}
if ($filter_status === 'tappad') {
    $villkor[] = 'n.Forlorad = 1';
}

$having = '1=1';
if ($filter_status === 'hemma') {
    $having = 'Utlanad = 0';
} elseif ($filter_status === 'utlanad') {
    $having = 'Utlanad = 1';
}

$stmt = $db->prepare(
    "SELECT n.*,
        ny.NyckelsystemID AS NyckelsystemNamn,
        k.Namn AS KnippaNamn,
        s1.Namn AS KnippaSkapNamn,
        s2.Namn AS DirektSkapNamn,
        EXISTS (SELECT 1 FROM nyko_lanrad lr WHERE lr.NyckelID = n.ID AND lr.Status = 'Utlanad') AS Utlanad
     FROM nyko_nyckel n
     LEFT JOIN nyko_nyckelsystem ny ON ny.ID = n.NyckelsystemID
     LEFT JOIN nyko_knippa k ON k.ID = n.KnippaID
     LEFT JOIN nyko_nyckelskap s1 ON s1.ID = k.NyckelskapID
     LEFT JOIN nyko_nyckelskap s2 ON s2.ID = n.NyckelskapID
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

foreach ($lista as &$rad) {
    $rad['BorI'] = $rad['KnippaNamn']
        ? 'Knippa: ' . $rad['KnippaNamn'] . ' (' . $rad['KnippaSkapNamn'] . ')'
        : 'Nyckelskåp: ' . $rad['DirektSkapNamn'];
    $rad['StatusText'] = $rad['Forlorad'] ? 'Tappad' : ($rad['Utlanad'] ? 'Utlånad' : 'Hemma');
}
unset($rad);

if (($_GET['export'] ?? '') === 'csv') {
    exportera_csv(
        'nyckelkoll-nycklar.csv',
        ['Nyckel-ID', 'Funktion', 'Placering', 'Nyckelsystem', 'Bor i', 'Status'],
        $lista,
        ['NyckelID', 'Funktion', 'Placering', 'NyckelsystemNamn', 'BorI', 'StatusText']
    );
}

$per_sida = 50;
$totalt_antal = count($lista);
$lista_sida = array_slice($lista, (hamta_sida() - 1) * $per_sida, $per_sida);

require 'includes/header.php';
?>

<h1>Nycklar</h1>
<?php visa_flash(); ?>

<?php if (inloggad()): ?>
    <p><a href="nycklar.php?redigera=ny" class="btn">+ Ny nyckel</a></p>
<?php endif; ?>

<form method="get" class="filterrad">
    <input type="hidden" name="sortera" value="<?= h($vald_sortnyckel) ?>">
    <input type="hidden" name="riktning" value="<?= h(strtolower($riktning)) ?>">
    <div>
        <label for="sok">Sök</label>
        <input type="text" id="sok" name="sok" value="<?= h($sokterm) ?>" placeholder="Nyckel-ID, funktion, plats...">
    </div>
    <div>
        <label for="nyckelsystem">Nyckelsystem</label>
        <select id="nyckelsystem" name="nyckelsystem">
            <option value="">-- alla --</option>
            <?php foreach ($nyckelsystem_lista as $ns): ?>
                <option value="<?= (int)$ns['ID'] ?>" <?= $filter_nyckelsystem === (int)$ns['ID'] ? 'selected' : '' ?>><?= h($ns['NyckelsystemID']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="plats">Bor i</label>
        <select id="plats" name="plats">
            <option value="">-- alla --</option>
            <option value="knippa" <?= $filter_plats === 'knippa' ? 'selected' : '' ?>>Knippa</option>
            <option value="skap" <?= $filter_plats === 'skap' ? 'selected' : '' ?>>Nyckelskåp (direkt)</option>
        </select>
    </div>
    <div>
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">-- alla --</option>
            <option value="hemma" <?= $filter_status === 'hemma' ? 'selected' : '' ?>>Hemma</option>
            <option value="utlanad" <?= $filter_status === 'utlanad' ? 'selected' : '' ?>>Utlånad</option>
            <option value="tappad" <?= $filter_status === 'tappad' ? 'selected' : '' ?>>Tappad</option>
        </select>
    </div>
    <button type="submit" class="btn btn-liten">Filtrera</button>
    <?php if ($sokterm !== '' || $filter_nyckelsystem > 0 || $filter_plats !== '' || $filter_status !== ''): ?>
        <a href="nycklar.php" class="btn btn-liten">Rensa</a>
    <?php endif; ?>
</form>

<p class="filter-antal">
    <?= visar_antal_text($totalt_antal, $per_sida, 'nycklar') ?>
    &nbsp;<a href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Exportera CSV</a>
</p>

<table>
    <thead>
        <tr>
            <th><?= sorteringshuvud('NyckelID', 'Nyckel-ID') ?></th>
            <th><?= sorteringshuvud('Funktion', 'Funktion') ?></th>
            <th><?= sorteringshuvud('Placering', 'Placering') ?></th>
            <th><?= sorteringshuvud('NyckelsystemNamn', 'Nyckelsystem') ?></th>
            <th>Bor i</th>
            <th>Status</th>
            <?php if (inloggad()): ?><th>Åtgärd</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (!$lista_sida): ?>
            <tr><td colspan="7"><?= ($sokterm !== '' || $filter_nyckelsystem > 0 || $filter_plats !== '' || $filter_status !== '') ? 'Inga nycklar matchar sökningen/filtret.' : 'Inga nycklar registrerade ännu.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($lista_sida as $rad): ?>
            <tr class="<?= $rad['Forlorad'] ? 'rad-varning' : '' ?>">
                <td><?= h($rad['NyckelID']) ?></td>
                <td><?= h($rad['Funktion']) ?></td>
                <td><?= h($rad['Placering']) ?></td>
                <td><?= h($rad['NyckelsystemNamn']) ?></td>
                <td><?= h($rad['BorI']) ?></td>
                <td><?= h($rad['StatusText']) ?></td>
                <?php if (inloggad()): ?>
                <td>
                    <a href="nycklar.php?redigera=<?= (int)$rad['ID'] ?>">Redigera</a>
                    &nbsp;
                    <form method="post" style="display:inline" onsubmit="return confirm('Ta bort denna nyckel?');">
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
    <h2><?= $redigerar ? 'Redigera nyckel' : 'Ny nyckel' ?></h2>
    <?php if (!$redigerar): ?>
        <p class="hjalptext">Finns nyckeln redan (samma Nyckel-ID) läggs ingen ny post till - istället uppdateras den befintliga posten, inklusive plats.</p>
    <?php endif; ?>
    <?php if (!$knippa_lista && !$nyckelskap_lista): ?>
        <p class="meddelande meddelande-varning">Det finns inga nyckelskåp eller knippor registrerade än. Skapa ett nyckelskåp (och ev. en knippa) först.</p>
    <?php else: ?>
    <form method="post" class="formular" id="nyckelformular">
        <?= csrf_falt() ?>
        <input type="hidden" name="action" value="spara">
        <input type="hidden" name="id" value="<?= $redigerar ? (int)$redigerar['ID'] : 0 ?>">

        <label for="NyckelID">Nyckel-ID<?= kravstjarna($fi, 'NyckelID') ?></label>
        <input type="text" id="NyckelID" name="NyckelID" value="<?= h($redigerar['NyckelID'] ?? '') ?>" <?= kravattribut($fi, 'NyckelID') ?>>

        <label for="Funktion">Funktion</label>
        <input type="text" id="Funktion" name="Funktion" value="<?= h($redigerar['Funktion'] ?? '') ?>">

        <label for="Placering">Placering (adress/område)</label>
        <input type="text" id="Placering" name="Placering" value="<?= h($redigerar['Placering'] ?? '') ?>">

        <label for="NyckelsystemID">Nyckelsystem</label>
        <select id="NyckelsystemID" name="NyckelsystemID">
            <option value="">-- inget --</option>
            <?php foreach ($nyckelsystem_lista as $ns): ?>
                <option value="<?= (int)$ns['ID'] ?>" <?= (isset($redigerar['NyckelsystemID']) && (int)$redigerar['NyckelsystemID'] === (int)$ns['ID']) ? 'selected' : '' ?>>
                    <?= h($ns['NyckelsystemID']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Bor i <span class="faltkrav">*</span></label>
        <div>
            <label style="font-weight:normal;"><input type="radio" name="plats_typ" value="knippa" <?= $formular_platstyp === 'knippa' ? 'checked' : '' ?> onclick="nyckelPlatsVaxla()"> En knippa</label>
            &nbsp;&nbsp;
            <label style="font-weight:normal;"><input type="radio" name="plats_typ" value="skap" <?= $formular_platstyp === 'skap' ? 'checked' : '' ?> onclick="nyckelPlatsVaxla()"> Direkt i ett nyckelskåp</label>
        </div>

        <div id="knippa_val">
            <label for="KnippaID">Knippa</label>
            <select id="KnippaID" name="KnippaID">
                <option value="">-- välj knippa --</option>
                <?php foreach ($knippa_lista as $kn): ?>
                    <option value="<?= (int)$kn['ID'] ?>" <?= (isset($redigerar['KnippaID']) && (int)$redigerar['KnippaID'] === (int)$kn['ID']) ? 'selected' : '' ?>>
                        <?= h($kn['Namn']) ?> (<?= h($kn['SkapNamn']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="skap_val">
            <label for="NyckelskapID">Nyckelskåp</label>
            <select id="NyckelskapID" name="NyckelskapID">
                <option value="">-- välj nyckelskåp --</option>
                <?php foreach ($nyckelskap_lista as $skap): ?>
                    <option value="<?= (int)$skap['ID'] ?>" <?= (isset($redigerar['NyckelskapID']) && (int)$redigerar['NyckelskapID'] === (int)$skap['ID']) ? 'selected' : '' ?>>
                        <?= h($skap['Namn']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn"><?= $redigerar ? 'Spara ändringar' : 'Lägg till' ?></button>
    </form>

    <?php if ($redigerar): ?>
        <div style="margin-top:16px;">
            <?php if (!$redigerar['Forlorad']): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Är du helt säker på att nyckeln är tappad? Detta markerar den som Tappad tills den ev. återfinns.');">
                    <?= csrf_falt() ?>
                    <input type="hidden" name="action" value="markera_tappad">
                    <input type="hidden" name="id" value="<?= (int)$redigerar['ID'] ?>">
                    <button type="submit" class="btn btn-fara">Tappad</button>
                </form>
            <?php else: ?>
                <p class="meddelande meddelande-varning" style="display:inline-block;">Den här nyckeln är markerad som <strong>Tappad</strong>.</p>
                <form method="post" style="display:inline">
                    <?= csrf_falt() ?>
                    <input type="hidden" name="action" value="aterfunnen">
                    <input type="hidden" name="id" value="<?= (int)$redigerar['ID'] ?>">
                    <button type="submit" class="btn">Återfunnen</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <script>
    function nyckelPlatsVaxla() {
        var typ = document.querySelector('input[name="plats_typ"]:checked').value;
        document.getElementById('knippa_val').style.display = (typ === 'knippa') ? 'block' : 'none';
        document.getElementById('skap_val').style.display = (typ === 'skap') ? 'block' : 'none';
    }
    nyckelPlatsVaxla();
    </script>
    <?php endif; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
