<?php
require_once 'config.php';

$sidtitel = 'Ej återlämnade i tid';

// -----------------------------------------------------------------
// Sök, filtrera, sortera
// -----------------------------------------------------------------
$sokterm = trim($_GET['sok'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_grupp = (int)($_GET['grupp'] ?? 0);

$sorteringsfalt = [
    'ID' => 'lan.ID',
    'Lantagare' => 'l.Namn',
    'Grupp' => 'g.Namn',
    'Utlaningsdatum' => 'lan.Utlaningsdatum',
    'Slutdatum' => 'lan.SlutdatumGiltighet',
    'Status' => 'lan.Status',
];
[$sql_sortering, $riktning, $vald_sortnyckel] = sakerstall_sortering($sorteringsfalt, 'Slutdatum');

$villkor = ["lan.Status IN ('Aktivt', 'Delvis aterlamnat')", 'lan.SlutdatumGiltighet <= CURDATE()'];
$typer = '';
$varden = [];

$sok = sok_villkor(['l.Namn', 'l.Telefonnummer', 'l.Mejl', 'g.Namn', 'CAST(lan.ID AS CHAR)'], $sokterm);
if ($sok['sql'] !== '') {
    $villkor[] = $sok['sql'];
    $typer .= $sok['typer'];
    $varden = array_merge($varden, $sok['varden']);
}
if ($filter_status !== '') {
    $villkor[] = 'lan.Status = ?';
    $typer .= 's';
    $varden[] = $filter_status;
}
if ($filter_grupp > 0) {
    $villkor[] = 'g.ID = ?';
    $typer .= 'i';
    $varden[] = $filter_grupp;
}

$stmt = $db->prepare(
    'SELECT lan.*, l.Namn AS LantagareNamn, l.Telefonnummer, l.Mejl, g.Namn AS GruppNamn,
        (SELECT COUNT(*) FROM nyko_lanrad lr WHERE lr.LanID = lan.ID) AS AntalNycklar,
        (SELECT COUNT(*) FROM nyko_lanrad lr WHERE lr.LanID = lan.ID AND lr.Status = \'Aterlamnad\') AS AntalAterlamnade
     FROM nyko_lan lan
     JOIN nyko_lantagare l ON l.ID = lan.LantagareID
     LEFT JOIN nyko_lantagargrupp g ON g.ID = l.LantagargruppID
     WHERE ' . implode(' AND ', $villkor) . " ORDER BY $sql_sortering $riktning"
);
if ($varden) {
    $stmt->bind_param($typer, ...$varden);
}
$stmt->execute();
$lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$grupp_lista = $db->query('SELECT ID, Namn FROM nyko_lantagargrupp ORDER BY Namn')->fetch_all(MYSQLI_ASSOC);

require 'includes/header.php';
?>

<h1>Ej återlämnade i tid</h1>
<?php visa_flash(); ?>

<p class="hjalptext">Lån vars slutdatum har passerat eller är idag, utan att vara helt återlämnade.</p>

<form method="get" class="filterrad">
    <input type="hidden" name="sortera" value="<?= h($vald_sortnyckel) ?>">
    <input type="hidden" name="riktning" value="<?= h(strtolower($riktning)) ?>">
    <div>
        <label for="sok">Sök</label>
        <input type="text" id="sok" name="sok" value="<?= h($sokterm) ?>" placeholder="Lån-nr, låntagare, telefon...">
    </div>
    <div>
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">-- alla --</option>
            <option value="Aktivt" <?= $filter_status === 'Aktivt' ? 'selected' : '' ?>>Aktivt</option>
            <option value="Delvis aterlamnat" <?= $filter_status === 'Delvis aterlamnat' ? 'selected' : '' ?>>Delvis återlämnat</option>
        </select>
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
    <?php if ($sokterm !== '' || $filter_status !== '' || $filter_grupp > 0): ?>
        <a href="ej_aterlamnade.php" class="btn btn-liten">Rensa</a>
    <?php endif; ?>
</form>

<p class="filter-antal"><?= count($lista) ?> ej återlämnade i tid visas.</p>

<table>
    <thead>
        <tr>
            <th><?= sorteringshuvud('ID', 'Lån-nr') ?></th>
            <th><?= sorteringshuvud('Lantagare', 'Låntagare') ?></th>
            <th>Telefonnummer</th>
            <th><?= sorteringshuvud('Grupp', 'Låntagargrupp') ?></th>
            <th>Antal nycklar</th>
            <th><?= sorteringshuvud('Utlaningsdatum', 'Utlåningsdatum') ?></th>
            <th><?= sorteringshuvud('Slutdatum', 'Slutdatum') ?></th>
            <th><?= sorteringshuvud('Status', 'Status') ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$lista): ?>
            <tr><td colspan="9"><?= ($sokterm !== '' || $filter_status !== '' || $filter_grupp > 0) ? 'Inga lån matchar sökningen/filtret.' : 'Inga ej återlämnade lån just nu.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($lista as $rad): ?>
            <?php $ar_idag = $rad['SlutdatumGiltighet'] === date('Y-m-d'); ?>
            <tr class="<?= $ar_idag ? 'rad-uppmarksamma' : 'rad-varning' ?>">
                <td><a href="lan_detalj.php?id=<?= (int)$rad['ID'] ?>">#<?= (int)$rad['ID'] ?></a></td>
                <td><?= h($rad['LantagareNamn']) ?></td>
                <td><?= h($rad['Telefonnummer']) ?></td>
                <td><?= h($rad['GruppNamn']) ?></td>
                <td><?= (int)$rad['AntalAterlamnade'] ?> / <?= (int)$rad['AntalNycklar'] ?> återlämnade</td>
                <td><?= h($rad['Utlaningsdatum']) ?></td>
                <td><?= h($rad['SlutdatumGiltighet']) ?> <?= $ar_idag ? '(idag)' : '&#9888;' ?></td>
                <td><?= h($rad['Status']) ?></td>
                <td><a href="aterlamning.php?lan=<?= (int)$rad['ID'] ?>" class="btn btn-liten">Återlämna</a></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require 'includes/footer.php'; ?>
