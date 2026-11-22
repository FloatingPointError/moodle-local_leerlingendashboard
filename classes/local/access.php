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

namespace local_leerlingendashboard\local;

use context_system;

/**
 * Access checks for the class overview (design, chapter 9).
 *
 * Teachers get their role per course, so the page (system context) is open to anyone who has
 * the capability in the system context or in at least one course. A teacher only sees data from
 * their own courses: the courses where they have the capability.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /** @var string The capability that gives access to the class overview. */
    public const CAPABILITY = 'local/leerlingendashboard:viewclass';

    /**
     * Whether the user has the capability in the system context (manager, admin).
     *
     * @param int|null $userid User id, null for the current user.
     * @return bool
     */
    public static function has_site_access(?int $userid = null): bool {
        return has_capability(self::CAPABILITY, context_system::instance(), $userid);
    }

    /**
     * Whether the user may open the class overview.
     *
     * @param int|null $userid User id, null for the current user.
     * @return bool
     */
    public static function can_view(?int $userid = null): bool {
        global $USER;
        if (!isloggedin() || isguestuser()) {
            return false;
        }
        if (self::has_site_access($userid)) {
            return true;
        }
        $userid = $userid ?? (int)$USER->id;
        return (bool)get_user_capability_course(self::CAPABILITY, $userid, true, '', '', 1);
    }

    /**
     * The ids of the user's own courses.
     *
     * @param int|null $userid User id, null for the current user.
     * @return int[]|null Course ids, or null if the user may see all courses.
     */
    public static function get_own_course_ids(?int $userid = null): ?array {
        global $USER;
        if (self::has_site_access($userid)) {
            return null;
        }
        $userid = $userid ?? (int)$USER->id;
        // Returns a list of records with an id field (not keyed by course id), or false.
        $courses = get_user_capability_course(self::CAPABILITY, $userid, true, '');
        if (!$courses) {
            return [];
        }
        return array_values(array_map(fn($course) => (int)$course->id, $courses));
    }

    /**
     * Whether a course belongs to the user's own courses.
     *
     * @param int $courseid Course id.
     * @param int|null $userid User id, null for the current user.
     * @return bool
     */
    public static function is_own_course(int $courseid, ?int $userid = null): bool {
        if ($courseid == SITEID) {
            return false;
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        return $context && has_capability(self::CAPABILITY, $context, $userid);
    }
}
