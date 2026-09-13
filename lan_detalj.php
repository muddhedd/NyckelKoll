<?php
require_once 'config.php';

$sidtitel = 'Lånedetaljer';

$lan_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare(
    'SELECT lan.*, l.Namn AS LantagareNamn, l.Telefonnummer, l.Mejl, g.Namn AS GruppNamn,
        a.Anvandarnamn AS AdminNamn
     FROM nyko_lan lan
     JOIN nyko_lantagare l ON l.ID = lan.LantagareID
     LEFT JOIN nyko_lantagargrupp g ON g.ID = l.LantagargruppID
     JOIN nyko_anvandare a ON a.ID = lan.AdministratorID
     WHERE lan.ID = ?'
);
$stmt->bind_param('i', $lan_id);
$stmt->execute();
$lan = $stmt->get_result()->fetch_assoc();
$stmt->close();

$lanrader = [];
if ($lan) {
    $stmt = $db->prepare(
        'SELECT lr.*, n.NyckelID, n.Funktion, k.Namn AS KnippaNamn
         FROM nyko_lanrad lr
         JOIN nyko_nyckel n ON n.ID = lr.NyckelID
         LEFT JOIN nyko_knippa k ON k.ID = lr.KnippaID
         WHERE lr.LanID = ?
         ORDER BY k.Namn, n.NyckelID'
    );
    $stmt->bind_param('i', $lan_id);
    $stmt->execute();
    $lanrader = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$grupperat = [];
foreach ($lanrader as $rad) {
    $grupp = $rad['KnippaNamn'] ?? '__losa__';
    $grupperat[$grupp][] = $rad;
}

require 'includes/header.php';
?>

<h1>Lån #<?= $lan_id ?></h1>

<?php if (!$lan): ?>
    <p class="meddelande meddelande-fel">Hittade inget lån med det lån-numret.</p>
<?php else: ?>

    <div class="kvitto">
        <h2>Lånekvitto - Lån #<?= (int)$lan['ID'] ?></h2>
        <p>
            <strong>Låntagare:</strong> <?= h($lan['LantagareNamn']) ?><?= $lan['GruppNamn'] ? ' (' . h($lan['GruppNamn']) . ')' : '' ?><br>
            <strong>Telefon:</strong> <?= h($lan['Telefonnummer']) ?> &nbsp; <strong>Mejl:</strong> <?= h($lan['Mejl']) ?>
        </p>
        <p>
            <strong>Utlåningsdatum:</strong> <?= h($lan['Utlaningsdatum']) ?><br>
            <strong>Administratör:</strong> <?= h($lan['AdminNamn']) ?><br>
            <strong>Giltigt till:</strong> <?= h($lan['SlutdatumGiltighet']) ?><br>
            <strong>Status:</strong> <?= h($lan['Status']) ?>
        </p>

        <h3>Lånade nycklar</h3>
        <?php foreach ($grupperat as $grupp => $rader): ?>
            <p style="margin-bottom:4px;">
                <strong><?= $grupp === '__losa__' ? 'Lösa nycklar' : 'Knippa: ' . h($grupp) ?></strong>
            </p>
            <ul style="margin-top:0;">
                <?php foreach ($rader as $rad): ?>
                    <li>
                        <?= h($rad['NyckelID']) ?><?= $rad['Funktion'] ? ' - ' . h($rad['Funktion']) : '' ?>
                        <?= $rad['Status'] === 'Aterlamnad' ? ' (återlämnad)' : '' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </div>

    <div class="no-print">
        <button type="button" class="btn" onclick="window.print()">Skriv ut</button>
        <a href="aktuella_utlaningar.php" class="btn">Tillbaka till aktuella utlåningar</a>
    </div>

<?php endif; ?>

<?php require 'includes/footer.php'; ?>
