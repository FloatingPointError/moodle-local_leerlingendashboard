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

namespace local_leerlingendashboard\privacy;

use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_leerlingendashboard\local\pins;

/**
 * Tests for the privacy provider (design, chapter 10).
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /** @var \stdClass Teacher. */
    protected $teacher;
    /** @var \stdClass Student with a pin. */
    protected $student;
    /** @var \stdClass Student without a pin. */
    protected $other;
    /** @var \stdClass Course. */
    protected $course;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['fullname' => 'Muzieklab test']);
        $this->teacher = $gen->create_user();
        $this->student = $gen->create_user();
        $this->other = $gen->create_user();
        $gen->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $gen->enrol_user($this->student->id, $this->course->id, 'student');

        $this->setUser($this->teacher);
        pins::pin($this->student->id, $this->course->id, null);
        $this->setUser();
    }

    public function test_get_metadata(): void {
        $items = provider::get_metadata(new collection('local_leerlingendashboard'))->get_collection();
        $this->assertCount(1, $items);
        $this->assertEquals(pins::TABLE, reset($items)->get_name());
    }

    public function test_get_contexts_for_userid(): void {
        $studentcontext = context_user::instance($this->student->id);
        $this->assertEquals([$studentcontext->id], provider::get_contexts_for_userid($this->student->id)->get_contextids());
        $this->assertEquals([$studentcontext->id], provider::get_contexts_for_userid($this->teacher->id)->get_contextids());
        $this->assertEmpty(provider::get_contexts_for_userid($this->other->id)->get_contextids());
    }

    public function test_get_users_in_context(): void {
        $userlist = new userlist(context_user::instance($this->student->id), 'local_leerlingendashboard');
        provider::get_users_in_context($userlist);
        $expected = [(int)$this->student->id, (int)$this->teacher->id];
        $actual = array_map('intval', $userlist->get_userids());
        sort($expected);
        sort($actual);
        $this->assertEquals($expected, $actual);
    }

    public function test_export_user_data(): void {
        $context = context_user::instance($this->student->id);
        $this->export_context_data_for_user($this->student->id, $context, 'local_leerlingendashboard');
        $data = writer::with_context($context)->get_data([get_string('privacy:path:pins', 'local_leerlingendashboard')]);
        $this->assertCount(1, $data->pins);
        $this->assertEquals('Muzieklab test', $data->pins[0]->course);
    }

    public function test_delete_data_for_user_student(): void {
        global $DB;
        $context = context_user::instance($this->student->id);
        provider::delete_data_for_user(new approved_contextlist($this->student, 'local_leerlingendashboard', [$context->id]));
        $this->assertFalse($DB->record_exists(pins::TABLE, ['userid' => $this->student->id]));
    }

    public function test_delete_data_for_user_teacher(): void {
        global $DB;
        $context = context_user::instance($this->student->id);
        provider::delete_data_for_user(new approved_contextlist($this->teacher, 'local_leerlingendashboard', [$context->id]));
        // The pin stays, but no longer refers to the teacher.
        $this->assertEquals(0, $DB->get_field(pins::TABLE, 'usermodified', ['userid' => $this->student->id]));
    }

    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context(context_user::instance($this->student->id));
        $this->assertEquals(0, $DB->count_records(pins::TABLE));
    }

    public function test_delete_data_for_users(): void {
        global $DB;
        $context = context_user::instance($this->student->id);
        provider::delete_data_for_users(new approved_userlist($context, 'local_leerlingendashboard', [$this->teacher->id]));
        $this->assertEquals(0, $DB->get_field(pins::TABLE, 'usermodified', ['userid' => $this->student->id]));

        provider::delete_data_for_users(new approved_userlist($context, 'local_leerlingendashboard', [$this->student->id]));
        $this->assertEquals(0, $DB->count_records(pins::TABLE));
    }
}
