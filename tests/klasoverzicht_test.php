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

namespace local_leerlingendashboard;

use local_leerlingendashboard\local\access;
use local_leerlingendashboard\local\klasoverzicht;
use local_leerlingendashboard\local\pins;
use local_leerlingendashboard\local\progress;

/**
 * Tests for the class overview data (design, chapters 4 to 9).
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(klasoverzicht::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(progress::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(pins::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
final class klasoverzicht_test extends \advanced_testcase {
    /** @var \stdClass Course of teacher 1. */
    protected $course1;
    /** @var \stdClass Course of teacher 1 and teacher 2. */
    protected $course2;
    /** @var \stdClass Teacher in course 1 and 2. */
    protected $teacher1;
    /** @var \stdClass Teacher in course 2. */
    protected $teacher2;
    /** @var \stdClass Student. */
    protected $student;
    /** @var \stdClass Other student. */
    protected $student2;
    /** @var \stdClass Cohort. */
    protected $cohort;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/choice/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $this->course1 = $gen->create_course(['enablecompletion' => 1]);
        $this->course2 = $gen->create_course(['enablecompletion' => 1]);
        $this->teacher1 = $gen->create_user(['firstname' => 'Tom', 'lastname' => 'Teacher']);
        $this->teacher2 = $gen->create_user(['firstname' => 'Tina', 'lastname' => 'Tutor']);
        $this->student = $gen->create_user(['firstname' => 'Sam', 'lastname' => 'Student']);
        $this->student2 = $gen->create_user(['firstname' => 'Bo', 'lastname' => 'Buddy']);

        $gen->enrol_user($this->teacher1->id, $this->course1->id, 'editingteacher');
        $gen->enrol_user($this->teacher1->id, $this->course2->id, 'editingteacher');
        $gen->enrol_user($this->teacher2->id, $this->course2->id, 'editingteacher');
        foreach ([$this->course1, $this->course2] as $course) {
            $gen->enrol_user($this->student->id, $course->id, 'student');
            $gen->enrol_user($this->student2->id, $course->id, 'student');
        }

        $this->cohort = $gen->create_cohort();
        cohort_add_member($this->cohort->id, $this->student->id);
        cohort_add_member($this->cohort->id, $this->student2->id);
    }

    /**
     * Set the last access of a user to a course.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @param int $time
     */
    protected function set_lastaccess(\stdClass $user, \stdClass $course, int $time): void {
        global $DB;
        $DB->delete_records('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id]);
        $DB->insert_record('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id, 'timeaccess' => $time]);
    }

    /**
     * Rows of the cohort as seen by the current user.
     *
     * @return \stdClass[]
     */
    protected function get_rows(): array {
        return (new klasoverzicht($this->cohort->id, access::get_own_course_ids()))->get_rows();
    }

    public function test_access(): void {
        $this->setUser($this->teacher2);
        $this->assertTrue(access::can_view());
        $this->assertSame([(int)$this->course2->id], access::get_own_course_ids());
        $this->assertFalse(access::is_own_course($this->course1->id));

        $this->setUser($this->student);
        $this->assertFalse(access::can_view());

        $this->setAdminUser();
        $this->assertNull(access::get_own_course_ids());
    }

    public function test_current_course_is_most_recent_own_course(): void {
        $this->set_lastaccess($this->student, $this->course1, time() - 100);
        $this->set_lastaccess($this->student, $this->course2, time() - 10);

        // Teacher 1 owns both courses: the most recent one.
        $this->setUser($this->teacher1);
        $rows = $this->get_rows();
        $this->assertEquals($this->course2->id, $rows[$this->student->id]->course->id);
        $this->assertFalse($rows[$this->student->id]->pinned);
        // No access at all: no course.
        $this->assertNull($rows[$this->student2->id]->course);

        // Teacher 2 only owns course 2.
        $this->set_lastaccess($this->student, $this->course1, time());
        $this->setUser($this->teacher2);
        $rows = $this->get_rows();
        $this->assertEquals($this->course2->id, $rows[$this->student->id]->course->id);
    }

    public function test_pin_goes_first_and_is_per_teacher(): void {
        $this->set_lastaccess($this->student, $this->course2, time());

        $this->setUser($this->teacher1);
        pins::pin($this->student->id, $this->course1->id, access::get_own_course_ids());
        $rows = $this->get_rows();
        $this->assertEquals($this->course1->id, $rows[$this->student->id]->course->id);
        $this->assertTrue($rows[$this->student->id]->pinned);

        // Teacher 2 does not see the pin in course 1.
        $this->setUser($this->teacher2);
        $rows = $this->get_rows();
        $this->assertEquals($this->course2->id, $rows[$this->student->id]->course->id);
        $this->assertFalse($rows[$this->student->id]->pinned);

        // Teacher 2 pins course 2: the pin of teacher 1 in course 1 stays.
        pins::pin($this->student->id, $this->course2->id, access::get_own_course_ids());
        global $DB;
        $this->assertEquals(2, $DB->count_records(pins::TABLE, ['userid' => $this->student->id]));

        // Teacher 1 pins course 1 again: replaces own pins (course 2 is shared).
        $this->setUser($this->teacher1);
        pins::pin($this->student->id, $this->course1->id, access::get_own_course_ids());
        $this->assertEquals([$this->course1->id],
            array_values($DB->get_fieldset_select(pins::TABLE, 'courseid', 'userid = ?', [$this->student->id])));

        $this->assertTrue(pins::unpin($this->student->id, $this->course1->id));
        $this->assertFalse(pins::unpin($this->student->id, $this->course1->id));
    }

    public function test_not_enrolled_warning(): void {
        global $DB;
        $this->set_lastaccess($this->student, $this->course1, time());
        $this->setUser($this->teacher1);
        // Suspend the enrolment: user_lastaccess stays, but no active enrolment.
        $enrol = $DB->get_record('enrol', ['courseid' => $this->course1->id, 'enrol' => 'manual']);
        enrol_get_plugin('manual')->update_user_enrol($enrol, $this->student->id, ENROL_USER_SUSPENDED);

        $rows = $this->get_rows();
        $this->assertEquals($this->course1->id, $rows[$this->student->id]->course->id);
        $this->assertFalse($rows[$this->student->id]->enrolled);
        $this->assertNull($rows[$this->student->id]->progress);
    }

    public function test_progress_uses_visibility_of_student(): void {
        $gen = $this->getDataGenerator();
        $visible = $gen->create_module('assign', ['course' => $this->course1->id,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $gen->create_module('assign', ['course' => $this->course1->id, 'visible' => 0,
            'completion' => COMPLETION_TRACKING_MANUAL]);

        $this->setAdminUser();
        $cm = get_coursemodule_from_id('assign', $visible->cmid);
        $completion = new \completion_info($this->course1);
        $completion->update_state($cm, COMPLETION_COMPLETE, $this->student->id, true);

        // Moodle itself, logged in as the student.
        $this->setUser($this->student);
        $expected = \core_completion\progress::get_course_progress_percentage($this->course1, $this->student->id);
        $this->assertEquals(100, $expected);

        // Our calculation, logged in as the teacher, gives the same.
        $this->setUser($this->teacher1);
        $this->assertEquals($expected, progress::get_percentage($this->course1, $this->student->id));
        $this->assertEquals(0, progress::get_percentage($this->course1, $this->student2->id));
    }

    public function test_groupmates_include_teachers(): void {
        $gen = $this->getDataGenerator();
        $group = $gen->create_group(['courseid' => $this->course1->id]);
        foreach ([$this->student, $this->student2, $this->teacher1] as $user) {
            $gen->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);
        }
        $this->set_lastaccess($this->student, $this->course1, time());

        $this->setUser($this->teacher1);
        $rows = $this->get_rows();
        $mates = array_keys($rows[$this->student->id]->groupmates);
        sort($mates);
        $expected = [(int)$this->student2->id, (int)$this->teacher1->id];
        sort($expected);
        $this->assertEquals($expected, $mates);
    }

    public function test_open_assignments(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $assigngen = $gen->get_plugin_generator('mod_assign');
        $online = ['assignsubmission_onlinetext_enabled' => 1];

        $a1 = $gen->create_module('assign', ['course' => $this->course1->id] + $online);
        $gen->create_module('assign', ['course' => $this->course1->id, 'visible' => 0] + $online);
        $this->set_lastaccess($this->student, $this->course1, time());
        $this->set_lastaccess($this->student2, $this->course1, time());

        $assigngen->create_submission(['userid' => $this->student->id, 'cmid' => $a1->cmid,
            'onlinetext_editor' => ['text' => 'x', 'format' => FORMAT_HTML]]);
        $DB->set_field('assign_submission', 'status', ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            ['assignment' => $a1->id, 'userid' => $this->student->id]);

        $this->setUser($this->teacher1);
        $rows = $this->get_rows();
        // Hidden assignment does not count; the visible one is submitted.
        $this->assertSame(0, $rows[$this->student->id]->openassignments);
        $this->assertSame(1, $rows[$this->student2->id]->openassignments);

        // An assignment with an access restriction counts as open.
        $availability = json_encode(['op' => '&', 'c' => [['type' => 'completion', 'cm' => (int)$a1->cmid, 'e' => 1]],
            'showc' => [true]]);
        $gen->create_module('assign', ['course' => $this->course1->id, 'availability' => $availability] + $online);
        $rows = $this->get_rows();
        $this->assertSame(1, $rows[$this->student->id]->openassignments);
    }

    public function test_open_team_assignment(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $group = $gen->create_group(['courseid' => $this->course1->id]);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $this->student->id]);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $this->student2->id]);
        $assign = $gen->create_module('assign', ['course' => $this->course1->id, 'teamsubmission' => 1,
            'assignsubmission_onlinetext_enabled' => 1]);
        $this->set_lastaccess($this->student, $this->course1, time());
        $this->set_lastaccess($this->student2, $this->course1, time());

        $gen->get_plugin_generator('mod_assign')->create_submission(['userid' => $this->student->id,
            'cmid' => $assign->cmid, 'onlinetext_editor' => ['text' => 'x', 'format' => FORMAT_HTML]]);
        $DB->set_field('assign_submission', 'status', ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            ['assignment' => $assign->id, 'userid' => 0, 'groupid' => $group->id]);

        $this->setUser($this->teacher1);
        $rows = $this->get_rows();
        $this->assertSame(0, $rows[$this->student->id]->openassignments);
        $this->assertSame(0, $rows[$this->student2->id]->openassignments);
    }

    public function test_laptop(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $options = ['Laptop 1', 'Laptop 2'];
        $visible = $gen->create_module('choice', ['course' => $this->course1->id, 'idnumber' => 'LAPTOP-klas',
            'option' => $options]);
        $hidden = $gen->create_module('choice', ['course' => $this->course1->id, 'idnumber' => 'laptop-oud',
            'option' => $options, 'visible' => 0]);
        $this->set_lastaccess($this->student, $this->course1, time());
        $this->set_lastaccess($this->student2, $this->course1, time());

        $answer = function(\stdClass $choicerec, \stdClass $user, string $text) use ($DB) {
            [$course, $cm] = get_course_and_cm_from_cmid($choicerec->cmid, 'choice');
            $choice = choice_get_choice($cm->instance);
            $optionid = $DB->get_field_select('choice_options', 'id',
                'choiceid = ? AND ' . $DB->sql_compare_text('text') . ' = ' . $DB->sql_compare_text('?'),
                [$choice->id, $text]);
            choice_user_submit_response($optionid, $choice, $user->id, $course, $cm);
        };
        $answer($visible, $this->student, 'Laptop 2');
        $answer($hidden, $this->student2, 'Laptop 1');

        $this->setUser($this->teacher1);
        $rows = $this->get_rows();
        $this->assertSame('Laptop 2', $rows[$this->student->id]->laptop);
        $this->assertNull($rows[$this->student2->id]->laptop);
    }

    public function test_suspended_user_not_shown(): void {
        global $DB;
        $DB->set_field('user', 'suspended', 1, ['id' => $this->student2->id]);
        $this->setUser($this->teacher1);
        $rows = $this->get_rows();
        $this->assertArrayHasKey($this->student->id, $rows);
        $this->assertArrayNotHasKey($this->student2->id, $rows);
    }

    public function test_observers_clean_up(): void {
        global $DB;
        $this->setUser($this->teacher1);
        pins::pin($this->student->id, $this->course1->id, null);
        pins::pin($this->student2->id, $this->course2->id, null);

        $this->setAdminUser();
        delete_course($this->course1, false);
        $this->assertFalse($DB->record_exists(pins::TABLE, ['courseid' => $this->course1->id]));

        delete_user($this->teacher1);
        $this->assertEquals(0, $DB->get_field(pins::TABLE, 'usermodified', ['userid' => $this->student2->id]));
        delete_user($this->student2);
        $this->assertFalse($DB->record_exists(pins::TABLE, ['userid' => $this->student2->id]));
    }
}
