# Ontwerp fase 3: Mijn Muzieklab

Status: review ronde 1 verwerkt (22-11-2026). Gebouwd op branch fase-3-mijn-muzieklab.
Basis: CLAUDE.md (fase 3), het ontwerp van fase 2 (`docs/ontwerp-fase-2.md`), Moodle 5.2.

## 1. Doel

Een pagina voor de leerling zelf: **Mijn Muzieklab**. De leerling ziet in één oogopslag
waar hij of zij nu aan werkt: dezelfde gegevens die de leerkracht in het klasoverzicht
ziet, maar alleen over zichzelf, en met wat extra hulp (welke opdrachten nog openstaan,
met een link).

Niet in fase 3: gegevens van andere leerlingen (behalve de namen van groepsgenoten),
iets wijzigen (geen vastzetten door de leerling), cijfers of feedback.

## 2. Wat de leerling ziet (voorstel)

Eén pagina `/local/leerlingendashboard/mijn.php`, in de systeemcontext, layout `base`
(geen blokken), net als het klasoverzicht.

**Blok "Mijn huidige cursus"** (beslist: alleen dit blok, geen eigen lijst "Mijn cursussen"; Moodle heeft die pagina al):

| onderdeel | inhoud |
|---|---|
| Cursus | naam van de huidige cursus, met link. Is ze vastgezet door een leerkracht: label "Vastgezet door je leerkracht" (zonder naam van de leerkracht). |
| Voortgang | het percentage als voortgangsbalk, dezelfde berekening als in het klasoverzicht (hoofdstuk 5 van fase 2). |
| Groepsgenoten | voor- en achternaam, of "geen". Met de zichtbaarheidsregels van Moodle, zie 5. |
| Open opdrachten | een **lijst** in plaats van ja/nee: naam met link, en de deadline als die er is. Zie 6. |
| Laptopnummer | het antwoord uit de laptop-keuze. Nog geen antwoord: een link "Kies je laptop" naar die keuze. Zie 7. |

Geen huidige cursus: een korte uitleg ("Je hebt nog geen cursus geopend") en een link
naar Mijn cursussen.

## 3. Huidige cursus voor de leerling

Dezelfde regel als in fase 2, maar zonder beperking tot de cursussen van een leerkracht:

1. de **recentste vastzetting** van deze leerling, door welke leerkracht ook;
2. anders de **laatst geopende cursus** (`user_lastaccess`), in welke categorie ook.

Verschil met het klasoverzicht: de leerkracht ziet een cursus ook als de leerling er niet
meer actief ingeschreven is (met waarschuwing). De leerling kan zo'n cursus niet openen.
Beslist: voor de leerling tellen **alleen cursussen met een actieve inschrijving**; een
vastzetting of laatst geopende cursus zonder actieve inschrijving wordt overgeslagen
(dan de volgende kandidaat).

## 4. Rechten (db/access.php)

| capability | type | contextniveau | risico | archetypes |
|---|---|---|---|---|
| `local/leerlingendashboard:viewown` | read | systeem | geen | user: allow |

- De rol "Geauthenticeerde gebruiker" (shortname `user`, in CLAUDE.md "geverifieerde
  gebruiker") is de standaardrol van iedereen die aangemeld is
  (`$CFG->defaultuserroleid`), en die geldt in de systeemcontext. Een capability in de
  systeemcontext met archetype `user` werkt dus meteen, zonder rol toe te wijzen.
- Gevolg: ook leerkrachten en de admin hebben `viewown`. Dat is geen probleem: de pagina
  toont alleen gegevens over de gebruiker zelf.
- Gasten hebben de rol `user` niet, en `require_login()` met gastweigering houdt ze tegen.
- Geen `RISK_PERSONAL`: de gebruiker ziet alleen eigen gegevens en namen van groepsgenoten,
  die Moodle bij groepszichtbaarheid "Iedereen" of "Leden" ook al toont.

Paginaflow:

```php
require_login(null, false);
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/leerlingendashboard/mijn.php'));
$PAGE->set_pagelayout('base');
require_capability('local/leerlingendashboard:viewown', $context);
```

**Navigatie** (beslist): een link "Mijn Muzieklab" in de primaire navigatie voor
wie `viewown` heeft **en** lid is van minstens één cohort (= leerling van een klas).
Leerkrachten zitten niet in een cohort en zien de link dus niet; ze zien wel
"Klasoverzicht". Mijn Muzieklab wordt **niet** de startpagina; het Moodle-dashboard blijft.

## 5. Groepsgenoten en groepszichtbaarheid

Sinds Moodle 4.2 heeft elke groep een zichtbaarheid (`groups.visibility`):

| waarde | betekenis in Moodle | groepsgenoten tonen aan de leerling? |
|---|---|---|
| 0 Iedereen (`GROUPS_VISIBILITY_ALL`) | iedereen ziet de groep en de leden | ja |
| 1 Leden (`GROUPS_VISIBILITY_MEMBERS`) | leden zien de groep en elkaar | ja |
| 2 Eigen (`GROUPS_VISIBILITY_OWN`) | leden zien alleen hun eigen lidmaatschap | **nee** |
| 3 Niemand (`GROUPS_VISIBILITY_NONE`) | leden weten niet dat ze in de groep zitten | **nee** |

Voorstel: Mijn Muzieklab volgt die regels, met Moodle's eigen hulpfunctie
`\core_group\visibility::sql_member_visibility_where()`. Het klasoverzicht verandert niet:
een leerkracht ziet alle groepen (zoals in Moodle zelf). Op de test-VM staan alle groepen
nu op "Iedereen".

## 6. Open opdrachten voor de leerling

Dezelfde regel als in fase 2 (hoofdstuk 7): zichtbare `mod_assign` in een zichtbare
sectie, zonder ingediende inzending (ook groepsinzending), datums tellen niet mee,
toegangsbeperking telt als open.

Wat de leerling te zien krijgt, hangt af van hoe Moodle de opdracht zelf toont
(`cm_info` met de modinfo van de leerling):

| situatie | getoond als |
|---|---|
| beschikbaar (`uservisible`) | naam met link, deadline als die er is |
| beperkt, maar Moodle toont ze grijs met uitleg (`is_visible_on_course_page()`) | naam zonder link, met slotje en "Nog vergrendeld" |
| beperkt en helemaal verborgen tot ze ontgrendeld is | alleen meegeteld: "en 1 opdracht die je later ontgrendelt", zonder naam |

Zo verklapt de pagina niets dat Moodle zelf voor de leerling verbergt (beslist).
Volgorde: zoals op de cursuspagina (sectie, dan positie).

## 7. Laptopnummer

Dezelfde regel als in fase 2 (hoofdstuk 8): het recentste antwoord in een zichtbare keuze
met ID-nummer `laptop…`. Extra voor de leerling: is er nog geen antwoord en is de
laptop-keuze voor de leerling beschikbaar (`uservisible`), dan een link "Kies je laptop".

## 8. Hergebruik van de code van fase 2

De berekeningen staan nu in `local\klasoverzicht`, gebundeld per cursus voor een hele
klas. Voorstel:

- De per-cursus berekeningen (voortgang, groepsgenoten, open opdrachten, laptop) verhuizen
  naar een aparte klasse, bv. `local\leerlingdata`, die een lijst leerlingen en een cursus
  krijgt. Het klasoverzicht en Mijn Muzieklab gebruiken die allebei. Zo kan een regel
  maar op één plek veranderen.
- Open opdrachten geeft een **lijst** (per opdracht: cm, naam, deadline) terug in plaats
  van een aantal. Het klasoverzicht telt die lijst.
- Groepsgenoten krijgt een optie "met groepszichtbaarheid" (aan voor de leerling, uit voor
  de leerkracht).
- De bestaande tests van fase 2 moeten na de verhuizing ongewijzigd slagen.

## 9. Privacy

- Mijn Muzieklab **bewaart niets** nieuws: geen tabel, geen gebruikersvoorkeur. De
  privacy-provider van fase 2 blijft gelden (vastzettingen); er komt niets bij.
- De leerling ziet de naam van een vastzettende leerkracht niet ("je leerkracht").
- Namen van groepsgenoten alleen volgens de groepszichtbaarheid (5).
- Geen event bij het bekijken van de pagina (Moodle logt het paginabezoek niet vanzelf;
  een `dashboard_viewed`-event voegt weinig toe). Beslist: geen event.

## 10. Testen

- PHPUnit: de bestaande 21 tests blijven slagen na de verhuizing (8); nieuwe tests voor
  `viewown`, de huidige cursus van de leerling (actieve inschrijving), groepszichtbaarheid
  (alle vier de waarden), de drie situaties van open opdrachten en de laptop-link.
- `cli/testdata_vm.php` en `docs/testdata-vm.md` uitbreiden: een groep met zichtbaarheid
  "Eigen" en een met "Niemand", een opdracht die helemaal verborgen is tot ze ontgrendeld
  is, en het verwachte resultaat per leerling.
- Verwacht resultaat: zie `docs/testdata-vm.md`, hoofdstuk "Verwacht resultaat in Mijn
  Muzieklab". Gecontroleerd op de test-VM, als elke leerling zelf en via HTTP.

## 11. Bestanden (ter info)

```
mijn.php                                  // nieuw
classes/local/leerlingdata.php            // nieuw: gedeelde berekeningen per cursus (8)
classes/local/huidigecursus.php           // nieuw: huidige cursus, voor leerkracht en leerling (3, 8)
classes/local/klasoverzicht.php           // gebruikt leerlingdata
classes/output/mijn_muzieklab.php         // nieuw: renderable
templates/mijn_muzieklab.mustache         // nieuw
classes/hook_callbacks.php                // link "Mijn Muzieklab"
db/access.php                             // viewown
lang/en, lang/nl                          // nieuwe strings
version.php                               // versieverhoging
tests/mijn_muzieklab_test.php             // nieuw (7 tests)
cli/testdata_vm.php, docs/testdata-vm.md  // uitbreiding
```

## 12. Uitgesteld uit fase 2

Beslist: **niet in fase 3**, pas veel later:

- CI op GitHub (`moodle-plugin-ci`), eventueel als verplichte check in de ruleset;
- een echte grunt-build voor het JavaScript;
- het klasoverzicht vanzelf laten bijwerken (verversen volstaat).

## 13. Beslissingen uit de review (22-11-2026)

Ronde 1:

1. Alleen het blok "Mijn huidige cursus"; geen eigen lijst "Mijn cursussen" (2).
2. De link "Mijn Muzieklab" is er voor wie in minstens één cohort zit. Mijn Muzieklab
   wordt niet de startpagina (4).
3. Vergrendelde opdrachten: de drie situaties in 6.
4. Voor de leerling tellen alleen cursussen met een actieve inschrijving (3).
5. Geen event bij het bekijken van Mijn Muzieklab (9).
6. CI, grunt-build en vanzelf bijwerken: niet in fase 3, pas veel later (12).

## 14. Nog open

Geen.
