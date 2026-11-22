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
 * Mijn Muzieklab: the current course of the student, with progress, group mates, open assignments
 * and laptop (design fase 3).
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_leerlingendashboard\hook_callbacks;
use local_leerlingendashboard\local\access;
use local_leerlingendashboard\output\mijn_muzieklab;

require(__DIR__ . '/../../config.php');

require_login(null, false);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/leerlingendashboard/mijn.php'));
$PAGE->set_pagelayout('base');
$PAGE->set_title(get_string('mymuzieklab', 'local_leerlingendashboard'));
$PAGE->set_heading(get_string('mymuzieklab', 'local_leerlingendashboard'));
$PAGE->set_primary_active_tab(hook_callbacks::NAVKEY_OWN);

require_capability(access::CAPABILITY_OWN, $context);

$renderer = $PAGE->get_renderer('core');
$page = new mijn_muzieklab($USER);

echo $OUTPUT->header();
echo $renderer->render_from_template('local_leerlingendashboard/mijn_muzieklab', $page->export_for_template($renderer));
echo $OUTPUT->footer();
