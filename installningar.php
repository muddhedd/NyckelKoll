<?php
require_once 'config.php';

$sidtitel = 'Inställningar';

if (!inloggad()) {
    require 'includes/header.php';
    echo '<h1>Inställningar</h1><p class="meddelande meddelande-fel">Du måste vara inloggad för att se den här sidan. <a href="login.php">Logga in</a></p>';
    require 'includes/footer.php';
    exit;
}

// Etiketter för fältnamnen i den tvingande-fält-listan (samma fältnamn
// återanvänds i flera tabeller, så en gemensam uppslagslista räcker).
$faltetiketter = [
    'NyckelsystemID' => 'Nyckelsystem-ID',
    'Installationsar' => 'Installationsår',
    'Garantitid' => 'Garantitid',
    'Garantipart' => 'Garantipart',
    'Servicestation' => 'Servicestation',
    'ForvantadLivslangd' => 'Förväntad teknisk livslängd',
    'Placering' => 'Placering',
    'Namn' => 'Namn',
    'Funktion' => 'Funktion',
    'Kod' => 'Kod',
    'NyckelID' => 'Nyckel-ID',
    'Telefonnummer' => 'Telefonnummer',
    'Mejl' => 'Mejl',
    'Personnummer' => 'Personnummer',
    'Kontaktperson' => 'Kontaktperson',
    'Organisationsnummer' => 'Organisationsnummer',
];
$tabellrubriker = [
    'Nyckelsystem' => 'Nyckelsystem',
    'Nyckelskap' => 'Nyckelskåp',
    'Knippa' => 'Knippor',
    'Nyckel' => 'Nycklar',
    'Lantagare' => 'Låntagare',
    'Lantagargrupp' => 'Låntagargrupper',
];

// -----------------------------------------------------------------
// Hantera POST
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifiera();
    krav_inloggning();

    $action = $_POST['action'] ?? '';

    if ($action === 'satt_api_losenord') {
        $nytt = p('api_losenord');
        $bekraft = p('api_losenord_bekraft');

        $fel = [];
        if (strlen($nytt) < 8) {
            $fel[] = 'API-lösenordet måste vara minst 8 tecken.';
        }
        if ($nytt !== $bekraft) {
            $fel[] = 'De två fälten matchar inte.';
        }

        if ($fel) {
            satt_flash('fel', implode(' ', $fel));
        } else {
            spara_systeminstallning('api_losenord_hash', password_hash($nytt, PASSWORD_BCRYPT));
            satt_flash('ok', 'API-lösenordet har satts/bytts. Kom ihåg att uppdatera alla system som anropar API:erna.');
        }
        header('Location: installningar.php');
        exit;
    }

    if ($action === 'spara_integration') {
        $integration = $_POST['integration'] ?? '';
        if (!in_array($integration, ['avtalskatalog', 'fastighetsteknik'], true)) {
            satt_flash('fel', 'Okänd integration.');
            header('Location: installningar.php');
            exit;
        }
        spara_systeminstallning($integration . '_aktiverad', isset($_POST['aktiverad']) ? '1' : '0');
        spara_systeminstallning($integration . '_url', p($integration . '_url'));
        // Lösenordet skrivs bara över om ett nytt faktiskt angetts, så man
        // inte råkar radera det befintliga bara genom att spara sidan igen.
        $nytt_losenord = p($integration . '_losenord');
        if ($nytt_losenord !== '') {
            spara_systeminstallning($integration . '_losenord', $nytt_losenord);
        }
        satt_flash('ok', 'Integrationsinställningarna sparades.');
        header('Location: installningar.php');
        exit;
    }

    if ($action === 'lagg_till_anvandare') {
        $nytt_anvandarnamn = p('anvandarnamn');
        $nytt_losenord = p('losenord');
        $bekraft = p('losenord_bekraft');

        $fel = [];
        if ($nytt_anvandarnamn === '') {
            $fel[] = 'Ange ett användarnamn.';
        }
        if (strlen($nytt_losenord) < 6) {
            $fel[] = 'Lösenordet måste vara minst 6 tecken.';
        }
        if ($nytt_losenord !== $bekraft) {
            $fel[] = 'De två lösenordsfälten matchar inte.';
        }

        if ($fel) {
            satt_flash('fel', implode(' ', $fel));
        } else {
            try {
                $hash = password_hash($nytt_losenord, PASSWORD_BCRYPT);
                $stmt = $db->prepare('INSERT INTO nyko_anvandare (Anvandarnamn, LosenordHash) VALUES (?, ?)');
                $stmt->bind_param('ss', $nytt_anvandarnamn, $hash);
                $stmt->execute();
                $stmt->close();
                satt_flash('ok', 'Användaren "' . $nytt_anvandarnamn . '" skapades.');
            } catch (mysqli_sql_exception $e) {
                $meddelande = $e->getCode() === 1062
                    ? 'Det finns redan en användare med det användarnamnet.'
                    : 'Kunde inte skapa användaren: ' . $e->getMessage();
                satt_flash('fel', $meddelande);
            }
        }
        header('Location: installningar.php');
        exit;
    }

    if ($action === 'byt_losenord') {
        $nuvarande = p('nuvarande_losenord');
        $nytt = p('nytt_losenord');
        $bekraft = p('nytt_losenord_bekraft');

        $stmt = $db->prepare('SELECT LosenordHash FROM nyko_anvandare WHERE ID = ?');
        $admin_id = inloggad_id();
        $stmt->bind_param('i', $admin_id);
        $stmt->execute();
        $rad = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $fel = [];
        if (!$rad || !password_verify($nuvarande, $rad['LosenordHash'])) {
            $fel[] = 'Nuvarande lösenord stämmer inte.';
        }
        if (strlen($nytt) < 6) {
            $fel[] = 'Det nya lösenordet måste vara minst 6 tecken.';
        }
        if ($nytt !== $bekraft) {
            $fel[] = 'De två nya lösenordsfälten matchar inte.';
        }

        if ($fel) {
            satt_flash('fel', implode(' ', $fel));
        } else {
            $hash = password_hash($nytt, PASSWORD_BCRYPT);
            $stmt = $db->prepare('UPDATE nyko_anvandare SET LosenordHash = ? WHERE ID = ?');
            $stmt->bind_param('si', $hash, $admin_id);
            $stmt->execute();
            $stmt->close();
            satt_flash('ok', 'Ditt lösenord har bytts.');
        }
        header('Location: installningar.php');
        exit;
    }

    if ($action === 'byt_annans_losenord') {
        $mal_id = (int)($_POST['id'] ?? 0);
        $nytt = p('nytt_losenord');
        $bekraft = p('nytt_losenord_bekraft');

        $stmt = $db->prepare('SELECT Anvandarnamn FROM nyko_anvandare WHERE ID = ?');
        $stmt->bind_param('i', $mal_id);
        $stmt->execute();
        $mal = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $fel = [];
        if (!$mal) {
            $fel[] = 'Användaren hittades inte.';
        }
        if (strlen($nytt) < 6) {
            $fel[] = 'Lösenordet måste vara minst 6 tecken.';
        }
        if ($nytt !== $bekraft) {
            $fel[] = 'De två lösenordsfälten matchar inte.';
        }

        if ($fel) {
            satt_flash('fel', implode(' ', $fel));
            header('Location: installningar.php?byt_losenord=' . $mal_id);
            exit;
        }

        $hash = password_hash($nytt, PASSWORD_BCRYPT);
        $stmt = $db->prepare('UPDATE nyko_anvandare SET LosenordHash = ? WHERE ID = ?');
        $stmt->bind_param('si', $hash, $mal_id);
        $stmt->execute();
        $stmt->close();
        satt_flash('ok', 'Lösenordet för "' . $mal['Anvandarnamn'] . '" har bytts.');
        header('Location: installningar.php');
        exit;
    }

    if ($action === 'ta_bort_anvandare') {
        $mal_id = (int)($_POST['id'] ?? 0);
        $admin_id = inloggad_id();

        if ($mal_id === $admin_id) {
            satt_flash('fel', 'Du kan inte ta bort ditt eget konto medan du är inloggad på det.');
            header('Location: installningar.php');
            exit;
        }

        $antal = (int)$db->query('SELECT COUNT(*) AS n FROM nyko_anvandare')->fetch_assoc()['n'];
        if ($antal <= 1) {
            satt_flash('fel', 'Går inte att ta bort - det måste finnas minst en användare kvar.');
            header('Location: installningar.php');
            exit;
        }

        $stmt = $db->prepare('SELECT Anvandarnamn FROM nyko_anvandare WHERE ID = ?');
        $stmt->bind_param('i', $mal_id);
        $stmt->execute();
        $mal = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $resultat = sakert_ta_bort('nyko_anvandare', $mal_id);
        if ($resultat['ok']) {
            satt_flash('ok', 'Användaren "' . ($mal['Anvandarnamn'] ?? '') . '" togs bort.');
        } else {
            satt_flash('fel', $resultat['meddelande']);
        }
        header('Location: installningar.php');
        exit;
    }

    if ($action === 'spara_falt') {
        $tabell = $_POST['tabell'] ?? '';
        if (!array_key_exists($tabell, $tabellrubriker)) {
            satt_flash('fel', 'Okänt register.');
            header('Location: installningar.php');
            exit;
        }

        $ikryssade = $_POST['falt'] ?? [];

        $stmt = $db->prepare('SELECT Falt FROM nyko_faltinstallningar WHERE Tabell = ?');
        $stmt->bind_param('s', $tabell);
        $stmt->execute();
        $alla_falt = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Falt');
        $stmt->close();

        $stmt = $db->prepare('UPDATE nyko_faltinstallningar SET Obligatoriskt = ? WHERE Tabell = ? AND Falt = ?');
        foreach ($alla_falt as $falt) {
            $obligatoriskt = in_array($falt, $ikryssade, true) ? 1 : 0;
            $stmt->bind_param('iss', $obligatoriskt, $tabell, $falt);
            $stmt->execute();
        }
        $stmt->close();

        satt_flash('ok', 'Obligatoriska fält för ' . $tabellrubriker[$tabell] . ' sparades.');
        header('Location: installningar.php');
        exit;
    }
}

// -----------------------------------------------------------------
// Data för visning
// -----------------------------------------------------------------
$anvandare_lista = $db->query('SELECT ID, Anvandarnamn, Skapad FROM nyko_anvandare ORDER BY Anvandarnamn')->fetch_all(MYSQLI_ASSOC);

$falt_per_tabell = [];
foreach (array_keys($tabellrubriker) as $tabell) {
    $stmt = $db->prepare('SELECT Falt, Obligatoriskt FROM nyko_faltinstallningar WHERE Tabell = ? ORDER BY ID');
    $stmt->bind_param('s', $tabell);
    $stmt->execute();
    $falt_per_tabell[$tabell] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$systeminstallningar = hamta_systeminstallningar();

$byt_losenord_for = null;
if (isset($_GET['byt_losenord'])) {
    $byt_id = (int)$_GET['byt_losenord'];
    $stmt = $db->prepare('SELECT ID, Anvandarnamn FROM nyko_anvandare WHERE ID = ?');
    $stmt->bind_param('i', $byt_id);
    $stmt->execute();
    $byt_losenord_for = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

require 'includes/header.php';
?>

<h1>Inställningar</h1>
<?php visa_flash(); ?>

<h2>Användare</h2>

<h3>Befintliga användare</h3>
<table>
    <thead><tr><th>Användarnamn</th><th>Skapad</th><th>Åtgärd</th></tr></thead>
    <tbody>
        <?php foreach ($anvandare_lista as $u): ?>
            <tr>
                <td><?= h($u['Anvandarnamn']) ?><?= (int)$u['ID'] === inloggad_id() ? ' (du)' : '' ?></td>
                <td><?= h($u['Skapad']) ?></td>
                <td>
                    <?php if ((int)$u['ID'] === inloggad_id()): ?>
                        <span class="hjalptext">Byt ditt eget lösenord nedan</span>
                    <?php else: ?>
                        <a href="installningar.php?byt_losenord=<?= (int)$u['ID'] ?>" class="btn btn-liten">Byt lösenord</a>
                        &nbsp;
                        <form method="post" style="display:inline" onsubmit="return confirm('Ta bort användaren &quot;<?= h(addslashes($u['Anvandarnamn'])) ?>&quot;? Går inte att ångra.');">
                            <?= csrf_falt() ?>
                            <input type="hidden" name="action" value="ta_bort_anvandare">
                            <input type="hidden" name="id" value="<?= (int)$u['ID'] ?>">
                            <button type="submit" class="btn btn-liten btn-fara">Ta bort</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if ($byt_losenord_for): ?>
    <h3>Byt lösenord för "<?= h($byt_losenord_for['Anvandarnamn']) ?>"</h3>
    <p class="hjalptext">Som inloggad administratör behöver du inte ange användarens nuvarande lösenord.</p>
    <form method="post" class="formular formular-smal" onsubmit="return confirm('Byta lösenord för <?= h(addslashes($byt_losenord_for['Anvandarnamn'])) ?>?');">
        <?= csrf_falt() ?>
        <input type="hidden" name="action" value="byt_annans_losenord">
        <input type="hidden" name="id" value="<?= (int)$byt_losenord_for['ID'] ?>">

        <label for="nytt_losenord_annan">Nytt lösenord (minst 6 tecken)</label>
        <input type="password" id="nytt_losenord_annan" name="nytt_losenord" required>

        <label for="nytt_losenord_annan_bekraft">Bekräfta nytt lösenord</label>
        <input type="password" id="nytt_losenord_annan_bekraft" name="nytt_losenord_bekraft" required>

        <button type="submit" class="btn">Byt lösenord</button>
    </form>
<?php endif; ?>

<h3>Lägg till ny användare</h3>
<form method="post" class="formular formular-smal">
    <?= csrf_falt() ?>
    <input type="hidden" name="action" value="lagg_till_anvandare">

    <label for="anvandarnamn">Användarnamn</label>
    <input type="text" id="anvandarnamn" name="anvandarnamn" required>

    <label for="losenord">Lösenord (minst 6 tecken)</label>
    <input type="password" id="losenord" name="losenord" required>

    <label for="losenord_bekraft">Bekräfta lösenord</label>
    <input type="password" id="losenord_bekraft" name="losenord_bekraft" required>

    <button type="submit" class="btn">Skapa användare</button>
</form>

<h3>Byt ditt eget lösenord</h3>
<form method="post" class="formular formular-smal">
    <?= csrf_falt() ?>
    <input type="hidden" name="action" value="byt_losenord">

    <label for="nuvarande_losenord">Nuvarande lösenord</label>
    <input type="password" id="nuvarande_losenord" name="nuvarande_losenord" required>

    <label for="nytt_losenord">Nytt lösenord (minst 6 tecken)</label>
    <input type="password" id="nytt_losenord" name="nytt_losenord" required>

    <label for="nytt_losenord_bekraft">Bekräfta nytt lösenord</label>
    <input type="password" id="nytt_losenord_bekraft" name="nytt_losenord_bekraft" required>

    <button type="submit" class="btn">Byt lösenord</button>
</form>

<h2>Integrationer</h2>
<p class="hjalptext">
    Kopplingar mot externa system. Systemen nedan är förberedda med fält
    och inställningar, men själva anropen väntar på att respektive system
    ska byggas färdigt - just nu ger "Hämta"-knapparna ute i registren
    ett tydligt felmeddelande istället för riktig data.
</p>

<h3>Avtalskatalog</h3>
<p class="hjalptext">
    Kopplas mot Avtalsnummer på <a href="lantagargrupper.php">Låntagargrupper</a>
    för att kunna hämta gruppens giltighetstid automatiskt.
</p>
<form method="post" class="formular formular-smal">
    <?= csrf_falt() ?>
    <input type="hidden" name="action" value="spara_integration">
    <input type="hidden" name="integration" value="avtalskatalog">

    <label style="font-weight:normal;">
        <input type="checkbox" name="aktiverad" <?= !empty($systeminstallningar['avtalskatalog_aktiverad']) ? 'checked' : '' ?>>
        Aktiverad
    </label>

    <label for="avtalskatalog_url">URL</label>
    <input type="text" id="avtalskatalog_url" name="avtalskatalog_url" value="<?= h($systeminstallningar['avtalskatalog_url'] ?? '') ?>" placeholder="https://...">

    <label for="avtalskatalog_losenord">Lösenord</label>
    <input type="password" id="avtalskatalog_losenord" name="avtalskatalog_losenord" placeholder="<?= !empty($systeminstallningar['avtalskatalog_losenord']) ? '(sparat - lämna tomt för att behålla)' : '' ?>">

    <button type="submit" class="btn btn-liten">Spara</button>
</form>

<h3>Förvaltning av Fastighetsteknik</h3>
<p class="hjalptext">
    Kopplas mot Fastighetsnummer på <a href="nyckelsystem.php">Nyckelsystem</a>
    för att kunna hämta fastighetsinformation automatiskt.
</p>
<form method="post" class="formular formular-smal">
    <?= csrf_falt() ?>
    <input type="hidden" name="action" value="spara_integration">
    <input type="hidden" name="integration" value="fastighetsteknik">

    <label style="font-weight:normal;">
        <input type="checkbox" name="aktiverad" <?= !empty($systeminstallningar['fastighetsteknik_aktiverad']) ? 'checked' : '' ?>>
        Aktiverad
    </label>

    <label for="fastighetsteknik_url">URL</label>
    <input type="text" id="fastighetsteknik_url" name="fastighetsteknik_url" value="<?= h($systeminstallningar['fastighetsteknik_url'] ?? '') ?>" placeholder="https://...">

    <label for="fastighetsteknik_losenord">Lösenord</label>
    <input type="password" id="fastighetsteknik_losenord" name="fastighetsteknik_losenord" placeholder="<?= !empty($systeminstallningar['fastighetsteknik_losenord']) ? '(sparat - lämna tomt för att behålla)' : '' ?>">

    <button type="submit" class="btn btn-liten">Spara</button>
</form>

<h2>API-åtkomst</h2>
<p class="hjalptext">
    Fyra enkla läs-API:er finns för att hämta ut information från NyckelKoll
    till andra system: <code>api_nyckelsystem.php</code>,
    <code>api_nycklar.php</code>, <code>api_lantagare.php</code> och
    <code>api_utlaningar.php</code> (aktuella utlåningar). Alla fyra delar
    samma lösenord och svarar med JSON.
</p>
<p class="hjalptext">
    Anropa dem antingen med headern <code>X-Api-Key: ditt-losenord</code>,
    eller med query-parametern <code>?losenord=ditt-losenord</code>. Är
    inget API-lösenord satt (som direkt efter installation) är API:erna
    avstängda och svarar alltid med fel.
</p>
<form method="post" class="formular formular-smal">
    <?= csrf_falt() ?>
    <input type="hidden" name="action" value="satt_api_losenord">

    <label for="api_losenord">
        <?= empty($systeminstallningar['api_losenord_hash']) ? 'Sätt API-lösenord' : 'Byt API-lösenord (minst 8 tecken)' ?>
    </label>
    <input type="password" id="api_losenord" name="api_losenord">

    <label for="api_losenord_bekraft">Bekräfta</label>
    <input type="password" id="api_losenord_bekraft" name="api_losenord_bekraft">

    <button type="submit" class="btn btn-liten"><?= empty($systeminstallningar['api_losenord_hash']) ? 'Sätt lösenord' : 'Byt lösenord' ?></button>
</form>
<p class="hjalptext">
    <?= empty($systeminstallningar['api_losenord_hash']) ? 'Inget API-lösenord är satt just nu - API:erna svarar med fel tills ett satts.' : 'Ett API-lösenord är satt. Lösenordet visas aldrig igen efter att det sparats - byt till ett nytt om du glömt det.' ?>
</p>

<h2>Obligatoriska fält per register</h2>
<p class="hjalptext">
    Ikryssade fält måste fyllas i vid registrering/import i respektive
    register. ID-fält, lån-nummer och strukturella kopplingar (t.ex.
    vilket nyckelskåp en knippa står i) är alltid obligatoriska och listas
    inte här.
</p>

<?php foreach ($falt_per_tabell as $tabell => $falt_rader): ?>
    <h3><?= h($tabellrubriker[$tabell]) ?></h3>
    <form method="post">
        <?= csrf_falt() ?>
        <input type="hidden" name="action" value="spara_falt">
        <input type="hidden" name="tabell" value="<?= h($tabell) ?>">

        <?php foreach ($falt_rader as $falt_rad): ?>
            <label style="font-weight:normal; display:block;">
                <input type="checkbox" name="falt[]" value="<?= h($falt_rad['Falt']) ?>" <?= $falt_rad['Obligatoriskt'] ? 'checked' : '' ?>>
                <?= h($faltetiketter[$falt_rad['Falt']] ?? $falt_rad['Falt']) ?>
            </label>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-liten" style="margin-top:10px;">Spara</button>
    </form>
<?php endforeach; ?>

<?php require 'includes/footer.php'; ?>
