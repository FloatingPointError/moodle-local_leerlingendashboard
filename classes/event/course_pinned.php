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

namespace local_leerlingendashboard\event;

/**
 * A teacher pinned a course as the current course of a student.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_pinned extends \core\event\base {
    #[\Override]
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_leerlingendashboard_pin';
    }

    #[\Override]
    public static function get_name() {
        return get_string('eventcoursepinned', 'local_leerlingendashboard');
    }

    #[\Override]
    public function get_description() {
        return "The user with id '$this->userid' pinned the course with id '$this->courseid' " .
            "for the user with id '$this->relateduserid'.";
    }

    #[\Override]
    public function get_url() {
        return new \moodle_url('/local/leerlingendashboard/index.php');
    }

    #[\Override]
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }

    #[\Override]
    public static function get_objectid_mapping() {
        return ['db' => 'local_leerlingendashboard_pin', 'restore' => \core\event\base::NOT_MAPPED];
    }
}
