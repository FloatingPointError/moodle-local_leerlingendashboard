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

use completion_info;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');

/**
 * Course progress as the student sees it (design, chapter 5).
 *
 * \core_completion\progress::get_course_progress_percentage() decides which activities count
 * with the visibility of the logged-in user (the teacher), so hidden activities would count.
 * This class follows the same steps, but with the course modinfo of the student. Counting the
 * completed activities is still done by Moodle's own completion_info::count_modules_completed().
 *
 * Keep in line with completion_info::get_user_activities_with_completion() (Moodle 5.2).
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress {
    /**
     * Course progress of a student, in percent.
     *
     * @param stdClass $course Course record.
     * @param int $userid The student.
     * @return float|null The percentage, or null if completion is not enabled, the student is not
     *         tracked, or there are no activities with completion that the student can see.
     */
    public static function get_percentage(stdClass $course, int $userid): ?float {
        $completion = new completion_info($course);
        if (!$completion->is_enabled() || !$completion->is_tracked_user($userid)) {
            return null;
        }
        if ($completion->is_course_complete($userid)) {
            return 100.0;
        }

        $cmids = self::get_activities_for_user($course, $userid);
        if (!$cmids) {
            return null;
        }
        return $completion->count_modules_completed($userid, $cmids) / count($cmids) * 100;
    }

    /**
     * Ids of the activities with completion that count for this student.
     *
     * @param stdClass $course Course record.
     * @param int $userid The student.
     * @return int[]
     */
    public static function get_activities_for_user(stdClass $course, int $userid): array {
        $cmids = [];
        // Modinfo of the student, so visibility is evaluated for the student, not for $USER.
        foreach (get_fast_modinfo($course, $userid)->get_cms() as $cm) {
            if ($cm->completion == COMPLETION_TRACKING_NONE || $cm->deletioninprogress) {
                continue;
            }
            if (!$cm->is_visible_on_course_page()) {
                continue;
            }
            if (!empty($cm->availability) || (int)$cm->groupmode === SEPARATEGROUPS || !empty($cm->groupingid)) {
                // Group or grouping restrictions can exclude the student.
                $info = new \core_availability\info_module($cm);
                if (!$info->filter_user_list([$userid => (object)['id' => $userid]])) {
                    continue;
                }
            }
            $cmids[] = (int)$cm->id;
        }
        return $cmids;
    }
}
