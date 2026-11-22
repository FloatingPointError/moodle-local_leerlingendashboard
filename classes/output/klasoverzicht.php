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

use context;
use context_course;
use core\output\renderable;
use core\output\renderer_base;
use core\output\single_select;
use core\output\templatable;
use local_leerlingendashboard\local\klasoverzicht as klasoverzicht_data;
use local_leerlingendashboard\local\pins;
use moodle_url;
use stdClass;

/**
 * The class overview page: cohort selector and one row per student.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class klasoverzicht implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass[] $cohorts Visible cohorts (id, name, contextid), keyed by id.
     * @param int $cohortid The chosen cohort, 0 for none.
     * @param int[]|null $owncourseids Own courses of the teacher, null for all courses.
     */
    public function __construct(
        /** @var stdClass[] Visible cohorts. */
        protected array $cohorts,
        /** @var int The chosen cohort. */
        protected int $cohortid,
        /** @var int[]|null Own courses of the teacher. */
        protected ?array $owncourseids,
    ) {
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $options = [];
        foreach ($this->cohorts as $cohort) {
            $options[$cohort->id] = format_string($cohort->name, true,
                ['context' => context::instance_by_id($cohort->contextid)]);
        }

        $select = new single_select(new moodle_url('/local/leerlingendashboard/index.php'), 'cohortid', $options,
            $this->cohortid ?: '', ['' => get_string('choosecohort', 'local_leerlingendashboard')]);
        $select->set_label(get_string('class', 'local_leerlingendashboard'));

        $data = [
            'cohortselect' => $output->render($select),
            'hascohorts' => !empty($options),
            'hascohort' => $this->cohortid > 0,
            'cohortname' => $options[$this->cohortid] ?? '',
            'pinurl' => (new moodle_url('/local/leerlingendashboard/pin.php'))->out(false),
            'sesskey' => sesskey(),
            'cohortid' => $this->cohortid,
            'rows' => [],
            'hasrows' => false,
        ];

        if ($this->cohortid) {
            $rows = (new klasoverzicht_data($this->cohortid, $this->owncourseids))->get_rows();
            $this->pinurl = $data['pinurl'];
            foreach ($rows as $row) {
                $data['rows'][] = $this->export_row($row);
            }
            $data['hasrows'] = !empty($data['rows']);
        }
        return $data;
    }

    /** @var string URL of pin.php, for the forms without JavaScript. */
    protected string $pinurl = '';

    /**
     * Data for the row of one student, to refresh that row after pinning (template klasoverzicht_row).
     *
     * @param int $userid The student.
     * @return array|null Null if the student is not (or no longer) shown in this class.
     */
    public function export_single_row(int $userid): ?array {
        $this->pinurl = (new moodle_url('/local/leerlingendashboard/pin.php'))->out(false);
        $rows = (new klasoverzicht_data($this->cohortid, $this->owncourseids))->get_rows($userid);
        return isset($rows[$userid]) ? $this->export_row($rows[$userid]) : null;
    }

    /**
     * Data for one row.
     *
     * @param stdClass $row Row from \local_leerlingendashboard\local\klasoverzicht::get_rows().
     * @return array
     */
    protected function export_row(stdClass $row): array {
        $component = 'local_leerlingendashboard';
        $na = get_string('notavailable', $component);
        $userid = (int)$row->user->id;

        $result = [
            'userid' => $userid,
            'cohortid' => $this->cohortid,
            'pinurl' => $this->pinurl,
            'sesskey' => sesskey(),
            'fullname' => fullname($row->user),
            'hascourse' => $row->course !== null,
            'coursename' => '',
            'courseurl' => '',
            'profileurl' => '',
            'pinned' => $row->pinned,
            'notenrolled' => false,
            'courseid' => 0,
            'progress' => $na,
            'groupmates' => $na,
            'open' => $na,
            'opentitle' => '',
            'laptop' => $na,
        ];

        if ($row->course) {
            $course = $row->course;
            $context = context_course::instance($course->id);
            $result['courseid'] = (int)$course->id;
            $result['coursename'] = format_string($course->fullname, true, ['context' => $context]);
            $result['courseurl'] = (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
            $result['profileurl'] = (new moodle_url('/user/view.php',
                ['id' => $userid, 'course' => $course->id]))->out(false);
            $result['notenrolled'] = !$row->enrolled;

            if ($row->progress !== null) {
                $result['progress'] = get_string('percent', $component, (int)round($row->progress));
            }

            if ($row->groupmates) {
                $result['groupmates'] = implode(', ', array_map('fullname', $row->groupmates));
            } else {
                $result['groupmates'] = get_string('nogroupmates', $component);
            }

            if ($row->openassignments !== null) {
                $result['open'] = get_string($row->openassignments > 0 ? 'yes' : 'no');
                $result['opentitle'] = get_string('openassignmentscount', $component, $row->openassignments);
            }

            if ($row->laptop !== null) {
                $result['laptop'] = $row->laptop;
            }
        } else {
            $result['coursename'] = get_string('nocourse', $component);
        }

        // Pin selector: own courses where the student is enrolled, except the pinned course itself.
        // Choosing a course pins it right away (design, 4.4). Unpin when the shown course is pinned.
        $result['pinoptions'] = [];
        foreach (pins::get_pinnable_courses($userid, $this->owncourseids) as $course) {
            if ($row->pinned && $row->course && $course->id == $row->course->id) {
                continue;
            }
            $result['pinoptions'][] = [
                'id' => (int)$course->id,
                'name' => format_string($course->fullname, true, ['context' => context_course::instance($course->id)]),
            ];
        }
        $result['canpin'] = !empty($result['pinoptions']);
        return $result;
    }
}
