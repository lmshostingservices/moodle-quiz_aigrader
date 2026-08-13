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

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the AI Grader quiz report plugin.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool
 */
function xmldb_quiz_aigrader_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // ⚠ LEGACY FORMAT: Savepoint 2025121200 is 10-digit (pre-dates the 13-digit YYYYMMDD00XXX
    //   standard). Functionally safe — all 2026 savepoints are numerically greater. Do NOT
    //   alter this value; changing it would break upgrade paths on installed Dec-2025 sites.
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

    // ⚠ LEGACY FORMAT: Savepoint 2025121540 is 10-digit (pre-dates the 13-digit YYYYMMDD00XXX
    //   standard). Functionally safe — numerically less than all 2026 savepoints. Do NOT alter.
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

    // ⚠ LEGACY FORMAT: Savepoint 2025122801 is 10-digit (pre-dates the 13-digit YYYYMMDD00XXX
    //   standard). Functionally safe — numerically less than all 2026 savepoints. Do NOT alter.
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

    // v3.7.3: VERSION BUMP — Maintenance release. No DB changes.
    if ($oldversion < 2026032200) {
        upgrade_plugin_savepoint(true, 2026032200, 'quiz', 'aigrader');
    }

    // v3.7.4: SETTINGS FIX — settings.php self-registers admin_settingpage. No DB changes.
    if ($oldversion < 2026032200) {
        upgrade_plugin_savepoint(true, 2026032200, 'quiz', 'aigrader');
    }

    // v3.7.5: SETTINGS FIX — Robust parent category detection for settings page. No DB changes.
    if ($oldversion < 2026032200) {
        upgrade_plugin_savepoint(true, 2026032200, 'quiz', 'aigrader');
    }

    // v3.7.6: SETTINGS FIX — Section ID must be 'quiz_aigrader' to match get_settings_section_name(). No DB changes.
    if ($oldversion < 2026032200) {
        upgrade_plugin_savepoint(true, 2026032200, 'quiz', 'aigrader');
    }

    // v3.7.7: SETTINGS FIX — Bulletproof self-registration, null $settings to prevent double-add. No DB changes.
    if ($oldversion < 2026032200) {
        upgrade_plugin_savepoint(true, 2026032200, 'quiz', 'aigrader');
    }

    // v3.7.8: REPORT FIX — get_grader_totals() now accepts $graderid filter. Stat cards update when grader selected.
    if ($oldversion < 2026032200) {
        upgrade_plugin_savepoint(true, 2026032200, 'quiz', 'aigrader');
    }

    // v3.8.0: CRITICAL FIX — Approve & Save now works in mixed-question quizzes.
    // method_exists() guard prevents fatal on question_state_finished::requires_grading().
    if ($oldversion < 2026032500) {
        upgrade_plugin_savepoint(true, 2026032500, 'quiz', 'aigrader');
    }

    // v3.8.1 - BUG FIX: Student notification email was sent after every single Approve click
    //   instead of only once when the entire quiz was fully teacher-approved.
    //   Root cause: The all-graded check used question STATE (requires_grading()) — but the
    //   AI pre-grades all essay questions in batch via "AI Suggest", moving every question
    //   from needsgrading → graded state before the teacher has reviewed any of them. So on
    //   the teacher's first Approve click, the state check found all other questions already
    //   in 'graded' state → all_questions_graded = true → notification fired immediately.
    //   Fix: Replaced state check with quiz_aigrader_grading_logs count. grading_logs gets
    //   one row per (qubaid, slot) only when the teacher clicks Approve & Save (upsert, no
    //   duplicates). We count logged slots vs total is_manual_graded() slots in the usage.
    //   Notification fires only when every manually-gradeable slot has a log entry.
    //   No DB schema changes. PHP only (ajax.php). version.php → 2026041700.
    if ($oldversion < 2026041700) {
        upgrade_plugin_savepoint(true, 2026041700, 'quiz', 'aigrader');
    }

    // v3.8.2 - BUMP: Consolidation release. Server-side fix: maxMark Zod schema in
    //   server/routes.ts updated to accept 0 (min(0)) for ungraded questions sent by
    //   Moodle (previously min(1) caused /api/grade-essay to reject requests with
    //   "Number must be greater than 0" when maxMark was 0). No DB schema changes.
    //   No PHP or AMD changes. version.php → 2026041700.
    if ($oldversion < 2026041700) {
        upgrade_plugin_savepoint(true, 2026041700, 'quiz', 'aigrader');
    }

    // v3.8.3 - BUG FIX: Approve & Save to Gradebook button now works on all Moodle versions.
    //   quiz_save_best_grade() deprecated in Moodle 4.2 and removed in newer builds —
    //   added function_exists() guard with quiz_update_grades() fallback. Also widened
    //   inner approve catch to \Throwable so PHP Errors are caught and returned as
    //   descriptive ok:false messages instead of bubbling silently. No DB schema changes.
    //   PHP only (ajax.php). version.php → 2026041700.
    if ($oldversion < 2026041700) {
        upgrade_plugin_savepoint(true, 2026041700, 'quiz', 'aigrader');
    }

    // v3.8.4 - BUG FIX: 'Approve & Save to Gradebook' intermittent failure.
    //   Root cause 1 (primary): feedbackRaw injected into <textarea> via jQuery .html()
    //   caused HTML entity parsing — AI feedback with &, <, > or </textarea> chars caused
    //   .val() to return corrupt/empty text at approve time. Fix: .val(feedbackRaw) set
    //   explicitly after .html() in gradeOne and gradeOneAsync.
    //   Root cause 2: no transaction around sumgrades update — wrapped update_record in
    //   delegated transaction for atomicity.
    //   Root cause 3: approve .fail() now surfaces xhr.responseJSON.message.
    //   AMD rebuilt. PHP (ajax.php) changed. No DB schema changes. version.php → 2026041700.
    if ($oldversion < 2026041700) {
        upgrade_plugin_savepoint(true, 2026041700, 'quiz', 'aigrader');
    }

    // v3.8.5 - MAINTENANCE: AMD build sync — aigrader.min.js was a 1-line stale
    //   placeholder; src/aigrader.js is 1488 lines. Resynced build/aigrader.min.js
    //   to match src and build/aigrader.js (now triple-match). No PHP/DB/logic
    //   changes. version.php → 2026042200.
    if ($oldversion < 2026042200) {
        upgrade_plugin_savepoint(true, 2026042200, 'quiz', 'aigrader');
    }
    // v3.8.6: AMD ENCODING FIX: All non-ASCII characters (em dashes, arrows, box-drawing chars, ellipsis, bullets, emoji, accented Latin) scrubbed from all AMD JS files (amd/src, amd/build, amd/build/*.min.js). Root cause of Moodle primary/secondary navigation menus disappearing site-wide: non-ASCII bytes in any installed plugin's AMD file cause a SyntaxError inside RequireJS's first.js bundle, throwing "No define call for core/first" and aborting the entire AMD module chain. No PHP, DB schema, or functional changes in this release.
    if ($oldversion < 2026042200) {
        upgrade_plugin_savepoint(true, 2026042200, 'quiz', 'aigrader');
    }

    // v3.8.7: AMD REGEX FIX: Invalid regular expression in aigrader.js/aigrader.min.js
    //   caused "Uncaught SyntaxError: Invalid regular expression: Range out of order in
    //   character class" on first.js load, throwing "No define call for core/first" and
    //   aborting the entire AMD chain (credits not shown, nav hidden).
    //   Root cause: /[chart-down]|.../ — the hyphen in [chart-down] was parsed as a
    //   character range t-d (t=116 > d=100) which is invalid. Same class of bug in
    //   [thumbs-up] (s-u, valid but wrong) and [[tip]...] patterns.
    //   Fix: escaped brackets to \[chart-down\], \[thumbs-up\], \[tip\] so they match
    //   the literal bracket notation. Strip regexes updated to /^(\[icon\]|\s)+/ pattern.
    //   No PHP, DB schema, or functional changes.
    if ($oldversion < 2026042300) {
        upgrade_plugin_savepoint(true, 2026042300, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026050500) {
        // v3.8.9: FIX-QUIZ-SAVE-GRADE-DEPRECATION (MDL-76897).
        // ajax.php was guarding with function_exists('quiz_save_best_grade') — this returns
        // TRUE on Moodle 4.2+ (the function still exists but is deprecated), causing the
        // deprecation error on Approve & Save. Fixed: guard now uses method_exists on
        // \mod_quiz\grade_calculator::recompute_final_grade — TRUE on 4.2+, so new API
        // is used; FALSE on older Moodle, so legacy quiz_save_best_grade() is called.
        // Works on all Moodle versions. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026050500, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026070300) {
        // v3.9.1: GROUP FILTER — Added group selector dropdown to the AI Essay Grader
        // report page. Trainers can pick any course group from a dropdown above the essay
        // cards; only submissions from students in that group are loaded via a JOIN on
        // {groups_members}. No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['report.php', 'version.php', 'lang/en/quiz_aigrader.php', 'styles.css', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026070300, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026072300) {
        // FIX-API-DOMAIN: Updated all API endpoint URLs from lms-labs.com to lms-labs.com.
        // lms-labs.com has no DNS resolution from Moodle server side; lms-labs.com is the
        // correct working domain. All ajax.php, api_client, unlock_verifier, lib.php calls updated.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026072300, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026072300) {
        // FIX-API-DOMAIN: Reverted API endpoint to lms-labs.com (correct domain).
        // essaygraderai.app was the original single-plugin domain; lms-labs.com is correct.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072300, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026072300) {
        // Domain update: lms-labs.com → lms-labs.com
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_plugin_savepoint(true, 2026072300, 'quiz', 'aigrader');
    }

    if ($oldversion < 2026080300) {
        // v3.9.7: UPGRADE FIX — Converted all savepoints to 10-digit format so upgrading
        // clients no longer hit a savepoint validation crash. No DB schema changes.
        upgrade_plugin_savepoint(true, 2026080300, 'quiz', 'aigrader');
    }

    // ── 13-digit → 10-digit DOWNGRADE BLOCK (DB RECOVERY REQUIRED) ──────────
    // Sites that ran the 13-digit promoted build (2026080300210) cannot upgrade
    // directly via Moodle's plugin installer.  Moodle's core upgrade dispatcher
    // compares $plugin->version (2026080300) against the stored version in
    // mdl_config_plugins.  Because 2026080300 < 2026080300210 numerically, core
    // interprets the install as a downgrade and aborts BEFORE calling this
    // function.  The upgrade function is never reached.
    //
    // Recovery procedure (run on the Moodle server before installing the ZIP):
    //   1.  Reset the stored version to the previous safe 10-digit checkpoint:
    //         UPDATE mdl_config_plugins
    //         SET    value = '2026072300'
    //         WHERE  plugin = 'quiz_aigrader' AND name = 'version';
    //   2.  Install the new quiz_aigrader ZIP through Site admin → Plugins.
    //   3.  Run the Moodle upgrade:
    //         php admin/cli/upgrade.php --non-interactive
    //       Moodle now sees 2026072300 < 2026080300 (upgrade, not downgrade),
    //       calls xmldb_quiz_aigrader_upgrade(2026072300), which executes the
    //       final savepoint block and returns true cleanly.
    //   4.  Verify plugin version shows 2026080300 in Site admin → Plugins.
    //
    // See also: db/fix_13digit_version.php (CLI helper that runs step 1 above).
    // ─────────────────────────────────────────────────────────────────────────

    return true;
}