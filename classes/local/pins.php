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
use local_leerlingendashboard\event\course_pinned;
use local_leerlingendashboard\event\course_unpinned;
use stdClass;

/**
 * Pinned courses (design, 4.1 and 9.4).
 *
 * A student can have several pinned courses, one per course. When a teacher pins a course,
 * earlier pins of that student in the teacher's own courses are replaced. Pins in courses of
 * colleagues stay.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pins {
    /** @var string Table name. */
    public const TABLE = 'local_leerlingendashboard_pin';

    /**
     * Check that the current user may pin or unpin this course for this student (design, 9.4).
     *
     * Used by pin.php and the web services, so both apply the same rules.
     *
     * @param string $action 'pin' or 'unpin'.
     * @param int $userid The student.
     * @param int $courseid The course.
     * @param int $cohortid The class the teacher is looking at.
     * @throws \moodle_exception If not allowed.
     */
    public static function require_can_change(string $action, int $userid, int $courseid, int $cohortid): void {
        global $DB;

        if (!in_array($action, ['pin', 'unpin'], true)) {
            throw new \moodle_exception('invalidrequest', 'error');
        }
        if (!access::can_view()) {
            throw new \required_capability_exception(\context_system::instance(), access::CAPABILITY,
                'nopermissions', '');
        }
        // Only own courses.
        $coursecontext = context_course::instance($courseid);
        if (!access::is_own_course($courseid)) {
            throw new \required_capability_exception($coursecontext, access::CAPABILITY, 'nopermissions', '');
        }
        // The student must be in the class the teacher is looking at.
        if (!$DB->record_exists('cohort_members', ['cohortid' => $cohortid, 'userid' => $userid])) {
            throw new \moodle_exception('errornotincohort', 'local_leerlingendashboard');
        }
        if ($action === 'pin' && !is_enrolled($coursecontext, $userid, '', true)) {
            throw new \moodle_exception('errornotenrolled', 'local_leerlingendashboard');
        }
    }

    /**
     * Pin a course as the current course of a student.
     *
     * Access checks are the caller's job, see require_can_change().
     *
     * @param int $userid The student.
     * @param int $courseid The course to pin.
     * @param int[]|null $owncourseids Own courses of the teacher, null for all courses.
     */
    public static function pin(int $userid, int $courseid, ?array $owncourseids): void {
        global $DB, $USER;

        $now = time();
        $transaction = $DB->start_delegated_transaction();

        // Replace earlier pins of this student within the teacher's own courses.
        $select = 'userid = :userid AND courseid <> :courseid';
        $params = ['userid' => $userid, 'courseid' => $courseid];
        if ($owncourseids !== null) {
            if ($owncourseids) {
                [$insql, $inparams] = $DB->get_in_or_equal($owncourseids, SQL_PARAMS_NAMED, 'own');
                $select .= " AND courseid $insql";
                $params += $inparams;
            } else {
                $select .= ' AND 1 = 0';
            }
        }
        foreach ($DB->get_records_select(self::TABLE, $select, $params) as $old) {
            self::delete_pin($old);
        }

        $pin = $DB->get_record(self::TABLE, ['userid' => $userid, 'courseid' => $courseid]);
        if ($pin) {
            $pin->usermodified = $USER->id;
            $pin->timemodified = $now;
            $DB->update_record(self::TABLE, $pin);
        } else {
            $pin = (object)[
                'userid' => $userid,
                'courseid' => $courseid,
                'usermodified' => $USER->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $pin->id = $DB->insert_record(self::TABLE, $pin);
        }

        $transaction->allow_commit();

        course_pinned::create([
            'objectid' => $pin->id,
            'context' => context_course::instance($courseid),
            'relateduserid' => $userid,
        ])->trigger();
    }

    /**
     * Unpin a course.
     *
     * @param int $userid The student.
     * @param int $courseid The pinned course.
     * @return bool Whether there was a pin.
     */
    public static function unpin(int $userid, int $courseid): bool {
        global $DB;
        $pin = $DB->get_record(self::TABLE, ['userid' => $userid, 'courseid' => $courseid]);
        if (!$pin) {
            return false;
        }
        self::delete_pin($pin);
        return true;
    }

    /**
     * Delete a pin and log it.
     *
     * @param stdClass $pin Pin record.
     */
    private static function delete_pin(stdClass $pin): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['id' => $pin->id]);
        $context = context_course::instance($pin->courseid, IGNORE_MISSING);
        if ($context) {
            $event = course_unpinned::create([
                'objectid' => $pin->id,
                'context' => $context,
                'relateduserid' => $pin->userid,
            ]);
            $event->add_record_snapshot(self::TABLE, $pin);
            $event->trigger();
        }
    }

    /**
     * Courses the teacher can pin for a student: own courses where the student is actively enrolled.
     *
     * @param int $userid The student.
     * @param int[]|null $owncourseids Own courses of the teacher, null for all courses.
     * @return array Course id => course record (id, fullname, shortname).
     */
    public static function get_pinnable_courses(int $userid, ?array $owncourseids): array {
        $courses = enrol_get_users_courses($userid, true, 'id, fullname, shortname', 'fullname ASC');
        if ($owncourseids === null) {
            return $courses;
        }
        return array_intersect_key($courses, array_flip($owncourseids));
    }
}
