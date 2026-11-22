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
 * Class overview for the teacher: one row per student of the chosen cohort.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_leerlingendashboard\hook_callbacks;
use local_leerlingendashboard\local\access;
use local_leerlingendashboard\output\klasoverzicht;

require(__DIR__ . '/../../config.php');

$cohortid = optional_param('cohortid', 0, PARAM_INT);

require_login(null, false);

$context = context_system::instance();
$url = new moodle_url('/local/leerlingendashboard/index.php', $cohortid ? ['cohortid' => $cohortid] : []);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('base');
$PAGE->set_title(get_string('classoverview', 'local_leerlingendashboard'));
$PAGE->set_heading(get_string('classoverview', 'local_leerlingendashboard'));
$PAGE->set_primary_active_tab(hook_callbacks::NAVKEY);

if (!access::can_view()) {
    throw new required_capability_exception($context, access::CAPABILITY, 'nopermissions', '');
}

$cohorts = $DB->get_records('cohort', ['visible' => 1], 'name ASC', 'id, name, contextid');
if ($cohortid && !isset($cohorts[$cohortid])) {
    throw new moodle_exception('invalidrecord', 'error', '', 'cohort');
}

$page = new klasoverzicht($cohorts, $cohortid, access::get_own_course_ids());
$PAGE->requires->js_call_amd('local_leerlingendashboard/klasoverzicht', 'init');

$renderer = $PAGE->get_renderer('core');
echo $OUTPUT->header();
echo $renderer->render_from_template('local_leerlingendashboard/klasoverzicht', $page->export_for_template($renderer));
echo $OUTPUT->footer();
