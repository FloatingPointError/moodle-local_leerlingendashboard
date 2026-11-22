# Ontwerp fase 2: klasoverzicht

Status: concept, reviewrondes 1 tot 4 van Michel verwerkt (22-11-2026).
Basis: CLAUDE.md, Moodle 5.2 (5.2.5+, build 20261117), PHP 8.3.

## 1. Overzicht

Eén pagina `/local/leerlingendashboard/index.php` in de systeemcontext. Bovenaan een
keuzelijst met cohorten (klas = cohort per lesmoment). Daaronder een tabel met één rij per
leerling uit het gekozen cohort en zes kolommen.

De meeste kolommen hangen af van de **huidige cursus** van de leerling. Die wordt dus
eerst per leerling bepaald, en daarna worden kolom 3 tot 6 voor dat paar (leerling,
cursus) opgehaald.

Verwerking per paginaverzoek:

1. Leerlingen van het cohort ophalen (1 query).
2. Huidige cursus per leerling bepalen (2 queries voor de hele klas).
3. Per unieke huidige cursus: cursus-record en modinfo laden (gecachet door Moodle).
4. Per leerling: voortgang, groepsgenoten, open opdrachten, laptopnummer.

Een klas is klein (tientallen leerlingen), dus per-leerling-werk is aanvaardbaar. Waar het
kan, wordt per cursus in één query gebundeld.

## 2. Leerlingen van het cohort

```sql
SELECT u.id, u.firstname, u.lastname, <overige naamvelden>
  FROM {cohort_members} cm
  JOIN {user} u ON u.id = cm.userid
 WHERE cm.cohortid = :cohortid
   AND u.deleted = 0
   AND u.suspended = 0
 ORDER BY u.lastname, u.firstname
```

- Naamvelden via `\core_user\fields::for_name()`, zodat `fullname()` werkt en de
  site-instelling `fullnamedisplay` gerespecteerd wordt.
- Geschorste accounts (`u.suspended = 1`) worden niet getoond (beslist).
- Cohortenlijst bovenaan: alle zichtbare cohorten (`cohort.visible = 1`), uit
  systeem- én categoriecontexten. Eerste versie: geen filter per leerkracht.
  Je kiest één cohort; de tabel toont dan alle leerlingen van dat cohort (beslist).
  Zolang er geen cohort gekozen is, staat er alleen de keuzelijst.
- Een leerling kan in meer cohorten zitten en verschijnt dan in elke klas (beslist).
- Gekozen cohort gaat via een URL-parameter `cohortid` (geen gebruikersvoorkeur
  bewaren, zie privacy).

## 3. Kolom 1: Leerling

`fullname($user)`. Optioneel een link naar het profiel. Geen query extra.

## 4. Kolom 2: Huidige cursus

Regel: vastgezette cursus gaat voor, anders de laatst geopende cursus (`user_lastaccess`),
in welke categorie ook. **Beide alleen binnen de eigen cursussen van de leerkracht**
(zie 9.4). Twee leerkrachten kunnen voor dezelfde leerling dus een andere huidige
cursus zien. Voor een manager of admin tellen alle cursussen.

### 4.1 Eigen tabel `local_leerlingendashboard_pin`

| veld          | type     | opmerking                                   |
|---------------|----------|---------------------------------------------|
| id            | int(10)  | primaire sleutel                            |
| userid        | int(10)  | de leerling, FK naar user                   |
| courseid      | int(10)  | de vastgezette cursus, FK naar course       |
| usermodified  | int(10)  | de leerkracht die vastzette, FK naar user   |
| timecreated   | int(10)  |                                             |
| timemodified  | int(10)  |                                             |

Unieke index op (`userid`, `courseid`): een leerling kan meer vastgezette cursussen
hebben, bijvoorbeeld één per leerkracht. Een leerkracht die vastzet, overschrijft dus
geen vastzetting van een collega in een andere cursus. Losmaken = die rij verwijderen.

Zijn er voor een leerkracht meer vastzettingen binnen de eigen cursussen (bv. twee
leerkrachten die dezelfde cursussen delen), dan telt de recentste (`timemodified`).
Wie mag vastzetten en losmaken: zie 9.4.

### 4.2 Queries

Vastgezette cursussen voor de hele klas:

```sql
SELECT p.userid, p.courseid
  FROM {local_leerlingendashboard_pin} p
 WHERE p.userid <IN leerlingen>
   AND p.courseid <IN eigen cursussen>      -- weglaten voor manager/admin
 ORDER BY p.userid, p.timemodified DESC
```

Laatst geopende cursus voor de hele klas:

```sql
SELECT ula.id, ula.userid, ula.courseid, ula.timeaccess
  FROM {user_lastaccess} ula
  JOIN {course} c ON c.id = ula.courseid
 WHERE ula.userid <IN leerlingen>
   AND c.id <> :siteid
   AND c.id <IN eigen cursussen>            -- weglaten voor manager/admin
 ORDER BY ula.userid, ula.timeaccess DESC
```

In PHP per leerling de eerste rij nemen (de recentste). `user_lastaccess` blijft
bestaan na uitschrijving, daarom de controle in 4.3.

### 4.3 Voorwaarden (beslist)

- Of de cursus nu vastgezet is of de laatst geopende is: is de leerling er niet meer
  **actief ingeschreven** (`is_enrolled($context, $userid, '', true)`), dan wordt de
  cursus toch getoond, met een waarschuwing ("niet meer ingeschreven").
  Kolom 3 tot 6 worden dan nog steeds berekend, voor zover Moodle de gegevens heeft.
- Geen cursus gevonden: cel toont "geen", kolom 3 tot 6 tonen "–".
- In de cel staat een icoon/label als de cursus vastgezet is, plus een knop
  "vastzetten/losmaken" (POST met `sesskey`, zie 9.4).
- Heeft de leerling geen enkele eigen cursus van de leerkracht geopend en is er niets
  vastgezet, dan toont de cel "geen". Cursussen van collega's komen nooit in beeld.

Opmerking: `user_lastaccess` wordt hoogstens elke 60 seconden bijgewerkt
(`LASTACCESS_UPDATE_SECS`). Voor dit doel ruim voldoende.

## 5. Kolom 3: Cursusvoltooiing in %

Het cijfer moet hetzelfde zijn als wat de leerling zelf bij Mijn cursussen ziet: de
activiteitsvoltooiing zoals Moodle die berekent, **met de zichtbaarheid van de leerling**.

### 5.1 Waarom niet gewoon `get_course_progress_percentage()`

De eerste versie van dit ontwerp gebruikte
`\core_completion\progress::get_course_progress_percentage($course, $userid)`. Op de
test-VM bleek dat die functie de zichtbaarheid bepaalt voor de **ingelogde gebruiker**,
niet voor `$userid`: `completion_info::get_activities()` gebruikt
`get_fast_modinfo($course)` van de huidige gebruiker. Een leerkracht ziet verborgen
activiteiten, dus die tellen mee en het percentage daalt. Gemeten op 22-11-2026:

| leerling, cursus            | bekeken door leerkracht | bekeken door leerling |
|-----------------------------|-------------------------|-----------------------|
| lln-a, Muzieklab test       | 60 %                    | 75 %                  |
| lln-c, Testcursus A         | 67 %                    | 100 %                 |
| lln-f, Testcursus A         | 33 %                    | 50 %                  |

Daarnaast bewaart de functie het resultaat in een request-cache per (cursus, leerling),
los van wie kijkt.

### 5.2 Berekening (beslist in reviewronde 4)

Dezelfde stappen als Moodle, maar met modinfo van de leerling. Alleen het selecteren van
de activiteiten doen we zelf; het tellen van de voltooide activiteiten blijft Moodle's
eigen `count_modules_completed()`.

```php
$completion = new completion_info($course);
if (!$completion->is_enabled() || !$completion->is_tracked_user($userid)) {
    return null;                                    // cel: "–"
}
if ($completion->is_course_complete($userid)) {
    return 100;
}
$cmids = [];
foreach (get_fast_modinfo($course, $userid)->get_cms() as $cm) {   // modinfo van de leerling
    if ($cm->completion == COMPLETION_TRACKING_NONE || $cm->deletioninprogress) {
        continue;
    }
    if (!$cm->is_visible_on_course_page()) {        // zoals de leerling het ziet
        continue;
    }
    if (!empty($cm->availability) || (int)$cm->groupmode === SEPARATEGROUPS
            || !empty($cm->groupingid)) {
        $info = new \core_availability\info_module($cm);
        if (!$info->filter_user_list([$userid => (object)['id' => $userid]])) {
            continue;                               // groeps-/groeperingsbeperking
        }
    }
    $cmids[] = $cm->id;
}
if (!$cmids) {
    return null;                                    // cel: "–"
}
return $completion->count_modules_completed($userid, $cmids) / count($cmids) * 100;
```

Dit is een kopie van de logica in `completion_info::get_user_activities_with_completion()`
(Moodle 5.2). Bij een Moodle-upgrade die code opnieuw vergelijken; een PHPUnit-test
vergelijkt onze uitkomst met `get_course_progress_percentage()` terwijl de leerling zelf
is ingelogd.

### 5.3 Weergave

- `null` (voltooiing in de cursus uit, leerling niet gevolgd, bv. niet meer
  ingeschreven, of geen activiteiten met voltooiing): cel toont "–".
- Wel gevolgd maar nog niets voltooid: "0 %" (beslist).
- Cursus als geheel voltooid: 100 %.
- Afronden op een geheel getal.
- Activiteiten met een toegangsbeperking die de leerling grijs ziet, tellen mee, net
  als bij Moodle zelf. Een beperking die de activiteit helemaal verbergt, telt niet mee.
  Dat is de voltooiingsregel van Moodle en staat los van de regel voor open opdrachten
  in hoofdstuk 7.

Gecontroleerd op de test-VM, ingelogd als `lk-test`: lln-a 75, lln-b 25, lln-d 0
(Muzieklab test); lln-c 100, lln-f 50, lln-g 0 (Testcursus A); lln-f 0 (Testcursus B).
Dat zijn dezelfde cijfers als de leerling zelf ziet.

## 6. Kolom 4: Groepsgenoten

Alle andere leden van de groepen waarin de leerling zit, in de huidige cursus.

```sql
SELECT DISTINCT u.id, <naamvelden>
  FROM {groups_members} gm1
  JOIN {groups} g         ON g.id = gm1.groupid AND g.courseid = :courseid
  JOIN {groups_members} gm2 ON gm2.groupid = g.id AND gm2.userid <> gm1.userid
  JOIN {user} u           ON u.id = gm2.userid AND u.deleted = 0 AND u.suspended = 0
  JOIN (<get_enrolled_sql($coursecontext, '', 0, true)>) je ON je.id = u.id
 WHERE gm1.userid = :userid
 ORDER BY u.lastname, u.firstname
```

- `get_enrolled_sql(..., onlyactive = true)` sluit uitgeschreven of geschorste
  inschrijvingen uit.
- Om per cursus maar één query te doen: dezelfde query met `gm1.userid IN (...)` en
  `gm1.userid` meeselecteren, daarna in PHP verdelen.
- Zit de leerling in meer groepen: de unie van alle groepsgenoten, zonder dubbels.
- Geen groepsgenoot (of geen groep): cel toont "geen".
- Leerkrachten die lid zijn van de groep tellen ook als groepsgenoot (beslist).
  Er wordt dus niet op rol gefilterd; wel moeten ze actief ingeschreven zijn in de
  cursus (zoals elk groepslid).
- Geen beperking tot een groepering: alle groepen van de cursus tellen (beslist).

## 7. Kolom 5: Open opdrachten (ja/nee)

"Ja" als er in de huidige cursus minstens één zichtbare `mod_assign` is zonder
ingediende inzending.

### 7.1 Zichtbaarheid (beslist)

- Verborgen opdracht = verborgen, ook als de sectie wel getoond wordt. Telt niet mee.
- Verborgen sectie = verborgen. Moodle zet bij het verbergen van een sectie zelf
  `course_modules.visible = 0` voor alle activiteiten erin (de oude waarde staat in
  `visibleold`), dus dit valt onder dezelfde controle. Voor de zekerheid controleren
  we ook `course_sections.visible`.
- **Toegangsbeperking is niet verborgen.** De leerling moet de opdracht nog
  ontgrendelen (bijvoorbeeld door een vorige opdracht af te ronden), dus de opdracht
  telt wél mee als open. Daarom gebruiken we **niet** `cm_info::uservisible`, want dat
  sluit beperkte opdrachten uit.
- Een opdracht die zichtbaar is maar niet op de cursuspagina staat
  (`visibleoncoursepage = 0`, "beschikbaar via link") telt als zichtbaar.

```php
$modinfo = get_fast_modinfo($course);   // structuur van de cursus, niet per leerling
foreach ($modinfo->get_instances_of('assign') as $cm) {
    if (!$cm->visible || $cm->deletioninprogress) { continue; }
    if (!$cm->get_section_info()->visible) { continue; }
    ...
}
```

Dezelfde lijst geldt voor iedereen in de cursus, dus dit hoeft maar één keer per cursus.

### 7.2 Ingediend of niet

Voor alle zichtbare opdrachten van de cursus in één query:

```sql
SELECT s.assignment
  FROM {assign_submission} s
 WHERE s.assignment <IN zichtbare assign-ids>
   AND s.latest = 1
   AND s.status = 'submitted'            -- ASSIGN_SUBMISSION_STATUS_SUBMITTED
   AND (
         (s.userid = :userid AND s.groupid = 0)                -- individuele inzending
      OR (s.userid = 0 AND s.groupid <IN groepen leerling>)    -- groepsinzending
       )
```

- Opdracht is open als er geen rij is. "Concept" (`draft`), "nieuw" (`new`),
  "heropend" (`reopened`) en geen rij tellen als open.
- `latest = 1` zodat een oude ingediende poging na heropenen niet meetelt.
- Groepsinzending (`assign.teamsubmission = 1`): de inzending staat op de groep.
  Er zijn nu geen opdrachten op de test-VM, maar de query houdt er rekening mee.
- Datums worden niet bekeken (beslist). Een opdracht blijft open als de deadline of
  afsluitdatum voorbij is, en ook als "inzenden vanaf" nog in de toekomst ligt.
- Opdrachten zonder online inzending (alleen offline of feedback) krijgen nooit een
  inzending en staan dus altijd open (beslist). Als dat ongewenst is, past de
  leerkracht de opdracht in de cursus aan (verbergen of online inzending aanzetten).
- Optioneel tonen we bij "ja" het aantal open opdrachten als tooltip. Kost niets extra.

## 8. Kolom 6: Laptopnummer

Het antwoord in de Keuze-activiteit van de huidige cursus waarvan het ID-nummer begint
met "laptop".

```sql
SELECT ca.id, ca.userid, ca.timemodified, co.text, cm.id AS cmid
  FROM {course_modules} cm
  JOIN {modules} m          ON m.id = cm.module AND m.name = 'choice'
  JOIN {course_sections} cs ON cs.id = cm.section
  JOIN {choice_answers} ca  ON ca.choiceid = cm.instance
  JOIN {choice_options} co  ON co.id = ca.optionid
 WHERE cm.course = :courseid
   AND cm.visible = 1
   AND cs.visible = 1
   AND cm.deletioninprogress = 0
   AND " . $DB->sql_like('cm.idnumber', ':prefix', false) . "   -- 'laptop%', hoofdletterongevoelig
   AND ca.userid <IN leerlingen>
 ORDER BY ca.userid, ca.timemodified DESC, ca.id DESC
```

- Eén query per cursus voor alle leerlingen samen.
- Weergave via `format_string($co->text)`.
- Geen antwoord of geen (zichtbare) laptop-keuze in de cursus: cel toont "–".
- Een verborgen laptop-keuze (of een keuze in een verborgen sectie) telt niet mee
  (beslist).
- Er hoort maar één laptop-keuze per cursus te zijn. Zijn er toch meer, dan telt
  **het recentste antwoord** van de leerling, in welke laptop-keuze ook (beslist:
  "de laatste keuze telt"). In PHP de eerste rij per leerling nemen.
- Keuze met "meer dan één antwoord": ook dan telt alleen het recentste antwoord.

## 9. Rechten (db/access.php)

### 9.1 Uitgangspunt (beslist)

De leerkracht houdt gewoon de bestaande rol **leraar** (`editingteacher`), die op deze
site per cursus wordt toegewezen. Er komt geen aparte systeemrol.

Gevolg: een rol die je in een cursus toewijst, geeft geen rechten in de
systeemcontext. `require_capability(..., context_system::instance())` zou de leerkracht
dus altijd weigeren. Daarom ziet de toegangscontrole er zo uit:

- De capability wordt gedefinieerd op **cursusniveau** (`CONTEXT_COURSE`), met de
  archetypes `editingteacher` en `manager` op allow. Zo krijgt de rol leraar ze
  automatisch bij installatie.
- De pagina zelf blijft in de systeemcontext (zoals CLAUDE.md vraagt).
- Je hebt toegang als je de capability hebt in de systeemcontext (manager, admin)
  **of in minstens één cursus**:

```php
function local_leerlingendashboard_can_view(): bool {
    global $USER;
    if (has_capability('local/leerlingendashboard:viewclass', context_system::instance())) {
        return true;
    }
    return (bool) get_user_capability_course('local/leerlingendashboard:viewclass',
        $USER->id, true, '', '', 1);
}
```

Wat een leerkracht dan te zien krijgt, staat in 9.4.

### 9.2 Capabilities

| capability                                | type  | contextniveau | risico          | archetypes                       |
|-------------------------------------------|-------|---------------|-----------------|----------------------------------|
| `local/leerlingendashboard:viewclass`     | read  | cursus        | `RISK_PERSONAL` | editingteacher, manager: allow   |

- `viewclass`: de klaspagina bekijken, en cursussen vastzetten en losmaken (beslist:
  geen aparte capability voor vastzetten).
- De niet-bewerkende leraar (`teacher`) krijgt niets. Wil je dat wel, dan voeg je dat
  toe in de roldefinitie.
- `viewown` voor de leerling komt pas in een latere fase; nu nog niet definiëren
  (een nieuwe capability later toevoegen is gewoon een versieverhoging).

### 9.3 Paginaflow

```php
require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/leerlingendashboard/index.php', ['cohortid' => $cohortid]));
$PAGE->set_pagelayout('base');   // Boost: 'base' heeft geen blokregio's
if (!local_leerlingendashboard_can_view()) {
    throw new required_capability_exception($context, 'local/leerlingendashboard:viewclass',
        'nopermissions', '');
}
```

- Vastzetten: aparte `pin.php`, alleen POST, met `require_sesskey()` en de controles
  uit 9.4.

### 9.4 Wat ziet een leerkracht (beslist)

Een leerkracht ziet alleen de cursussen waarin hij/zij zelf leraar is. Per cohort kunnen
er dus meerdere leerkrachten zijn, die elk alleen hun eigen cursussen zien.

"Eigen cursussen" = de cursussen waarin de gebruiker `viewclass` heeft:

```php
$mycourses = get_user_capability_course('local/leerlingendashboard:viewclass',
    $USER->id, true, 'id');
```

Wie `viewclass` in de systeemcontext heeft (manager, admin), ziet alle cursussen.

Uitwerking:

- **Rijen:** alle leerlingen van het gekozen cohort, altijd. De klas is het cohort.
  Naam (kolom 1) is dus altijd zichtbaar.
- **Huidige cursus:** wordt per leerkracht berekend, alleen binnen de eigen cursussen
  (beslist in ronde 3): eerst de recentste eigen vastzetting, anders de laatst geopende
  eigen cursus. Kolom 3 tot 6 gaan over die cursus.
  Werkt een leerling nu in de cursus van een collega, dan zie je de laatste van jouw
  cursussen waarin de leerling werkte.
- **Vastzetten:** de leerkracht kan alleen een eigen cursus vastzetten, waarin de
  leerling ingeschreven is. De keuzelijst toont alleen die cursussen. Een bestaande
  vastzetting van dezelfde leerling binnen de eigen cursussen wordt vervangen; een
  vastzetting in een cursus van een collega blijft staan.
- **Losmaken:** mag elke leerkracht die `viewclass` heeft in de vastgezette cursus.
  Vastzettingen in cursussen van een collega ziet hij/zij niet.
- Een manager of admin ziet alle cursussen. Voor hen telt de recentste vastzetting,
  in welke cursus ook.

## 10. Privacy (classes/privacy/provider.php)

De plugin bewaart zelf persoonsgegevens (tabel `local_leerlingendashboard_pin`), dus een
volledige provider is verplicht:

- `\core_privacy\local\metadata\provider`: beschrijft de tabel en de velden `userid`,
  `courseid`, `usermodified`, `timecreated`, `timemodified` (met taalstrings in en/nl).
- `\core_privacy\local\request\plugin\provider` en
  `\core_privacy\local\request\core_userlist_provider`.

Context: de gegevens horen bij de leerling, dus ze worden geplaatst in de
**gebruikerscontext van de leerling**.

| methode                              | gedrag                                                                 |
|--------------------------------------|------------------------------------------------------------------------|
| `get_contexts_for_userid`            | gebruikerscontext van de leerling (als `userid`), plus de gebruikerscontexten van leerlingen waarvoor deze gebruiker `usermodified` is |
| `get_users_in_context`               | in een gebruikerscontext: de leerling en de `usermodified`             |
| `export_user_data`                   | de vastgezette cursus (naam, datum); voor de leerkracht: dat hij/zij die vastzette |
| `delete_data_for_all_users_in_context` | alle rijen van die leerling verwijderen                              |
| `delete_data_for_user`               | als leerling: rijen verwijderen. Als leerkracht: `usermodified` op 0 zetten, de vastzetting blijft |
| `delete_data_for_users`              | idem, voor een lijst gebruikers                                        |

Wat de plugin alleen **leest** (cohorten, groepen, voltooiing, inzendingen,
keuze-antwoorden, `user_lastaccess`) blijft eigendom van core en de betreffende
modules; die hebben hun eigen provider. De plugin bewaart daar geen kopie of cache van.

Opruimen buiten de privacy-API om (observers in `db/events.php`):

- `\core\event\user_deleted`: rijen met `userid` verwijderen, `usermodified` op 0.
- `\core\event\course_deleted`: rijen met die `courseid` verwijderen.

Geen gebruikersvoorkeur voor het laatst gekozen cohort (anders ook een
`user_preference_provider` nodig). Logging: een event
`\local_leerlingendashboard\event\course_pinned` (en `course_unpinned`) is nuttig als
spoor; dat gaat via de standaard logstore, die zijn eigen privacy regelt. Een
`class_viewed`-event is optioneel.

## 11. Bestanden in de bouwfase (ter info)

```
version.php
lang/en/local_leerlingendashboard.php
lang/nl/local_leerlingendashboard.php
db/access.php
db/install.xml
db/events.php
index.php
pin.php
classes/local/klasoverzicht.php      // de queries uit dit document
classes/output/klasoverzicht.php     // renderable/templatable
classes/observer.php
classes/privacy/provider.php
classes/event/course_pinned.php
classes/event/course_unpinned.php
templates/klasoverzicht.mustache
tests/klasoverzicht_test.php         // PHPUnit met testleerlingen
tests/privacy/provider_test.php
```

## 12. Testdata op de VM (stand 22-11-2026)

- Cohorten: "Muzieklab ma 16u" en "Muzieklab woe 14u", allebei **zonder leden**.
- Cursussen: ML Test, testcursus-a, testcursus-b (voltooiing aan; b zonder groepsmodus).
- 2 groepen, 0 opdrachten (`mod_assign`), 0 keuze-activiteiten met ID-nummer "laptop…".

Voor een zinvolle test zijn testleerlingen in de cohorten, opdrachten (ook een
groepsopdracht en een verborgen opdracht) en een laptop-keuze nodig.

## 13. Beslissingen uit de review (22-11-2026)

Ronde 1:

1. Leerkracht = gewone rol leraar, geen aparte systeemrol (zie 9.1).
2. Je kiest één cohort; de tabel toont alle leerlingen van dat cohort.
3. Verborgen opdracht of verborgen sectie = verborgen. Toegangsbeperking = niet
   verborgen, telt mee als open.
4. Datums tellen niet mee. Opdrachten zonder online inzending staan altijd open.
5. Maar één laptop-keuze per cursus; zijn er meer, dan telt het recentste antwoord.
   Een verborgen laptop-keuze telt niet mee.
6. Niet meer ingeschreven: de cursus wordt getoond, met een waarschuwing.
7. Geschorste leerlingen worden niet getoond.
8. Een leerling kan in meer cohorten zitten en staat dan in elke klas.
9. Voortgang: "–" als voltooiing uit staat of de leerling niet gevolgd wordt, anders
   het percentage, ook "0 %".

Ronde 2:

10. Vastzetten komt in fase 2. Elke leraar mag vastzetten en losmaken, zonder aparte
    capability (zie 9.2 en 9.4).
11. Leerkrachten die lid zijn van een groep tellen als groepsgenoot. Geen beperking tot
    een groepering.
12. Leerkrachten zien alleen de cursussen waarin ze zelf leraar zijn (zie 9.4).

Ronde 3:

13. De huidige cursus wordt per leerkracht bepaald, alleen binnen de eigen cursussen.
    Vastzettingen gelden per cursus (meer per leerling mogelijk), zie 4.1 en 9.4.

Ronde 4:

14. Voortgang wordt berekend met de zichtbaarheid van de leerling, niet van de
    leerkracht (zie 5.2).

## 14. Nog open

Geen. Het ontwerp is klaar voor een laatste blik en daarna de bouw.
