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

use core\hook\navigation\primary_extend;
use local_leerlingendashboard\local\access;
use moodle_url;
use navigation_node;

/**
 * Hook callbacks for local_leerlingendashboard.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /** @var string Key of the class overview node in the primary navigation. */
    public const NAVKEY = 'local_leerlingendashboard';

    /** @var string Key of the Mijn Muzieklab node in the primary navigation. */
    public const NAVKEY_OWN = 'local_leerlingendashboard_mijn';

    /**
     * Add links to the class overview (teachers) and Mijn Muzieklab (students in a cohort)
     * in the primary navigation.
     *
     * @param primary_extend $hook
     */
    public static function extend_primary_navigation(primary_extend $hook): void {
        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return;
        }
        if (access::can_view_own() && access::is_cohort_member()) {
            $hook->get_primaryview()->add(
                get_string('mymuzieklab', 'local_leerlingendashboard'),
                new moodle_url('/local/leerlingendashboard/mijn.php'),
                navigation_node::TYPE_ROOTNODE,
                null,
                self::NAVKEY_OWN
            );
        }
        if (access::can_view()) {
            $hook->get_primaryview()->add(
                get_string('classoverview', 'local_leerlingendashboard'),
                new moodle_url('/local/leerlingendashboard/index.php'),
                navigation_node::TYPE_ROOTNODE,
                null,
                self::NAVKEY
            );
        }
    }
}
