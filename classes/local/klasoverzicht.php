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

use core_user\fields;
use stdClass;

/**
 * Data for the class overview: one row per student of a cohort (design, chapters 2 to 8).
 *
 * Everything is computed for the viewing teacher: only their own courses are used.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class klasoverzicht {
    /**
     * Constructor.
     *
     * @param int $cohortid The cohort (class).
     * @param int[]|null $owncourseids Own courses of the teacher, null for all courses.
     */
    public function __construct(
        /** @var int The cohort (class). */
        protected int $cohortid,
        /** @var int[]|null Own courses of the teacher, null for all courses. */
        protected ?array $owncourseids,
    ) {
    }

    /**
     * One row per student.
     *
     * Each row has: user (stdClass with name fields), course (stdClass|null), pinned (bool),
     * enrolled (bool), progress (float|null), groupmates (stdClass[]), openassignments (int|null),
     * opencmids (int[] course module ids of the open assignments), laptop (string|null).
     *
     * @param int|null $onlyuserid Only this student (to refresh one row), null for the whole class.
     * @return stdClass[] Rows, keyed by user id, sorted by name.
     */
    public function get_rows(?int $onlyuserid = null): array {
        global $DB;

        $students = $this->get_students($onlyuserid);
        if (!$students) {
            return [];
        }

        $rows = [];
        foreach ($students as $student) {
            $rows[$student->id] = (object)[
                'user' => $student,
                'course' => null,
                'pinned' => false,
                'enrolled' => false,
                'progress' => null,
                'groupmates' => [],
                'openassignments' => null,
                'opencmids' => [],
                'laptop' => null,
            ];
        }

        $current = $this->get_current_courses(array_keys($students));
        $bycourse = [];
        foreach ($current as $userid => $info) {
            $bycourse[$info->courseid][] = $userid;
            $rows[$userid]->pinned = $info->pinned;
        }
        if (!$bycourse) {
            return $rows;
        }

        $courses = $DB->get_records_list('course', 'id', array_keys($bycourse));
        foreach ($bycourse as $courseid => $userids) {
            if (!isset($courses[$courseid])) {
                continue;
            }
            $this->add_course_data($courses[$courseid], $userids, $rows);
        }
        return $rows;
    }

    /**
     * Students of the cohort: not deleted, not suspended (design, chapter 2).
     *
     * @param int|null $onlyuserid Only this student, null for all.
     * @return stdClass[] Users keyed by id, sorted by last name and first name.
     */
    protected function get_students(?int $onlyuserid = null): array {
        global $DB;
        $namefields = fields::for_name()->get_sql('u')->selects;
        $params = ['cohortid' => $this->cohortid];
        $usersql = '';
        if ($onlyuserid !== null) {
            $usersql = 'AND u.id = :userid';
            $params['userid'] = $onlyuserid;
        }
        $sql = "SELECT u.id $namefields
                  FROM {cohort_members} cm
                  JOIN {user} u ON u.id = cm.userid
                 WHERE cm.cohortid = :cohortid
                   AND u.deleted = 0
                   AND u.suspended = 0
                       $usersql
              ORDER BY u.lastname, u.firstname, u.id";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * The current course per student: most recent own pin, else most recently accessed own course
     * (design fase 2, chapter 4).
     *
     * @param int[] $userids Students.
     * @return stdClass[] User id => (courseid, pinned).
     */
    public function get_current_courses(array $userids): array {
        return huidigecursus::get($userids, $this->owncourseids);
    }

    /**
     * Fill columns 3 to 6 for the students whose current course is this course.
     *
     * @param stdClass $course Course record.
     * @param int[] $userids Students with this course as current course.
     * @param stdClass[] $rows Rows, keyed by user id (changed in place).
     */
    protected function add_course_data(stdClass $course, array $userids, array &$rows): void {
        $data = new leerlingdata($course, $userids);
        $enrolled = $data->get_active_enrolled();
        $groupmates = $data->get_groupmates();
        $openassignments = $data->get_open_assignments();
        $laptops = $data->get_laptops();

        foreach ($userids as $userid) {
            $row = $rows[$userid];
            $row->course = $course;
            $row->enrolled = isset($enrolled[$userid]);
            $row->progress = $data->get_progress($userid);
            $row->groupmates = $groupmates[$userid] ?? [];
            $row->opencmids = $openassignments[$userid] ?? [];
            $row->openassignments = count($row->opencmids);
            $row->laptop = $laptops[$userid] ?? null;
        }
    }
}
