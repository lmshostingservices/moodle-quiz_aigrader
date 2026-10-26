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
 * Database upgrade steps for AI Grader quiz report.
 *
 * @package    quiz_aigrader
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the AI Grader quiz report plugin.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool
 */
function xmldb_quiz_aigrader_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Legacy format: savepoint 2025121200 is 10-digit (it pre-dates the 13-digit YYYYMMDD00XXX
    // standard). This is functionally safe because all 2026 savepoints are numerically greater.
    // Do not alter this value; changing it would break upgrade paths on Dec-2025 installations.
    if ($oldversion < 2025121200) {
        // Define table quiz_aigrader_schedules to store email report schedules.
        $table = new xmldb_table('quiz_aigrader_schedules');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('frequency', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'weekly');
        $table->add_field('recipients', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('lastrun', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('nextrun', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('format', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'excel');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('enabled_nextrun', XMLDB_INDEX_NOTUNIQUE, ['enabled', 'nextrun']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025121200, 'quiz', 'aigrader');
    }

    // Legacy format: savepoint 2025121540 is 10-digit (it pre-dates the 13-digit YYYYMMDD00XXX
    // standard). This is functionally safe because it is lower than all 2026 savepoints. Do not alter.
    if ($oldversion < 2025121540) {
        // Define table quiz_aigrader_grading_logs for grading time tracking.
        $table = new xmldb_table('quiz_aigrader_grading_logs');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('graderid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('qubaid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('slot', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timegraded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('quizid', XMLDB_KEY_FOREIGN, ['quizid'], 'quiz', ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('graderid', XMLDB_KEY_FOREIGN, ['graderid'], 'user', ['id']);

        $table->add_index('graderid_timegraded', XMLDB_INDEX_NOTUNIQUE, ['graderid', 'timegraded']);
        $table->add_index('courseid_timegraded', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'timegraded']);
        $table->add_index('quizid_qubaid_slot', XMLDB_INDEX_UNIQUE, ['quizid', 'qubaid', 'slot']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025121540, 'quiz', 'aigrader');
    }

    // Legacy format: savepoint 2025122801 is 10-digit (it pre-dates the 13-digit YYYYMMDD00XXX
    // standard). This is functionally safe because it is lower than all 2026 savepoints. Do not alter.
    if ($oldversion < 2025122801) {
        // Add attempt context table for consistent grading across attempts.
        $table = new xmldb_table('quiz_aigrader_attempt_ctx');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('slot', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('attemptnum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('criteriamet', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('feedbacksummary', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('lastgrade', XMLDB_TYPE_NUMBER, '10,2', null, null, null, null);
        $table->add_field('humanreview', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('autolocked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('quizid', XMLDB_KEY_FOREIGN, ['quizid'], 'quiz', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('quiz_user_question_slot', XMLDB_INDEX_UNIQUE, ['quizid', 'userid', 'questionid', 'slot']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025122801, 'quiz', 'aigrader');
    }

    // Versions 3.7.3 to 3.7.8: settings page registration and report fixes. No DB changes.
    if ($oldversion < 2026032200) {
        upgrade_plugin_savepoint(true, 2026032200, 'quiz', 'aigrader');
    }

    // Version 3.8.0: Approve and save now works in mixed-question quizzes.
    // A method_exists() guard prevents a fatal on question_state_finished::requires_grading().
    if ($oldversion < 2026032500) {
        upgrade_plugin_savepoint(true, 2026032500, 'quiz', 'aigrader');
    }

    // Versions 3.8.1 to 3.8.4: student notification, grade saving and AMD fixes.
    // The all-graded check now counts rows in quiz_aigrader_grading_logs instead of relying on
    // question state, so the student notification is only sent once the whole quiz is approved.
    // No DB schema changes.
    if ($oldversion < 2026041700) {
        upgrade_plugin_savepoint(true, 2026041700, 'quiz', 'aigrader');
    }

    // Versions 3.8.5 and 3.8.6: AMD build resync and removal of non-ASCII characters from the
    // AMD JavaScript files. No DB schema changes.
    if ($oldversion < 2026042200) {
        upgrade_plugin_savepoint(true, 2026042200, 'quiz', 'aigrader');
    }

    // Version 3.8.7: fixed an invalid regular expression in the AMD module that aborted the
    // RequireJS module chain. No DB schema changes.
    if ($oldversion < 2026042300) {
        upgrade_plugin_savepoint(true, 2026042300, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026050500) {
        // Version 3.8.9: the deprecation guard for quiz_save_best_grade() now uses method_exists()
        // on \mod_quiz\grade_calculator::recompute_final_grade so the new API is used on Moodle
        // 4.2 and later and the legacy function only on older releases. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050500, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026070300) {
        // Version 3.9.1: added a group selector to the report page so submissions can be filtered
        // by course group. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026070300, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026072300) {
        // Updated the API endpoint URLs used by the plugin. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026072300, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026080300) {
        // Version 3.9.7: converted all savepoints to the 10-digit format so upgrading clients
        // no longer hit a savepoint validation failure. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026080300, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026102500) {
        // Version 4.4.0: the assessment decision record.
        //
        // Until now the only evidence an assessment decision had been made was one row in
        // quiz_aigrader_grading_logs saying who approved and when, and that row was
        // overwritten on re-approval. Nothing recorded what the AI suggested, whether the
        // assessor changed it, or what rules were in force. This table is append-only: one
        // row per approval, never updated, so the history of a mark can be reconstructed.
        $table = new xmldb_table('quiz_aigrader_decisions');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('graderid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('qubaid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('slot', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('attemptnum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('maxmark', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('aimark', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
        $table->add_field('finalmark', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('markchanged', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('feedbackchanged', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('aifeedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('finalfeedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('criteriamet', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('policymode', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('graderinfohash', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('instructionshash', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('modelversion', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('requestid', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('reviewseconds', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('humanreview', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('autolocked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('acknowledgement', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('quizid', XMLDB_KEY_FOREIGN, ['quizid'], 'quiz', ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('graderid', XMLDB_KEY_FOREIGN, ['graderid'], 'user', ['id']);

        $table->add_index('qubaid_slot', XMLDB_INDEX_NOTUNIQUE, ['qubaid', 'slot']);
        $table->add_index('graderid_timecreated', XMLDB_INDEX_NOTUNIQUE, ['graderid', 'timecreated']);
        $table->add_index('quizid_timecreated', XMLDB_INDEX_NOTUNIQUE, ['quizid', 'timecreated']);
        $table->add_index('userid_quizid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'quizid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Separate the AI's opinion from the assessor's decision on the attempt context.
        //
        // lastgrade and criteriamet were written when the suggestion was generated, before
        // any human had seen it, and were never corrected when the assessor overrode the
        // mark. They now hold the approved decision, and the AI's suggestion is kept
        // alongside in its own columns so the two can never be confused again.
        $ctx = new xmldb_table('quiz_aigrader_attempt_ctx');

        $field = new xmldb_field('aigrade', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'lastgrade');
        if (!$dbman->field_exists($ctx, $field)) {
            $dbman->add_field($ctx, $field);
        }

        $field = new xmldb_field('aicriteriamet', XMLDB_TYPE_TEXT, null, null, null, null, null, 'aigrade');
        if (!$dbman->field_exists($ctx, $field)) {
            $dbman->add_field($ctx, $field);
        }

        $field = new xmldb_field('aifeedback', XMLDB_TYPE_TEXT, null, null, null, null, null, 'aicriteriamet');
        if (!$dbman->field_exists($ctx, $field)) {
            $dbman->add_field($ctx, $field);
        }

        $field = new xmldb_field('suggestedtime', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'aifeedback');
        if (!$dbman->field_exists($ctx, $field)) {
            $dbman->add_field($ctx, $field);
        }

        upgrade_plugin_savepoint(true, 2026102500, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026102501) {
        // Version 4.4.1: the marking queue became a table, the criteria shown are now the
        // assessor's own from the question's grader information rather than a fixed vendor
        // list, and three settings were added. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026102501, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026102502) {
        // Version 4.5.0: feedback is organised by the question's own grading criteria
        // instead of three generic headings, and the assessor scores each criterion with
        // the mark following as their sum. No DB schema changes - the decision record's
        // criteriamet column now holds the scored criteria as JSON rather than a list of
        // met identifiers, which is a superset of what it held before.
        upgrade_plugin_savepoint(true, 2026102502, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026102503) {
        // Version 5.0.0: a security and correctness release. The grading statistics panel
        // built its rows by concatenating course and grader names into HTML, which made one
        // assessor's own name executable in another assessor's browser; it is now built
        // through the DOM. Diagnostic logging, which carried learner responses and the
        // feedback written about them, no longer reaches the browser console unless the site
        // is in developer debug mode. The accessible label on the expand control moved from
        // Bootstrap's sr-only, absent from the Moodle 5 themes, to Moodle's accesshide. No
        // DB schema changes.
        upgrade_plugin_savepoint(true, 2026102503, 'quiz', 'aigrader');
    }

    return true;
}
