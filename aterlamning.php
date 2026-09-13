<?php
require_once 'config.php';

$sidtitel = 'Återlämning';

// -----------------------------------------------------------------
// Hantera POST (kräver inloggning) - registrera återlämning
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifiera();
    krav_inloggning();

    $action = $_POST['action'] ?? '';

    if ($action === 'registrera_aterlamning') {
        $lan_id = (int)($_POST['lan_id'] ?? 0);
        $valda_lanrad_ider = array_map('intval', $_POST['aterlamna'] ?? []);

        if (!$valda_lanrad_ider) {
            satt_flash('fel', 'Kryssa i minst en nyckel/knippa som lämnas tillbaka.');
            header('Location: aterlamning.php?lan=' . $lan_id);
            exit;
        }

        // Hämta lånet och kontrollera att det inte redan är helt återlämnat
        $stmt = $db->prepare("SELECT ID, Status FROM nyko_lan WHERE ID = ? AND Status != 'Helt aterlamnat'");
        $stmt->bind_param('i', $lan_id);
        $stmt->execute();
        $lan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lan) {
            satt_flash('fel', 'Hittade inget aktivt lån med det lån-numret.');
            header('Location: aterlamning.php');
            exit;
        }

        try {
            $db->begin_transaction();
            $admin_id = inloggad_id();

            // Markera bara rader som verkligen tillhör detta lån och som fortfarande är utlånade
            $paverkade_knippor = [];
            $stmt = $db->prepare(
                "UPDATE nyko_lanrad
                 SET Status = 'Aterlamnad', AterlamningsdatumRad = NOW(), AterlamnadAvAdministratorID = ?
                 WHERE ID = ? AND LanID = ? AND Status = 'Utlanad'"
            );
            foreach ($valda_lanrad_ider as $lanrad_id) {
                $stmt->bind_param('iii', $admin_id, $lanrad_id, $lan_id);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $rad_stmt = $db->prepare('SELECT KnippaID FROM nyko_lanrad WHERE ID = ?');
                    $rad_stmt->bind_param('i', $lanrad_id);
                    $rad_stmt->execute();
                    $knippa_id = $rad_stmt->get_result()->fetch_assoc()['KnippaID'] ?? null;
                    $rad_stmt->close();
                    if ($knippa_id !== null) {
                        $paverkade_knippor[(int)$knippa_id] = true;
                    }
                }
            }
            $stmt->close();

            // Flagga avvikelse för varje berörd knippa som nu är ofullständigt återlämnad
            // (om det inte redan finns en oåtgärdad avvikelse för samma lån+knippa)
            foreach (array_keys($paverkade_knippor) as $knippa_id) {
                $stmt = $db->prepare(
                    "SELECT
                        (SELECT COUNT(*) FROM nyko_lanrad WHERE LanID = ? AND KnippaID = ?) AS Totalt,
                        (SELECT COUNT(*) FROM nyko_lanrad WHERE LanID = ? AND KnippaID = ? AND Status = 'Aterlamnad') AS Aterlamnade"
                );
                $stmt->bind_param('iiii', $lan_id, $knippa_id, $lan_id, $knippa_id);
                $stmt->execute();
                $rakning = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ((int)$rakning['Aterlamnade'] > 0 && (int)$rakning['Aterlamnade'] < (int)$rakning['Totalt']) {
                    $finns = $db->prepare('SELECT ID FROM nyko_knippaavvikelse WHERE LanID = ? AND KnippaID = ? AND Atgardad = 0');
                    $finns->bind_param('ii', $lan_id, $knippa_id);
                    $finns->execute();
                    $befintlig = $finns->get_result()->fetch_assoc();
                    $finns->close();

                    if (!$befintlig) {
                        $knippa_namn = $db->prepare('SELECT Namn FROM nyko_knippa WHERE ID = ?');
                        $knippa_namn->bind_param('i', $knippa_id);
                        $knippa_namn->execute();
                        $namn = $knippa_namn->get_result()->fetch_assoc()['Namn'] ?? ('#' . $knippa_id);
                        $knippa_namn->close();

                        $beskrivning = 'Knippan "' . $namn . '" lämnades tillbaka ofullständig i lån #' . $lan_id
                            . ' (' . (int)$rakning['Aterlamnade'] . ' av ' . (int)$rakning['Totalt'] . ' nycklar återlämnade).';
                        $insert = $db->prepare('INSERT INTO nyko_knippaavvikelse (LanID, KnippaID, Beskrivning) VALUES (?, ?, ?)');
                        $insert->bind_param('iis', $lan_id, $knippa_id, $beskrivning);
                        $insert->execute();
                        $insert->close();
                    }
                }
            }

            // Räkna om lånets totala status
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS Totalt, SUM(Status = \'Aterlamnad\') AS Aterlamnade FROM nyko_lanrad WHERE LanID = ?'
            );
            $stmt->bind_param('i', $lan_id);
            $stmt->execute();
            $rakning = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $totalt = (int)$rakning['Totalt'];
            $aterlamnade = (int)$rakning['Aterlamnade'];

            if ($aterlamnade >= $totalt && $totalt > 0) {
                $nystatus = 'Helt aterlamnat';
                $stmt = $db->prepare('UPDATE nyko_lan SET Status = ?, Aterlamningsdatum = NOW() WHERE ID = ?');
                $stmt->bind_param('si', $nystatus, $lan_id);
            } else {
                $nystatus = 'Delvis aterlamnat';
                $stmt = $db->prepare('UPDATE nyko_lan SET Status = ? WHERE ID = ?');
                $stmt->bind_param('si', $nystatus, $lan_id);
            }
            $stmt->execute();
            $stmt->close();

            $db->commit();

            $antal = count($valda_lanrad_ider);
            if ($nystatus === 'Helt aterlamnat') {
                satt_flash('ok', $antal . ' nyckel/nycklar registrerade som återlämnade. Lånet är nu helt återlämnat.');
            } else {
                satt_flash('ok', $antal . ' nyckel/nycklar registrerade som återlämnade. Lånet är delvis återlämnat - resten kvarstår.');
            }
        } catch (mysqli_sql_exception $e) {
            $db->rollback();
            satt_flash('fel', 'Kunde inte registrera återlämningen: ' . $e->getMessage());
        }

        header('Location: aterlamning.php?lan=' . $lan_id);
        exit;
    }
}

// -----------------------------------------------------------------
// Sök efter aktiva lån (på person, grupp, nyckel, knippa eller lån-nummer)
// -----------------------------------------------------------------
$sokterm = trim($_GET['sok'] ?? '');
$soktraffar = [];
if ($sokterm !== '') {
    $sok = sok_villkor(['l.Namn', 'l.Telefonnummer', 'l.Mejl', 'g.Namn', 'n.NyckelID', 'k.Namn', 'CAST(lan.ID AS CHAR)'], $sokterm);
    $stmt = $db->prepare(
        "SELECT DISTINCT lan.ID, lan.SlutdatumGiltighet, lan.Status, l.Namn AS LantagareNamn, g.Namn AS GruppNamn
         FROM nyko_lan lan
         JOIN nyko_lantagare l ON l.ID = lan.LantagareID
         LEFT JOIN nyko_lantagargrupp g ON g.ID = l.LantagargruppID
         LEFT JOIN nyko_lanrad lr ON lr.LanID = lan.ID
         LEFT JOIN nyko_nyckel n ON n.ID = lr.NyckelID
         LEFT JOIN nyko_knippa k ON k.ID = lr.KnippaID
         WHERE lan.Status != 'Helt aterlamnat' AND " . $sok['sql'] . '
         ORDER BY lan.SlutdatumGiltighet'
    );
    $stmt->bind_param($sok['typer'], ...$sok['varden']);
    $stmt->execute();
    $soktraffar = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// -----------------------------------------------------------------
// Valt lån (via sökträff, via "Återlämna"-knappen i Aktuella utlåningar,
// eller direkt via query-parametern lan=)
// -----------------------------------------------------------------
$vald_lan_id = (int)($_GET['lan'] ?? 0);
$lan = null;
$lanrader = [];

if ($vald_lan_id > 0) {
    $stmt = $db->prepare(
        "SELECT lan.*, l.Namn AS LantagareNamn, l.Telefonnummer, l.Mejl, g.Namn AS GruppNamn
         FROM nyko_lan lan
         JOIN nyko_lantagare l ON l.ID = lan.LantagareID
         LEFT JOIN nyko_lantagargrupp g ON g.ID = l.LantagargruppID
         WHERE lan.ID = ?"
    );
    $stmt->bind_param('i', $vald_lan_id);
    $stmt->execute();
    $lan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($lan) {
        $stmt = $db->prepare(
            'SELECT lr.*, n.NyckelID, n.Funktion, k.Namn AS KnippaNamn
             FROM nyko_lanrad lr
             JOIN nyko_nyckel n ON n.ID = lr.NyckelID
             LEFT JOIN nyko_knippa k ON k.ID = lr.KnippaID
             WHERE lr.LanID = ?
             ORDER BY k.Namn, n.NyckelID'
        );
        $stmt->bind_param('i', $vald_lan_id);
        $stmt->execute();
        $lanrader = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$grupperat = [];
foreach ($lanrader as $rad) {
    $grupp = $rad['KnippaNamn'] ?? '__losa__';
    $grupperat[$grupp][] = $rad;
}

require 'includes/header.php';
?>

<h1>Återlämning</h1>
<?php visa_flash(); ?>

<h2>Sök upp ett lån</h2>
<form method="get" class="filterrad">
    <div>
        <label for="sok">Sök</label>
        <input type="text" id="sok" name="sok" value="<?= h($sokterm) ?>" placeholder="Lån-nr, låntagare, grupp, nyckel, knippa...">
    </div>
    <button type="submit" class="btn btn-liten">Sök</button>
    <?php if ($sokterm !== ''): ?>
        <a href="aterlamning.php" class="btn btn-liten">Rensa</a>
    <?php endif; ?>
</form>

<?php if ($sokterm !== ''): ?>
    <table>
        <thead>
            <tr><th>Lån-nr</th><th>Låntagare</th><th>Grupp</th><th>Slutdatum</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
            <?php if (!$soktraffar): ?>
                <tr><td colspan="6">Inga aktiva lån matchar sökningen.</td></tr>
            <?php endif; ?>
            <?php foreach ($soktraffar as $rad): ?>
                <tr>
                    <td>#<?= (int)$rad['ID'] ?></td>
                    <td><?= h($rad['LantagareNamn']) ?></td>
                    <td><?= h($rad['GruppNamn']) ?></td>
                    <td><?= h($rad['SlutdatumGiltighet']) ?></td>
                    <td><?= h($rad['Status']) ?></td>
                    <td><a href="aterlamning.php?lan=<?= (int)$rad['ID'] ?>" class="btn btn-liten">Öppna</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($vald_lan_id > 0): ?>
    <h2>Lån #<?= $vald_lan_id ?></h2>

    <?php if (!$lan): ?>
        <p class="meddelande meddelande-fel">Hittade inget lån med det lån-numret.</p>
    <?php elseif ($lan['Status'] === 'Helt aterlamnat'): ?>
        <p class="meddelande meddelande-ok">Det här lånet är redan helt återlämnat. <a href="lan_detalj.php?id=<?= $vald_lan_id ?>">Visa lånekvittot</a>.</p>
    <?php else: ?>
        <p>
            <strong>Låntagare:</strong> <?= h($lan['LantagareNamn']) ?><?= $lan['GruppNamn'] ? ' (' . h($lan['GruppNamn']) . ')' : '' ?><br>
            <strong>Telefon:</strong> <?= h($lan['Telefonnummer']) ?> &nbsp; <strong>Mejl:</strong> <?= h($lan['Mejl']) ?><br>
            <strong>Slutdatum:</strong> <?= h($lan['SlutdatumGiltighet']) ?> &nbsp; <strong>Status:</strong> <?= h($lan['Status']) ?>
        </p>

        <?php if (!inloggad()): ?>
            <p class="meddelande meddelande-varning">Du måste vara inloggad för att registrera en återlämning. Du kan se lånets innehåll här, men inte kryssa av något.</p>
        <?php endif; ?>

        <form method="post">
            <?= csrf_falt() ?>
            <input type="hidden" name="action" value="registrera_aterlamning">
            <input type="hidden" name="lan_id" value="<?= $vald_lan_id ?>">

            <?php foreach ($grupperat as $grupp => $rader): ?>
                <h3><?= $grupp === '__losa__' ? 'Lösa nycklar' : 'Knippa: ' . h($grupp) ?></h3>
                <?php foreach ($rader as $rad): ?>
                    <?php if ($rad['Status'] === 'Aterlamnad'): ?>
                        <p class="hjalptext" style="margin:2px 0;">
                            &#10003; <?= h($rad['NyckelID']) ?><?= $rad['Funktion'] ? ' - ' . h($rad['Funktion']) : '' ?> (redan återlämnad)
                        </p>
                    <?php else: ?>
                        <label style="font-weight:normal; display:block;">
                            <input type="checkbox" name="aterlamna[]" value="<?= (int)$rad['ID'] ?>" <?= !inloggad() ? 'disabled' : '' ?>>
                            <?= h($rad['NyckelID']) ?><?= $rad['Funktion'] ? ' - ' . h($rad['Funktion']) : '' ?>
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <?php if (inloggad()): ?>
                <button type="submit" class="btn" style="margin-top:16px;">Registrera återlämning</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
