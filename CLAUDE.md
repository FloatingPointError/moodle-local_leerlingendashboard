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

## Beslissingen
- Local plugin met eigen pagina's in de systeemcontext (site-breed), geen blok.
- Klas = cohort per lesmoment. Eerste versie toont alle cohorten.
- Huidige cursus = laatst geopende cursus, in welke categorie ook. De leerkracht kan een cursus per leerling vastzetten, en die gaat voor.
- Laptop = antwoord uit de Keuze-activiteit van de huidige cursus, ID-nummer begint met "laptop". Geen eigen laptoptabel.
- Opdracht = alleen mod_assign. Ingeleverd = inzending met status "ingediend". De mediaspeler telt alleen mee in voortgang %.
- Eerste versie heeft geen leerlingweergave.
- Rechten: viewclass (leerkracht), viewown (leerling, later).
