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

use core_external\external_api;
use local_leerlingendashboard\local\pins;

/**
 * Tests for the pin and unpin web services.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(pin_base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(pin_course::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(unpin_course::class)]
final class pin_course_test extends \core_external\tests\externallib_testcase {
    public function test_pin_and_unpin(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'Muzieklab test']);
        $other = $gen->create_course();
        $teacher = $gen->create_user();
        $student = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($student->id, $other->id, 'student');
        $cohort = $gen->create_cohort();
        cohort_add_member($cohort->id, $student->id);

        $this->setUser($teacher);
        $result = external_api::clean_returnvalue(pin_course::execute_returns(),
            pin_course::execute($student->id, $course->id, $cohort->id));
        $this->assertTrue($DB->record_exists(pins::TABLE, ['userid' => $student->id, 'courseid' => $course->id]));
        $this->assertStringContainsString('data-userid="' . $student->id . '"', $result['html']);
        $this->assertStringContainsString('Muzieklab test', $result['html']);

        external_api::clean_returnvalue(unpin_course::execute_returns(),
            unpin_course::execute($student->id, $course->id, $cohort->id));
        $this->assertFalse($DB->record_exists(pins::TABLE, ['userid' => $student->id]));

        // Not an own course.
        $this->expectException(\required_capability_exception::class);
        pin_course::execute($student->id, $other->id, $cohort->id);
    }

    public function test_student_not_in_cohort(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $student = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->enrol_user($student->id, $course->id, 'student');
        $cohort = $gen->create_cohort();

        $this->setUser($teacher);
        $this->expectException(\moodle_exception::class);
        pin_course::execute($student->id, $course->id, $cohort->id);
    }
}
