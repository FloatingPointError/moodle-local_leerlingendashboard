# Testgegevens op de test-VM

Script: `cli/testdata_vm.php`. **Alleen voor de test-VM**, nooit op een site met echte
leerlinggegevens.

```
cd /var/www/moodle/public
sudo -u www-data /usr/bin/php8.3 local/leerlingendashboard/cli/testdata_vm.php --run
```

Het script stopt zonder iets te doen als `lln-c` al bestaat. Alles gebeurt in één
databasetransactie; bij een fout wordt niets bewaard.

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
  - Testcursus A: c, d, f, g. lln-h is er na een bezoek weer uitgeschreven.
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

## Verwacht resultaat op de klaspagina

| leerkracht | cohort | leerling | huidige cursus | % | groepsgenoten | open | laptop |
|---|---|---|---|---|---|---|---|
| lk-test | ma 16u | lln-a | Muzieklab test | 75 | Leerkracht Test, Leerling C | nee | Laptop 3 |
| lk-test | ma 16u | lln-b | Muzieklab test | 25 | Leerkracht Test | ja (concept) | Laptop 7 |
| lk-test | ma 16u | lln-c | Testcursus A | 100 | Leerling F, Leerkracht Twee | nee | Laptop 5 |
| lk-test | ma 16u | lln-d | Muzieklab test | 0 | geen | ja | – |
| lk-test | ma 16u | lln-e | (niet getoond: geschorst) | | | | |
| lk-test | woe 14u | lln-f | Testcursus A | 50 | Leerling C, Leerkracht Twee | ja | Laptop 9 |
| lk-test | woe 14u | lln-g | Testcursus A | 0 | Leerling D | ja | – (verborgen keuze) |
| lk-test | woe 14u | lln-h | Testcursus A + waarschuwing "niet meer ingeschreven" | – | geen | ja | – |
| lk-test2 | woe 14u | lln-d | Testcursus A | 0 | Leerling G | ja | – |
| lk-test2 | woe 14u | lln-f | Testcursus B | 0 | geen | ja (offline opdracht) | – |
| lk-test2 | woe 14u | lln-g | Testcursus A | 0 | Leerling D | ja | – |
| lk-test2 | woe 14u | lln-h | Testcursus A + waarschuwing | – | geen | ja | – |
| lk-test2 | ma 16u | lln-a, lln-b | geen | – | – | – | – |
| lk-test2 | ma 16u | lln-c | Testcursus A | 100 | Leerling F, Leerkracht Twee | nee | Laptop 5 |

Een paar regels die je hiermee test:

- Een groepsinzending telt voor alle groepsleden: lln-c staat daardoor op "nee".
- Een opdracht met toegangsbeperking telt als open: lln-a moest Opdracht 3 ook indienen.
- Een opdracht in een verborgen sectie telt niet mee.
- Elke leerkracht ziet alleen zijn of haar eigen cursussen. Bij lln-f ziet lk-test
  Testcursus A en lk-test2 Testcursus B.
