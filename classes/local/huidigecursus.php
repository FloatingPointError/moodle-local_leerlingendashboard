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

use context_course;
use stdClass;

/**
 * The current course of a student: most recent pin, else most recently accessed course
 * (design fase 2, chapter 4; design fase 3, chapter 3).
 *
 * Used by the class overview (for a teacher, limited to their own courses) and by
 * Mijn Muzieklab (for the student, all courses, only active enrolments).
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class huidigecursus {
    /**
     * The current course per student.
     *
     * @param int[] $userids Students.
     * @param int[]|null $courseids Only these courses (own courses of a teacher), null for all courses.
     * @param bool $onlyactive Skip courses where the student is not actively enrolled (for the student).
     * @return stdClass[] User id => (courseid, pinned).
     */
    public static function get(array $userids, ?array $courseids = null, bool $onlyactive = false): array {
        global $DB;

        if (!$userids || $courseids === []) {
            return [];
        }
        [$usersql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $coursesql = '';
        if ($courseids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
            $coursesql = "AND c.id $insql";
            $params += $inparams;
        }
        $params['siteid'] = SITEID;

        // Candidates in order of preference: pins (most recent first), then accessed courses (most recent first).
        $candidates = [];
        $sql = "SELECT p.id, p.userid, p.courseid
                  FROM {" . pins::TABLE . "} p
                  JOIN {course} c ON c.id = p.courseid
                 WHERE p.userid $usersql
                   AND c.id <> :siteid
                       $coursesql
              ORDER BY p.userid, p.timemodified DESC, p.id DESC";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $pin) {
            $candidates[$pin->userid][] = (object)['courseid' => (int)$pin->courseid, 'pinned' => true];
        }
        $rs->close();

        $sql = "SELECT ula.id, ula.userid, ula.courseid
                  FROM {user_lastaccess} ula
                  JOIN {course} c ON c.id = ula.courseid
                 WHERE ula.userid $usersql
                   AND c.id <> :siteid
                       $coursesql
              ORDER BY ula.userid, ula.timeaccess DESC, ula.id DESC";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $access) {
            $candidates[$access->userid][] = (object)['courseid' => (int)$access->courseid, 'pinned' => false];
        }
        $rs->close();

        $result = [];
        foreach ($candidates as $userid => $list) {
            foreach ($list as $candidate) {
                if ($onlyactive && !is_enrolled(context_course::instance($candidate->courseid), $userid, '', true)) {
                    continue;
                }
                $result[$userid] = $candidate;
                break;
            }
        }
        return $result;
    }
}
