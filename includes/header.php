<?php
// Förväntar sig att config.php redan är inkluderad och att $sidtitel ev. är satt.
$sidtitel = $sidtitel ?? 'NyckelKoll';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($sidtitel) ?> :: NyckelKoll</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="skarm">

<header class="topp">
    <div class="topp-vanster">
        <a href="index.php" class="logo">NyckelKoll<span class="logo-blink">_</span></a>
        <span class="intern-varning">※ EJ EXPONERAD MOT INTERNET ※</span>
    </div>
    <div class="topp-hoger">
        <?php if (inloggad()): ?>
            <span class="inloggad-som">Inloggad: <?= h(inloggad_namn()) ?></span>
            <a href="logout.php" class="btn btn-liten">Logga ut</a>
        <?php else: ?>
            <a href="login.php" class="btn btn-liten">Logga in</a>
        <?php endif; ?>
    </div>
</header>

<nav class="menyrader">
    <div class="menyrad">
        <a href="index.php">Oversikt</a>
        <a href="utlaning.php">Ny utlaning</a>
        <a href="aktuella_utlaningar.php">Aktuella utlaningar</a>
        <a href="aterlamning.php">Aterlamning</a>
        <a href="avvikelser.php">Avvikelser</a>
        <a href="ej_aterlamnade.php">Ej aterlamnade i tid</a>
    </div>
    <div class="menyrad">
        <a href="nyckelsystem.php">Nyckelsystem</a>
        <a href="nyckelskap.php">Nyckelskap</a>
        <a href="knippor.php">Knippor</a>
        <a href="nycklar.php">Nycklar</a>
        <a href="lantagare.php">Lantagare</a>
        <a href="lantagargrupper.php">Lantagargrupper</a>
        <?php if (inloggad()): ?>
            <a href="installningar.php">Installningar</a>
            <a href="import_export.php">Import/Export</a>
        <?php endif; ?>
    </div>
</nav>

<main class="innehall">
