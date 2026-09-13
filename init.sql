-- =====================================================================
-- NyckelKoll - Databasschema
-- MariaDB / MySQL
-- Alla tabeller använder prefixet nyko_
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Användare (inloggning krävs för att ändra/importera/administrera)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nyko_anvandare (
    ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Anvandarnamn VARCHAR(50) NOT NULL UNIQUE,
    LosenordHash VARCHAR(255) NOT NULL,
    Skapad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Nyckelsystem
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nyko_nyckelsystem (
    ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    NyckelsystemID VARCHAR(100) NOT NULL UNIQUE,
    Installationsar YEAR NULL,
    Garantitid VARCHAR(100) NULL,
    Garantipart VARCHAR(150) NULL,
    Servicestation VARCHAR(150) NOT NULL,
    ForvantadLivslangd VARCHAR(100) NULL,
    Placering VARCHAR(255) NULL,
    Fastighetsnummer VARCHAR(100) NULL,
    FastighetsInfo TEXT NULL,
    Skapad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Uppdaterad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Nyckelskåp (Kod-fältet är känsligt, döljs för ej inloggade i GUI)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nyko_nyckelskap (
    ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Namn VARCHAR(150) NOT NULL UNIQUE,
    Placering VARCHAR(255) NULL,
    Funktion VARCHAR(255) NULL,
    Kod VARCHAR(100) NULL,
    Skapad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Uppdaterad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Knippa (bor alltid i ett nyckelskåp)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nyko_knippa (
    ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Namn VARCHAR(150) NOT NULL,
    Funktion VARCHAR(255) NULL,
    Placering VARCHAR(255) NULL,
    NyckelskapID INT UNSIGNED NOT NULL,
    Skapad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Uppdaterad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_knippa_nyckelskap FOREIGN KEY (NyckelskapID) REFERENCES nyko_nyckelskap(ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Nyckel (hör antingen till en knippa ELLER direkt till ett nyckelskåp,
-- aldrig båda, aldrig inget - kontrolleras i init.sql via CHECK samt
-- alltid i PHP-logiken för säkerhets skull)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nyko_nyckel (
    ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    NyckelID VARCHAR(100) NOT NULL UNIQUE,
    Funktion VARCHAR(255) NULL,
    Placering VARCHAR(255) NULL,
    NyckelsystemID INT UNSIGNED NULL,
    KnippaID INT UNSIGNED NULL,
    NyckelskapID INT UNSIGNED NULL,
    Forlorad TINYINT(1) NOT NULL DEFAULT 0,
    Skapad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Uppdaterad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_nyckel_nyckelsystem FOREIGN KEY (NyckelsystemID) REFERENCES nyko_nyckelsystem(ID),
    CONSTRAINT fk_nyckel_knippa FOREIGN KEY (KnippaID) REFERENCES nyko_knippa(ID),
    CONSTRAINT fk_nyckel_nyckelskap FOREIGN KEY (NyckelskapID) REFERENCES nyko_nyckelskap(ID),
    CONSTRAINT chk_nyckel_plats CHECK (
        (KnippaID IS NOT NULL AND NyckelskapID IS NULL) OR
        (KnippaID IS NULL AND NyckelskapID IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Låntagargrupp
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nyko_lantagargrupp (
    ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Namn VARCHAR(150) NOT NULL UNIQUE,
    Kontaktperson VARCHAR(150) NULL,
    Telefonnummer VARCHAR(50) NULL,
    Organisationsnummer VARCHAR(50) NULL,
    InternKontaktperson VARCHAR(150) NULL,
    GiltigTill DATE NULL,
    Avstangd TINYINT(1) NOT NULL DEFAULT 0,
    Fritext TEXT NULL,
    Avtalsnummer VARCHAR(100) NULL,
    Skapad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Uppdaterad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Låntagare (Personnummer är känsligt, döljs för ej inloggade i GUI)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nyko_lantagare (
    ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Namn VARCHAR(150) NOT NULL,
    Telefonnummer VARCHAR(50) NOT NULL,
    Mejl VARCHAR(150) NOT NULL,
    Personnummer VARCHAR(20) NULL,
    LantagargruppID INT UNSIGNED NULL,
    InternKontaktperson VARCHAR(150) NULL,
    GiltigTill DATE NULL,
    Avstangd TINYINT(1) NOT NULL DEFAULT 0,
    Fritext TEXT NULL,
    Skapad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Uppdaterad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lantagare_grupp FOREIGN KEY (LantagargruppID) REFERENCES nyko_lantagargrupp(ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Lån (lån-numret = ID)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nyko_lan (
    ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    LantagareID INT UNSIGNED NOT NULL,
    AdministratorID INT UNSIGNED NOT NULL,
    Utlaningsdatum DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    SlutdatumGiltighet DATE NOT NULL,
    Status ENUM('Aktivt','Delvis aterlamnat','Helt aterlamnat') NOT NULL DEFAULT 'Aktivt',
    Aterlamningsdatum DATETIME NULL,
    CONSTRAINT fk_lan_lantagare FOREIGN KEY (LantagareID) REFERENCES nyko_lantagare(ID),
    CONSTRAINT fk_lan_administrator FOREIGN KEY (AdministratorID) REFERENCES nyko_anvandare(ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Lånrad (varje enskild nyckel i lånet - även nycklar som ingår i en
-- knippa listas var för sig här, med KnippaID ifyllt, så att delvis
-- återlämning av en knippa går att hantera per nyckel)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nyko_lanrad (
    ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    LanID INT UNSIGNED NOT NULL,
    NyckelID INT UNSIGNED NOT NULL,
    KnippaID INT UNSIGNED NULL,
    Status ENUM('Utlanad','Aterlamnad') NOT NULL DEFAULT 'Utlanad',
    AterlamningsdatumRad DATETIME NULL,
    AterlamnadAvAdministratorID INT UNSIGNED NULL,
    CONSTRAINT fk_lanrad_lan FOREIGN KEY (LanID) REFERENCES nyko_lan(ID),
    CONSTRAINT fk_lanrad_nyckel FOREIGN KEY (NyckelID) REFERENCES nyko_nyckel(ID),
    CONSTRAINT fk_lanrad_knippa FOREIGN KEY (KnippaID) REFERENCES nyko_knippa(ID),
    CONSTRAINT fk_lanrad_aterlamnadav FOREIGN KEY (AterlamnadAvAdministratorID) REFERENCES nyko_anvandare(ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Knippa-avvikelse (flaggas automatiskt vid ofullständig återlämning,
-- släcks inte förrän en administratör åtgärdar manuellt)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nyko_knippaavvikelse (
    ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    LanID INT UNSIGNED NOT NULL,
    KnippaID INT UNSIGNED NOT NULL,
    Beskrivning VARCHAR(500) NOT NULL,
    Skapad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Atgardad TINYINT(1) NOT NULL DEFAULT 0,
    AtgardadAvAdministratorID INT UNSIGNED NULL,
    AtgardadDatum DATETIME NULL,
    CONSTRAINT fk_avvikelse_lan FOREIGN KEY (LanID) REFERENCES nyko_lan(ID),
    CONSTRAINT fk_avvikelse_knippa FOREIGN KEY (KnippaID) REFERENCES nyko_knippa(ID),
    CONSTRAINT fk_avvikelse_atgardadav FOREIGN KEY (AtgardadAvAdministratorID) REFERENCES nyko_anvandare(ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Fältinställningar (styr vilka icke-strukturella fält som är
-- obligatoriska - administratören kan slå av/på)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nyko_faltinstallningar (
    ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Tabell VARCHAR(50) NOT NULL,
    Falt VARCHAR(50) NOT NULL,
    Obligatoriskt TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_tabell_falt (Tabell, Falt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Standardvärden för fältinställningar enligt ursprungsspecifikationen
INSERT INTO nyko_faltinstallningar (Tabell, Falt, Obligatoriskt) VALUES
    ('Nyckelsystem', 'NyckelsystemID', 1),
    ('Nyckelsystem', 'Installationsar', 0),
    ('Nyckelsystem', 'Garantitid', 0),
    ('Nyckelsystem', 'Garantipart', 0),
    ('Nyckelsystem', 'Servicestation', 1),
    ('Nyckelsystem', 'ForvantadLivslangd', 0),
    ('Nyckelsystem', 'Placering', 0),
    ('Nyckelskap', 'Namn', 1),
    ('Nyckelskap', 'Placering', 0),
    ('Nyckelskap', 'Funktion', 0),
    ('Nyckelskap', 'Kod', 0),
    ('Knippa', 'Namn', 1),
    ('Knippa', 'Funktion', 0),
    ('Knippa', 'Placering', 0),
    ('Nyckel', 'NyckelID', 1),
    ('Nyckel', 'Funktion', 0),
    ('Nyckel', 'Placering', 0),
    ('Lantagare', 'Namn', 1),
    ('Lantagare', 'Telefonnummer', 1),
    ('Lantagare', 'Mejl', 1),
    ('Lantagare', 'Personnummer', 0),
    ('Lantagargrupp', 'Namn', 1),
    ('Lantagargrupp', 'Kontaktperson', 0),
    ('Lantagargrupp', 'Telefonnummer', 0),
    ('Lantagargrupp', 'Organisationsnummer', 0)
ON DUPLICATE KEY UPDATE Falt = Falt;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Systeminställningar (nyckel/värde) - används för integrationer mot
-- externa system (Avtalskatalog, Fastighetsteknik, m.fl.) samt det
-- delade lösenordet för de utgående API:erna.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nyko_systeminstallningar (
    Nyckel VARCHAR(100) NOT NULL PRIMARY KEY,
    Varde TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO nyko_systeminstallningar (Nyckel, Varde) VALUES
    ('avtalskatalog_aktiverad', '0'),
    ('avtalskatalog_url', ''),
    ('avtalskatalog_losenord', ''),
    ('fastighetsteknik_aktiverad', '0'),
    ('fastighetsteknik_url', ''),
    ('fastighetsteknik_losenord', ''),
    ('api_losenord_hash', '')
ON DUPLICATE KEY UPDATE Nyckel = Nyckel;

-- ---------------------------------------------------------------------
-- Standardanvändare: admin / 123qwe!!
-- (LosenordHash nedan är en bcrypt-hash av "123qwe!!", genererad i förväg.
-- Byt lösenord i produktion genom att generera en ny hash i PHP med
-- password_hash('nytt-losenord', PASSWORD_BCRYPT) och uppdatera raden.)
-- ---------------------------------------------------------------------
INSERT INTO nyko_anvandare (Anvandarnamn, LosenordHash) VALUES
    ('admin', '$2b$12$AzV4vuwp/xI0K0paj42IseSgapt6nNsOQ1.u5VFdjRZit7j/L6302')
ON DUPLICATE KEY UPDATE Anvandarnamn = Anvandarnamn;
