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

/**
 * Web services for local_leerlingendashboard (used by the class overview page).
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_leerlingendashboard_pin_course' => [
        'classname' => \local_leerlingendashboard\external\pin_course::class,
        'description' => 'Pin a course as the current course of a student, and return the refreshed row.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/leerlingendashboard:viewclass',
    ],
    'local_leerlingendashboard_unpin_course' => [
        'classname' => \local_leerlingendashboard\external\unpin_course::class,
        'description' => 'Unpin a course for a student, and return the refreshed row.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/leerlingendashboard:viewclass',
    ],
];
