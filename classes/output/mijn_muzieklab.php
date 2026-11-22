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

namespace local_leerlingendashboard\output;

use context_course;
use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use local_leerlingendashboard\local\huidigecursus;
use local_leerlingendashboard\local\leerlingdata;
use moodle_url;
use stdClass;

/**
 * Mijn Muzieklab: the current course of the student, with progress, group mates, open assignments
 * and laptop (design fase 3).
 *
 * Shows data of the current user only.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mijn_muzieklab implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass $user The student (the current user).
     */
    public function __construct(
        /** @var stdClass The student (the current user). */
        protected stdClass $user,
    ) {
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        global $DB;
        $component = 'local_leerlingendashboard';
        $userid = (int)$this->user->id;

        $data = [
            'hascourse' => false,
            'mycoursesurl' => (new moodle_url('/my/courses.php'))->out(false),
        ];

        // Current course: most recent pin by any teacher, else last accessed; only active enrolments (chapter 3).
        $current = huidigecursus::get([$userid], null, true)[$userid] ?? null;
        $course = $current ? $DB->get_record('course', ['id' => $current->courseid]) : null;
        if (!$course) {
            return $data;
        }

        $context = context_course::instance($course->id);
        $student = new leerlingdata($course, [$userid]);
        $modinfo = get_fast_modinfo($course, $userid);

        $data['hascourse'] = true;
        $data['coursename'] = format_string($course->fullname, true, ['context' => $context]);
        $data['courseurl'] = (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        $data['pinned'] = $current->pinned;

        // Progress (design fase 2, chapter 5).
        $progress = $student->get_progress($userid);
        $data['hasprogress'] = $progress !== null;
        $data['progress'] = $progress !== null ? (int)round($progress) : 0;

        // Group mates, with the group visibility of Moodle (chapter 5).
        $mates = $student->get_groupmates(true)[$userid] ?? [];
        $data['groupmates'] = $mates ? implode(', ', array_map('fullname', $mates)) : get_string('nogroupmates', $component);

        // Open assignments as a list (chapter 6).
        $data['assignments'] = [];
        $hidden = 0;
        foreach ($student->get_open_assignments()[$userid] ?? [] as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            if ($cm->uservisible) {
                $data['assignments'][] = [
                    'name' => $cm->get_formatted_name(),
                    'url' => $cm->url ? $cm->url->out(false) : '',
                    'locked' => false,
                    'due' => $this->get_due_date($cm, $userid),
                ];
            } else if ($cm->is_visible_on_course_page()) {
                // Restricted, but Moodle shows it greyed out with the conditions.
                $data['assignments'][] = [
                    'name' => $cm->get_formatted_name(),
                    'url' => '',
                    'locked' => true,
                    'due' => $this->get_due_date($cm, $userid),
                ];
            } else {
                // Hidden until unlocked: count only, no name.
                $hidden++;
            }
        }
        $data['hasassignments'] = !empty($data['assignments']) || $hidden > 0;
        $data['hiddenassignments'] = $hidden > 0
            ? get_string($hidden === 1 ? 'hiddenassignment' : 'hiddenassignments', $component, $hidden)
            : '';

        // Laptop (chapter 7): the answer, or a link to the laptop choice.
        $laptop = $student->get_laptops()[$userid] ?? null;
        $data['haslaptop'] = $laptop !== null;
        $data['laptop'] = $laptop ?? '';
        $data['chooselaptopurl'] = '';
        if ($laptop === null) {
            foreach ($student->get_laptop_choice_cmids() as $choicecmid) {
                $choicecm = $modinfo->get_cm($choicecmid);
                if ($choicecm->uservisible && $choicecm->url) {
                    $data['chooselaptopurl'] = $choicecm->url->out(false);
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * The due date of an assignment for this student, as Moodle shows it on the course page
     * (including overrides and extensions).
     *
     * @param \cm_info $cm The assignment.
     * @param int $userid The student.
     * @return string Label and date, or '' if there is no due date.
     */
    protected function get_due_date(\cm_info $cm, int $userid): string {
        foreach (\core\activity_dates::get_dates_for_module($cm, $userid) as $date) {
            if (($date['dataid'] ?? '') === 'duedate' && !empty($date['timestamp'])) {
                return $date['label'] . ' ' . userdate($date['timestamp'], get_string('strftimedatetimeshort', 'langconfig'));
            }
        }
        return '';
    }
}
