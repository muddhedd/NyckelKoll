# NyckelKoll

Internt verktyg för att hålla koll på nyckelsystem, nyckelskåp, knippor,
nycklar, låntagare och utlåning/återlämning av nycklar.

**Viktigt:** NyckelKoll är byggt för att ligga på en intern server och ska
**inte** exponeras mot internet. Verktyget saknar skydd mot t.ex.
automatiserade attacker, brute-force-inloggning och liknande hot som en
publik webbplats behöver hantera. Se till att servern endast är
nätverksmässigt tillgänglig inom det interna nätverket.

---

## Innehåll

- [Snabbstart](#snabbstart)
- [Användarmanual](#användarmanual)
  - [Vem kan göra vad](#vem-kan-göra-vad)
  - [Grundflödet: nyckelns "hem"](#grundflödet-nyckelns-hem)
  - [Registren](#registren-nyckelsystem-nyckelskåp-knippor-nycklar-låntagare-låntagargrupper)
  - [Låntagarens giltighet](#låntagarens-giltighet)
  - [Ny utlåning](#ny-utlåning)
  - [Aktuella utlåningar och lånedetaljer](#aktuella-utlåningar-och-lånedetaljer)
  - [Återlämning](#återlämning)
  - [Avvikelser](#avvikelser)
  - [Ej återlämnade i tid](#ej-återlämnade-i-tid)
  - [Tappad / Återfunnen](#tappad--återfunnen)
  - [Import och export](#import-och-export)
  - [Inställningar](#inställningar)
  - [Utgående API:er](#utgående-apier)
- [Teknisk dokumentation](#teknisk-dokumentation)
  - [Teknikstack](#teknikstack)
  - [Databas](#databas)
  - [Filstruktur](#filstruktur)
  - [Delade hjälpfunktioner](#delade-hjälpfunktioner-configphp)
  - [Säkerhet](#säkerhet)
  - [Designprinciper](#designprinciper)
- [Kända begränsningar och planerat](#kända-begränsningar-och-planerat)

---

## Snabbstart

1. Skapa en databas och importera [`init.sql`](init.sql). Den skapar alla
   tabeller, standardvärden för obligatoriska fält, och en färdig
   administratörsanvändare: **användarnamn `admin`, lösenord `123qwe!!`.**
   Byt lösenordet så snart du loggat in (under Inställningar).
2. Vill du ha lite testdata att öva på, importera även
   [`exempeldata.sql`](exempeldata.sql) efteråt (valfritt).
3. Fyll i databasuppgifter i [`config.php`](config.php)
   (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
4. Placera samtliga filer på den interna webbservern (PHP 8.1+
   rekommenderas).
5. Se till att servern **inte** är tillgänglig från internet.
6. Logga in och byt lösenordet på `admin`-kontot under **Inställningar**.

---

## Användarmanual

### Vem kan göra vad

- **Utan inloggning:** vem som helst på det interna nätverket kan läsa
  all information i alla register och i lånehistoriken. Tre undantag
  visas maskerade (`••••••`): **kod** på ett nyckelskåp, **personnummer**
  på en låntagare, samt att export/import och Inställningar kräver
  inloggning rakt av.
- **Inloggad:** krävs för att lägga till, ändra eller ta bort poster,
  registrera ny utlåning eller återlämning, hantera avvikelser, sätta
  fältkrav, hantera användare och integrationer, samt importera/exportera
  CSV.

### Grundflödet: nyckelns "hem"

En nyckel eller knippa "bor" alltid på en fast plats när den inte är
utlånad:

- En **knippa** bor alltid i ett **nyckelskåp**.
- En **nyckel** bor antingen direkt i ett **nyckelskåp**, eller i en
  **knippa** (aldrig båda samtidigt).

När en nyckel/knippa lånas ut flyttas den logiskt "ut" ur sitt hem, och
när den lämnas tillbaka registreras den som åter i sitt hem igen. Det går
också att flytta en nyckel till ett annat nyckelskåp, eller in/ut ur en
knippa, utan att en utlåning behöver ske - det sker direkt i registren.

### Registren (Nyckelsystem, Nyckelskåp, Knippor, Nycklar, Låntagare, Låntagargrupper)

Alla sex register fungerar enligt samma mönster:

- **Sök, filtrera, sortera:** en sökruta (fritextsökning över relevanta
  fält) plus registerspecifika filter (t.ex. nyckelskåp, status,
  låntagargrupp), och klickbara kolumnrubriker som växlar
  sorteringsriktning. Alla val ligger i webbadressen, så en filtrerad vy
  går att länka till direkt.
- **Export:** en "Exportera CSV"-länk exporterar exakt det aktuellt
  filtrerade/sökta/sorterade urvalet - hela urvalet, inte bara den sida
  som just visas. Känsliga fält (Personnummer, Kod) utesluts ur exporten
  om man inte är inloggad, av samma skäl som de maskeras i listan.
- **Sidbläddring:** 50 rader per sida, dyker bara upp när urvalet är
  större än så.
- **Lägg till / Redigera / Ta bort** (kräver inloggning). Borttagning är
  skyddad - används posten någon annanstans (t.ex. ett nyckelskåp med
  knippor i) visas ett tydligt felmeddelande istället för att krascha.

Registerspecifikt:

- **Nyckelsystem:** ett valfritt **Fastighetsnummer**-fält kopplar mot en
  framtida "Förvaltning av Fastighetsteknik" (se
  [Inställningar](#inställningar)).
- **Nyckelskåp:** **Kod**-fältet visas maskerat för utloggade.
- **Knippa:** kopplas alltid till ett nyckelskåp. Listan visar live-status
  (Hemma / Delvis utlånad / Utlånad / Tom knippa) baserat på hur många av
  knippans nycklar som för tillfället är utlånade.
- **Nycklar:** väljer vid registrering om nyckeln bor i en knippa eller
  direkt i ett nyckelskåp (aldrig båda). Listan visar live-status
  (Hemma / Utlånad / **Tappad**, se [eget avsnitt](#tappad--återfunnen)).
- **Låntagare** och **Låntagargrupper:** se [nästa avsnitt](#låntagarens-giltighet)
  om giltighet, samt Låntagargruppens **Avtalsnummer**-fält för en
  framtida avtalskatalog-integration.

**"Lägg till" slår ihop med befintlig post om den redan finns** (per
ursprungsspecifikationen) för Nyckelsystem (nyckel: Nyckelsystem-ID),
Nyckelskåp (nyckel: Namn), Knippa (nyckel: Namn + vilket nyckelskåp den
står i), Nycklar (nyckel: Nyckel-ID) och Låntagargrupper (nyckel: Namn).
Tomma fält i formuläret skriver aldrig över redan ifylld information på
den befintliga posten. **Låntagare slås aldrig ihop automatiskt** - två
olika personer kan heta samma sak, så "Lägg till" skapar alltid en ny
låntagare; vill man ändra en befintlig används "Redigera" på den raden.

### Låntagarens giltighet

Både en enskild låntagare och en låntagargrupp kan ha:

- **Intern kontaktperson** (fritext, egen anteckning per post)
- **Giltig till** (datum - tomt betyder ingen bortre gräns)
- **Avstängd** (kryssruta)
- **Fritext** (synlig för alla, till skillnad från personnummer/kod -
  ärvs inte ner till gruppens medlemmar)

Är låntagaren medlem i en grupp **ärvs Avstängd och Giltig till ner**, och
**mest restriktiva av person och grupp vinner**: avstängd om ENTINGEN
personen eller gruppen är avstängd, och tidigaste av personens och
gruppens giltig-till-datum gäller.

En avstängd eller giltighets-utgången låntagare går **inte** att välja
vid ny utlåning - spärren gäller både i gränssnittet (dropdownen visar
`AVSTÄNGD`/`giltighet utgången` och är inaktiverad) och server-side, så
den går inte att kringgå genom att mixtra med formuläret.

### Ny utlåning

1. Gå till **Ny utlåning**.
2. Välj nyckelskåp - visar de knippor (helt hemma) och lösa nycklar som
   just nu finns i det skåpet.
3. Välj låntagare och slutdatum, bocka för det som ska lånas ut, tryck
   **"Lägg till i lånet"**. Upprepa med fler nyckelskåp vid behov -
   lånekorgen samlar ihop alla poster tills du sparar.
4. Ångrar du dig går det att ta bort poster ur korgen igen, ända tills
   lånet sparas.
5. **Spara lånet** - skapar lån-numret och låser vyn (går inte längre att
   ändra). En sista koll görs att inget i korgen hunnit lånas ut av någon
   annan under tiden.
6. Efter sparning visas ett kvitto med **Skriv ut**-knapp (webbläsarens
   vanliga utskrift/spara-som-PDF, med en egen utskriftsstil som bara
   visar kvittot) samt **Stäng**. Innan sparning finns istället en
   **Avbryt**-knapp som slänger hela den påbörjade korgen.

Låntagare väljs enbart bland redan registrerade låntagare - saknas
låntagaren, lägg till den under Låntagare först.

### Aktuella utlåningar och lånedetaljer

**Aktuella utlåningar** listar alla lån som inte är helt återlämnade, med
sök/filter/sortering som registren, plus en **Återlämna**-knapp per rad.
Klick på lån-numret öppnar samma kvitto-vy som efter en sparad utlåning
(`lan_detalj.php`), med en Skriv ut-knapp - öppen för alla, ingen
inloggning krävs för att titta.

### Återlämning

1. Gå till **Återlämning**, sök upp lånet på person, låntagargrupp,
   nyckel, knippa eller lån-nummer - eller kom direkt via
   Återlämna-knappen i Aktuella utlåningar.
2. Bocka för det som lämnas tillbaka. Går bra i flera omgångar (delvis
   återlämning). Redan återlämnade rader visas som gråa bockade
   informationsrader.
3. Lånets status uppdateras automatiskt: **Aktivt** → **Delvis
   återlämnat** → **Helt återlämnat** (det sistnämnda sätter även
   återlämningsdatum).
4. **Automatisk avvikelseflaggning:** lämnas en knippa tillbaka
   ofullständig (någon nyckel saknas) skapas automatiskt en avvikelse -
   men bara en gång per lån+knippa, så upprepade delvisa återlämningar
   inte skapar dubbletter. Flaggan försvinner inte automatiskt även om
   resten kommer tillbaka senare - den måste åtgärdas manuellt under
   Avvikelser.

Att titta på ett lån kräver ingen inloggning, men kryssrutorna och
spara-knappen är dolda/inaktiverade om man inte är inloggad.

### Avvikelser

Listar knippor som lämnats tillbaka ofullständiga, med sök/filter/
sortering (standardfiltret visar bara öppna avvikelser). Varje rad visar
vilket lån, låntagare, knippa, nyckelskåp och en automatiskt genererad
beskrivning. Inloggad administratör trycker **Åtgärda** när knippan är
utredd/omhändertagen, eller **Öppna igen** om något åtgärdades av
misstag. Själva "göra om"-arbetet (flytta en saknad nyckel, ändra
knippans sammansättning) görs i de vanliga registren
(Nycklar/Knippor) - Avvikelser är bara till för att hitta och kvittera.

### Ej återlämnade i tid

Listar lån vars slutdatum har passerat **eller är idag**, utan att vara
helt återlämnade. Rader som förfaller idag är gula, redan förfallna är
röda. Samma sök/filter/sortering och Återlämna-knapp som Aktuella
utlåningar.

### Tappad / Återfunnen

En nyckel kan markeras **Tappad** från dess redigeringsvy (kräver en
bekräftelsedialog). Så länge en nyckel är tappad går det inte att markera
den tappad igen - istället visas en **Återfunnen**-knapp som sätter den
tillbaka till normalt läge. "Tappad" är en helt egen flagga, fristående
från den beräknade Hemma/Utlånad-statusen, och har alltid företräde i
listans statuskolumn.

### Import och export

**Import** (under Import/Export, kräver inloggning) finns för:

- **Nycklar** - CSV med kolumnerna
  `NyckelID; Funktion; Placering; Nyckelsystem; Knippa; Nyckelskap`.
  Nyckelsystem, Knippa och Nyckelskåp anges med namn/ID som text - finns
  de inte skapas de automatiskt (med resten av sina fält tomma). Ange
  antingen Knippa eller Nyckelskåp, aldrig båda/inget. Skapas en ny
  knippa "på fri hand" hamnar den i ett automatiskt skapat nyckelskåp
  som heter **"Ej tilldelad"**, så en knippa aldrig kan skapas utan skåp
  via import.
- **Låntagare** - CSV med kolumnerna
  `Namn; Telefonnummer; Mejl; Personnummer; Lantagargrupp`. Grupp skapas
  automatiskt om den saknas. Varje rad skapar alltid en ny låntagare
  (ingen sammanslagning).

Båda importerna validerar obligatoriska fält enligt de inställningar som
sätts under Inställningar, och kör rad för rad - lyckade rader importeras
även om andra rader misslyckas. Resultatet visas direkt: antal lyckade
rader, samt radnummer + felmeddelande för de som inte gick.
Nedladdningsbara CSV-mallar med exempelrader finns länkade ovanför varje
importformulär.

**Export:** en knapp "Exportera utlåningar" laddar ner samma rader som
Aktuella utlåningar (allt utom helt återlämnade lån). Övriga register har
sin egen export direkt på respektive registersida (se ovan).

### Inställningar

Hela sidan kräver inloggning.

- **Användare:** lista över befintliga konton, formulär för att skapa en
  ny användare, ett separat formulär för att byta sitt eget lösenord
  (kräver nuvarande lösenord), samt **Byt lösenord**- och **Ta bort**-
  knappar per rad för att hantera andra användares konton (kräver inte
  det gamla lösenordet, bara en bekräftelsedialog). Man kan varken byta
  lösenord på eller ta bort sitt eget konto via listan, och systemet
  vägrar ta bort den sista kvarvarande användaren.
- **Integrationer:** två förberedda kopplingar mot externa system som
  ännu inte finns - **Avtalskatalog** (mot Låntagargruppens
  Avtalsnummer) och **Förvaltning av Fastighetsteknik** (mot
  Nyckelsystemets Fastighetsnummer). Vardera har en på/av-kryssruta, en
  URL och ett lösenord. När en integration är aktiverad dyker en
  "Hämta"-knapp upp på respektive registersida - men själva anropet är
  just nu en tydlig platshållare som väntar på att systemen ska byggas.
- **API-åtkomst:** sätt/byt det delade lösenordet för de fyra utgående
  API:erna (se [nedan](#utgående-apier)).
- **Obligatoriska fält:** en kryssrutelista per register, med en egen
  Spara-knapp per lista. Redan tvingande fält är förbockade. ID-fält och
  strukturella kopplingar (t.ex. vilket nyckelskåp en knippa hör till)
  styrs inte här - de är alltid obligatoriska av databasens konstruktion.

### Utgående API:er

Fyra enkla läs-endpoints som returnerar JSON: `api_nyckelsystem.php`,
`api_nycklar.php`, `api_lantagare.php` och `api_utlaningar.php` (aktuella
utlåningar). Autentisering sker med ett delat lösenord (satt under
Inställningar), skickat antingen som headern `X-Api-Key: ditt-losenord`
eller query-parametern `?losenord=ditt-losenord`. Är inget lösenord satt
svarar alla fyra med HTTP 401 - API:erna är avstängda by default.

```
curl "https://din-server/nyckelkoll/api_nycklar.php?losenord=DITT-LOSENORD"
```

---

## Teknisk dokumentation

### Teknikstack

- PHP (procedurell, mysqli med prepared statements genomgående)
- MariaDB / MySQL
- Vanlig HTML/CSS/JS - inga externa ramverk eller CDN-beroenden, eftersom
  servern ska kunna köra helt utan internetåtkomst
- Sessionsbaserad inloggning, lösenord hashas med bcrypt
  (`password_hash` / `password_verify`)
- DOS-inspirerad, minimalistisk stil: ljus bakgrund, mörk text, röda
  länkar, gråa knappar med ljus text (`style.css`)

### Databas

Alla tabeller använder prefixet `nyko_`. Se [`init.sql`](init.sql) för
det fullständiga, aktuella schemat (skapar allt från grunden, inklusive
admin-kontot) - det är den enda källan som behöver köras för en ny
installation.

| Tabell | Beskrivning |
|---|---|
| `nyko_anvandare` | Inloggningskonton (bcrypt-hash) |
| `nyko_nyckelsystem` | Nyckelsystem/låssystem, inkl. Fastighetsnummer/FastighetsInfo |
| `nyko_nyckelskap` | Nyckelskåp - kodfältet dolt för utloggade |
| `nyko_knippa` | Knippor - bor alltid i ett nyckelskåp |
| `nyko_nyckel` | Nycklar - hör till knippa ELLER nyckelskåp, aldrig båda; Forlorad-flagga |
| `nyko_lantagargrupp` | Grupper av låntagare, inkl. giltighetsfält + Avtalsnummer |
| `nyko_lantagare` | Låntagare - personnummer dolt för utloggade, inkl. giltighetsfält |
| `nyko_lan` | Ett lån/utlåningstillfälle, lån-numret = ID |
| `nyko_lanrad` | Varje enskild lånad nyckel (även de i en knippa) |
| `nyko_knippaavvikelse` | Automatiska flaggor för ofullständigt återlämnade knippor |
| `nyko_faltinstallningar` | Vilka fält som är obligatoriska per register |
| `nyko_systeminstallningar` | Nyckel/värde-inställningar: integrationer + API-lösenord |

### Filstruktur

```
config.php              Databaskoppling, session, delade hjälpfunktioner
includes/header.php     Delad sidhuvud/meny
includes/footer.php     Delad sidfot
style.css               All styling (DOS-inspirerad, ljus/mörk/röd/grå)
init.sql                Skapar alla nyko_-tabeller, standardvärden, admin-konto
exempeldata.sql         Valfri testdata (skåp, knippor, nycklar, låntagare,
                        lån - inkl. två försenade lån och en öppen avvikelse)
index.php               Översikt/dashboard
login.php / logout.php  Inloggning / utloggning
nyckelsystem.php        Register: nyckelsystem
nyckelskap.php          Register: nyckelskåp
knippor.php             Register: knippor
nycklar.php             Register: nycklar (inkl. Tappad/Återfunnen)
lantagare.php           Register: låntagare (inkl. giltighet)
lantagargrupper.php     Register: låntagargrupper (inkl. giltighet + ärvning)
utlaning.php            Bygg och spara ett nytt lån, med kvitto/utskrift
aktuella_utlaningar.php Lista över aktuella (ej helt återlämnade) lån
lan_detalj.php          Enskild lånedetalj/kvitto (klickbart lån-nummer)
aterlamning.php         Sök upp lån och registrera återlämning
avvikelser.php          Hantera avvikelser på ofullständigt återlämnade knippor
ej_aterlamnade.php      Lån som passerat sitt slutdatum (eller är idag)
installningar.php       Användare, integrationer, API-lösenord, fältkrav
import_export.php       CSV-import (nycklar/låntagare) och export (utlåningar)
api_nyckelsystem.php    Utgående JSON-API: nyckelsystem
api_nycklar.php         Utgående JSON-API: nycklar
api_lantagare.php       Utgående JSON-API: låntagare
api_utlaningar.php      Utgående JSON-API: aktuella utlåningar
README.md               Denna fil
```

### Delade hjälpfunktioner (`config.php`)

Nästan all återanvändbar logik bor i `config.php`, så det finns bara ett
ställe att ändra när en regel behöver justeras:

| Funktion | Används för |
|---|---|
| `h()` | HTML-escaping |
| `inloggad()` / `krav_inloggning()` / `inloggad_id()` / `inloggad_namn()` | Sessionsstatus |
| `dolj_om_utloggad()` | Maskerar känsliga fält (personnummer, kod) för utloggade |
| `csrf_falt()` / `csrf_verifiera()` | CSRF-skydd på formulär |
| `satt_flash()` / `hamta_flash()` / `visa_flash()` | Flash-meddelanden efter redirect |
| `hamta_faltinstallningar()` / `kravattribut()` / `kravstjarna()` | Obligatoriska fält per register |
| `sok_villkor()` | Fritextsökning över flera kolumner |
| `sakerstall_sortering()` / `sorteringshuvud()` | Vitlistad, klickbar kolumnsortering |
| `hamta_sida()` / `sidlank()` / `sidbladdring()` / `visar_antal_text()` | Sidbläddring |
| `exportera_csv()` | CSV-export av ett godtyckligt urval |
| `slaihop_eller_infoga()` | "Lägg till"-logik: slå ihop med befintlig post eller skapa ny |
| `uppdatera_post()` | "Redigera"-logik: uppdaterar en post direkt via ID |
| `sakert_ta_bort()` | Borttagning som översätter FK-konflikter till vänliga felmeddelanden |
| `kombinera_giltighet()` / `lantagare_ar_giltig()` | Giltighetslogik (person + grupp, mest restriktiva vinner) |
| `hamta_systeminstallningar()` / `spara_systeminstallning()` | Nyckel/värde-inställningar |
| `hamta_giltighet_fran_avtalskatalog()` / `hamta_fran_fastighetsteknik()` | Platshållare för externa integrationer |
| `api_krav_giltigt_losenord()` / `api_svara()` | Autentisering och svar för `api_*.php` |

### Säkerhet

- Alla databasanrop använder prepared statements (mysqli).
- Inloggningslösenord hashas med bcrypt; det delade API-lösenordet
  likaså.
- CSRF-skydd via sessionstoken på alla formulär som ändrar data.
- Personnummer och nyckelskåpskod maskeras i gränssnittet - och
  utesluts helt ur både sökning och export - för den som inte är
  inloggad, så ett dolt värde inte kan avslöjas genom att pröva sig fram.
- **Integrationslösenorden** (Avtalskatalog, Fastighetsteknik) lagras i
  **klartext** i `nyko_systeminstallningar`, till skillnad från
  inloggnings- och API-lösenord. Anledningen är att de måste **skickas
  till** ett externt system (vi måste kunna läsa tillbaka dem), medan
  de andra bara någonsin behöver **verifieras**. Rimlig avvägning givet
  att hela appen redan förutsätter en intern, oexponerad servermiljö -
  men värt att känna till.
- Applikationen har inget eget skydd mot brute-force-inloggningsförsök;
  det förutsätts att servern inte är nätverksmässigt exponerad.

### Designprinciper

- **En nyckel har antingen `KnippaID` eller `NyckelskapID` satt, aldrig
  båda** - kontrolleras med en `CHECK`-constraint i databasen samt i
  PHP-logiken.
- **Vid utlåning av en knippa skapas en `nyko_lanrad`-post per nyckel** i
  knippan (med `KnippaID` ifyllt), inte en enda rad för hela knippan.
  Det gör att delvis återlämning och avvikelsehantering blir spårbart
  per nyckel.
- **Avvikelser släcks aldrig automatiskt**, bara manuellt via
  Avvikelser-sidan - även om resten av en ofullständig knippa kommer
  tillbaka senare.
- **Giltighet (Avstängd/Giltig till) är alltid "mest restriktiva vinner"**
  mellan en låntagare och dennes grupp - aldrig tvärtom.
- **Externa integrationer byggs i två steg:** fält + inställningar +
  en anropspunkt läggs till direkt, men själva nätverksanropet lämnas
  som en tydlig platshållarfunktion tills det externa systemet faktiskt
  finns. Undviker att bygga mot ett gissat kontrakt.
- **Sidbläddring görs i PHP** (`array_slice()` på ett redan hämtat,
  filtrerat och sorterat resultat) snarare än med SQL LIMIT/OFFSET -
  enklare kod, gott och väl tillräckligt snabbt för den datamängd en
  intern verktygsapp som den här realistiskt hanterar.

---

## Kända begränsningar och planerat

- **Statistikvy:** antal påbörjade lån per tidsperiod, hur många som
  lämnas tillbaka i tid och hur många som blir försenade. Planerad som
  en egen vy att bygga vidare på.
- **Tappad-flaggan påverkar inte knippans status:** en nyckel som sitter
  i en knippa och markeras Tappad räknas ändå som "hemma" i knippans
  egen Hemma/Delvis utlånad/Utlånad-beräkning i `knippor.php` och
  `utlaning.php`.
- **Avtalskatalog och Fastighetsteknik** är fält- och
  inställningsmässigt klara men anropar inga riktiga system än - se
  `hamta_giltighet_fran_avtalskatalog()` och `hamta_fran_fastighetsteknik()`
  i `config.php`.
- **API-lösenordet är delat mellan alla fyra endpoints** och inte kopplat
  till en enskild klient/användare - går att verifiera att ett anrop var
  giltigt, men inte att spåra vilket system som gjorde det. Räcker för
  ett fåtal betrodda interna konsumenter.
- **CSV-export för nycklar/låntagare-registren via Import/Export-sidan**
  finns bara för utlåningar där; övriga register exporteras istället
  direkt från respektive registersida.
