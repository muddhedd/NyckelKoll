<?php
require_once 'config.php';

$sidtitel = 'Oversikt';

$antal_nyckelskap = (int)$db->query('SELECT COUNT(*) AS n FROM nyko_nyckelskap')->fetch_assoc()['n'];
$antal_knippor = (int)$db->query('SELECT COUNT(*) AS n FROM nyko_knippa')->fetch_assoc()['n'];
$antal_nycklar = (int)$db->query('SELECT COUNT(*) AS n FROM nyko_nyckel')->fetch_assoc()['n'];
$antal_lantagare = (int)$db->query('SELECT COUNT(*) AS n FROM nyko_lantagare')->fetch_assoc()['n'];

$antal_aktiva_lan = (int)$db->query(
    "SELECT COUNT(*) AS n FROM nyko_lan WHERE Status != 'Helt aterlamnat'"
)->fetch_assoc()['n'];

$antal_forfallna = (int)$db->query(
    "SELECT COUNT(*) AS n FROM nyko_lan WHERE Status != 'Helt aterlamnat' AND SlutdatumGiltighet < CURDATE()"
)->fetch_assoc()['n'];

$antal_avvikelser = (int)$db->query(
    'SELECT COUNT(*) AS n FROM nyko_knippaavvikelse WHERE Atgardad = 0'
)->fetch_assoc()['n'];

require 'includes/header.php';
?>

<h1>Oversikt</h1>

<?php if ($antal_forfallna > 0): ?>
    <p class="meddelande meddelande-varning">
        &#9888; <strong><?= $antal_forfallna ?></strong> lan har passerat sitt slutdatum utan att vara helt aterlamnade.
        <a href="ej_aterlamnade.php">Visa ej aterlamnade lan &rarr;</a>
    </p>
<?php endif; ?>

<?php if ($antal_avvikelser > 0): ?>
    <p class="meddelande meddelande-varning">
        &#9888; <strong><?= $antal_avvikelser ?></strong> oatgardade avvikelser pa knippor.
        <a href="avvikelser.php">Visa avvikelser &rarr;</a>
    </p>
<?php endif; ?>

<div class="kort-rad">
    <div class="kort">
        <span class="kort-siffra"><?= $antal_nyckelskap ?></span>
        <span class="kort-etikett">Nyckelskap</span>
    </div>
    <div class="kort">
        <span class="kort-siffra"><?= $antal_knippor ?></span>
        <span class="kort-etikett">Knippor</span>
    </div>
    <div class="kort">
        <span class="kort-siffra"><?= $antal_nycklar ?></span>
        <span class="kort-etikett">Nycklar</span>
    </div>
    <div class="kort">
        <span class="kort-siffra"><?= $antal_lantagare ?></span>
        <span class="kort-etikett">Lantagare</span>
    </div>
    <div class="kort">
        <span class="kort-siffra"><?= $antal_aktiva_lan ?></span>
        <span class="kort-etikett">Aktiva lan</span>
    </div>
    <div class="kort <?= $antal_forfallna > 0 ? 'kort-varning' : '' ?>">
        <span class="kort-siffra"><?= $antal_forfallna ?></span>
        <span class="kort-etikett">Ej aterlamnade</span>
    </div>
</div>

<p class="hjalptext">
    Alla register ar lasbara har utan inloggning. Logga in for att lagga till,
    andra, importera/exportera eller hantera utlaning och aterlamning.
</p>

<?php require 'includes/footer.php'; ?>
