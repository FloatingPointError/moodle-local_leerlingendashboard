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

use context;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_leerlingendashboard\local\pins;

/**
 * Privacy provider (design, chapter 10).
 *
 * The plugin stores pinned courses. They belong to the student, so they live in the user
 * context of the student. The teacher who pinned (usermodified) is also personal data.
 *
 * @package    local_leerlingendashboard
 * @copyright  2026 Michel Labruyère
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(pins::TABLE, [
            'userid' => 'privacy:metadata:pin:userid',
            'courseid' => 'privacy:metadata:pin:courseid',
            'usermodified' => 'privacy:metadata:pin:usermodified',
            'timecreated' => 'privacy:metadata:pin:timecreated',
            'timemodified' => 'privacy:metadata:pin:timemodified',
        ], 'privacy:metadata:pin');
        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {" . pins::TABLE . "} p
                  JOIN {context} ctx ON ctx.instanceid = p.userid AND ctx.contextlevel = :userlevel
                 WHERE p.userid = :userid OR p.usermodified = :usermodified";
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, [
            'userlevel' => CONTEXT_USER,
            'userid' => $userid,
            'usermodified' => $userid,
        ]);
        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof context_user) {
            return;
        }
        $params = ['studentid' => $context->instanceid];
        $userlist->add_from_sql('userid', "SELECT userid FROM {" . pins::TABLE . "} WHERE userid = :studentid", $params);
        $userlist->add_from_sql('usermodified',
            "SELECT usermodified FROM {" . pins::TABLE . "} WHERE userid = :studentid AND usermodified <> 0", $params);
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_user) {
                continue;
            }
            $sql = "SELECT p.*, c.fullname
                      FROM {" . pins::TABLE . "} p
                 LEFT JOIN {course} c ON c.id = p.courseid
                     WHERE p.userid = :studentid
                       AND (p.userid = :userid OR p.usermodified = :usermodified)
                  ORDER BY p.timemodified, p.id";
            $pins = $DB->get_records_sql($sql, [
                'studentid' => $context->instanceid,
                'userid' => $userid,
                'usermodified' => $userid,
            ]);
            if (!$pins) {
                continue;
            }
            $data = [];
            foreach ($pins as $pin) {
                $data[] = (object)[
                    'course' => $pin->fullname !== null ? format_string($pin->fullname) : (int)$pin->courseid,
                    'pinnedbyyou' => transform::yesno($pin->usermodified == $userid),
                    'timecreated' => transform::datetime($pin->timecreated),
                    'timemodified' => transform::datetime($pin->timemodified),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('privacy:path:pins', 'local_leerlingendashboard')],
                (object)['pins' => $data]
            );
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;
        if ($context instanceof context_user) {
            $DB->delete_records(pins::TABLE, ['userid' => $context->instanceid]);
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_user) {
                continue;
            }
            if ((int)$context->instanceid === $userid) {
                // The student: remove their pins.
                $DB->delete_records(pins::TABLE, ['userid' => $userid]);
            } else {
                // The teacher: the pin stays, but no longer refers to them.
                $DB->set_field(pins::TABLE, 'usermodified', 0,
                    ['userid' => $context->instanceid, 'usermodified' => $userid]);
            }
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof context_user) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        if (in_array((int)$context->instanceid, array_map('intval', $userids), true)) {
            $DB->delete_records(pins::TABLE, ['userid' => $context->instanceid]);
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['studentid'] = $context->instanceid;
        $DB->set_field_select(pins::TABLE, 'usermodified', 0, "userid = :studentid AND usermodified $insql", $params);
    }
}
