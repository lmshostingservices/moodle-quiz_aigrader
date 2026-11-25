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

namespace quiz_aigrader\local;

/**
 * Removes the plugin's records when what they describe is deleted.
 *
 * Every record here is reached through a quiz, an attempt or a user. Once that is gone the
 * Privacy API can no longer find the record, so it would sit in the database for good. The
 * observers handle the events Moodle fires; the daily task catches what it does not (a quiz
 * removed with its course, attempts removed by a course reset).
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup {
    /** @var string[] Tables keyed by quiz. */
    const QUIZ_TABLES = ['quiz_aigrader_attempt_ctx', 'quiz_aigrader_decisions', 'quiz_aigrader_grading_logs',
        'quiz_aigrader_criteria'];

    /**
     * A quiz was deleted: remove everything recorded for it.
     *
     * @param \core\event\course_module_deleted $event
     * @return void
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        if (($event->other['modulename'] ?? '') !== 'quiz') {
            return;
        }
        self::delete_quiz((int)$event->other['instanceid']);
    }

    /**
     * A quiz attempt was deleted: remove the decisions and logs for it, and its suggestions.
     *
     * @param \mod_quiz\event\attempt_deleted $event
     * @return void
     */
    public static function attempt_deleted(\mod_quiz\event\attempt_deleted $event): void {
        global $DB;
        $attempt = $event->get_record_snapshot('quiz_attempts', $event->objectid);
        if (!$attempt) {
            return;
        }
        $DB->delete_records('quiz_aigrader_decisions', ['qubaid' => $attempt->uniqueid]);
        $DB->delete_records('quiz_aigrader_grading_logs', ['qubaid' => $attempt->uniqueid]);
        $DB->delete_records('quiz_aigrader_attempt_ctx', [
            'quizid' => $attempt->quiz,
            'userid' => $attempt->userid,
            'attemptnum' => $attempt->attempt,
        ]);
    }

    /**
     * A user was deleted.
     *
     * Their own records go. Where they graded someone else, the learner's decision record
     * stays as the learner's evidence, with the grader's identity removed.
     *
     * @param \core\event\user_deleted $event
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        self::delete_user((int)$event->objectid);
    }

    /**
     * Remove everything recorded for one quiz.
     *
     * @param int $quizid
     * @return void
     */
    public static function delete_quiz(int $quizid): void {
        global $DB;
        foreach (self::QUIZ_TABLES as $table) {
            $DB->delete_records($table, ['quizid' => $quizid]);
        }
    }

    /**
     * Remove a user's records, and their name from records about other people.
     *
     * @param int $userid
     * @return void
     */
    public static function delete_user(int $userid): void {
        global $DB;
        $DB->delete_records('quiz_aigrader_attempt_ctx', ['userid' => $userid]);
        $DB->delete_records('quiz_aigrader_decisions', ['userid' => $userid]);
        $DB->delete_records('quiz_aigrader_grading_logs', ['graderid' => $userid]);
        $DB->delete_records('quiz_aigrader_schedules', ['userid' => $userid]);
        $DB->set_field('quiz_aigrader_decisions', 'acknowledgement', null, ['graderid' => $userid]);
        $DB->set_field('quiz_aigrader_decisions', 'graderid', 0, ['graderid' => $userid]);
        $DB->set_field('quiz_aigrader_criteria', 'confirmedby', 0, ['confirmedby' => $userid]);
    }

    /**
     * Remove records whose quiz, attempt or user no longer exists.
     *
     * @return int How many records were removed.
     */
    public static function remove_orphans(): int {
        global $DB;
        $removed = 0;
        foreach (self::QUIZ_TABLES as $table) {
            $removed += self::delete_where($table, "quizid NOT IN (SELECT id FROM {quiz})");
        }
        foreach (['quiz_aigrader_decisions', 'quiz_aigrader_grading_logs'] as $table) {
            $removed += self::delete_where($table, "qubaid NOT IN (SELECT id FROM {question_usages})");
        }
        $removed += self::delete_where(
            'quiz_aigrader_attempt_ctx',
            "NOT EXISTS (SELECT 1 FROM {quiz_attempts} qa
                          WHERE qa.quiz = {quiz_aigrader_attempt_ctx}.quizid
                            AND qa.userid = {quiz_aigrader_attempt_ctx}.userid)"
        );
        $byuser = [
            'quiz_aigrader_decisions' => 'userid',
            'quiz_aigrader_schedules' => 'userid',
            'quiz_aigrader_grading_logs' => 'graderid',
        ];
        foreach ($byuser as $table => $field) {
            $removed += self::delete_where($table, "$field NOT IN (SELECT id FROM {user} WHERE deleted = 0)");
        }
        return $removed;
    }

    /**
     * Delete the rows of a table that match a condition, and say how many there were.
     *
     * @param string $table
     * @param string $where SQL condition with no parameters.
     * @return int
     */
    private static function delete_where(string $table, string $where): int {
        global $DB;
        $count = $DB->count_records_select($table, $where);
        if ($count) {
            $DB->delete_records_select($table, $where);
        }
        return $count;
    }
}
