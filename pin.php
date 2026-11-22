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
 * Pin or unpin a course as the current course of a student (POST only).
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_leerlingendashboard\local\access;
use local_leerlingendashboard\local\pins;

require(__DIR__ . '/../../config.php');

require_login(null, false);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/leerlingendashboard/pin.php'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('invalidrequest', 'error');
}
require_sesskey();

$action = required_param('action', PARAM_ALPHA);
$userid = required_param('userid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$cohortid = required_param('cohortid', PARAM_INT);

$returnurl = new moodle_url('/local/leerlingendashboard/index.php', ['cohortid' => $cohortid]);

pins::require_can_change($action, $userid, $courseid, $cohortid);

if ($action === 'pin') {
    pins::pin($userid, $courseid, access::get_own_course_ids());
    $message = get_string('coursepinned', 'local_leerlingendashboard');
} else {
    pins::unpin($userid, $courseid);
    $message = get_string('courseunpinned', 'local_leerlingendashboard');
}

redirect($returnurl, $message, null, notification::NOTIFY_SUCCESS);
