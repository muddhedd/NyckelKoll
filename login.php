<?php
require_once 'config.php';

$fel = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifiera();

    $anvandarnamn = trim($_POST['anvandarnamn'] ?? '');
    $losenord = (string)($_POST['losenord'] ?? '');

    if ($anvandarnamn === '' || $losenord === '') {
        $fel = 'Fyll i bade anvandarnamn och losenord.';
    } else {
        $stmt = $db->prepare('SELECT ID, Anvandarnamn, LosenordHash FROM nyko_anvandare WHERE Anvandarnamn = ?');
        $stmt->bind_param('s', $anvandarnamn);
        $stmt->execute();
        $rad = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($rad && password_verify($losenord, $rad['LosenordHash'])) {
            session_regenerate_id(true);
            $_SESSION['anvandare_id'] = (int)$rad['ID'];
            $_SESSION['anvandarnamn'] = $rad['Anvandarnamn'];
            header('Location: index.php');
            exit;
        }
        $fel = 'Fel anvandarnamn eller losenord.';
    }
}

if (inloggad()) {
    header('Location: index.php');
    exit;
}

$sidtitel = 'Logga in';
require 'includes/header.php';
?>

<h1>Logga in</h1>

<?php if ($fel !== ''): ?>
    <p class="meddelande meddelande-fel"><?= h($fel) ?></p>
<?php endif; ?>

<form method="post" class="formular formular-smal">
    <?= csrf_falt() ?>

    <label for="anvandarnamn">Anvandarnamn</label>
    <input type="text" id="anvandarnamn" name="anvandarnamn" autocomplete="username" required autofocus>

    <label for="losenord">Losenord</label>
    <input type="password" id="losenord" name="losenord" autocomplete="current-password" required>

    <button type="submit" class="btn">Logga in</button>
</form>

<p class="hjalptext">
    Alla kan lasa informationen i NyckelKoll utan att logga in.
    Inloggning kravs endast for att lagga till, andra, importera eller
    hantera utlaning/aterlamning.
</p>

<?php require 'includes/footer.php'; ?>
