# Testgegevens op de test-VM

Script: `cli/testdata_vm.php`. **Alleen voor de test-VM**, nooit op een site met echte
leerlinggegevens.

```
cd /var/www/moodle/public
sudo -u www-data /usr/bin/php8.3 local/leerlingendashboard/cli/testdata_vm.php --run
```

Het script heeft twee delen. Het deel van fase 2 draait alleen als `lln-c` nog niet bestaat,
het deel van fase 3 alleen als de groep "Duo B" nog niet bestaat. Bestaan ze allebei, dan
stopt het zonder iets te doen. Alles gebeurt in één databasetransactie; bij een fout wordt
niets bewaard.

## Beginsituatie (snapshot)

- Cursussen `ML Test` (Muzieklab test), `testcursus-a`, `testcursus-b`.
- In Muzieklab test: leraar `lk-test`, leerlingen `lln-a` en `lln-b`, groepen
  "Groep A" (lln-a, lk-test) en "Groep B" (lln-b, lk-test), en een mediaspeler met
  voltooiing.
- Cohorten `muzieklab-ma-16u` en `muzieklab-woe-14u`, zonder leden.

## Wat het script aanmaakt

Wachtwoord van alle nieuwe accounts: `Test-Muzieklab1!`

- **Leerkrachten**
  - `lk-test` krijgt er Testcursus A bij, dus Muzieklab test en Testcursus A.
  - `lk-test2` is nieuw: Testcursus A en Testcursus B.
- **Leerlingen** `lln-c` tot `lln-h`. `lln-e` heeft een geschorst account.
- **Cohorten**
  - ma 16u: a, b, c, d, e.
  - woe 14u: d, f, g, h. lln-d zit in beide.
- **Inschrijvingen**
  - Muzieklab test: c, d, e.
  - Testcursus A: c, d, f, g, h. De inschrijving van lln-h wordt na een bezoek geschorst.
    (Niet uitgeschreven: dan verwijdert Moodle ook de `user_lastaccess`-rij.)
  - Testcursus B: f, h.
- **Groepen**
  - lln-c komt erbij in Groep A.
  - Testcursus A: "Duo 1" (c, f, lk-test2) en "Duo 2" (d, g).
- **Opdrachten**
  - Muzieklab test: Opdracht 1 (zichtbaar), Opdracht 2 (verborgen), Opdracht 3
    (toegangsbeperking: Opdracht 1 voltooid).
  - Testcursus A: Duo-opdracht (groepsinzending), Opdracht individueel, Opdracht in
    verborgen sectie 3.
  - Testcursus B: Opdracht zonder online inzending.
- **Inzendingen**
  - lln-a: Opdracht 1 en 3 ingediend.
  - lln-b: Opdracht 1 als concept.
  - Duo 1: Duo-opdracht ingediend.
  - lln-c: Opdracht individueel ingediend.
- **Laptop-keuzes**
  - `laptop-ma` in Muzieklab test.
  - `Laptop-woe` in Testcursus A (met hoofdletter, om hoofdletterongevoeligheid te testen).
  - `laptop-oud` in Testcursus A, verborgen.
- **Voltooiing** (handmatig): de inzendingen hierboven, plus de laptop-keuze voor lln-a.
- **Laatst geopend** (`user_lastaccess`): zie de tabel hieronder.

### Deel fase 3 (Mijn Muzieklab)

- **Groep "Duo B"** in Testcursus B (lln-f, lln-h) met zichtbaarheid **Eigen**: de
  leerkracht ziet lln-h als groepsgenoot van lln-f, lln-f zelf niet.
- **Opdracht 4 - verborgen tot ontgrendeld** in Muzieklab test: toegangsbeperking "Opdracht 1
  voltooid", helemaal verborgen zolang die niet voldaan is. lln-a heeft ze ingediend.
  Ze heeft voltooiing, dus ze telt mee in de voortgang van wie ze ziet (lln-a, lln-b).
- **Deadline** op Opdracht 1: een week na het draaien van het script, 09:00 (tijdzone van de
  site, Europe/London; lln-a, lln-b en lk-test staan op Europe/Brussels en zien 10:00).

## Verwacht resultaat op de klaspagina

| leerkracht | cohort | leerling | huidige cursus | % | groepsgenoten | open | laptop |
|---|---|---|---|---|---|---|---|
| lk-test | ma 16u | lln-a | Muzieklab test | 60 | Leerling C, Leerkracht Test | nee | Laptop 3 |
| lk-test | ma 16u | lln-b | Muzieklab test | 20 | Leerkracht Test | ja (concept) | Laptop 7 |
| lk-test | ma 16u | lln-c | Testcursus A | 100 | Leerling F, Leerkracht Twee | nee | Laptop 5 |
| lk-test | ma 16u | lln-d | Muzieklab test | 0 | geen | ja | – |
| lk-test | ma 16u | lln-e | (niet getoond: geschorst) | | | | |
| lk-test | woe 14u | lln-f | Testcursus A | 50 | Leerling C, Leerkracht Twee | ja | Laptop 9 |
| lk-test | woe 14u | lln-g | Testcursus A | 0 | Leerling D | ja | – (verborgen keuze) |
| lk-test | woe 14u | lln-h | Testcursus A + waarschuwing "niet meer ingeschreven" | – | geen | ja | – |
| lk-test2 | woe 14u | lln-d | Testcursus A | 0 | Leerling G | ja | – |
| lk-test2 | woe 14u | lln-f | Testcursus B | 0 | Leerling H (groep "Eigen": de leerkracht ziet alles) | ja (offline opdracht) | – |
| lk-test2 | woe 14u | lln-g | Testcursus A | 0 | Leerling D | ja | – |
| lk-test2 | woe 14u | lln-h | Testcursus A + waarschuwing | – | geen | ja | – |
| lk-test2 | ma 16u | lln-a, lln-b | geen | – | – | – | – |
| lk-test2 | ma 16u | lln-c | Testcursus A | 100 | Leerling F, Leerkracht Twee | nee | Laptop 5 |

Groepsgenoten staan gesorteerd op achternaam ("Leerling C" vóór "Leerkracht Test").
Kolom 2 toont onder elke cursus een keuzelijst "Vastzetten op..." met de eigen cursussen
waarin de leerling actief ingeschreven is. Een cursus kiezen zet die meteen vast en vernieuwt
alleen die rij. Is de cursus vastgezet, dan staat er ook een knop "Losmaken".

Een paar regels die je hiermee test:

- Een groepsinzending telt voor alle groepsleden: lln-c staat daardoor op "nee".
- Een opdracht met toegangsbeperking telt als open: lln-a moest Opdracht 3 ook indienen.
- Een opdracht in een verborgen sectie telt niet mee.
- Elke leerkracht ziet alleen zijn of haar eigen cursussen. Bij lln-f ziet lk-test
  Testcursus A en lk-test2 Testcursus B.

## Verwacht resultaat in Mijn Muzieklab (als de leerling zelf)

| leerling | huidige cursus | % | groepsgenoten | open opdrachten | laptop | menulink |
|---|---|---|---|---|---|---|
| lln-a | Muzieklab test | 60 | Leerling C, Leerkracht Test | geen | Laptop 3 | ja |
| lln-b | Muzieklab test | 20 | Leerkracht Test | Opdracht 1 (met deadline), Opdracht 3, Opdracht 4 | Laptop 7 | ja |
| lln-c | Testcursus A | 100 | Leerling F, Leerkracht Twee | geen | Laptop 5 | ja |
| lln-d | Muzieklab test | 0 | geen | Opdracht 1 (met deadline), Opdracht 3 (vergrendeld), "en 1 opdracht die je later ontgrendelt" | link "Kies je laptop" | ja |
| lln-f | Testcursus B | 0 | geen (groep "Eigen") | Opdracht zonder online inzending | – | ja |
| lln-g | Testcursus A | 0 | Leerling D | Duo-opdracht, Opdracht individueel | link "Kies je laptop" | ja |
| lln-h | geen huidige cursus (Testcursus A: inschrijving geschorst, Testcursus B nooit geopend) | | | | | ja |
| lk-test, lk-test2 | (geen menulink: niet in een cohort) | | | | | nee |
