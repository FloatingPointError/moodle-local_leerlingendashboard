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
use local_leerlingendashboard\local\huidigecursus;
use local_leerlingendashboard\local\leerlingdata;
use local_leerlingendashboard\local\pins;
use local_leerlingendashboard\output\mijn_muzieklab;

/**
 * Tests for Mijn Muzieklab (design fase 3).
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mijn_muzieklab::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(huidigecursus::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(leerlingdata::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
final class mijn_muzieklab_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    protected $course;
    /** @var \stdClass Student. */
    protected $student;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->dirroot . '/mod/choice/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['fullname' => 'Muzieklab test', 'enablecompletion' => 1]);
        $this->student = $gen->create_user(['firstname' => 'Sam', 'lastname' => 'Student']);
        $gen->enrol_user($this->student->id, $this->course->id, 'student');
        $this->set_lastaccess($this->student, $this->course, time());
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
     * Template data of Mijn Muzieklab for the student, as the student.
     *
     * @return array
     */
    protected function export(): array {
        global $PAGE;
        $this->setUser($this->student);
        get_fast_modinfo($this->course, 0, true);
        return (new mijn_muzieklab($this->student))->export_for_template($PAGE->get_renderer('core'));
    }

    public function test_access(): void {
        $this->setUser($this->student);
        $this->assertTrue(access::can_view_own());
        $this->assertFalse(access::is_cohort_member());
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $this->student->id);
        $this->assertTrue(access::is_cohort_member());
        // A student cannot open the class overview.
        $this->assertFalse(access::can_view());

        $this->setGuestUser();
        $this->assertFalse(access::can_view_own());
    }

    public function test_current_course_only_active_enrolment(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $other = $gen->create_course(['fullname' => 'Andere cursus']);
        $gen->enrol_user($this->student->id, $other->id, 'student');
        $this->set_lastaccess($this->student, $this->course, time() - 100);

        // Pin in the other course, then suspend the enrolment there: the pin is skipped for the student.
        $this->setAdminUser();
        pins::pin($this->student->id, $other->id, null);
        $enrol = $DB->get_record('enrol', ['courseid' => $other->id, 'enrol' => 'manual']);
        enrol_get_plugin('manual')->update_user_enrol($enrol, $this->student->id, ENROL_USER_SUSPENDED);

        $data = $this->export();
        $this->assertTrue($data['hascourse']);
        $this->assertEquals('Muzieklab test', $data['coursename']);
        $this->assertFalse($data['pinned']);

        // The teacher's view still shows the pinned course.
        $current = huidigecursus::get([$this->student->id], null, false);
        $this->assertEquals($other->id, $current[$this->student->id]->courseid);
    }

    public function test_no_current_course(): void {
        global $DB;
        $DB->delete_records('user_lastaccess', ['userid' => $this->student->id]);
        $data = $this->export();
        $this->assertFalse($data['hascourse']);
    }

    public function test_groupmates_follow_group_visibility(): void {
        $gen = $this->getDataGenerator();
        $mates = [];
        foreach ([GROUPS_VISIBILITY_ALL => 'Alle', GROUPS_VISIBILITY_MEMBERS => 'Leden',
                GROUPS_VISIBILITY_OWN => 'Eigen', GROUPS_VISIBILITY_NONE => 'Niemand'] as $visibility => $name) {
            $group = $gen->create_group(['courseid' => $this->course->id, 'visibility' => $visibility]);
            $mate = $gen->create_user(['firstname' => 'Maat', 'lastname' => $name]);
            $gen->enrol_user($mate->id, $this->course->id, 'student');
            $gen->create_group_member(['groupid' => $group->id, 'userid' => $this->student->id]);
            $gen->create_group_member(['groupid' => $group->id, 'userid' => $mate->id]);
            $mates[$visibility] = $mate;
        }

        $data = $this->export();
        $this->assertStringContainsString('Maat Alle', $data['groupmates']);
        $this->assertStringContainsString('Maat Leden', $data['groupmates']);
        $this->assertStringNotContainsString('Maat Eigen', $data['groupmates']);
        $this->assertStringNotContainsString('Maat Niemand', $data['groupmates']);

        // Without visibility (teacher view) all four are shown.
        $all = (new leerlingdata($this->course, [(int)$this->student->id]))->get_groupmates()[$this->student->id];
        $this->assertCount(4, $all);
    }

    public function test_open_assignments_three_situations(): void {
        $gen = $this->getDataGenerator();
        $online = ['assignsubmission_onlinetext_enabled' => 1];
        $first = $gen->create_module('assign', ['course' => $this->course->id, 'name' => 'Beschikbaar',
            'duedate' => time() + WEEKSECS, 'completion' => COMPLETION_TRACKING_MANUAL] + $online);
        $condition = ['op' => '&', 'c' => [['type' => 'completion', 'cm' => (int)$first->cmid, 'e' => 1]]];
        $gen->create_module('assign', ['course' => $this->course->id, 'name' => 'Grijs',
            'availability' => json_encode($condition + ['showc' => [true]])] + $online);
        $gen->create_module('assign', ['course' => $this->course->id, 'name' => 'Verborgen tot ontgrendeld',
            'availability' => json_encode($condition + ['showc' => [false]])] + $online);
        $gen->create_module('assign', ['course' => $this->course->id, 'name' => 'Verborgen', 'visible' => 0] + $online);

        $data = $this->export();
        $this->assertTrue($data['hasassignments']);
        $this->assertCount(2, $data['assignments']);
        [$available, $greyed] = $data['assignments'];
        $this->assertEquals('Beschikbaar', $available['name']);
        $this->assertFalse($available['locked']);
        $this->assertNotEmpty($available['url']);
        $this->assertNotEmpty($available['due']);
        $this->assertEquals('Grijs', $greyed['name']);
        $this->assertTrue($greyed['locked']);
        $this->assertEmpty($greyed['url']);
        // Hidden until unlocked: counted, without name.
        $this->assertStringContainsString('1', $data['hiddenassignments']);
        $this->assertStringNotContainsString('Verborgen tot ontgrendeld', json_encode($data));
        // A hidden assignment does not count at all.
        $this->assertStringNotContainsString('"Verborgen"', json_encode($data));
    }

    public function test_laptop_link_and_answer(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $choice = $gen->create_module('choice', ['course' => $this->course->id, 'idnumber' => 'laptop-klas',
            'option' => ['Laptop 1', 'Laptop 2']]);

        $data = $this->export();
        $this->assertFalse($data['haslaptop']);
        $this->assertStringContainsString('/mod/choice/view.php', $data['chooselaptopurl']);

        [$course, $cm] = get_course_and_cm_from_cmid($choice->cmid, 'choice');
        $choicerecord = choice_get_choice($cm->instance);
        $optionid = $DB->get_field_select('choice_options', 'id',
            'choiceid = ? AND ' . $DB->sql_compare_text('text') . ' = ' . $DB->sql_compare_text('?'),
            [$choicerecord->id, 'Laptop 2']);
        choice_user_submit_response($optionid, $choicerecord, $this->student->id, $course, $cm);

        $data = $this->export();
        $this->assertTrue($data['haslaptop']);
        $this->assertEquals('Laptop 2', $data['laptop']);
        $this->assertEmpty($data['chooselaptopurl']);
    }

    public function test_progress(): void {
        $gen = $this->getDataGenerator();
        $assign = $gen->create_module('assign', ['course' => $this->course->id,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $gen->create_module('assign', ['course' => $this->course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->setAdminUser();
        $completion = new \completion_info($this->course);
        $completion->update_state(get_coursemodule_from_id('assign', $assign->cmid), COMPLETION_COMPLETE,
            $this->student->id, true);

        $data = $this->export();
        $this->assertTrue($data['hasprogress']);
        $this->assertEquals(50, $data['progress']);
    }
}
