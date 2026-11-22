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

/**
 * Web service local_leerlingendashboard_pin_course.
 *
 * Pin a course as the current course of a student, and return the refreshed row.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pin_course extends pin_base {
    /**
     * Pin a course as the current course of a student, and return the refreshed row.
     *
     * @param int $userid The student.
     * @param int $courseid The course.
     * @param int $cohortid The class.
     * @return array html of the row, message.
     */
    public static function execute(int $userid, int $courseid, int $cohortid): array {
        return self::change('pin', $userid, $courseid, $cohortid);
    }
}
