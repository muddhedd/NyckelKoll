<?php
require_once 'config.php';

$sidtitel = 'Ny utlåning';

// -----------------------------------------------------------------
// Sessionens "korg" för lånet som byggs upp - rensas när det sparas
// eller avbryts.
// -----------------------------------------------------------------
if (!isset($_SESSION['nytt_lan'])) {
    $_SESSION['nytt_lan'] = [
        'lantagare_id' => null,
        'slutdatum' => null,
        'poster' => [],      // lista av ['typ' => 'knippa'|'nyckel', 'id' => int]
        'sparat_lan_id' => null,
    ];
}
$korg = &$_SESSION['nytt_lan'];
$ar_sparat = $korg['sparat_lan_id'] !== null;

/** Alla nyckel-ID:n som redan ligger i korgen (knippor expanderade till sina nycklar). */
function korg_nyckel_ider(array $poster): array
{
    global $db;
    $ider = [];
    foreach ($poster as $post) {
        if ($post['typ'] === 'nyckel') {
            $ider[] = (int)$post['id'];
        } else {
            $stmt = $db->prepare('SELECT ID FROM nyko_nyckel WHERE KnippaID = ?');
            $stmt->bind_param('i', $post['id']);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $rad) {
                $ider[] = (int)$rad['ID'];
            }
            $stmt->close();
        }
    }
    return $ider;
}

/** Alla knippa-ID:n som redan ligger i korgen som egna poster. */
function korg_knippa_ider(array $poster): array
{
    $ider = [];
    foreach ($poster as $post) {
        if ($post['typ'] === 'knippa') {
            $ider[] = (int)$post['id'];
        }
    }
    return $ider;
}

// -----------------------------------------------------------------
// Hantera POST (kräver inloggning)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifiera();
    krav_inloggning();

    $action = $_POST['action'] ?? '';

    if ($action === 'avbryt' && !$ar_sparat) {
        $_SESSION['nytt_lan'] = ['lantagare_id' => null, 'slutdatum' => null, 'poster' => [], 'sparat_lan_id' => null];
        satt_flash('ok', 'Lånet avbröts, ingen information sparades.');
        header('Location: utlaning.php');
        exit;
    }

    if ($action === 'lagg_till' && !$ar_sparat) {
        $vald_lantagare_id = (int)($_POST['lantagare_id'] ?? 0) ?: $korg['lantagare_id'];
        if ($vald_lantagare_id) {
            $giltighet = lantagare_ar_giltig($vald_lantagare_id);
            if (!$giltighet['giltig']) {
                satt_flash('fel', 'Den valda låntagaren kan inte låna just nu: ' . $giltighet['anledning']);
                header('Location: utlaning.php?skap=' . (int)($_POST['skap'] ?? 0));
                exit;
            }
        }
        $korg['lantagare_id'] = $vald_lantagare_id;
        $korg['slutdatum'] = p_null('slutdatum') ?? $korg['slutdatum'];

        $skap = (int)($_POST['skap'] ?? 0);
        $valda_knippor = array_map('intval', $_POST['knippa'] ?? []);
        $valda_nycklar = array_map('intval', $_POST['nyckel'] ?? []);

        $redan_nyckel_ider = korg_nyckel_ider($korg['poster']);
        $redan_knippa_ider = korg_knippa_ider($korg['poster']);
        $antal_tillagda = 0;

        // Knippor: måste tillhöra valt skåp, vara helt hemma, och inte redan i korgen
        foreach ($valda_knippor as $knippa_id) {
            if (in_array($knippa_id, $redan_knippa_ider, true)) {
                continue;
            }
            $stmt = $db->prepare(
                "SELECT k.ID,
                    (SELECT COUNT(*) FROM nyko_nyckel n WHERE n.KnippaID = k.ID) AS Totalt,
                    (SELECT COUNT(*) FROM nyko_nyckel n WHERE n.KnippaID = k.ID
                        AND EXISTS (SELECT 1 FROM nyko_lanrad lr WHERE lr.NyckelID = n.ID AND lr.Status = 'Utlanad')
                    ) AS Utlanade
                 FROM nyko_knippa k WHERE k.ID = ? AND k.NyckelskapID = ?"
            );
            $stmt->bind_param('ii', $knippa_id, $skap);
            $stmt->execute();
            $rad = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($rad && (int)$rad['Totalt'] > 0 && (int)$rad['Utlanade'] === 0) {
                $korg['poster'][] = ['typ' => 'knippa', 'id' => $knippa_id];
                $antal_tillagda++;
            }
        }

        // Lösa nycklar: måste tillhöra valt skåp direkt, vara hemma, och inte redan i korgen
        foreach ($valda_nycklar as $nyckel_id) {
            if (in_array($nyckel_id, $redan_nyckel_ider, true)) {
                continue;
            }
            $stmt = $db->prepare(
                "SELECT n.ID FROM nyko_nyckel n
                 WHERE n.ID = ? AND n.NyckelskapID = ?
                 AND NOT EXISTS (SELECT 1 FROM nyko_lanrad lr WHERE lr.NyckelID = n.ID AND lr.Status = 'Utlanad')"
            );
            $stmt->bind_param('ii', $nyckel_id, $skap);
            $stmt->execute();
            $rad = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($rad) {
                $korg['poster'][] = ['typ' => 'nyckel', 'id' => $nyckel_id];
                $antal_tillagda++;
            }
        }

        if ($antal_tillagda > 0) {
            satt_flash('ok', $antal_tillagda . ' post(er) tillagda i lånet.');
        } else {
            satt_flash('varning', 'Inget lades till - det du kryssade i fanns redan i lånet, eller är inte längre tillgängligt.');
        }
        header('Location: utlaning.php?skap=' . $skap);
        exit;
    }

    if ($action === 'ta_bort_post' && !$ar_sparat) {
        $index = (int)($_POST['index'] ?? -1);
        if (isset($korg['poster'][$index])) {
            array_splice($korg['poster'], $index, 1);
            satt_flash('ok', 'Posten togs bort ur lånet.');
        }
        header('Location: utlaning.php');
        exit;
    }

    if ($action === 'spara' && !$ar_sparat) {
        $lantagare_id = (int)($korg['lantagare_id'] ?? 0);
        $slutdatum = $korg['slutdatum'] ?? null;

        $fel = [];
        if ($lantagare_id <= 0) {
            $fel[] = 'Välj en låntagare.';
        } else {
            $giltighet = lantagare_ar_giltig($lantagare_id);
            if (!$giltighet['giltig']) {
                $fel[] = 'Låntagaren kan inte längre låna: ' . $giltighet['anledning'];
            }
        }
        if (!$slutdatum || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $slutdatum)) {
            $fel[] = 'Ange ett giltigt slutdatum.';
        } elseif ($slutdatum < date('Y-m-d')) {
            $fel[] = 'Slutdatumet har redan passerat - välj ett datum idag eller senare.';
        }
        if (empty($korg['poster'])) {
            $fel[] = 'Lägg till minst en nyckel eller knippa innan du sparar.';
        }

        if ($fel) {
            satt_flash('fel', implode(' ', $fel));
            header('Location: utlaning.php');
            exit;
        }

        // Bygg en platt lista av nyckel-ID:n (knippor expanderade) och
        // dubbelkolla att inget hunnit lånas ut av någon annan under tiden.
        $nyckel_ider = array_unique(korg_nyckel_ider($korg['poster']));
        $krockar = [];
        foreach ($nyckel_ider as $nyckel_id) {
            $stmt = $db->prepare("SELECT NyckelID FROM nyko_nyckel WHERE ID = ? AND EXISTS (SELECT 1 FROM nyko_lanrad lr WHERE lr.NyckelID = ? AND lr.Status = 'Utlanad')");
            $stmt->bind_param('ii', $nyckel_id, $nyckel_id);
            $stmt->execute();
            $rad = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($rad) {
                $krockar[] = $rad['NyckelID'];
            }
        }
        if ($krockar) {
            satt_flash('fel', 'Följande nycklar har redan lånats ut av någon annan sedan du lade till dem: ' . implode(', ', $krockar) . '. Ta bort dem ur lånet och försök igen.');
            header('Location: utlaning.php');
            exit;
        }

        try {
            $db->begin_transaction();

            $admin_id = inloggad_id();
            $stmt = $db->prepare('INSERT INTO nyko_lan (LantagareID, AdministratorID, SlutdatumGiltighet, Status) VALUES (?, ?, ?, ?)');
            $status = 'Aktivt';
            $stmt->bind_param('iiss', $lantagare_id, $admin_id, $slutdatum, $status);
            $stmt->execute();
            $lan_id = $db->insert_id;
            $stmt->close();

            $stmt = $db->prepare(
                'INSERT INTO nyko_lanrad (LanID, NyckelID, KnippaID)
                 SELECT ?, ID, KnippaID FROM nyko_nyckel WHERE ID = ?'
            );
            foreach ($nyckel_ider as $nyckel_id) {
                $stmt->bind_param('ii', $lan_id, $nyckel_id);
                $stmt->execute();
            }
            $stmt->close();

            $db->commit();

            $korg['sparat_lan_id'] = $lan_id;
            satt_flash('ok', 'Lånet sparades (lån-nummer ' . $lan_id . ').');
        } catch (mysqli_sql_exception $e) {
            $db->rollback();
            satt_flash('fel', 'Kunde inte spara lånet: ' . $e->getMessage());
        }
        header('Location: utlaning.php');
        exit;
    }

    if ($action === 'stang' && $ar_sparat) {
        $_SESSION['nytt_lan'] = ['lantagare_id' => null, 'slutdatum' => null, 'poster' => [], 'sparat_lan_id' => null];
        // OBS: pekar tillfälligt på index.php tills aktuella_utlaningar.php är byggd.
        header('Location: index.php');
        exit;
    }
}

require 'includes/header.php';
?>

<?php if ($ar_sparat): ?>

    <?php
    // -----------------------------------------------------------------
    // Låst kvittovy - lånet är sparat, inget går längre att ändra
    // -----------------------------------------------------------------
    $lan_id = $korg['sparat_lan_id'];
    $stmt = $db->prepare(
        'SELECT lan.*, l.Namn AS LantagareNamn, l.Telefonnummer, l.Mejl, l.Personnummer, g.Namn AS GruppNamn,
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

    $grupperat = [];
    foreach ($lanrader as $rad) {
        $grupp = $rad['KnippaNamn'] ?? '__losa__';
        $grupperat[$grupp][] = $rad;
    }
    ?>

    <h1>Lån #<?= (int)$lan['ID'] ?></h1>
    <?php visa_flash(); ?>

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
                    <li><?= h($rad['NyckelID']) ?><?= $rad['Funktion'] ? ' - ' . h($rad['Funktion']) : '' ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </div>

    <div class="no-print">
        <button type="button" class="btn" onclick="window.print()">Skriv ut</button>
        <form method="post" style="display:inline">
            <?= csrf_falt() ?>
            <input type="hidden" name="action" value="stang">
            <button type="submit" class="btn">Stäng</button>
        </form>
    </div>

<?php else: ?>

    <?php
    // -----------------------------------------------------------------
    // Byggvy - lånet håller på att skapas
    // -----------------------------------------------------------------
    $lantagare_lista = $db->query(
        'SELECT l.ID, l.Namn, l.Telefonnummer, l.Avstangd, l.GiltigTill, g.Avstangd AS GruppAvstangd, g.GiltigTill AS GruppGiltigTill
         FROM nyko_lantagare l
         LEFT JOIN nyko_lantagargrupp g ON g.ID = l.LantagargruppID
         ORDER BY l.Namn'
    )->fetch_all(MYSQLI_ASSOC);
    $nyckelskap_lista = $db->query('SELECT ID, Namn FROM nyko_nyckelskap ORDER BY Namn')->fetch_all(MYSQLI_ASSOC);

    $valt_skap = (int)($_GET['skap'] ?? 0);
    $tillgangliga_knippor = [];
    $tillgangliga_nycklar = [];

    if ($valt_skap > 0) {
        $redan_nyckel_ider = korg_nyckel_ider($korg['poster']);
        $redan_knippa_ider = korg_knippa_ider($korg['poster']);

        $stmt = $db->prepare(
            "SELECT k.ID, k.Namn,
                (SELECT COUNT(*) FROM nyko_nyckel n WHERE n.KnippaID = k.ID) AS Totalt,
                (SELECT COUNT(*) FROM nyko_nyckel n WHERE n.KnippaID = k.ID
                    AND EXISTS (SELECT 1 FROM nyko_lanrad lr WHERE lr.NyckelID = n.ID AND lr.Status = 'Utlanad')
                ) AS Utlanade
             FROM nyko_knippa k
             WHERE k.NyckelskapID = ?
             HAVING Totalt > 0 AND Utlanade = 0
             ORDER BY k.Namn"
        );
        $stmt->bind_param('i', $valt_skap);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $rad) {
            if (!in_array((int)$rad['ID'], $redan_knippa_ider, true)) {
                $tillgangliga_knippor[] = $rad;
            }
        }
        $stmt->close();

        $stmt = $db->prepare(
            "SELECT n.ID, n.NyckelID, n.Funktion FROM nyko_nyckel n
             WHERE n.NyckelskapID = ?
             AND NOT EXISTS (SELECT 1 FROM nyko_lanrad lr WHERE lr.NyckelID = n.ID AND lr.Status = 'Utlanad')
             ORDER BY n.NyckelID"
        );
        $stmt->bind_param('i', $valt_skap);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $rad) {
            if (!in_array((int)$rad['ID'], $redan_nyckel_ider, true)) {
                $tillgangliga_nycklar[] = $rad;
            }
        }
        $stmt->close();
    }

    // Lånekorgens innehåll för visning (med namn, inte bara ID)
    $korgrader = [];
    foreach ($korg['poster'] as $index => $post) {
        if ($post['typ'] === 'knippa') {
            $stmt = $db->prepare('SELECT k.Namn, s.Namn AS SkapNamn FROM nyko_knippa k JOIN nyko_nyckelskap s ON s.ID = k.NyckelskapID WHERE k.ID = ?');
            $stmt->bind_param('i', $post['id']);
            $stmt->execute();
            $rad = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $korgrader[] = ['index' => $index, 'text' => 'Knippa: ' . ($rad['Namn'] ?? '?') . ' (' . ($rad['SkapNamn'] ?? '?') . ')'];
        } else {
            $stmt = $db->prepare('SELECT NyckelID FROM nyko_nyckel WHERE ID = ?');
            $stmt->bind_param('i', $post['id']);
            $stmt->execute();
            $rad = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $korgrader[] = ['index' => $index, 'text' => 'Nyckel: ' . ($rad['NyckelID'] ?? '?')];
        }
    }
    ?>

    <h1>Ny utlåning</h1>
    <?php visa_flash(); ?>

    <?php if (!$lantagare_lista): ?>
        <p class="meddelande meddelande-varning">Det finns inga låntagare registrerade än. <a href="lantagare.php">Lägg till en låntagare</a> innan du skapar ett lån.</p>
    <?php endif; ?>

    <h2>1. Välj nyckelskåp</h2>
    <form method="get" class="filterrad">
        <div>
            <label for="skap">Nyckelskåp</label>
            <select id="skap" name="skap">
                <option value="">-- välj --</option>
                <?php foreach ($nyckelskap_lista as $skap): ?>
                    <option value="<?= (int)$skap['ID'] ?>" <?= $valt_skap === (int)$skap['ID'] ? 'selected' : '' ?>><?= h($skap['Namn']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-liten">Visa innehåll</button>
    </form>

    <?php if ($valt_skap > 0): ?>
        <h2>2. Låntagare, slutdatum och vad som ska lånas ut</h2>
        <?php if (!$tillgangliga_knippor && !$tillgangliga_nycklar): ?>
            <p class="hjalptext">Inget ledigt att låna ut i det här skåpet just nu.</p>
        <?php else: ?>
        <form method="post" class="formular">
            <?= csrf_falt() ?>
            <input type="hidden" name="action" value="lagg_till">
            <input type="hidden" name="skap" value="<?= $valt_skap ?>">

            <label for="lantagare_id">Låntagare <span class="faltkrav">*</span></label>
            <select id="lantagare_id" name="lantagare_id" required>
                <option value="">-- välj låntagare --</option>
                <?php foreach ($lantagare_lista as $l): ?>
                    <?php
                    $kombinerat = kombinera_giltighet((bool)$l['Avstangd'], $l['GiltigTill'], (bool)$l['GruppAvstangd'], $l['GruppGiltigTill']);
                    $ej_giltig_text = '';
                    if ($kombinerat['avstangd']) {
                        $ej_giltig_text = ' - AVSTÄNGD';
                    } elseif ($kombinerat['giltig_till'] !== null && $kombinerat['giltig_till'] < date('Y-m-d')) {
                        $ej_giltig_text = ' - giltighet utgången';
                    }
                    ?>
                    <option value="<?= (int)$l['ID'] ?>" <?= (int)($korg['lantagare_id'] ?? 0) === (int)$l['ID'] ? 'selected' : '' ?> <?= $ej_giltig_text ? 'disabled' : '' ?>>
                        <?= h($l['Namn']) ?> (<?= h($l['Telefonnummer']) ?>)<?= $ej_giltig_text ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="slutdatum">Slutdatum <span class="faltkrav">*</span></label>
            <input type="date" id="slutdatum" name="slutdatum" value="<?= h($korg['slutdatum'] ?? '') ?>" required>

            <?php if ($tillgangliga_knippor): ?>
                <h3>Knippor i skåpet</h3>
                <?php foreach ($tillgangliga_knippor as $k): ?>
                    <label style="font-weight:normal;">
                        <input type="checkbox" name="knippa[]" value="<?= (int)$k['ID'] ?>">
                        <?= h($k['Namn']) ?> (<?= (int)$k['Totalt'] ?> nycklar)
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($tillgangliga_nycklar): ?>
                <h3>Lösa nycklar i skåpet</h3>
                <?php foreach ($tillgangliga_nycklar as $n): ?>
                    <label style="font-weight:normal;">
                        <input type="checkbox" name="nyckel[]" value="<?= (int)$n['ID'] ?>">
                        <?= h($n['NyckelID']) ?><?= $n['Funktion'] ? ' - ' . h($n['Funktion']) : '' ?>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>

            <button type="submit" class="btn">Lägg till i lånet</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>

    <h2>Lånekorg (<?= count($korgrader) ?> poster)</h2>
    <?php if (!$korgrader): ?>
        <p class="hjalptext">Inget tillagt än. Välj ett nyckelskåp ovan för att börja lägga till nycklar/knippor.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Post</th><th>Åtgärd</th></tr></thead>
            <tbody>
                <?php foreach ($korgrader as $rad): ?>
                    <tr>
                        <td><?= h($rad['text']) ?></td>
                        <td>
                            <form method="post" style="display:inline">
                                <?= csrf_falt() ?>
                                <input type="hidden" name="action" value="ta_bort_post">
                                <input type="hidden" name="index" value="<?= (int)$rad['index'] ?>">
                                <button type="submit" class="btn btn-liten btn-fara">Ta bort</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p>
        <form method="post" style="display:inline">
            <?= csrf_falt() ?>
            <input type="hidden" name="action" value="spara">
            <button type="submit" class="btn">Spara lånet</button>
        </form>
        &nbsp;
        <form method="post" style="display:inline" onsubmit="return confirm('Avbryta hela lånet? Inget kommer sparas.');">
            <?= csrf_falt() ?>
            <input type="hidden" name="action" value="avbryt">
            <button type="submit" class="btn btn-fara">Avbryt</button>
        </form>
    </p>

<?php endif; ?>

<?php require 'includes/footer.php'; ?>
