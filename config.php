<?php
/**
 * config.php
 * Databaskoppling, sessionshantering och delade hjälpfunktioner för NyckelKoll.
 * Denna fil inkluderas allra först i varje sida.
 */

// ---------------------------------------------------------------------
// Databasuppgifter - fyll i dessa för din interna server
// ---------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'nyckelkoll');
define('DB_USER', 'andra_dessa');
define('DB_PASS', 'andra_dessa');

// ---------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------
// Databaskoppling (mysqli, prepared statements används genomgående)
// ---------------------------------------------------------------------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    die('Databasanslutning misslyckades. Kontrollera uppgifterna i config.php. (' . htmlspecialchars($e->getMessage()) . ')');
}

// ---------------------------------------------------------------------
// Hjälpfunktioner
// ---------------------------------------------------------------------

/** Escape:ar text för säker utskrift i HTML. */
function h(?string $text): string
{
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

/** True om en användare är inloggad. */
function inloggad(): bool
{
    return isset($_SESSION['anvandare_id']);
}

/** Avbryt sidan med 403 om ingen är inloggad. Används i action-filer. */
function krav_inloggning(): void
{
    if (!inloggad()) {
        http_response_code(403);
        die('Du måste vara inloggad för att göra detta.');
    }
}

/** Namnet på den inloggade användaren, eller null. */
function inloggad_namn(): ?string
{
    return $_SESSION['anvandarnamn'] ?? null;
}

/** Id på den inloggade användaren, eller null. */
function inloggad_id(): ?int
{
    return $_SESSION['anvandare_id'] ?? null;
}

/**
 * Maskerar känsliga fält (t.ex. personnummer, kod) för den som inte
 * är inloggad. Returnerar värdet oförändrat om inloggad.
 */
function dolj_om_utloggad(?string $varde): string
{
    if (inloggad()) {
        return h($varde);
    }
    if ($varde === null || $varde === '') {
        return '';
    }
    return '••••••';
}

/** Enkel CSRF-token-hantering för formulär som ändrar data. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_falt(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_verifiera(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Ogiltig formulärsession (CSRF). Ladda om sidan och försök igen.');
    }
}

// ---------------------------------------------------------------------
// Flash-meddelanden (visas en gång efter en redirect, t.ex. efter spara)
// ---------------------------------------------------------------------

function satt_flash(string $typ, string $text): void
{
    $_SESSION['flash'] = ['typ' => $typ, 'text' => $text];
}

function hamta_flash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/** Skriver ut ev. flash-meddelande direkt (fel/varning/ok). */
function visa_flash(): void
{
    $flash = hamta_flash();
    if (!$flash) {
        return;
    }
    $klass = match ($flash['typ']) {
        'fel' => 'meddelande-fel',
        'varning' => 'meddelande-varning',
        default => 'meddelande-ok',
    };
    echo '<p class="meddelande ' . $klass . '">' . h($flash['text']) . '</p>';
}

// ---------------------------------------------------------------------
// Fältinställningar (styr vilka falt som ar obligatoriska per register)
// ---------------------------------------------------------------------

/** Hämtar fält => obligatoriskt (bool) för en given tabell (Tabell-kolumnen i nyko_faltinstallningar). */
function hamta_faltinstallningar(string $tabell): array
{
    global $db;
    $resultat = [];
    $stmt = $db->prepare('SELECT Falt, Obligatoriskt FROM nyko_faltinstallningar WHERE Tabell = ?');
    $stmt->bind_param('s', $tabell);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($rad = $res->fetch_assoc()) {
        $resultat[$rad['Falt']] = (bool)$rad['Obligatoriskt'];
    }
    $stmt->close();
    return $resultat;
}

/** HTML-attribut 'required' om fältet är obligatoriskt enligt installningarna. */
function kravattribut(array $fi, string $falt): string
{
    return !empty($fi[$falt]) ? 'required' : '';
}

/** Liten röd stjärna om fältet är obligatoriskt enligt installningarna. */
function kravstjarna(array $fi, string $falt): string
{
    return !empty($fi[$falt]) ? ' <span class="faltkrav">*</span>' : '';
}

// ---------------------------------------------------------------------
// POST-hjälpare
// ---------------------------------------------------------------------

/** Trimmat strängvärde från POST, eller tom sträng. */
function p(string $namn): string
{
    return trim((string)($_POST[$namn] ?? ''));
}

/** Trimmat strängvärde från POST, eller null om tomt (för valfria/numeriska falt). */
function p_null(string $namn): ?string
{
    $varde = trim((string)($_POST[$namn] ?? ''));
    return $varde === '' ? null : $varde;
}

// ---------------------------------------------------------------------
// Sök, filtrering och sortering i listor
// ---------------------------------------------------------------------

/**
 * Bygger ett SQL-villkor för fritextsökning över flera kolumner.
 * Returnerar ['sql' => string, 'typer' => string, 'varden' => array].
 * 'sql' är '' om söktermen är tom (inget villkor att lägga till då).
 */
function sok_villkor(array $kolumner, string $term): array
{
    $term = trim($term);
    if ($term === '' || !$kolumner) {
        return ['sql' => '', 'typer' => '', 'varden' => []];
    }
    $delar = [];
    $typer = '';
    $varden = [];
    $liketerm = '%' . $term . '%';
    foreach ($kolumner as $kolumn) {
        $delar[] = "$kolumn LIKE ?";
        $typer .= 's';
        $varden[] = $liketerm;
    }
    return ['sql' => '(' . implode(' OR ', $delar) . ')', 'typer' => $typer, 'varden' => $varden];
}

/**
 * Validerar sorteringsval från $_GET mot en vitlista (nyckel => faktiskt
 * SQL-uttryck att sortera på). Skyddar mot SQL-injektion via query-strängen.
 * Returnerar [sql_uttryck, 'ASC'|'DESC', validerad_nyckel].
 */
function sakerstall_sortering(array $tillatna, string $standard): array
{
    $falt = $_GET['sortera'] ?? $standard;
    if (!array_key_exists($falt, $tillatna)) {
        $falt = $standard;
    }
    $riktning = (isset($_GET['riktning']) && $_GET['riktning'] === 'desc') ? 'DESC' : 'ASC';
    return [$tillatna[$falt], $riktning, $falt];
}

/**
 * Skriver ut en klickbar kolumnrubrik som växlar sorteringsriktning och
 * behåller övriga aktiva query-parametrar (sök, filter). Döljer alltid
 * en ev. öppen redigeringsvy när man sorterar om.
 */
function sorteringshuvud(string $faltnyckel, string $etikett): string
{
    $nuvarande = $_GET['sortera'] ?? '';
    $riktning = (isset($_GET['riktning']) && $_GET['riktning'] === 'desc') ? 'desc' : 'asc';
    $ny_riktning = ($nuvarande === $faltnyckel && $riktning === 'asc') ? 'desc' : 'asc';

    $parametrar = $_GET;
    unset($parametrar['redigera']);
    $parametrar['sortera'] = $faltnyckel;
    $parametrar['riktning'] = $ny_riktning;

    $pil = '';
    if ($nuvarande === $faltnyckel) {
        $pil = $riktning === 'asc' ? ' &#9650;' : ' &#9660;';
    }

    return '<a href="?' . h(http_build_query($parametrar)) . '">' . h($etikett) . '</a>' . $pil;
}

// ---------------------------------------------------------------------
// Låntagarens giltighet (avstängd / giltig-till) - kombinerar personens
// egna fält med dennes låntagargrupp. Mest restriktiva vinner: avstängd
// om ENTINGEN person eller grupp är avstängd, tidigaste giltig-till-
// datumet gäller.
// ---------------------------------------------------------------------

/** Ren kombinationslogik, utan databasanrop - återanvänds av både listvyer och spärren. */
function kombinera_giltighet(bool $person_avstangd, ?string $person_datum, bool $grupp_avstangd, ?string $grupp_datum): array
{
    $avstangd = $person_avstangd || $grupp_avstangd;
    if ($person_datum !== null && $grupp_datum !== null) {
        $giltig_till = min($person_datum, $grupp_datum);
    } else {
        $giltig_till = $person_datum ?? $grupp_datum;
    }
    return ['avstangd' => $avstangd, 'giltig_till' => $giltig_till];
}

/**
 * Avgör om en låntagare får låna ut nycklar just nu.
 * Returnerar ['giltig' => bool, 'anledning' => ?string].
 */
function lantagare_ar_giltig(int $lantagare_id): array
{
    global $db;
    $stmt = $db->prepare(
        'SELECT l.Avstangd AS PersonAvstangd, l.GiltigTill AS PersonGiltigTill,
            g.Avstangd AS GruppAvstangd, g.GiltigTill AS GruppGiltigTill
         FROM nyko_lantagare l
         LEFT JOIN nyko_lantagargrupp g ON g.ID = l.LantagargruppID
         WHERE l.ID = ?'
    );
    $stmt->bind_param('i', $lantagare_id);
    $stmt->execute();
    $rad = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rad) {
        return ['giltig' => false, 'anledning' => 'Låntagaren hittades inte.'];
    }

    $kombinerat = kombinera_giltighet(
        (bool)$rad['PersonAvstangd'],
        $rad['PersonGiltigTill'],
        (bool)$rad['GruppAvstangd'],
        $rad['GruppGiltigTill']
    );

    if ($kombinerat['avstangd']) {
        return ['giltig' => false, 'anledning' => 'Låntagaren (eller dennes låntagargrupp) är avstängd.'];
    }
    if ($kombinerat['giltig_till'] !== null && $kombinerat['giltig_till'] < date('Y-m-d')) {
        return ['giltig' => false, 'anledning' => 'Giltigheten gick ut ' . $kombinerat['giltig_till'] . ' (person eller grupp).'];
    }
    return ['giltig' => true, 'anledning' => null];
}

// ---------------------------------------------------------------------
// Systeminställningar (nyckel/värde) - integrationer m.m.
// ---------------------------------------------------------------------

/** Hämtar alla systeminställningar som en assoc-array Nyckel => Varde. */
function hamta_systeminstallningar(): array
{
    global $db;
    $rader = $db->query('SELECT Nyckel, Varde FROM nyko_systeminstallningar')->fetch_all(MYSQLI_ASSOC);
    $resultat = [];
    foreach ($rader as $rad) {
        $resultat[$rad['Nyckel']] = $rad['Varde'];
    }
    return $resultat;
}

/** Sparar (eller skapar) en enskild systeminställning. */
function spara_systeminstallning(string $nyckel, string $varde): void
{
    global $db;
    $stmt = $db->prepare('INSERT INTO nyko_systeminstallningar (Nyckel, Varde) VALUES (?, ?) ON DUPLICATE KEY UPDATE Varde = VALUES(Varde)');
    $stmt->bind_param('ss', $nyckel, $varde);
    $stmt->execute();
    $stmt->close();
}

/**
 * Hämtar giltighetsinformation för ett avtalsnummer från Avtalskatalogen.
 * PLATSHÅLLARE - Avtalskatalogen är inte byggd än. Byt ut kroppen mot ett
 * riktigt HTTP-anrop (t.ex. med curl eller file_get_contents) mot den URL
 * som satts i Inställningar när systemet finns på plats.
 * Returnerar ['ok' => bool, 'giltig_till' => ?string, 'fel' => ?string].
 */
function hamta_giltighet_fran_avtalskatalog(string $avtalsnummer): array
{
    $installningar = hamta_systeminstallningar();
    if (empty($installningar['avtalskatalog_aktiverad'])) {
        return ['ok' => false, 'giltig_till' => null, 'fel' => 'Avtalskatalog-integrationen är inte aktiverad under Inställningar.'];
    }
    if (empty($installningar['avtalskatalog_url'])) {
        return ['ok' => false, 'giltig_till' => null, 'fel' => 'Ingen URL till Avtalskatalogen är sparad under Inställningar.'];
    }
    // TODO: ersätt med ett riktigt anrop, t.ex.:
    // $svar = @file_get_contents($installningar['avtalskatalog_url'] . '?avtalsnummer=' . urlencode($avtalsnummer) . '&losenord=' . urlencode($installningar['avtalskatalog_losenord']));
    // $data = json_decode($svar, true);
    return ['ok' => false, 'giltig_till' => null, 'fel' => 'Avtalskatalogen är inte byggd än - det här är en förberedd platshållare som väntar på att systemet ska finnas.'];
}

/**
 * Hämtar fastighetsinformation för ett fastighetsnummer från
 * Förvaltning av Fastighetsteknik.
 * PLATSHÅLLARE - systemet är inte byggt än. Byt ut kroppen mot ett
 * riktigt HTTP-anrop när det finns på plats.
 * Returnerar ['ok' => bool, 'info' => ?string, 'fel' => ?string].
 */
function hamta_fran_fastighetsteknik(string $fastighetsnummer): array
{
    $installningar = hamta_systeminstallningar();
    if (empty($installningar['fastighetsteknik_aktiverad'])) {
        return ['ok' => false, 'info' => null, 'fel' => 'Fastighetsteknik-integrationen är inte aktiverad under Inställningar.'];
    }
    if (empty($installningar['fastighetsteknik_url'])) {
        return ['ok' => false, 'info' => null, 'fel' => 'Ingen URL till Fastighetsteknik är sparad under Inställningar.'];
    }
    // TODO: ersätt med ett riktigt anrop, t.ex.:
    // $svar = @file_get_contents($installningar['fastighetsteknik_url'] . '?fastighetsnummer=' . urlencode($fastighetsnummer) . '&losenord=' . urlencode($installningar['fastighetsteknik_losenord']));
    // $data = json_decode($svar, true);
    return ['ok' => false, 'info' => null, 'fel' => 'Fastighetsteknik är inte byggt än - det här är en förberedd platshållare som väntar på att systemet ska finnas.'];
}

// ---------------------------------------------------------------------
// API-autentisering (för de utgående läs-API:erna, api_*.php)
// ---------------------------------------------------------------------

/**
 * Kräver ett giltigt API-lösenord (skickat som headern X-Api-Key, eller
 * som query-/post-parametern "losenord"). Avslutar begäran med ett
 * JSON-felsvar och HTTP 401 om det saknas eller är fel. Sätter också
 * Content-Type till JSON för resten av svaret.
 */
function api_krav_giltigt_losenord(): void
{
    header('Content-Type: application/json; charset=UTF-8');

    $angivet = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['losenord'] ?? $_POST['losenord'] ?? '');
    $installningar = hamta_systeminstallningar();
    $hash = $installningar['api_losenord_hash'] ?? '';

    if ($angivet === '' || $hash === '' || !password_verify($angivet, $hash)) {
        http_response_code(401);
        echo json_encode(['fel' => 'Saknat eller ogiltigt API-lösenord.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/** Skickar ett JSON-svar och avslutar begäran. */
function api_svara(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ---------------------------------------------------------------------
// Sidbläddring
// ---------------------------------------------------------------------

/** Aktuell sida (1-baserad) från query-strängen, aldrig mindre än 1. */
function hamta_sida(): int
{
    $sida = (int)($_GET['sida'] ?? 1);
    return $sida < 1 ? 1 : $sida;
}

/** En enskild sidlänk, behåller övriga aktiva query-parametrar. */
function sidlank(int $sida, string $etikett, bool $aktiv = false): string
{
    if ($aktiv) {
        return '<strong>' . h($etikett) . '</strong>';
    }
    $parametrar = $_GET;
    $parametrar['sida'] = $sida;
    return '<a href="?' . h(http_build_query($parametrar)) . '">' . h($etikett) . '</a>';
}

/**
 * Skriver ut sidbläddringskontroller om det finns fler än en sida,
 * annars en tom sträng. $totalt_antal ska vara det FULLA antalet
 * träffar (före uppdelning i sidor), inte bara det som visas just nu.
 */
function sidbladdring(int $totalt_antal, int $per_sida = 50): string
{
    $sidor_totalt = (int)ceil($totalt_antal / $per_sida);
    if ($sidor_totalt <= 1) {
        return '';
    }
    $aktuell = max(1, min($sidor_totalt, hamta_sida()));

    $delar = [];
    if ($aktuell > 1) {
        $delar[] = sidlank($aktuell - 1, '« Föregående');
    }

    $start = max(1, $aktuell - 2);
    $slut = min($sidor_totalt, $aktuell + 2);
    if ($start > 1) {
        $delar[] = sidlank(1, '1');
        if ($start > 2) {
            $delar[] = '…';
        }
    }
    for ($s = $start; $s <= $slut; $s++) {
        $delar[] = sidlank($s, (string)$s, $s === $aktuell);
    }
    if ($slut < $sidor_totalt) {
        if ($slut < $sidor_totalt - 1) {
            $delar[] = '…';
        }
        $delar[] = sidlank($sidor_totalt, (string)$sidor_totalt);
    }

    if ($aktuell < $sidor_totalt) {
        $delar[] = sidlank($aktuell + 1, 'Nästa »');
    }

    return '<nav class="sidbladdring">' . implode(' ', $delar) . '</nav>';
}

/** Text som beskriver hur många av det totala antalet som visas just nu. */
function visar_antal_text(int $totalt_antal, int $per_sida, string $enhet): string
{
    if ($totalt_antal <= $per_sida) {
        return $totalt_antal . ' ' . $enhet . ' visas.';
    }
    $sida = max(1, hamta_sida());
    $fran = ($sida - 1) * $per_sida + 1;
    $till = min($totalt_antal, $sida * $per_sida);
    return 'Visar ' . $fran . '–' . $till . ' av ' . $totalt_antal . ' ' . $enhet . '.';
}

/**
 * Skriver ut en aktuellt-filtrerat-CSV-fil till php://output och avslutar.
 * $rader är hela (ofiltrerade av sidbläddring) resultatlistan.
 */
function exportera_csv(string $filnamn, array $kolumnrubriker, array $rader, array $faltnamn): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filnamn . '"');
    echo "\xEF\xBB\xBF";
    $ut = fopen('php://output', 'w');
    fputcsv($ut, $kolumnrubriker, ';');
    foreach ($rader as $rad) {
        $ut_rad = [];
        foreach ($faltnamn as $falt) {
            $ut_rad[] = $rad[$falt] ?? '';
        }
        fputcsv($ut, $ut_rad, ';');
    }
    fclose($ut);
    exit;
}

// ---------------------------------------------------------------------
// Slå ihop eller skapa ny (för "Lägg till") / direkt uppdatering (för "Redigera")
// ---------------------------------------------------------------------

/**
 * Används av "Lägg till"-formulär. Letar upp en befintlig post via ett
 * naturligt nyckelvärde (t.ex. Namn, NyckelsystemID). Finns posten redan:
 * uppdatera den, men rör bara de fält som faktiskt skickades ifyllda
 * (null i $falt = "rör inte", så befintlig information skrivs aldrig över
 * med tomt). Finns posten inte: skapa en ny rad med de fält som gavs.
 *
 * $sok:  assoc kolumn => värde som identifierar posten (en eller flera kolumner)
 * $falt: assoc kolumn => värde (eller null) för alla kolumner som ska kunna sättas
 *
 * Returnerar ['ok' => bool, 'skapad' => bool, 'id' => ?int, 'fel' => ?string]
 */
function slaihop_eller_infoga(string $tabell, array $sok, array $falt): array
{
    global $db;

    $where = [];
    $typer = '';
    $varden = [];
    foreach ($sok as $kolumn => $varde) {
        $where[] = "`$kolumn` = ?";
        $typer .= 's';
        $varden[] = $varde;
    }

    $stmt = $db->prepare("SELECT ID FROM `$tabell` WHERE " . implode(' AND ', $where));
    $stmt->bind_param($typer, ...$varden);
    $stmt->execute();
    $befintlig = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    try {
        if ($befintlig) {
            $id = (int)$befintlig['ID'];
            $satser = [];
            $uvarden = [];
            $utyper = '';
            foreach ($falt as $kolumn => $varde) {
                if ($varde !== null) {
                    $satser[] = "`$kolumn` = ?";
                    $utyper .= 's';
                    $uvarden[] = $varde;
                }
            }
            if ($satser) {
                $uvarden[] = $id;
                $utyper .= 'i';
                $stmt2 = $db->prepare("UPDATE `$tabell` SET " . implode(', ', $satser) . " WHERE ID = ?");
                $stmt2->bind_param($utyper, ...$uvarden);
                $stmt2->execute();
                $stmt2->close();
            }
            return ['ok' => true, 'skapad' => false, 'id' => $id, 'fel' => null];
        }

        $kolumner = array_keys($falt);
        $kollista = implode(',', array_map(fn($k) => "`$k`", $kolumner));
        $platshallare = implode(',', array_fill(0, count($kolumner), '?'));
        $typer2 = str_repeat('s', count($kolumner));
        $stmt3 = $db->prepare("INSERT INTO `$tabell` ($kollista) VALUES ($platshallare)");
        $stmt3->bind_param($typer2, ...array_values($falt));
        $stmt3->execute();
        $nyttid = $db->insert_id;
        $stmt3->close();
        return ['ok' => true, 'skapad' => true, 'id' => $nyttid, 'fel' => null];
    } catch (mysqli_sql_exception $e) {
        $meddelande = $e->getCode() === 1062
            ? 'En post med samma unika värde finns redan.'
            : 'Kunde inte spara: ' . $e->getMessage();
        return ['ok' => false, 'skapad' => false, 'id' => null, 'fel' => $meddelande];
    }
}

/**
 * Används av "Redigera"-formulär. Uppdaterar en specifik post via ID med
 * exakt de fältvärden som skickas in (null tillåtet, för att medvetet
 * tömma ett valfritt fält - till skillnad från slaihop_eller_infoga()).
 * Returnerar ['ok' => bool, 'fel' => ?string]
 */
function uppdatera_post(string $tabell, int $id, array $falt): array
{
    global $db;
    $satser = [];
    $varden = [];
    $typer = '';
    foreach ($falt as $kolumn => $varde) {
        $satser[] = "`$kolumn` = ?";
        $typer .= 's';
        $varden[] = $varde;
    }
    $varden[] = $id;
    $typer .= 'i';

    try {
        $stmt = $db->prepare("UPDATE `$tabell` SET " . implode(', ', $satser) . " WHERE ID = ?");
        $stmt->bind_param($typer, ...$varden);
        $stmt->execute();
        $stmt->close();
        return ['ok' => true, 'fel' => null];
    } catch (mysqli_sql_exception $e) {
        $meddelande = $e->getCode() === 1062
            ? 'En annan post med samma unika värde finns redan.'
            : 'Kunde inte spara: ' . $e->getMessage();
        return ['ok' => false, 'fel' => $meddelande];
    }
}

// ---------------------------------------------------------------------
// Säker borttagning
// ---------------------------------------------------------------------

/**
 * Tar bort en rad ur en tabell och översätter FK-konflikter till ett
 * begripligt svenskt felmeddelande istället för ett rått SQL-fel.
 * Returnerar ['ok' => bool, 'meddelande' => string].
 */
function sakert_ta_bort(string $tabell, int $id): array
{
    global $db;
    try {
        $stmt = $db->prepare("DELETE FROM `$tabell` WHERE ID = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        return ['ok' => true, 'meddelande' => 'Posten togs bort.'];
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1451) {
            return [
                'ok' => false,
                'meddelande' => 'Kan inte tas bort - posten används av annan information (t.ex. nycklar, knippor, lån eller lånehistorik). Ta bort eller ändra dessa kopplingar först.',
            ];
        }
        return ['ok' => false, 'meddelande' => 'Kunde inte ta bort posten: ' . $e->getMessage()];
    }
}
