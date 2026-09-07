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

/**
 * Privacy Subsystem implementation for quiz_aigrader.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_aigrader\privacy;

use context;
use context_module;
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy Subsystem implementation for quiz_aigrader.
 *
 * The plugin stores per-user grading context, per-grader activity logs and
 * per-user scheduled report configurations. It also transmits question text
 * and student answers to an external grading service.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Returns metadata about the data this plugin stores and transmits.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'quiz_aigrader_attempt_ctx',
            [
                'quizid' => 'privacy:metadata:quiz_aigrader_attempt_ctx:quizid',
                'userid' => 'privacy:metadata:quiz_aigrader_attempt_ctx:userid',
                'questionid' => 'privacy:metadata:quiz_aigrader_attempt_ctx:questionid',
                'slot' => 'privacy:metadata:quiz_aigrader_attempt_ctx:slot',
                'attemptnum' => 'privacy:metadata:quiz_aigrader_attempt_ctx:attemptnum',
                'criteriamet' => 'privacy:metadata:quiz_aigrader_attempt_ctx:criteriamet',
                'feedbacksummary' => 'privacy:metadata:quiz_aigrader_attempt_ctx:feedbacksummary',
                'lastgrade' => 'privacy:metadata:quiz_aigrader_attempt_ctx:lastgrade',
                'humanreview' => 'privacy:metadata:quiz_aigrader_attempt_ctx:humanreview',
                'autolocked' => 'privacy:metadata:quiz_aigrader_attempt_ctx:autolocked',
                'timecreated' => 'privacy:metadata:quiz_aigrader_attempt_ctx:timecreated',
                'timemodified' => 'privacy:metadata:quiz_aigrader_attempt_ctx:timemodified',
            ],
            'privacy:metadata:quiz_aigrader_attempt_ctx'
        );

        $collection->add_database_table(
            'quiz_aigrader_grading_logs',
            [
                'quizid' => 'privacy:metadata:quiz_aigrader_grading_logs:quizid',
                'courseid' => 'privacy:metadata:quiz_aigrader_grading_logs:courseid',
                'graderid' => 'privacy:metadata:quiz_aigrader_grading_logs:graderid',
                'qubaid' => 'privacy:metadata:quiz_aigrader_grading_logs:qubaid',
                'slot' => 'privacy:metadata:quiz_aigrader_grading_logs:slot',
                'timegraded' => 'privacy:metadata:quiz_aigrader_grading_logs:timegraded',
            ],
            'privacy:metadata:quiz_aigrader_grading_logs'
        );

        $collection->add_database_table(
            'quiz_aigrader_schedules',
            [
                'userid' => 'privacy:metadata:quiz_aigrader_schedules:userid',
                'frequency' => 'privacy:metadata:quiz_aigrader_schedules:frequency',
                'recipients' => 'privacy:metadata:quiz_aigrader_schedules:recipients',
                'lastrun' => 'privacy:metadata:quiz_aigrader_schedules:lastrun',
                'nextrun' => 'privacy:metadata:quiz_aigrader_schedules:nextrun',
                'enabled' => 'privacy:metadata:quiz_aigrader_schedules:enabled',
                'format' => 'privacy:metadata:quiz_aigrader_schedules:format',
                'timecreated' => 'privacy:metadata:quiz_aigrader_schedules:timecreated',
                'timemodified' => 'privacy:metadata:quiz_aigrader_schedules:timemodified',
            ],
            'privacy:metadata:quiz_aigrader_schedules'
        );

        $collection->add_user_preference(
            'quiz_aigrader_datefrom',
            'privacy:metadata:preference:datefrom'
        );
        $collection->add_user_preference(
            'quiz_aigrader_dateto',
            'privacy:metadata:preference:dateto'
        );
        $collection->add_user_preference(
            'quiz_aigrader_report_startdate',
            'privacy:metadata:preference:reportstartdate'
        );
        $collection->add_user_preference(
            'quiz_aigrader_report_enddate',
            'privacy:metadata:preference:reportenddate'
        );
        $collection->add_user_preference(
            'quiz_aigrader_report_graderid',
            'privacy:metadata:preference:reportgraderid'
        );

        $collection->add_external_location_link(
            'essaygraderai_api',
            [
                'questiontext' => 'privacy:metadata:essaygraderai_api:questiontext',
                'answer' => 'privacy:metadata:essaygraderai_api:answer',
            ],
            'privacy:metadata:essaygraderai_api'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {quiz_aigrader_attempt_ctx} ac
                  JOIN {quiz} q ON q.id = ac.quizid
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                 WHERE ac.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'modname' => 'quiz',
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ]);

        $sql = "SELECT ctx.id
                  FROM {quiz_aigrader_grading_logs} gl
                  JOIN {quiz} q ON q.id = gl.quizid
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                 WHERE gl.graderid = :graderid";
        $contextlist->add_from_sql($sql, [
            'modname' => 'quiz',
            'contextlevel' => CONTEXT_MODULE,
            'graderid' => $userid,
        ]);

        if (self::has_schedules($userid)) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context instanceof context_module) {
            $params = ['cmid' => $context->instanceid, 'modname' => 'quiz'];

            $sql = "SELECT ac.userid
                      FROM {quiz_aigrader_attempt_ctx} ac
                      JOIN {quiz} q ON q.id = ac.quizid
                      JOIN {course_modules} cm ON cm.instance = q.id
                      JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                     WHERE cm.id = :cmid";
            $userlist->add_from_sql('userid', $sql, $params);

            $sql = "SELECT gl.graderid
                      FROM {quiz_aigrader_grading_logs} gl
                      JOIN {quiz} q ON q.id = gl.quizid
                      JOIN {course_modules} cm ON cm.instance = q.id
                      JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                     WHERE cm.id = :cmid";
            $userlist->add_from_sql('graderid', $sql, $params);
        } else if ($context instanceof context_system) {
            $sql = "SELECT s.userid FROM {quiz_aigrader_schedules} s";
            $userlist->add_from_sql('userid', $sql, []);
        }
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_module) {
                $cm = get_coursemodule_from_id('quiz', $context->instanceid, 0, false, IGNORE_MISSING);
                if (!$cm) {
                    continue;
                }

                $records = $DB->get_records(
                    'quiz_aigrader_attempt_ctx',
                    ['quizid' => $cm->instance, 'userid' => $userid]
                );
                if ($records) {
                    $data = [];
                    foreach ($records as $record) {
                        $data[] = (object) [
                            'questionid' => $record->questionid,
                            'slot' => $record->slot,
                            'attemptnum' => $record->attemptnum,
                            'criteriamet' => $record->criteriamet,
                            'feedbacksummary' => $record->feedbacksummary,
                            'lastgrade' => $record->lastgrade,
                            'humanreview' => transform::yesno($record->humanreview),
                            'autolocked' => transform::yesno($record->autolocked),
                            'timecreated' => transform::datetime($record->timecreated),
                            'timemodified' => transform::datetime($record->timemodified),
                        ];
                    }
                    writer::with_context($context)->export_data(
                        [get_string('privacy:path:attemptcontext', 'quiz_aigrader')],
                        (object) ['gradingcontext' => $data]
                    );
                }

                $logs = $DB->get_records(
                    'quiz_aigrader_grading_logs',
                    ['quizid' => $cm->instance, 'graderid' => $userid]
                );
                if ($logs) {
                    $data = [];
                    foreach ($logs as $log) {
                        $data[] = (object) [
                            'qubaid' => $log->qubaid,
                            'slot' => $log->slot,
                            'timegraded' => transform::datetime($log->timegraded),
                        ];
                    }
                    writer::with_context($context)->export_data(
                        [get_string('privacy:path:gradinglogs', 'quiz_aigrader')],
                        (object) ['gradinglogs' => $data]
                    );
                }
            } else if ($context instanceof context_system) {
                $schedules = $DB->get_records('quiz_aigrader_schedules', ['userid' => $userid]);
                if ($schedules) {
                    $data = [];
                    foreach ($schedules as $schedule) {
                        $data[] = (object) [
                            'frequency' => $schedule->frequency,
                            'recipients' => $schedule->recipients,
                            'format' => $schedule->format,
                            'enabled' => transform::yesno($schedule->enabled),
                            'lastrun' => $schedule->lastrun ? transform::datetime($schedule->lastrun) : null,
                            'nextrun' => $schedule->nextrun ? transform::datetime($schedule->nextrun) : null,
                            'timecreated' => transform::datetime($schedule->timecreated),
                            'timemodified' => transform::datetime($schedule->timemodified),
                        ];
                    }
                    writer::with_context($context)->export_data(
                        [get_string('privacy:path:schedules', 'quiz_aigrader')],
                        (object) ['schedules' => $data]
                    );
                }
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        if ($context instanceof context_module) {
            $cm = get_coursemodule_from_id('quiz', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return;
            }
            $DB->delete_records('quiz_aigrader_attempt_ctx', ['quizid' => $cm->instance]);
            $DB->delete_records('quiz_aigrader_grading_logs', ['quizid' => $cm->instance]);
        } else if ($context instanceof context_system) {
            $DB->delete_records('quiz_aigrader_schedules');
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_module) {
                $cm = get_coursemodule_from_id('quiz', $context->instanceid, 0, false, IGNORE_MISSING);
                if (!$cm) {
                    continue;
                }
                $DB->delete_records(
                    'quiz_aigrader_attempt_ctx',
                    ['quizid' => $cm->instance, 'userid' => $userid]
                );
                $DB->delete_records(
                    'quiz_aigrader_grading_logs',
                    ['quizid' => $cm->instance, 'graderid' => $userid]
                );
            } else if ($context instanceof context_system) {
                $DB->delete_records('quiz_aigrader_schedules', ['userid' => $userid]);
            }
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        if ($context instanceof context_module) {
            $cm = get_coursemodule_from_id('quiz', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return;
            }
            $params = array_merge($inparams, ['quizid' => $cm->instance]);
            $DB->delete_records_select(
                'quiz_aigrader_attempt_ctx',
                "quizid = :quizid AND userid {$insql}",
                $params
            );
            $DB->delete_records_select(
                'quiz_aigrader_grading_logs',
                "quizid = :quizid AND graderid {$insql}",
                $params
            );
        } else if ($context instanceof context_system) {
            $DB->delete_records_select('quiz_aigrader_schedules', "userid {$insql}", $inparams);
        }
    }

    /**
     * Export the saved filter preferences for the specified user.
     *
     * The grading-screen date range is stored one preference per course module, so those
     * are looked up by name prefix rather than from a fixed list.
     *
     * @param int $userid The user whose preferences should be exported.
     * @return void
     */
    public static function export_user_preferences(int $userid) {
        $simple = [
            'quiz_aigrader_report_startdate' => 'privacy:preference:reportstartdate',
            'quiz_aigrader_report_enddate' => 'privacy:preference:reportenddate',
            'quiz_aigrader_report_graderid' => 'privacy:preference:reportgraderid',
        ];

        foreach ($simple as $name => $stringid) {
            $value = get_user_preferences($name, null, $userid);
            if ($value !== null) {
                writer::export_user_preference(
                    'quiz_aigrader',
                    $name,
                    $value,
                    get_string($stringid, 'quiz_aigrader')
                );
            }
        }

        foreach (self::get_date_filter_preferences($userid) as $name => $value) {
            writer::export_user_preference(
                'quiz_aigrader',
                $name,
                $value,
                get_string('privacy:preference:datefilter', 'quiz_aigrader')
            );
        }
    }

    /**
     * Every per-course-module grading date filter preference held for a user.
     *
     * @param int $userid The user to look up.
     * @return array Preference name to stored value.
     */
    protected static function get_date_filter_preferences(int $userid): array {
        global $DB;

        $like = $DB->sql_like('name', ':pattern');
        $records = $DB->get_records_select(
            'user_preferences',
            "userid = :userid AND {$like}",
            [
                'userid' => $userid,
                'pattern' => $DB->sql_like_escape('quiz_aigrader_date') . '%',
            ],
            '',
            'id, name, value'
        );

        $found = [];
        foreach ($records as $record) {
            $found[$record->name] = $record->value;
        }

        return $found;
    }

    /**
     * Whether the given user owns any scheduled report configuration.
     *
     * @param int $userid The user to check.
     * @return bool True if the user has at least one schedule.
     */
    protected static function has_schedules(int $userid): bool {
        global $DB;
        return $DB->record_exists('quiz_aigrader_schedules', ['userid' => $userid]);
    }
}
