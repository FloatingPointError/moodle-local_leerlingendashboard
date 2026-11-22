<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Maakt testgegevens aan voor het klasoverzicht (fase 2). ALLEEN VOOR DE TEST-VM.
 *
 * Verwachte beginsituatie (snapshot van de test-VM):
 * - cursussen met korte naam 'ML Test', 'testcursus-a' en 'testcursus-b';
 * - in 'ML Test': leraar lk-test, leerlingen lln-a en lln-b, groepen 'Groep A' en 'Groep B';
 * - cohorten met ID-nummer 'muzieklab-ma-16u' en 'muzieklab-woe-14u'.
 *
 * Het verwachte resultaat per leerkracht staat in docs/testdata-vm.md.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/choice/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

[$options, $unrecognised] = cli_get_params(['run' => false, 'help' => false], ['h' => 'help']);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}
if ($options['help'] || !$options['run']) {
    echo "Maakt testleerlingen, opdrachten, keuzes en voltooiing aan voor local_leerlingendashboard.
ALLEEN VOOR DE TEST-VM, nooit op een site met echte leerlinggegevens.

Gebruik:
  sudo -u www-data /usr/bin/php8.3 local/leerlingendashboard/cli/testdata_vm.php --run
";
    exit(0);
}

global $DB;

if ($DB->record_exists('user', ['username' => 'lln-c', 'deleted' => 0])) {
    cli_error('Testgegevens bestaan al (lln-c gevonden). Niets gedaan.');
}

// Geen e-mail versturen: de test-VM heeft geen mailserver, en Moodle stuurt meldingen
// over inzendingen pas na de commit (een fout daar breekt het script af).
$CFG->noemailever = true;

\core\session\manager::set_user(get_admin());
$transaction = $DB->start_delegated_transaction();
$gen = new testing_data_generator();
$assigngen = $gen->get_plugin_generator('mod_assign');
$password = 'Test-Muzieklab1!';

$c2 = $DB->get_record('course', ['shortname' => 'ML Test'], '*', MUST_EXIST);      // Muzieklab test.
$c3 = $DB->get_record('course', ['shortname' => 'testcursus-a'], '*', MUST_EXIST); // Testcursus A.
$c4 = $DB->get_record('course', ['shortname' => 'testcursus-b'], '*', MUST_EXIST); // Testcursus B.

$cohortma = $DB->get_record('cohort', ['idnumber' => 'muzieklab-ma-16u'], '*', MUST_EXIST);
$cohortwoe = $DB->get_record('cohort', ['idnumber' => 'muzieklab-woe-14u'], '*', MUST_EXIST);

// ---- Gebruikers ----
$u = [];
$u['lk'] = $DB->get_record('user', ['username' => 'lk-test'], '*', MUST_EXIST);
$u['a'] = $DB->get_record('user', ['username' => 'lln-a'], '*', MUST_EXIST);
$u['b'] = $DB->get_record('user', ['username' => 'lln-b'], '*', MUST_EXIST);
$u['lk2'] = $gen->create_user(['username' => 'lk-test2', 'firstname' => 'Leerkracht', 'lastname' => 'Twee',
    'password' => $password, 'email' => 'lk-test2@example.invalid']);
$new = ['c' => 'C', 'd' => 'D', 'e' => 'E', 'f' => 'F', 'g' => 'G', 'h' => 'H'];
foreach ($new as $k => $last) {
    $u[$k] = $gen->create_user(['username' => "lln-$k", 'firstname' => 'Leerling', 'lastname' => $last,
        'password' => $password, 'email' => "lln-$k@example.invalid", 'suspended' => ($k === 'e' ? 1 : 0)]);
}

// ---- Cohorten ----
foreach (['a', 'b', 'c', 'd', 'e'] as $k) {
    cohort_add_member($cohortma->id, $u[$k]->id);
}
foreach (['d', 'f', 'g', 'h'] as $k) {
    cohort_add_member($cohortwoe->id, $u[$k]->id);
}

// ---- Leraren en inschrijvingen ----
$gen->enrol_user($u['lk']->id, $c3->id, 'editingteacher');   // lk-test: cursus 2 + 3.
$gen->enrol_user($u['lk2']->id, $c3->id, 'editingteacher');  // lk-test2: cursus 3 + 4.
$gen->enrol_user($u['lk2']->id, $c4->id, 'editingteacher');
foreach (['c', 'd', 'e'] as $k) {
    $gen->enrol_user($u[$k]->id, $c2->id, 'student');
}
foreach (['c', 'd', 'f', 'g', 'h'] as $k) {
    $gen->enrol_user($u[$k]->id, $c3->id, 'student');
}
foreach (['f', 'h'] as $k) {
    $gen->enrol_user($u[$k]->id, $c4->id, 'student');
}

// ---- Groepen ----
$groepa = $DB->get_record('groups', ['courseid' => $c2->id, 'name' => 'Groep A'], '*', MUST_EXIST);
$gen->create_group_member(['groupid' => $groepa->id, 'userid' => $u['c']->id]);
$duo1 = $gen->create_group(['courseid' => $c3->id, 'name' => 'Duo 1']);
$duo2 = $gen->create_group(['courseid' => $c3->id, 'name' => 'Duo 2']);
foreach (['c', 'f', 'lk2'] as $k) {
    $gen->create_group_member(['groupid' => $duo1->id, 'userid' => $u[$k]->id]);
}
foreach (['d', 'g'] as $k) {
    $gen->create_group_member(['groupid' => $duo2->id, 'userid' => $u[$k]->id]);
}

// ---- Opdrachten ----
$online = ['assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 0];
$offline = ['assignsubmission_onlinetext_enabled' => 0, 'assignsubmission_file_enabled' => 0];

$a1 = $gen->create_module('assign', ['course' => $c2->id, 'name' => 'Opdracht 1 - zichtbaar',
    'completion' => COMPLETION_TRACKING_MANUAL] + $online);
$a2 = $gen->create_module('assign', ['course' => $c2->id, 'name' => 'Opdracht 2 - verborgen',
    'visible' => 0, 'completion' => COMPLETION_TRACKING_MANUAL] + $online);
$availability = json_encode(['op' => '&', 'c' => [['type' => 'completion', 'cm' => (int)$a1->cmid, 'e' => 1]],
    'showc' => [true]]);
$a3 = $gen->create_module('assign', ['course' => $c2->id, 'name' => 'Opdracht 3 - met toegangsbeperking',
    'availability' => $availability, 'completion' => COMPLETION_TRACKING_MANUAL] + $online);

$a4 = $gen->create_module('assign', ['course' => $c3->id, 'name' => 'Duo-opdracht (groepsinzending)',
    'teamsubmission' => 1, 'preventsubmissionnotingroup' => 0, 'completion' => COMPLETION_TRACKING_MANUAL] + $online);
$a5 = $gen->create_module('assign', ['course' => $c3->id, 'name' => 'Opdracht individueel',
    'completion' => COMPLETION_TRACKING_MANUAL] + $online);
$a6 = $gen->create_module('assign', ['course' => $c3->id, 'name' => 'Opdracht in verborgen sectie',
    'completion' => COMPLETION_TRACKING_MANUAL] + $online, ['section' => 3]);
$sectioninfo = get_fast_modinfo($c3->id)->get_section_info(3);
\core_courseformat\formatactions::section($c3->id)->set_visibility($sectioninfo, false);

$a7 = $gen->create_module('assign', ['course' => $c4->id, 'name' => 'Opdracht zonder online inzending',
    'completion' => COMPLETION_TRACKING_MANUAL] + $offline);

// Inzendingen. $status 'submitted' of 'draft'.
$submit = function(stdClass $assignrec, stdClass $user, string $status) use ($assigngen, $DB) {
    $assigngen->create_submission(['userid' => $user->id, 'cmid' => $assignrec->cmid,
        'onlinetext_editor' => ['text' => 'Testtekst van ' . $user->username, 'format' => FORMAT_HTML]]);
    // Status rechtstreeks zetten op de laatste poging (individueel of van de groep).
    [$course, $cm] = get_course_and_cm_from_cmid($assignrec->cmid, 'assign');
    $assign = new assign(context_module::instance($cm->id), $cm, $course);
    $sub = $assign->get_instance()->teamsubmission
        ? $assign->get_group_submission($user->id, 0, false)
        : $assign->get_user_submission($user->id, false);
    $DB->set_field('assign_submission', 'status', $status, ['id' => $sub->id]);
};
$submit($a1, $u['a'], 'submitted');
$submit($a3, $u['a'], 'submitted');   // lln-a: alles in cursus 2 ingediend -> "nee".
$submit($a1, $u['b'], 'draft');       // lln-b: concept -> "ja".
$submit($a4, $u['c'], 'submitted');   // Duo 1 (c, f) heeft de groepsopdracht ingediend.
$submit($a5, $u['c'], 'submitted');   // lln-c: in cursus 3 alles ingediend -> "nee".

// ---- Laptop-keuzes ----
$laptops = [];
for ($i = 1; $i <= 12; $i++) {
    $laptops[] = "Laptop $i";
}
$ch2 = $gen->create_module('choice', ['course' => $c2->id, 'name' => 'Welke laptop gebruik je?',
    'idnumber' => 'laptop-ma', 'option' => $laptops, 'allowupdate' => 1, 'completion' => COMPLETION_TRACKING_MANUAL]);
$ch3 = $gen->create_module('choice', ['course' => $c3->id, 'name' => 'Laptop',
    'idnumber' => 'Laptop-woe', 'option' => $laptops, 'allowupdate' => 1]);
$ch3old = $gen->create_module('choice', ['course' => $c3->id, 'name' => 'Laptop (oud, verborgen)',
    'idnumber' => 'laptop-oud', 'option' => $laptops, 'visible' => 0]);

$answer = function(stdClass $choicerec, stdClass $user, string $label) use ($DB) {
    [$course, $cm] = get_course_and_cm_from_cmid($choicerec->cmid, 'choice');
    $choice = choice_get_choice($cm->instance);
    $optionid = $DB->get_field_select('choice_options', 'id',
        'choiceid = :choiceid AND ' . $DB->sql_compare_text('text') . ' = ' . $DB->sql_compare_text(':label'),
        ['choiceid' => $choice->id, 'label' => $label], MUST_EXIST);
    choice_user_submit_response($optionid, $choice, $user->id, $course, $cm);
};
$answer($ch2, $u['a'], 'Laptop 3');
$answer($ch2, $u['b'], 'Laptop 7');
$answer($ch2, $u['c'], 'Laptop 1');
$answer($ch3, $u['c'], 'Laptop 5');
$answer($ch3, $u['f'], 'Laptop 9');
$answer($ch3old, $u['d'], 'Laptop 12');  // Verborgen keuze: mag niet getoond worden.
$answer($ch3old, $u['g'], 'Laptop 11');

// ---- Voltooiing (handmatig) ----
$complete = function(stdClass $modrec, stdClass $user) {
    [$course, $cm] = get_course_and_cm_from_cmid($modrec->cmid);
    $completion = new completion_info($course);
    $completion->update_state($cm, COMPLETION_COMPLETE, $user->id, true);
};
$complete($a1, $u['a']);
$complete($a3, $u['a']);
$complete($ch2, $u['a']);
$complete($a1, $u['b']);
$complete($a4, $u['c']);
$complete($a5, $u['c']);
$complete($a4, $u['f']);

// ---- Laatst geopend (user_lastaccess) ----
$now = time();
$access = [
    // [leerling, cursus, seconden geleden]
    ['a', $c2->id, 3600],
    ['b', $c2->id, 7200],
    ['c', $c2->id, 86400], ['c', $c3->id, 600],         // c: nu in Testcursus A.
    ['d', $c3->id, 86400 * 3], ['d', $c2->id, 1800],    // d: nu in Muzieklab test, ook Testcursus A.
    ['f', $c3->id, 86400 * 2], ['f', $c4->id, 300],     // f: lk-test ziet A, lk-test2 ziet B.
    ['g', $c3->id, 5400],
    ['h', $c3->id, 900],                                // h wordt hierna uitgeschreven uit A.
];
foreach ($access as [$k, $courseid, $ago]) {
    $DB->insert_record('user_lastaccess', ['userid' => $u[$k]->id, 'courseid' => $courseid,
        'timeaccess' => $now - $ago]);
}

// lln-h uitschrijven uit cursus 3 (waarschuwing "niet meer ingeschreven").
$manual = enrol_get_plugin('manual');
$instance = $DB->get_record('enrol', ['courseid' => $c3->id, 'enrol' => 'manual'], '*', MUST_EXIST);
$manual->unenrol_user($instance, $u['h']->id);

$transaction->allow_commit();
purge_caches(['courses' => true]);
echo "Klaar.\n";
