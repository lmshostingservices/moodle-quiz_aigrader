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
 * Version information for AI Essay Grader quiz report plugin.
 *
 * v3.9.9 - RELEASE HOUSEKEEPING ONLY. Identical grade-fix code to v3.9.8.
 *   v3.9.8 was promoted to the release pipeline before the compliance fixes
 *   (GPL headers on db/fix_13digit_version.php and tests/upgrade_simulation.php,
 *   @package/@copyright/@license on tests/upgrade_simulation.php, AMD function
 *   spacing, removal of the unreferenced lang/en/quizreport_aigrader.php, and
 *   the aigrader/ ZIP root folder). This release carries those fixes and
 *   supersedes 3.9.8, which should not be deployed. Version numeric kept to
 *   Moodle's 10-digit YYYYMMDDXX format: 2026081701.
 *
 * v3.9.8 - CRITICAL FIX: AI-approved grades never reached the gradebook.
 *   Symptom: after "Approve & Save to Gradebook", the quiz attempt showed the
 *   correct mark (e.g. 28.00/28.00) but the course gradebook showed "-" for that
 *   student, permanently, on every quiz using AI grading, across all courses.
 *   Manually marking the same question through Moodle's own Manual grading screen
 *   worked correctly, as did a quiz regrade, which is what masked the defect.
 *
 *   Root cause: ajax.php called
 *       \mod_quiz\grade_calculator::create($quizobj)->recompute_final_grade();
 *   with no argument. The method signature is
 *       recompute_final_grade(?int $userid = null, array $attempts = [])
 *   and its first action is:
 *       if (empty($userid)) { $userid = $USER->id; }
 *   It does NOT inherit the userid from the quiz_settings object passed to
 *   create(), despite that object being constructed with the student's id.
 *   Every approve therefore recomputed the grade of the logged-in TEACHER, who
 *   has no attempts — yielding a null best grade and a DELETE against the
 *   teacher's quiz_grades row — while the student's quiz_grades and grade_grades
 *   rows were never written at all. The attempt's sumgrades was updated correctly
 *   a few lines earlier, which is why the quiz UI looked right and only the
 *   gradebook was wrong.
 *
 *   Regression origin: the legacy quiz_save_best_grade($quiz, $attempt->userid)
 *   fallback always passed the userid. The defect was introduced in v3.8.3 when
 *   the Moodle 4.2+ grade_calculator branch was added and the userid argument was
 *   not carried across. Sites on Moodle < 4.2 were unaffected.
 *
 *   Fix: pass $attempt->userid explicitly to recompute_final_grade().
 *
 *   Also added: gradebook write verification. After the push, the grade is read
 *   back from grade_grades and the result returned in the approve JSON response
 *   as gradebookverified / gradebookwarning. A failed gradebook write can no
 *   longer present to the teacher as a success. Non-fatal by design — the mark
 *   and feedback are already committed, so verification failure warns rather
 *   than aborts. No DB schema changes. version.php → 2026081701.
 *
 * v3.8.4 - BUG FIX: 'Approve & Save to Gradebook' intermittent failure.
 *   Root cause 1 (primary): feedbackRaw was injected into <textarea> via jQuery .html(),
 *   which passes the string through the browser HTML parser — AI feedback containing &, <,
 *   >, or the literal substring </textarea> caused .val() to return corrupt or empty text
 *   when the teacher clicked Approve. Fix: after .html(), set the textarea value explicitly
 *   via .val(feedbackRaw) in both gradeOne and gradeOneAsync, bypassing HTML parsing.
 *   Root cause 2 (secondary): no DB transaction around sumgrades update — wrapped
 *   $DB->update_record(quiz_attempts) in a delegated transaction for atomicity.
 *   Root cause 3 (minor): approve .fail() handler swallowed the server error message —
 *   now surfaces xhr.responseJSON.message when available.
 *   AMD rebuilt. PHP (ajax.php) changed. No DB schema changes. version.php → 2026041700384.
 *
 * v3.8.3 - BUG FIX: Approve & Save to Gradebook button now works on all Moodle versions.
 *   Root cause 1: quiz_save_best_grade() was deprecated in Moodle 4.2 and removed in
 *   newer builds. Without a function_exists() guard, calling it threw a fatal PHP Error
 *   which was NOT caught by the inner catch (Exception $e) block, bubbling up to the
 *   outer handler and returning ok:false — silently aborting every Approve & Save.
 *   Root cause 2: The inner catch (Exception $e) at the end of the approve action did
 *   not catch PHP Error objects (e.g. type errors, removed methods). Changed to
 *   catch (\Throwable $e) so any PHP error in the approve flow is caught and reported
 *   as a descriptive JSON error message instead of triggering a silent failure.
 *   Fix: Added function_exists('quiz_save_best_grade') guard with quiz_update_grades()
 *   fallback, and widened approve catch to \Throwable.
 *
 * v3.8.0 - CRITICAL FIX: Approve & Save button now works in all quizzes.
 *   Root cause: the "all questions graded?" notification check called
 *   $state->requires_grading() on every slot in the attempt, including
 *   auto-graded question types (MCQ, true/false, matching, etc.) whose state
 *   class is question_state_finished. That class does not define requires_grading(),
 *   so PHP threw "Call to undefined method question_state_finished::requires_grading()"
 *   which was caught by the outer try/catch and returned ok:false — silently aborting
 *   every single Approve & Save in any quiz that mixed essay questions with other types.
 *   Fixed with method_exists() guard and isset($usage_reloaded) safety check.
 *
 * v3.7.9 - FIX: Grading-complete notification email now fires only once all essay
 *   questions in a student's quiz attempt have been approved by the teacher.
 *
 * v3.7.8 - REPORT FIX: Grader report stat cards (Total Approved, Active Graders,
 *   Avg Per Grader, Avg Time/Question) now correctly filter when a specific grader
 *   is selected. Previously get_grader_totals() ignored the graderid parameter,
 *   so stat cards always showed site-wide totals regardless of filter selection.
 *   Also fixed Avg Time/Question using mismatched data sources (filtered time ÷
 *   unfiltered approved count). Downloads (CSV/Excel) also now respect grader filter.
 *
 * v3.7.7 - SETTINGS FIX: Bulletproof settings registration — always self-registers
 *   admin_settingpage and adds to tree with robust fallback. Nulls $settings to prevent
 *   Moodle's load_settings() from double-adding to a potentially invalid parent node.
 *   Fixes "sectionerror" on /admin/settings.php?section=quiz_aigrader across all Moodle 4.x/5.x.
 *
 * v3.6.7 - PERFORMANCE: Eliminated N+1 question_engine::load_questions_usage_by_activity() calls in render_essay_table(). Answer text, question text, and maxmark are now fetched in two bulk SQL queries (one for metadata, one for answer step data) instead of one heavy multi-query Moodle API call per student attempt. Also: correlated subquery on question_attempt_steps replaced with LEFT JOIN anti-pattern; user loading replaced with single get_records_list() bulk query. On a 100-student quiz these changes reduce page load from ~30s to under 2s.
 * v3.6.6 - INTEGRATION: Essay Guard risk badges now appear on every student card in the grader. Low/Mild/Medium/High colour-coded pill badges link to the Essay Guard student detail page. Medium and High risk students also get a contextual advisory panel with guidance for the assessor (override the AI grade, ask student to rephrase). Graceful degradation: badge code is silently skipped when Essay Guard is not installed.
 * v3.6.5 - PREMIUM: Feedback cards now use SVG icons (checkmark for success, alert circle for warnings, info circle for tips) instead of emoji/text bullets. Gradient card backgrounds, CSS class system with inline fallbacks for review page.
 * v3.6.4 - FIX: Strip colored circle emoji (🟢🟠🔵🟣🟡🔴) from AI bullet points - these were passing through the STRIP regex
 * v3.6.3 - FIX: Legacy green/orange filled-circle bullets replaced with neutral dark gray text bullets for premium SaaS styling
 * v3.6.2 - SESSION LOCK FIX: Added \core\session\manager::write_close() after auth checks to prevent blocking concurrent requests during AI grading.
 * v3.6.1 - FIX: Duplicate orange feedback boxes when student scores full marks
 * - Long content lines containing "areas for improvement" mid-sentence were
 *   incorrectly parsed as section headers, creating a second empty orange box
 * - Added length guard (<=80 chars) so only short header lines create new sections
 * 
 * v3.58.0 - FIX: Binary scoring rules for industry-specific vocabulary recognition
 * - AI now uses strict MET/NOT MET binary scoring (no partial credit)
 * - Workplace vocabulary (haul roads, processing plant, scaffolding, triage) triggers automatic mark award
 * - Forbidden behaviour: AI cannot say "could be stronger" and still deduct marks
 * - Self-check: AI must quote missing evidence before deducting marks
 * - Temperature set to 0 for maximum consistency (90%+ success rate)
 *
 * @package    quiz_aigrader
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'quiz_aigrader';
$plugin->version   = 2026081701;
$plugin->requires  = 2022041900;
$plugin->supported  = [400, 500];  // Moodle 4.0 to 5.x
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '3.9.9'; // CRITICAL FIX: recompute_final_grade() was called without $userid, so AI-approved grades were computed against the logged-in teacher instead of the student and never reached the gradebook. See header for full analysis. No DB schema changes. version.php -> 2026081701.
