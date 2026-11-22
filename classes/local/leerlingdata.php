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
 * Data of students in one course: enrolment, groups, group mates, open assignments, laptop and
 * progress (design fase 2, chapters 5 to 8; design fase 3, chapters 5 to 8).
 *
 * Shared by the class overview and Mijn Muzieklab, so every rule lives in one place. Queries are
 * bundled for all given students at once.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class leerlingdata {
    /** @var string Prefix of the idnumber of the laptop choice. */
    public const LAPTOP_PREFIX = 'laptop';

    /** @var context_course Course context. */
    protected context_course $context;

    /** @var array|null Cache: user id => int[] group ids. */
    protected ?array $usergroups = null;

    /**
     * Constructor.
     *
     * @param stdClass $course Course record.
     * @param int[] $userids Students.
     */
    public function __construct(
        /** @var stdClass Course record. */
        protected stdClass $course,
        /** @var int[] Students. */
        protected array $userids,
    ) {
        $this->context = context_course::instance($course->id);
    }

    /**
     * Which students are actively enrolled in the course.
     *
     * @return array User id => true.
     */
    public function get_active_enrolled(): array {
        global $DB;
        if (!$this->userids) {
            return [];
        }
        [$esql, $params] = get_enrolled_sql($this->context, '', 0, true);
        [$usersql, $userparams] = $DB->get_in_or_equal($this->userids, SQL_PARAMS_NAMED, 'au');
        $ids = $DB->get_fieldset_sql("SELECT je.id FROM ($esql) je WHERE je.id $usersql", $params + $userparams);
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Course progress of a student, as the student sees it (design fase 2, chapter 5).
     *
     * @param int $userid The student.
     * @return float|null
     */
    public function get_progress(int $userid): ?float {
        return progress::get_percentage($this->course, $userid);
    }

    /**
     * The groups of each student in the course.
     *
     * @return array User id => int[] group ids.
     */
    public function get_user_groups(): array {
        global $DB;
        if ($this->usergroups !== null) {
            return $this->usergroups;
        }
        $this->usergroups = [];
        if (!$this->userids) {
            return $this->usergroups;
        }
        [$usersql, $params] = $DB->get_in_or_equal($this->userids, SQL_PARAMS_NAMED, 'gu');
        $params['courseid'] = $this->course->id;
        $sql = "SELECT gm.id, gm.userid, gm.groupid
                  FROM {groups_members} gm
                  JOIN {groups} g ON g.id = gm.groupid
                 WHERE g.courseid = :courseid
                   AND gm.userid $usersql";
        foreach ($DB->get_records_sql($sql, $params) as $member) {
            $this->usergroups[$member->userid][] = (int)$member->groupid;
        }
        return $this->usergroups;
    }

    /**
     * Group mates: other members of the student's groups in the course, teachers included,
     * if actively enrolled (design fase 2, chapter 6).
     *
     * @param bool $withvisibility Apply the group visibility of Moodle for the current user
     *         (design fase 3, chapter 5). Only correct when $USER is the student, as in Mijn Muzieklab.
     *         The class overview (teacher) shows all groups.
     * @return array User id => stdClass[] users with name fields, keyed by user id.
     */
    public function get_groupmates(bool $withvisibility = false): array {
        global $DB;
        if (!$this->userids) {
            return [];
        }
        [$esql, $params] = get_enrolled_sql($this->context, '', 0, true);
        [$usersql, $userparams] = $DB->get_in_or_equal($this->userids, SQL_PARAMS_NAMED, 'mu');
        $params += $userparams;
        $params['courseid'] = $this->course->id;
        $visibilitysql = '';
        if ($withvisibility) {
            // Pass gm1 (the student's own membership): the helper checks that $USER is a member of
            // that group with its own subquery alias gm2, so passing gm2 here would compare gm2 with itself.
            [$where, $visparams] = \core_group\visibility::sql_member_visibility_where('g', 'gm1', 'u', 'vis');
            $visibilitysql = "AND $where";
            $params += $visparams;
        }
        $namefields = fields::for_name()->get_sql('u')->selects;
        $sql = "SELECT gm1.userid AS studentid, u.id $namefields
                  FROM {groups_members} gm1
                  JOIN {groups} g ON g.id = gm1.groupid AND g.courseid = :courseid
                  JOIN {groups_members} gm2 ON gm2.groupid = g.id AND gm2.userid <> gm1.userid
                  JOIN {user} u ON u.id = gm2.userid AND u.deleted = 0 AND u.suspended = 0
                  JOIN ($esql) je ON je.id = u.id
                 WHERE gm1.userid $usersql
                       $visibilitysql
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
     * Open assignments per student, in the order of the course page (design fase 2, chapter 7).
     *
     * An assignment counts if it is visible and not in a hidden section. Access restrictions do
     * not matter, nor do dates. It is open unless the latest attempt has status "submitted",
     * for the student or, with group submission, for one of the student's groups.
     *
     * @return array User id => int[] course module ids of the open assignments.
     */
    public function get_open_assignments(): array {
        global $DB;
        if (!$this->userids) {
            return [];
        }

        // Structure of the course: the same for everyone, so the modinfo of $USER is fine.
        $modinfo = get_fast_modinfo($this->course);
        $assigns = [];
        foreach ($modinfo->get_sections() as $cmids) {
            foreach ($cmids as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if ($cm->modname !== 'assign' || !$cm->visible || $cm->deletioninprogress
                        || !$cm->get_section_info()->visible) {
                    continue;
                }
                $assigns[(int)$cm->instance] = (int)$cm->id;
            }
        }
        if (!$assigns) {
            return array_fill_keys($this->userids, []);
        }
        $assignids = array_keys($assigns);
        $teamsubmission = $DB->get_records_list('assign', 'id', $assignids, '', 'id, teamsubmission');

        [$assignsql, $params] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED, 'a');
        [$usersql, $userparams] = $DB->get_in_or_equal($this->userids, SQL_PARAMS_NAMED, 'su');
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

        $usergroups = $this->get_user_groups();
        $result = [];
        foreach ($this->userids as $userid) {
            $open = [];
            foreach ($assigns as $assignid => $cmid) {
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
                $open[] = $cmid;
            }
            $result[$userid] = $open;
        }
        return $result;
    }

    /**
     * The laptop number per student: the most recent answer in a visible choice whose idnumber
     * starts with "laptop" (design fase 2, chapter 8).
     *
     * @return array User id => laptop text (formatted).
     */
    public function get_laptops(): array {
        global $DB;
        if (!$this->userids) {
            return [];
        }
        [$usersql, $params] = $DB->get_in_or_equal($this->userids, SQL_PARAMS_NAMED, 'lu');
        [$likesql, $likeparams] = $this->laptop_choice_condition();
        $params += $likeparams;
        $sql = "SELECT ca.id, ca.userid, co.text, cm.id AS cmid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {course_sections} cs ON cs.id = cm.section
                  JOIN {choice_answers} ca ON ca.choiceid = cm.instance
                  JOIN {choice_options} co ON co.id = ca.optionid
                 WHERE $likesql
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

    /**
     * The visible laptop choices of the course, in the order of the course page
     * (for the link "Choose your laptop", design fase 3, chapter 7).
     *
     * @return int[] Course module ids.
     */
    public function get_laptop_choice_cmids(): array {
        global $DB;
        [$likesql, $params] = $this->laptop_choice_condition();
        $cmids = array_map('intval', $DB->get_fieldset_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {course_sections} cs ON cs.id = cm.section
              WHERE $likesql", $params));
        if (!$cmids) {
            return [];
        }
        // Order of the course page.
        $order = [];
        foreach (get_fast_modinfo($this->course)->get_sections() as $sectioncmids) {
            foreach ($sectioncmids as $cmid) {
                $order[] = (int)$cmid;
            }
        }
        return array_values(array_intersect($order, $cmids));
    }

    /**
     * SQL condition for a visible laptop choice in this course (aliases cm, m, cs).
     *
     * @return array [sql, params]
     */
    protected function laptop_choice_condition(): array {
        global $DB;
        $params = [
            'courseid' => $this->course->id,
            'modname' => 'choice',
            'prefix' => $DB->sql_like_escape(self::LAPTOP_PREFIX) . '%',
        ];
        $sql = "cm.course = :courseid
                AND cm.visible = 1
                AND cs.visible = 1
                AND cm.deletioninprogress = 0
                AND " . $DB->sql_like('cm.idnumber', ':prefix', false);
        return [$sql, $params];
    }
}
