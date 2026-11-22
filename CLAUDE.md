# local_leerlingendashboard

Moodle local plugin voor Muzieklab: klasoverzicht voor de leerkracht (later ook Mijn Muzieklab voor leerlingen).

## Omgeving
- Moodle 5.2, code in /var/www/moodle/public. Plugin staat in public/local/leerlingendashboard.
- PHP 8.3. Draai CLI altijd als: sudo -u www-data /usr/bin/php8.3 <script>
- Dit is een test-VM. Gebruik alleen testleerlingen, nooit echte leerlinggegevens.
- Taal van de gebruikersinterface: Nederlands (lang/nl) en Engels (lang/en).

## Werkwijze
- Een aparte branch per fase, bv. fase-2-klasoverzicht. Nooit rechtstreeks op main.
- Commit en push naar GitHub voor Michel een snapshot terugzet.
- Een privacy-provider is verplicht zodra de plugin zelf gegevens bewaart.

## Het klasoverzicht: één rij per leerling, zes kolommen
1. Leerling (voor- en achternaam).
2. Cursus: de huidige cursus.
3. Cursusvoltooiing in %: de voortgang van de leerling in die cursus (activiteitsvoltooiing, zoals Moodle die zelf berekent).
4. Groepsgenoten: de groepsgenoten in de huidige cursus, met voor- en achternaam. Is er geen groepsgenoot, dan toont de cel "geen".
5. Open opdrachten (ja/nee): ja als er in de huidige cursus minstens één zichtbare Opdracht-activiteit (mod_assign) openstaat, dus zonder ingediende inzending. Met of zonder deadline. Verborgen opdrachten tellen niet mee.
6. Laptopnummer: het antwoord uit de Keuze-activiteit van de huidige cursus, waarvan het ID-nummer begint met "laptop". Geen eigen laptoptabel.

## Beslissingen
- Local plugin met eigen pagina's in de systeemcontext (site-breed), geen blok. De pagina zet zelf de systeemcontext.
- Klas = cohort per lesmoment. Bovenaan kies je het cohort. Eerste versie toont alle cohorten.
- Huidige cursus = laatst geopende cursus (user_lastaccess), in welke categorie ook. De leerkracht kan per leerling een cursus vastzetten, en die gaat voor. De plugin bewaart die vastgezette cursus zelf.
- Opdracht = alleen mod_assign. Ingeleverd = inzending met status "ingediend". De mediaspeler en andere activiteiten tellen alleen mee in de voortgang %.
- Eerste versie heeft geen leerlingweergave.
- Rechten (capabilities): viewclass voor de leerkracht, later viewown voor de leerling. Leerlingen krijgen die later via de rol "geverifieerde gebruiker".
- Maatwerk voor deze eigen leeromgeving, een brede uitrol is geen ontwerpdoel.
- Moodle 5.2: tabellen en pagina's volgens de Moodle-conventies (Mustache, eigen layout zonder blokken).

## Fases
Fase 2 is het ontwerp (queries, rechten, privacy) en de bouw van de klaspagina. Dat gebeurt in deze volgorde: eerst een ontwerp dat Michel nakijkt, dan pas code.
