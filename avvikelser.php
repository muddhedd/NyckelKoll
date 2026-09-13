<?php
require_once 'config.php';

$sidtitel = 'Avvikelser';

// -----------------------------------------------------------------
// Hantera POST (kräver inloggning)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifiera();
    krav_inloggning();

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'atgarda') {
        $admin_id = inloggad_id();
        $stmt = $db->prepare('UPDATE nyko_knippaavvikelse SET Atgardad = 1, AtgardadAvAdministratorID = ?, AtgardadDatum = NOW() WHERE ID = ?');
        $stmt->bind_param('ii', $admin_id, $id);
        $stmt->execute();
        $stmt->close();
        satt_flash('ok', 'Avvikelsen markerades som åtgärdad.');
        header('Location: avvikelser.php');
        exit;
    }

    if ($action === 'oppna_igen') {
        $stmt = $db->prepare('UPDATE nyko_knippaavvikelse SET Atgardad = 0, AtgardadAvAdministratorID = NULL, AtgardadDatum = NULL WHERE ID = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        satt_flash('ok', 'Avvikelsen öppnades igen.');
        header('Location: avvikelser.php');
        exit;
    }
}

// -----------------------------------------------------------------
// Sök, filtrera, sortera
// -----------------------------------------------------------------
$sokterm = trim($_GET['sok'] ?? '');
$filter_status = trim($_GET['status'] ?? 'oppna');

$sorteringsfalt = [
    'Skapad' => 'av.Skapad',
    'Knippa' => 'k.Namn',
    'LanID' => 'av.LanID',
    'Atgardad' => 'av.Atgardad',
];
[$sql_sortering, $riktning, $vald_sortnyckel] = sakerstall_sortering($sorteringsfalt, 'Skapad');
if (!isset($_GET['riktning']) && !isset($_GET['sortera'])) {
    $riktning = 'DESC'; // nyast överst som standard
}
// Se till att sorteringshuvud() och de dolda fälten nedan ser samma val,
// även när det bara är standardvärden och inget uttryckligen valdes i URL:en.
$_GET['sortera'] = $vald_sortnyckel;
$_GET['riktning'] = strtolower($riktning);

$villkor = ['1=1'];
$typer = '';
$varden = [];

$sok = sok_villkor(['k.Namn', 's.Namn', 'l.Namn', 'CAST(av.LanID AS CHAR)'], $sokterm);
if ($sok['sql'] !== '') {
    $villkor[] = $sok['sql'];
    $typer .= $sok['typer'];
    $varden = array_merge($varden, $sok['varden']);
}
if ($filter_status === 'oppna') {
    $villkor[] = 'av.Atgardad = 0';
} elseif ($filter_status === 'atgardade') {
    $villkor[] = 'av.Atgardad = 1';
}

$stmt = $db->prepare(
    'SELECT av.*, k.Namn AS KnippaNamn, s.Namn AS SkapNamn, l.Namn AS LantagareNamn,
        a1.Anvandarnamn AS AtgardadAvNamn
     FROM nyko_knippaavvikelse av
     JOIN nyko_knippa k ON k.ID = av.KnippaID
     JOIN nyko_nyckelskap s ON s.ID = k.NyckelskapID
     JOIN nyko_lan lan ON lan.ID = av.LanID
     JOIN nyko_lantagare l ON l.ID = lan.LantagareID
     LEFT JOIN nyko_anvandare a1 ON a1.ID = av.AtgardadAvAdministratorID
     WHERE ' . implode(' AND ', $villkor) . " ORDER BY $sql_sortering $riktning"
);
if ($varden) {
    $stmt->bind_param($typer, ...$varden);
}
$stmt->execute();
$lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

require 'includes/header.php';
?>

<h1>Avvikelser</h1>
<?php visa_flash(); ?>

<p class="hjalptext">
    Här samlas knippor som lämnats tillbaka ofullständiga (någon nyckel saknades).
    Flaggan skapas automatiskt vid återlämning och försvinner inte förrän den
    åtgärdas manuellt här - även om resten av knippan skulle lämnas tillbaka senare.
</p>

<form method="get" class="filterrad">
    <input type="hidden" name="sortera" value="<?= h($vald_sortnyckel) ?>">
    <input type="hidden" name="riktning" value="<?= h(strtolower($riktning)) ?>">
    <div>
        <label for="sok">Sök</label>
        <input type="text" id="sok" name="sok" value="<?= h($sokterm) ?>" placeholder="Knippa, skåp, låntagare, lån-nr...">
    </div>
    <div>
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="oppna" <?= $filter_status === 'oppna' ? 'selected' : '' ?>>Öppna</option>
            <option value="atgardade" <?= $filter_status === 'atgardade' ? 'selected' : '' ?>>Åtgärdade</option>
            <option value="alla" <?= $filter_status === 'alla' ? 'selected' : '' ?>>Alla</option>
        </select>
    </div>
    <button type="submit" class="btn btn-liten">Filtrera</button>
    <?php if ($sokterm !== '' || $filter_status !== 'oppna'): ?>
        <a href="avvikelser.php" class="btn btn-liten">Rensa</a>
    <?php endif; ?>
</form>

<p class="filter-antal"><?= count($lista) ?> avvikelser visas.</p>

<table>
    <thead>
        <tr>
            <th><?= sorteringshuvud('Skapad', 'Skapad') ?></th>
            <th><?= sorteringshuvud('LanID', 'Lån') ?></th>
            <th>Låntagare</th>
            <th><?= sorteringshuvud('Knippa', 'Knippa') ?></th>
            <th>Nyckelskåp</th>
            <th>Beskrivning</th>
            <th><?= sorteringshuvud('Atgardad', 'Status') ?></th>
            <?php if (inloggad()): ?><th>Åtgärd</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (!$lista): ?>
            <tr><td colspan="8"><?= $filter_status === 'oppna' ? 'Inga öppna avvikelser just nu.' : 'Inga avvikelser matchar sökningen/filtret.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($lista as $rad): ?>
            <tr class="<?= !$rad['Atgardad'] ? 'rad-varning' : '' ?>">
                <td><?= h($rad['Skapad']) ?></td>
                <td><a href="lan_detalj.php?id=<?= (int)$rad['LanID'] ?>">#<?= (int)$rad['LanID'] ?></a></td>
                <td><?= h($rad['LantagareNamn']) ?></td>
                <td><?= h($rad['KnippaNamn']) ?></td>
                <td><?= h($rad['SkapNamn']) ?></td>
                <td><?= h($rad['Beskrivning']) ?></td>
                <td>
                    <?php if ($rad['Atgardad']): ?>
                        Åtgärdad<?= $rad['AtgardadAvNamn'] ? ' (' . h($rad['AtgardadAvNamn']) . ')' : '' ?><br>
                        <span class="hjalptext"><?= h($rad['AtgardadDatum']) ?></span>
                    <?php else: ?>
                        Öppen
                    <?php endif; ?>
                </td>
                <?php if (inloggad()): ?>
                <td>
                    <?php if (!$rad['Atgardad']): ?>
                        <form method="post" onsubmit="return confirm('Markera denna avvikelse som åtgärdad?');">
                            <?= csrf_falt() ?>
                            <input type="hidden" name="action" value="atgarda">
                            <input type="hidden" name="id" value="<?= (int)$rad['ID'] ?>">
                            <button type="submit" class="btn btn-liten">Åtgärda</button>
                        </form>
                    <?php else: ?>
                        <form method="post">
                            <?= csrf_falt() ?>
                            <input type="hidden" name="action" value="oppna_igen">
                            <input type="hidden" name="id" value="<?= (int)$rad['ID'] ?>">
                            <button type="submit" class="btn btn-liten btn-fara">Öppna igen</button>
                        </form>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require 'includes/footer.php'; ?>
