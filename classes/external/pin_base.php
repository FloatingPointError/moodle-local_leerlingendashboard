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

namespace local_leerlingendashboard\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_leerlingendashboard\local\access;
use local_leerlingendashboard\local\pins;
use local_leerlingendashboard\output\klasoverzicht;

/**
 * Shared code for pinning and unpinning from the class overview without reloading the page.
 *
 * Applies the same checks as pin.php, then returns the refreshed row of the student.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class pin_base extends external_api {
    /**
     * Parameters: the student, the course and the class.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'The student'),
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'cohortid' => new external_value(PARAM_INT, 'The class (cohort) the teacher is looking at'),
        ]);
    }

    /**
     * Pin or unpin, and render the refreshed row.
     *
     * @param string $action 'pin' or 'unpin'.
     * @param int $userid The student.
     * @param int $courseid The course.
     * @param int $cohortid The class.
     * @return array html of the row, message.
     */
    protected static function change(string $action, int $userid, int $courseid, int $cohortid): array {
        global $PAGE;

        $params = self::validate_parameters(self::execute_parameters(),
            ['userid' => $userid, 'courseid' => $courseid, 'cohortid' => $cohortid]);
        $context = context_system::instance();
        self::validate_context($context);

        pins::require_can_change($action, $params['userid'], $params['courseid'], $params['cohortid']);

        $owncourseids = access::get_own_course_ids();
        if ($action === 'pin') {
            pins::pin($params['userid'], $params['courseid'], $owncourseids);
            $message = get_string('coursepinned', 'local_leerlingendashboard');
        } else {
            pins::unpin($params['userid'], $params['courseid']);
            $message = get_string('courseunpinned', 'local_leerlingendashboard');
        }

        $renderer = $PAGE->get_renderer('core');
        $row = (new klasoverzicht([], $params['cohortid'], $owncourseids))->export_single_row($params['userid']);
        $html = $row ? $renderer->render_from_template('local_leerlingendashboard/klasoverzicht_row', $row) : '';

        return ['html' => $html, 'message' => $message];
    }

    /**
     * Returns: the refreshed row and a message.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'HTML of the refreshed table row'),
            'message' => new external_value(PARAM_TEXT, 'Message for the teacher'),
        ]);
    }
}
