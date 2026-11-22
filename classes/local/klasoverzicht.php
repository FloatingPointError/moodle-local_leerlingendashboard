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
use core_user\fields;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');

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
    /** @var string Prefix of the idnumber of the laptop choice. */
    public const LAPTOP_PREFIX = 'laptop';

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
     * laptop (string|null).
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
     * (design, chapter 4).
     *
     * @param int[] $userids Students.
     * @return stdClass[] User id => (courseid, pinned).
     */
    public function get_current_courses(array $userids): array {
        global $DB;

        if ($this->owncourseids === []) {
            return [];
        }
        [$usersql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $coursesql = '';
        if ($this->owncourseids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($this->owncourseids, SQL_PARAMS_NAMED, 'c');
            $coursesql = "AND c.id $insql";
            $params += $inparams;
        }
        $params['siteid'] = SITEID;

        $result = [];

        // 1. Pinned courses: the most recent pin per student.
        $sql = "SELECT p.id, p.userid, p.courseid
                  FROM {" . pins::TABLE . "} p
                  JOIN {course} c ON c.id = p.courseid
                 WHERE p.userid $usersql
                   AND c.id <> :siteid
                       $coursesql
              ORDER BY p.userid, p.timemodified DESC, p.id DESC";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $pin) {
            if (!isset($result[$pin->userid])) {
                $result[$pin->userid] = (object)['courseid' => (int)$pin->courseid, 'pinned' => true];
            }
        }
        $rs->close();

        // 2. Most recently accessed course, for students without a pin.
        $sql = "SELECT ula.id, ula.userid, ula.courseid
                  FROM {user_lastaccess} ula
                  JOIN {course} c ON c.id = ula.courseid
                 WHERE ula.userid $usersql
                   AND c.id <> :siteid
                       $coursesql
              ORDER BY ula.userid, ula.timeaccess DESC, ula.id DESC";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $access) {
            if (!isset($result[$access->userid])) {
                $result[$access->userid] = (object)['courseid' => (int)$access->courseid, 'pinned' => false];
            }
        }
        $rs->close();

        return $result;
    }

    /**
     * Fill columns 3 to 6 for the students whose current course is this course.
     *
     * @param stdClass $course Course record.
     * @param int[] $userids Students with this course as current course.
     * @param stdClass[] $rows Rows, keyed by user id (changed in place).
     */
    protected function add_course_data(stdClass $course, array $userids, array &$rows): void {
        $context = context_course::instance($course->id);

        $enrolled = $this->get_active_enrolled($context, $userids);
        $usergroups = $this->get_user_groups($course->id, $userids);
        $groupmates = $this->get_groupmates($context, $userids);
        $openassignments = $this->count_open_assignments($course, $userids, $usergroups);
        $laptops = $this->get_laptops($course->id, $userids);

        foreach ($userids as $userid) {
            $row = $rows[$userid];
            $row->course = $course;
            $row->enrolled = isset($enrolled[$userid]);
            $row->progress = progress::get_percentage($course, $userid);
            $row->groupmates = $groupmates[$userid] ?? [];
            $row->openassignments = $openassignments[$userid] ?? 0;
            $row->laptop = $laptops[$userid] ?? null;
        }
    }

    /**
     * Which students are actively enrolled in the course (design, 4.3).
     *
     * @param context_course $context Course context.
     * @param int[] $userids Students.
     * @return array User id => true.
     */
    protected function get_active_enrolled(context_course $context, array $userids): array {
        global $DB;
        [$esql, $params] = get_enrolled_sql($context, '', 0, true);
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'au');
        $ids = $DB->get_fieldset_sql("SELECT je.id FROM ($esql) je WHERE je.id $usersql", $params + $userparams);
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * The groups of each student in the course.
     *
     * @param int $courseid Course id.
     * @param int[] $userids Students.
     * @return array User id => int[] group ids.
     */
    protected function get_user_groups(int $courseid, array $userids): array {
        global $DB;
        [$usersql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'gu');
        $params['courseid'] = $courseid;
        $sql = "SELECT gm.id, gm.userid, gm.groupid
                  FROM {groups_members} gm
                  JOIN {groups} g ON g.id = gm.groupid
                 WHERE g.courseid = :courseid
                   AND gm.userid $usersql";
        $result = [];
        foreach ($DB->get_records_sql($sql, $params) as $member) {
            $result[$member->userid][] = (int)$member->groupid;
        }
        return $result;
    }

    /**
     * Group mates: other members of the student's groups in the course, teachers included,
     * if actively enrolled (design, chapter 6).
     *
     * @param context_course $context Course context.
     * @param int[] $userids Students.
     * @return array User id => stdClass[] users with name fields, keyed by user id.
     */
    protected function get_groupmates(context_course $context, array $userids): array {
        global $DB;
        [$esql, $params] = get_enrolled_sql($context, '', 0, true);
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'mu');
        $params += $userparams;
        $params['courseid'] = $context->instanceid;
        $namefields = fields::for_name()->get_sql('u')->selects;
        $sql = "SELECT gm1.userid AS studentid, u.id $namefields
                  FROM {groups_members} gm1
                  JOIN {groups} g ON g.id = gm1.groupid AND g.courseid = :courseid
                  JOIN {groups_members} gm2 ON gm2.groupid = g.id AND gm2.userid <> gm1.userid
                  JOIN {user} u ON u.id = gm2.userid AND u.deleted = 0 AND u.suspended = 0
                  JOIN ($esql) je ON je.id = u.id
                 WHERE gm1.userid $usersql
              ORDER BY u.lastname, u.firstname, u.id";
        $result = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $record) {
            $studentid = $record->studentid;
            unset($record->studentid);
            $result[$studentid][$record->id] = $record;
        }
        $rs->close();
        return $result;
    }

    /**
     * Number of open assignments per student (design, chapter 7).
     *
     * An assignment counts if it is visible and not in a hidden section. Access restrictions do
     * not matter, nor do dates. It is open unless the latest attempt has status "submitted",
     * for the student or, with group submission, for one of the student's groups.
     *
     * @param stdClass $course Course record.
     * @param int[] $userids Students.
     * @param array $usergroups User id => int[] group ids.
     * @return array User id => number of open assignments.
     */
    protected function count_open_assignments(stdClass $course, array $userids, array $usergroups): array {
        global $DB;

        // Structure of the course: the same for everyone, so the modinfo of $USER is fine.
        $assignids = [];
        foreach (get_fast_modinfo($course)->get_instances_of('assign') as $cm) {
            if (!$cm->visible || $cm->deletioninprogress || !$cm->get_section_info()->visible) {
                continue;
            }
            $assignids[] = (int)$cm->instance;
        }
        if (!$assignids) {
            return array_fill_keys($userids, 0);
        }

        $teamsubmission = $DB->get_records_list('assign', 'id', $assignids, '', 'id, teamsubmission');

        [$assignsql, $params] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED, 'a');
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'su');
        $params += $userparams;
        $params['submitted'] = ASSIGN_SUBMISSION_STATUS_SUBMITTED;
        $sql = "SELECT s.id, s.assignment, s.userid, s.groupid
                  FROM {assign_submission} s
                 WHERE s.assignment $assignsql
                   AND s.latest = 1
                   AND s.status = :submitted
                   AND (s.userid $usersql OR s.userid = 0)";
        $byuser = [];
        $bygroup = [];
        foreach ($DB->get_records_sql($sql, $params) as $submission) {
            if ($submission->userid) {
                $byuser[$submission->assignment][$submission->userid] = true;
            } else {
                $bygroup[$submission->assignment][$submission->groupid] = true;
            }
        }

        $result = [];
        foreach ($userids as $userid) {
            $open = 0;
            foreach ($assignids as $assignid) {
                if (!empty($byuser[$assignid][$userid])) {
                    continue;
                }
                if (!empty($teamsubmission[$assignid]->teamsubmission)) {
                    // Group submission: a student without a group submits in the default group 0.
                    $groups = $usergroups[$userid] ?? [0];
                    if (array_intersect_key($bygroup[$assignid] ?? [], array_flip($groups))) {
                        continue;
                    }
                }
                $open++;
            }
            $result[$userid] = $open;
        }
        return $result;
    }

    /**
     * The laptop number per student: the most recent answer in a visible choice whose idnumber
     * starts with "laptop" (design, chapter 8).
     *
     * @param int $courseid Course id.
     * @param int[] $userids Students.
     * @return array User id => laptop text (formatted).
     */
    protected function get_laptops(int $courseid, array $userids): array {
        global $DB;
        [$usersql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'lu');
        $params['courseid'] = $courseid;
        $params['modname'] = 'choice';
        $params['prefix'] = $DB->sql_like_escape(self::LAPTOP_PREFIX) . '%';
        $idnumberlike = $DB->sql_like('cm.idnumber', ':prefix', false);
        $sql = "SELECT ca.id, ca.userid, co.text, cm.id AS cmid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {course_sections} cs ON cs.id = cm.section
                  JOIN {choice_answers} ca ON ca.choiceid = cm.instance
                  JOIN {choice_options} co ON co.id = ca.optionid
                 WHERE cm.course = :courseid
                   AND cm.visible = 1
                   AND cs.visible = 1
                   AND cm.deletioninprogress = 0
                   AND $idnumberlike
                   AND ca.userid $usersql
              ORDER BY ca.userid, ca.timemodified DESC, ca.id DESC";
        $result = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $answer) {
            if (!isset($result[$answer->userid])) {
                $context = \context_module::instance($answer->cmid);
                $result[$answer->userid] = format_string($answer->text, true, ['context' => $context]);
            }
        }
        $rs->close();
        return $result;
    }
}
