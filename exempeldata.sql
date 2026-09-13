-- =====================================================================
-- NyckelKoll - Exempeldata
-- Körs EFTER init.sql, mot en databas som redan har tabellerna skapade
-- (och gärna en tom eller ren testdatabas - detta är påhittad testdata,
-- inte tänkt att blandas in i skarp drift).
--
-- Innehåller bland annat:
--  - Ett lån som är FÖRSENAT (slutdatum passerat, fortfarande "Aktivt")
--  - Ett andra försenat lån på en lös nyckel, för lite variation
--  - Ett vanligt aktivt lån som INTE är försenat
--  - Ett delvis återlämnat lån, med en tillhörande avvikelse på knippan
--  - Ett historiskt lån som är helt återlämnat
--
-- Alla datum räknas relativt dagens datum (NOW()/CURDATE()) så att det
-- försenade lånet förblir försenat oavsett när filen körs.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Nyckelsystem
-- ---------------------------------------------------------------------
INSERT INTO nyko_nyckelsystem (NyckelsystemID, Installationsar, Servicestation, Placering) VALUES
('LAS-2020', 2020, 'Låsservice AB', 'Centrum');

-- ---------------------------------------------------------------------
-- Nyckelskåp
-- ---------------------------------------------------------------------
INSERT INTO nyko_nyckelskap (Namn, Placering, Funktion, Kod) VALUES
('Huvudskåpet', 'Reception', 'Allmänt', '4711'),
('Städskåpet', 'Källarplan', 'Städutrustning', '9922');

-- ---------------------------------------------------------------------
-- Knippor
-- ---------------------------------------------------------------------
INSERT INTO nyko_knippa (Namn, Funktion, Placering, NyckelskapID) VALUES
('Städknippa', 'Städning trapphus A-C', 'Trapphus A-C', (SELECT ID FROM nyko_nyckelskap WHERE Namn = 'Städskåpet')),
('Kontorsknippa', 'Kontor plan 2', 'Plan 2', (SELECT ID FROM nyko_nyckelskap WHERE Namn = 'Huvudskåpet')),
('Källarknippa', 'Källarförråd', 'Källarplan', (SELECT ID FROM nyko_nyckelskap WHERE Namn = 'Huvudskåpet'));

-- ---------------------------------------------------------------------
-- Nycklar i knipporna
-- ---------------------------------------------------------------------
INSERT INTO nyko_nyckel (NyckelID, Funktion, Placering, NyckelsystemID, KnippaID) VALUES
('N-101', 'Trapphus A', 'Trapphus A', (SELECT ID FROM nyko_nyckelsystem WHERE NyckelsystemID = 'LAS-2020'), (SELECT ID FROM nyko_knippa WHERE Namn = 'Städknippa')),
('N-102', 'Trapphus B', 'Trapphus B', (SELECT ID FROM nyko_nyckelsystem WHERE NyckelsystemID = 'LAS-2020'), (SELECT ID FROM nyko_knippa WHERE Namn = 'Städknippa')),
('N-103', 'Trapphus C', 'Trapphus C', (SELECT ID FROM nyko_nyckelsystem WHERE NyckelsystemID = 'LAS-2020'), (SELECT ID FROM nyko_knippa WHERE Namn = 'Städknippa')),
('N-201', 'Kontor 201', 'Plan 2', (SELECT ID FROM nyko_nyckelsystem WHERE NyckelsystemID = 'LAS-2020'), (SELECT ID FROM nyko_knippa WHERE Namn = 'Kontorsknippa')),
('N-202', 'Kontor 202', 'Plan 2', (SELECT ID FROM nyko_nyckelsystem WHERE NyckelsystemID = 'LAS-2020'), (SELECT ID FROM nyko_knippa WHERE Namn = 'Kontorsknippa')),
('N-203', 'Kontor 203', 'Plan 2', (SELECT ID FROM nyko_nyckelsystem WHERE NyckelsystemID = 'LAS-2020'), (SELECT ID FROM nyko_knippa WHERE Namn = 'Kontorsknippa')),
('N-401', 'Källarförråd 1', 'Källarplan', (SELECT ID FROM nyko_nyckelsystem WHERE NyckelsystemID = 'LAS-2020'), (SELECT ID FROM nyko_knippa WHERE Namn = 'Källarknippa')),
('N-402', 'Källarförråd 2', 'Källarplan', (SELECT ID FROM nyko_nyckelsystem WHERE NyckelsystemID = 'LAS-2020'), (SELECT ID FROM nyko_knippa WHERE Namn = 'Källarknippa'));

-- ---------------------------------------------------------------------
-- Lösa nycklar direkt i Huvudskåpet
-- ---------------------------------------------------------------------
INSERT INTO nyko_nyckel (NyckelID, Funktion, Placering, NyckelsystemID, NyckelskapID) VALUES
('N-301', 'Konferensrum', 'Plan 1', (SELECT ID FROM nyko_nyckelsystem WHERE NyckelsystemID = 'LAS-2020'), (SELECT ID FROM nyko_nyckelskap WHERE Namn = 'Huvudskåpet')),
('N-302', 'Förråd', 'Källarplan', NULL, (SELECT ID FROM nyko_nyckelskap WHERE Namn = 'Huvudskåpet'));

-- ---------------------------------------------------------------------
-- Låntagargrupper
-- ---------------------------------------------------------------------
INSERT INTO nyko_lantagargrupp (Namn, Kontaktperson, Telefonnummer, Organisationsnummer) VALUES
('Posten', 'Karin Karlsson', '010-1112233', '556677-8899'),
('Vaktbolaget', 'Erik Ek', '010-4445566', '556611-2233');

-- ---------------------------------------------------------------------
-- Låntagare
-- ---------------------------------------------------------------------
INSERT INTO nyko_lantagare (Namn, Telefonnummer, Mejl, Personnummer, LantagargruppID) VALUES
('Anna Andersson', '070-1234567', 'anna.andersson@example.com', '19800101-1234', (SELECT ID FROM nyko_lantagargrupp WHERE Namn = 'Posten')),
('Bengt Bengtsson', '070-2345678', 'bengt.bengtsson@example.com', NULL, NULL),
('Cecilia Nilsson', '070-3456789', 'cecilia.nilsson@example.com', NULL, (SELECT ID FROM nyko_lantagargrupp WHERE Namn = 'Vaktbolaget'));

-- ---------------------------------------------------------------------
-- Lån 1: Anna lånade Städknippa för 20 dagar sedan, slutdatum för 10
-- dagar sedan - FÖRSENAT, fortfarande "Aktivt" (ingen återlämning gjord).
-- ---------------------------------------------------------------------
INSERT INTO nyko_lan (LantagareID, AdministratorID, Utlaningsdatum, SlutdatumGiltighet, Status) VALUES (
    (SELECT ID FROM nyko_lantagare WHERE Namn = 'Anna Andersson'),
    (SELECT ID FROM nyko_anvandare WHERE Anvandarnamn = 'admin'),
    DATE_SUB(NOW(), INTERVAL 20 DAY),
    DATE_SUB(CURDATE(), INTERVAL 10 DAY),
    'Aktivt'
);
SET @lan1 = LAST_INSERT_ID();
INSERT INTO nyko_lanrad (LanID, NyckelID, KnippaID)
SELECT @lan1, ID, KnippaID FROM nyko_nyckel WHERE NyckelID IN ('N-101', 'N-102', 'N-103');

-- ---------------------------------------------------------------------
-- Lån 2: Anna lånade en lös nyckel för 25 dagar sedan, slutdatum för 5
-- dagar sedan - också FÖRSENAT, för lite variation (lös nyckel istället
-- för knippa).
-- ---------------------------------------------------------------------
INSERT INTO nyko_lan (LantagareID, AdministratorID, Utlaningsdatum, SlutdatumGiltighet, Status) VALUES (
    (SELECT ID FROM nyko_lantagare WHERE Namn = 'Anna Andersson'),
    (SELECT ID FROM nyko_anvandare WHERE Anvandarnamn = 'admin'),
    DATE_SUB(NOW(), INTERVAL 25 DAY),
    DATE_SUB(CURDATE(), INTERVAL 5 DAY),
    'Aktivt'
);
SET @lan2 = LAST_INSERT_ID();
INSERT INTO nyko_lanrad (LanID, NyckelID, KnippaID)
SELECT @lan2, ID, KnippaID FROM nyko_nyckel WHERE NyckelID = 'N-302';

-- ---------------------------------------------------------------------
-- Lån 3: Bengt lånade en lös nyckel för 3 dagar sedan, slutdatum om 14
-- dagar - vanligt aktivt lån, INTE försenat.
-- ---------------------------------------------------------------------
INSERT INTO nyko_lan (LantagareID, AdministratorID, Utlaningsdatum, SlutdatumGiltighet, Status) VALUES (
    (SELECT ID FROM nyko_lantagare WHERE Namn = 'Bengt Bengtsson'),
    (SELECT ID FROM nyko_anvandare WHERE Anvandarnamn = 'admin'),
    DATE_SUB(NOW(), INTERVAL 3 DAY),
    DATE_ADD(CURDATE(), INTERVAL 14 DAY),
    'Aktivt'
);
SET @lan3 = LAST_INSERT_ID();
INSERT INTO nyko_lanrad (LanID, NyckelID, KnippaID)
SELECT @lan3, ID, KnippaID FROM nyko_nyckel WHERE NyckelID = 'N-301';

-- ---------------------------------------------------------------------
-- Lån 4: Bengt lånade Kontorsknippa för 8 dagar sedan, slutdatum om 6
-- dagar (inte försenat). Två av tre nycklar är återlämnade, en kvarstår
-- - "Delvis återlämnat", med en tillhörande oåtgärdad avvikelse.
-- ---------------------------------------------------------------------
INSERT INTO nyko_lan (LantagareID, AdministratorID, Utlaningsdatum, SlutdatumGiltighet, Status) VALUES (
    (SELECT ID FROM nyko_lantagare WHERE Namn = 'Bengt Bengtsson'),
    (SELECT ID FROM nyko_anvandare WHERE Anvandarnamn = 'admin'),
    DATE_SUB(NOW(), INTERVAL 8 DAY),
    DATE_ADD(CURDATE(), INTERVAL 6 DAY),
    'Delvis aterlamnat'
);
SET @lan4 = LAST_INSERT_ID();
INSERT INTO nyko_lanrad (LanID, NyckelID, KnippaID, Status, AterlamningsdatumRad, AterlamnadAvAdministratorID)
SELECT @lan4, ID, KnippaID, 'Aterlamnad', DATE_SUB(NOW(), INTERVAL 1 DAY), (SELECT ID FROM nyko_anvandare WHERE Anvandarnamn = 'admin')
FROM nyko_nyckel WHERE NyckelID IN ('N-201', 'N-202');
INSERT INTO nyko_lanrad (LanID, NyckelID, KnippaID)
SELECT @lan4, ID, KnippaID FROM nyko_nyckel WHERE NyckelID = 'N-203';

INSERT INTO nyko_knippaavvikelse (LanID, KnippaID, Beskrivning, Skapad) VALUES (
    @lan4,
    (SELECT ID FROM nyko_knippa WHERE Namn = 'Kontorsknippa'),
    CONCAT('Knippan "Kontorsknippa" lämnades tillbaka ofullständig i lån #', @lan4, ' (2 av 3 nycklar återlämnade).'),
    DATE_SUB(NOW(), INTERVAL 1 DAY)
);

-- ---------------------------------------------------------------------
-- Lån 5: Cecilia lånade Källarknippa för 30 dagar sedan, slutdatum för
-- 16 dagar sedan, men lämnade tillbaka allt i tid (15 dagar sedan) -
-- historiskt exempel på ett helt återlämnat lån.
-- ---------------------------------------------------------------------
INSERT INTO nyko_lan (LantagareID, AdministratorID, Utlaningsdatum, SlutdatumGiltighet, Status, Aterlamningsdatum) VALUES (
    (SELECT ID FROM nyko_lantagare WHERE Namn = 'Cecilia Nilsson'),
    (SELECT ID FROM nyko_anvandare WHERE Anvandarnamn = 'admin'),
    DATE_SUB(NOW(), INTERVAL 30 DAY),
    DATE_SUB(CURDATE(), INTERVAL 16 DAY),
    'Helt aterlamnat',
    DATE_SUB(NOW(), INTERVAL 15 DAY)
);
SET @lan5 = LAST_INSERT_ID();
INSERT INTO nyko_lanrad (LanID, NyckelID, KnippaID, Status, AterlamningsdatumRad, AterlamnadAvAdministratorID)
SELECT @lan5, ID, KnippaID, 'Aterlamnad', DATE_SUB(NOW(), INTERVAL 15 DAY), (SELECT ID FROM nyko_anvandare WHERE Anvandarnamn = 'admin')
FROM nyko_nyckel WHERE NyckelID IN ('N-401', 'N-402');

-- ---------------------------------------------------------------------
-- Sammanfattning av vad exempeldatan innehåller:
--  - Lån #1 och #2 (Anna): FÖRSENADE, syns i "Ej återlämnade i tid"
--  - Lån #3 (Bengt): aktivt, inte försenat
--  - Lån #4 (Bengt): delvis återlämnat, syns i Avvikelser (öppen avvikelse
--    på Kontorsknippa)
--  - Lån #5 (Cecilia): helt återlämnat, historiskt exempel
-- ---------------------------------------------------------------------
