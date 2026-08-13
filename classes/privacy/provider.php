<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU GPL v3.

namespace quiz_aigrader\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for AI Grader.
 *
 * AI Grader does NOT store personal data in plugin tables.
 * It only sends the student answer + question text to the external API.
 *
 * @package   quiz_aigrader
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright  2026 LMS-Labs
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {
    /**
     * Describe data sent to external API.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link(
            'essaygraderai_api',
            [
                'questiontext' => 'privacy:metadata:essaygraderai_api:questiontext',
                'answer'       => 'privacy:metadata:essaygraderai_api:answer',
            ],
            'privacy:metadata:essaygraderai_api'
        );

        return $collection;
    }

    /** No local storage — return an empty context list. */
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    /** No user data stored locally. */
    public static function get_users_in_context(userlist $userlist) {}

    /** Nothing to export. */
    public static function export_user_data(approved_contextlist $contextlist) {}

    /** Nothing to delete. */
    public static function delete_data_for_all_users_in_context(\context $context) {}

    /** Nothing to delete. */
    public static function delete_data_for_user(approved_contextlist $contextlist) {}

    /** Nothing to delete. */
    public static function delete_data_for_users(approved_userlist $userlist) {}
}