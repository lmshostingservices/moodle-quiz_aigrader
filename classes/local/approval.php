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

use context_course;
use context_module;
use core_text;
use moodle_url;
use question_engine;
use stdClass;

/**
 * Approve an assessor's mark: write it to the attempt, the gradebook and the decision record.
 *
 * Moved out of ajax.php in 5.2.0 so the web service quiz_aigrader_approve_mark and the legacy
 * endpoint share one implementation, and so the whole approval can be tested end to end.
 * The caller has already checked the session, the capability and the attempt (see access).
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class approval {
    /**
     * Approve one mark.
     *
     * @param stdClass $cm The quiz course module.
     * @param context_module $context The quiz context.
     * @param stdClass|null $attemptrec The quiz_attempts row the usage belongs to.
     * @param int $qubaid The question usage id.
     * @param int $slot The slot being marked.
     * @param string $marksupplied The absolute mark chosen, as text; '' when not supplied.
     * @param float|null $legacygrade100 A percentage sent by an older cached AMD build.
     * @param string $feedbacktext The feedback HTML delivered to the learner.
     * @param string $acknowledgement The assessor's reason, when the attempt needs one.
     * @param string $policymode The full-marks feedback policy the browser applied.
     * @param string $criteriajson The assessor's per-criterion scores, as JSON.
     * @return array The response: ok, and either the saved mark or a message.
     */
    public static function approve(
        stdClass $cm,
        context_module $context,
        ?stdClass $attemptrec,
        int $qubaid,
        int $slot,
        string $marksupplied,
        ?float $legacygrade100,
        string $feedbacktext,
        string $acknowledgement,
        string $policymode,
        string $criteriajson
    ): array {
        global $CFG, $DB, $USER, $SITE;
        require_once($CFG->libdir . '/questionlib.php');

        try {
            $usage = question_engine::load_questions_usage_by_activity($qubaid);
            if (!in_array((int)$slot, array_map('intval', $usage->get_slots()), true)) {
                return ['ok' => false, 'message' => get_string('error_invalidslot', 'quiz_aigrader')];
            }
            $qa = $usage->get_question_attempt($slot);
            $problem = access::question_problem($qa);
            if ($problem !== null) {
                return ['ok' => false, 'message' => get_string(access::message_id($problem), 'quiz_aigrader'),
                    'error_code' => $problem];
            }

            $maxmark = round((float)$qa->get_max_mark(), 2);

            // The criteria in force at the moment of the decision, for the record. Resolved
            // again here rather than taken from the browser, which is not evidence.
            $decisioncriteria = null;
            try {
                $qobj = $qa->get_question();
                $decisioncriteria = \quiz_aigrader\local\criteria::for_fields(
                    isset($qobj->graderinfo) ? (string)$qobj->graderinfo : '',
                    (string)$qobj->questiontext,
                    isset($qobj->generalfeedback) ? (string)$qobj->generalfeedback : '',
                    (float)$maxmark,
                    isset($qobj->defaultmark) ? (float)$qobj->defaultmark : 0.0
                );
                $decisioncriteria = \quiz_aigrader\local\confirmed::apply(
                    $decisioncriteria,
                    \quiz_aigrader\local\confirmed::state(
                        (int)$cm->instance,
                        (int)$qobj->id,
                        \quiz_aigrader\local\confirmed::fields_hash(
                            isset($qobj->graderinfo) ? (string)$qobj->graderinfo : '',
                            (string)$qobj->questiontext,
                            isset($qobj->generalfeedback) ? (string)$qobj->generalfeedback : '',
                            isset($qobj->defaultmark) ? (float)$qobj->defaultmark : 0.0
                        ),
                        (float)$maxmark
                    )
                );
            } catch (\Throwable $criteriaerror) {
                debugging('AI Essay Grader: criteria could not be resolved for the record: '
                    . $criteriaerror->getMessage(), DEBUG_DEVELOPER);
            }

            if ($marksupplied !== '') {
                if (!is_numeric($marksupplied)) {
                    return [
                        'ok' => false,
                        'message' => get_string('error_markinvalid', 'quiz_aigrader'),
                    ];
                }
                $mark = round((float)$marksupplied, 2);
            } else if ($legacygrade100 !== null) {
                $mark = round(($maxmark * $legacygrade100) / 100, 2);
            } else {
                return [
                    'ok' => false,
                    'message' => get_string('error_missingparams', 'quiz_aigrader'),
                ];
            }

            // Reject an out-of-range mark rather than clamping it. A mark outside the
            // question's scale means the request did not come from the grading UI, and
            // quietly storing a clamped value would hide that from the assessor and from
            // the decision record.
            if ($mark < 0 || $mark > $maxmark) {
                return [
                    'ok' => false,
                    'message' => get_string('error_markoutofrange', 'quiz_aigrader', (object)[
                        'mark' => format_float($mark, -1),
                        'maxmark' => format_float($maxmark, -1),
                    ]),
                ];
            }

            // Derived for display and for the decision record only. Nothing is reconstructed
            // from this value.
            $grade100 = $maxmark > 0 ? round(($mark / $maxmark) * 100) : 0;

            // The server's own record of the suggestion being approved. Read here rather
            // than taken from the browser, so the decision record compares the assessor's
            // mark against what the grading service actually returned.
            $approvequiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
            $approveuserid = $attemptrec ? (int)$attemptrec->userid : 0;
            $approvectx = null;
            try {
                $approvectx = $DB->get_record('quiz_aigrader_attempt_ctx', [
                    'quizid' => $approvequiz->id,
                    'userid' => $approveuserid,
                    'questionid' => $qa->get_question_id(),
                    'slot' => $slot,
                ]);
            } catch (\Exception $ctxerror) {
                debugging('AI Essay Grader: attempt_ctx read failed: ' . $ctxerror->getMessage(), DEBUG_DEVELOPER);
            }
            // The record is kept per question, not per attempt. A suggestion made for an earlier
            // attempt is not a suggestion for this one: drop it, so the learner is not told AI
            // drafted this feedback and the decision record does not compare against it.
            $staleattempt = $approvectx && $attemptrec && (int)$approvectx->attemptnum > 0
                && (int)$approvectx->attemptnum !== (int)$attemptrec->attempt;
            if ($staleattempt) {
                $approvectx->aigrade = null;
                $approvectx->aifeedback = '';
                $approvectx->aicriteria = null;
            }

            // Minimum review time, enforced here and not only in the browser.
            //
            // Until 4.3.0 this was a client-side countdown that disabled one button. A POST
            // was accepted the instant the suggestion returned, and after a bulk grade every
            // countdown ran concurrently so all the buttons unlocked together. The setting
            // promised assessors "will be required to wait" and nothing required it.
            $minreviewtime = (int)get_config('quiz_aigrader', 'min_review_time');
            $reviewseconds = null;
            if ($approvectx && !empty($approvectx->suggestedtime)) {
                $reviewseconds = max(0, time() - (int)$approvectx->suggestedtime);
                if ($minreviewtime > 0 && $reviewseconds < $minreviewtime) {
                    return [
                        'ok' => false,
                        'message' => get_string('review_too_soon', 'quiz_aigrader', (object)[
                            'required' => $minreviewtime,
                            'actual' => $reviewseconds,
                        ]),
                        'error_code' => 'REVIEW_TOO_SOON',
                        'retryafter' => $minreviewtime - $reviewseconds,
                    ];
                }
            }

            // Human review: refuse the approval until the assessor acknowledges it and says
            // why. Previously this flag was written as a literal 0, never read, and surfaced
            // only as a sentence in a textarea the assessor could delete.
            $underhumanreview = $approvectx ? (bool)$approvectx->humanreview : false;
            if ($underhumanreview && trim($acknowledgement) === '') {
                return [
                    'ok' => false,
                    'message' => get_string('humanreview_acknowledgement_required', 'quiz_aigrader'),
                    'error_code' => 'ACKNOWLEDGEMENT_REQUIRED',
                    'needsacknowledgement' => true,
                ];
            }

            // Calculate the score label using the actual max mark.
            // number_format(), not format_float(): this string is parsed back with floatval()
            // elsewhere, and format_float() would use the site's decimal separator.
            $scorelabel = \quiz_aigrader\local\formatter::score_label($mark, $maxmark);

            // The learner is told the feedback was drafted with AI and approved by a named
            // person (EU AI Act, Article 26(11)). Added here, not in the browser, so it cannot
            // be left out, and in the learner's own language. Only when the service actually
            // made a suggestion for this response: a mark the grader gave without one is not
            // AI-assisted, and saying it was would mislead the learner.
            // Clean the feedback before it is stored. Moodle shows question comments without
            // cleaning them again (question_behaviour::format_comment() sets noclean), so this is
            // the one place to make sure nothing but safe markup reaches the learner's review
            // page, whatever the browser sent.
            $feedbacktext = clean_text($feedbacktext, FORMAT_HTML);
            $learnerfeedback = $feedbacktext;
            $aisuggested = $approvectx && ($approvectx->aigrade !== null || trim((string)$approvectx->aifeedback) !== '');
            if ($aisuggested) {
                $learnerlang = $attemptrec ? (string)$DB->get_field('user', 'lang', ['id' => $attemptrec->userid]) : '';
                $learnerfeedback = \quiz_aigrader\local\transparency::add_learner_notice($feedbacktext, $USER, $learnerlang);
            }

            // Save the grade and feedback into the question engine.
            $qa->manual_grade($learnerfeedback, $mark, FORMAT_HTML);
            question_engine::save_questions_usage_by_activity($usage);

            // Update the quiz attempt's total grade. The attempt owning this usage has already
            // been validated as belonging to this course module.
            $attempt = $attemptrec;
            if ($attempt) {
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');

                // Recalculate and save the attempt's sumgrades.
                //
                // Two faults are fixed here. The reload and write are serialised on a lock
                // keyed to the attempt: the delegated transaction that used to wrap them took
                // no row lock, so two approvals on different slots of the same attempt each
                // computed a total from their own reload and the later write won, losing one
                // essay's contribution. And only the sumgrades column is written: $attempt was
                // loaded before the question engine wrote, so update_record() on the whole row
                // put stale values back over state, timemodified and timefinish if anything
                // else had touched the attempt in the meantime.
                $sumlock = null;
                try {
                    $lockfactory = \core\lock\lock_config::get_lock_factory('quiz_aigrader_sumgrades');
                    $sumlock = $lockfactory->get_lock((string)$attempt->id, 10);
                } catch (\Throwable $lockerror) {
                    debugging(
                        'AI Essay Grader: could not acquire sumgrades lock: '
                            . $lockerror->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
                try {
                    $usagereloaded = question_engine::load_questions_usage_by_activity($qubaid);
                    $attempttotal = $usagereloaded->get_total_mark();
                    $DB->set_field('quiz_attempts', 'sumgrades', $attempttotal, ['id' => $attempt->id]);
                    $attempt->sumgrades = $attempttotal;
                } finally {
                    if ($sumlock) {
                        $sumlock->release();
                    }
                }

                // Update the user's best grade in the gradebook. The quiz record was already
                // loaded for the approval gates above.
                $quiz = $approvequiz;

                // Notify the rest of Moodle that a question was graded by hand, using the
                // same parameters core uses in mod/quiz/comment.php. Logging is a courtesy,
                // never a reason to fail a save that has already been committed, so any
                // problem here is recorded and swallowed rather than aborting the approval.
                try {
                    \mod_quiz\event\question_manually_graded::create([
                        'objectid' => $qa->get_question_id(),
                        'courseid' => $cm->course,
                        'context' => $context,
                        'other' => [
                            'quizid' => $quiz->id,
                            'attemptid' => $attempt->id,
                            'slot' => $slot,
                        ],
                    ])->trigger();
                } catch (\Throwable $eventerror) {
                    debugging(
                        'AI Essay Grader could not log the manual grading event: '
                            . $eventerror->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }

                // Prefer the Moodle 4.2+ grade_calculator API and fall back to the legacy call
                // for older sites. quiz_save_best_grade() was deprecated in MDL-76897
                // (Moodle 4.2) and triggers a deprecation error even where it still exists.
                //
                // The userid MUST be passed explicitly:
                // grade_calculator::recompute_final_grade(?int $userid = null) does NOT read
                // the userid from the quiz_settings object it was constructed with. Calling it
                // with no argument recomputes the grade of the logged-in teacher (who has no
                // attempts, so the student's quiz_grades and grade_grades rows are never
                // written and the gradebook shows a permanent "-").
                if (method_exists('\mod_quiz\grade_calculator', 'recompute_final_grade')) {
                    $quizobj = \mod_quiz\quiz_settings::create($quiz->id, $attempt->userid);
                    \mod_quiz\grade_calculator::create($quizobj)->recompute_final_grade($attempt->userid);
                } else {
                    quiz_save_best_grade($quiz, $attempt->userid);
                }

                // Gradebook write verification.
                // Read the grade back so a failed push can never present to the teacher as a
                // success. Non-fatal: the question mark and feedback are already committed, so
                // a verification failure is reported as a warning rather than an abort.
                $gradebookverified = false;
                $gradebookwarning = null;
                try {
                    $gradeitem = $DB->get_record('grade_items', [
                        'itemtype'     => 'mod',
                        'itemmodule'   => 'quiz',
                        'iteminstance' => $quiz->id,
                        'courseid'     => $quiz->course,
                    ]);
                    if (!$gradeitem) {
                        $gradebookwarning = get_string('gradebookwarning_noitem', 'quiz_aigrader');
                    } else if ($gradeitem->needsupdate) {
                        $gradebookwarning = get_string('gradebookwarning_needsupdate', 'quiz_aigrader');
                    } else {
                        $gradegrade = $DB->get_record('grade_grades', [
                            'itemid' => $gradeitem->id,
                            'userid' => $attempt->userid,
                        ]);
                        if (!$gradegrade || $gradegrade->finalgrade === null) {
                            $gradebookwarning = get_string('gradebookwarning_nograde', 'quiz_aigrader');
                        } else {
                            $gradebookverified = true;
                        }
                    }
                } catch (\Throwable $verifyerr) {
                    debugging($verifyerr->getMessage(), DEBUG_DEVELOPER);
                    $gradebookwarning = get_string('gradebookwarning_verifyfailed', 'quiz_aigrader');
                }
                if (!$gradebookverified) {
                    debugging(
                        'AI Essay Grader: gradebook verification failed for userid '
                        . $attempt->userid . ' on quiz ' . $quiz->id . ' - ' . $gradebookwarning,
                        DEBUG_DEVELOPER
                    );
                }

                // Completion engine trigger.
                // recompute_final_grade and quiz_save_best_grade update the gradebook row but
                // do NOT notify Moodle's activity completion subsystem, which only re-runs when
                // update_state() is called explicitly. Without this the student never appears
                // as complete in the course completion report and "passing grade" completion
                // conditions are never evaluated, even after the teacher approves the grade.
                //
                // COMPLETION_UNKNOWN forces a full re-evaluation of all completion conditions
                // rather than hard-coding COMPLETE or INCOMPLETE, so any combination of
                // teacher-configured completion criteria is honoured correctly.
                try {
                    require_once($CFG->libdir . '/completionlib.php');
                    $completioncourse = $DB->get_record('course', ['id' => $quiz->course], '*', MUST_EXIST);
                    $completion = new \completion_info($completioncourse);
                    if ($completion->is_enabled($cm)) {
                        $completion->update_state($cm, COMPLETION_UNKNOWN, $attempt->userid);
                    }
                } catch (\Throwable $completionerr) {
                    // A completion update failure must never abort the approve response, since
                    // the grade is already saved. Log for debugging only.
                    debugging('AI Essay Grader: completion update failed: ' . $completionerr->getMessage(), DEBUG_DEVELOPER);
                }

                // Log the grading event for time tracking (upsert - update if it already exists).
                // Wrapped in try/catch to handle the case where the table does not exist yet.
                try {
                    $now = time();
                    $existing = $DB->get_record('quiz_aigrader_grading_logs', [
                        'quizid' => $quiz->id,
                        'qubaid' => $qubaid,
                        'slot' => $slot,
                    ]);
                    if ($existing) {
                        $existing->graderid = $USER->id;
                        $existing->timegraded = $now;
                        $DB->update_record('quiz_aigrader_grading_logs', $existing);
                    } else {
                        $logrecord = new stdClass();
                        $logrecord->quizid = $quiz->id;
                        $logrecord->courseid = $quiz->course;
                        $logrecord->graderid = $USER->id;
                        $logrecord->qubaid = $qubaid;
                        $logrecord->slot = $slot;
                        $logrecord->timegraded = $now;
                        $DB->insert_record('quiz_aigrader_grading_logs', $logrecord);
                    }
                } catch (\Exception $e) {
                    // Table might not exist yet - continue without logging.
                    debugging('AI Essay Grader: grading_logs table not found: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }

                // The assessment decision record.
                //
                // This is the evidence that a human made this decision. Before 4.4.0 the only
                // trace of an approval was one row saying who and when, overwritten whenever
                // the mark was revisited, so the record could not show what the AI suggested,
                // whether the assessor agreed with it, or who had approved it first. The table
                // is append-only: a re-approval adds a row rather than replacing one, and the
                // history of a mark can be reconstructed from it.
                $autolocked = $approvectx ? (bool)$approvectx->autolocked : false;
                $humanreview = $underhumanreview;
                try {
                    $aigrade100 = ($approvectx && $approvectx->aigrade !== null)
                        ? (float)$approvectx->aigrade : null;
                    $aimark = $aigrade100 === null ? null : round(($maxmark * $aigrade100) / 100, 5);
                    $aifeedback = $approvectx ? (string)$approvectx->aifeedback : '';

                    // Compare on the text, not the markup: the assessor edits plain text in
                    // the textarea and the browser re-renders it to HTML on save, so comparing
                    // HTML would report every approval as edited.
                    $aiplain = trim(html_to_text($aifeedback, 0, false));
                    $finalplain = trim(html_to_text($feedbacktext, 0, false));

                    $decision = new stdClass();
                    $decision->quizid = $quiz->id;
                    $decision->courseid = $quiz->course;
                    $decision->userid = $attempt->userid;
                    $decision->graderid = $USER->id;
                    $decision->qubaid = $qubaid;
                    $decision->slot = $slot;
                    $decision->questionid = $qa->get_question_id();
                    $decision->attemptnum = (int)$attempt->attempt;
                    $decision->maxmark = $maxmark;
                    $decision->aimark = $aimark;
                    $decision->finalmark = $mark;
                    $decision->markchanged = ($aimark === null || abs($aimark - $mark) > 0.0001) ? 1 : 0;
                    $decision->feedbackchanged = ($aiplain !== '' && $aiplain !== $finalplain) ? 1 : 0;
                    $decision->aifeedback = $aifeedback;
                    $decision->finalfeedback = $learnerfeedback;
                    // Prefer the assessor's scored criteria over the AI's met/not-met list.
                    // Validated as JSON before storing so a malformed post cannot put
                    // unparseable text into the record.
                    $decision->criteriamet = $approvectx ? $approvectx->aicriteriamet : null;
                    $posted = $criteriajson !== '' ? json_decode($criteriajson, true) : null;
                    $decision->policymode = $policymode !== '' ? $policymode : null;
                    // Where the criteria came from and a fingerprint of them, so an auditor can
                    // see what this mark was measured against.
                    $decision->criteriasource = null;
                    $decision->graderinfohash = null;
                    // The suggestion's own record first: what the assessor was shown for this exact
                    // response. Without one (marked by hand, or recorded before 5.0.4), the
                    // question's criteria as they stand, when certain; otherwise one overall mark.
                    $stored = $approvectx && !empty($approvectx->aicriteria) ? json_decode($approvectx->aicriteria, true) : null;
                    if (
                        is_array($stored)
                        && (int)($stored['qubaid'] ?? 0) === (int)$qubaid
                        && (int)($stored['slot'] ?? 0) === (int)$slot
                        && (int)($stored['attempt'] ?? 0) === (int)$attempt->attempt
                    ) {
                        $decision->criteriasource = clean_param((string)($stored['source'] ?? ''), PARAM_ALPHA) ?: null;
                        $decision->graderinfohash = !empty($stored['fingerprint'])
                            ? clean_param((string)$stored['fingerprint'], PARAM_ALPHANUM)
                            : null;
                        // The assessor's per-criterion scores, recorded only when they are scores
                        // on exactly the criteria this suggestion was measured against: same
                        // criteria, same order, same weights, each score within its weight. The
                        // browser is not evidence of what the criteria were.
                        $shown = is_array($stored['criteria'] ?? null) ? $stored['criteria'] : [];
                        if (is_array($posted) && $posted && count($posted) === count($shown)) {
                            $scores = [];
                            foreach (array_values($posted) as $i => $item) {
                                $want = $shown[$i];
                                $awarded = is_array($item) && isset($item['awarded']) && is_numeric($item['awarded'])
                                    ? (float)$item['awarded'] : null;
                                if (
                                    !is_array($item) || $awarded === null
                                    || (string)($item['label'] ?? '') !== (string)($want['label'] ?? '')
                                    || abs((float)($item['max'] ?? -1) - (float)($want['max'] ?? 0)) > 0.005
                                    || $awarded < -0.005 || $awarded > (float)$want['max'] + 0.005
                                ) {
                                    $scores = null;
                                    break;
                                }
                                $scores[] = $item;
                            }
                            if ($scores !== null) {
                                $decision->criteriamet = json_encode($scores, JSON_UNESCAPED_UNICODE);
                            } else {
                                debugging(
                                    'AI Essay Grader: posted criteria do not match the suggestion; not recorded.',
                                    DEBUG_DEVELOPER
                                );
                            }
                        }
                    } else if ($decisioncriteria !== null) {
                        $certain = $decisioncriteria['confidence'] === \quiz_aigrader\local\criteria::CERTAIN;
                        $decision->criteriasource = $certain
                            ? $decisioncriteria['source']
                            : \quiz_aigrader\local\criteria::SOURCE_NONE;
                        $decision->graderinfohash = $certain
                            ? \quiz_aigrader\local\criteria::fingerprint($decisioncriteria)
                            : null;
                    }
                    $decision->instructionshash = null;
                    $decision->modelversion = null;
                    $decision->requestid = null;
                    $decision->reviewseconds = $reviewseconds;
                    $decision->humanreview = $humanreview ? 1 : 0;
                    $decision->autolocked = $autolocked ? 1 : 0;
                    $decision->acknowledgement = trim($acknowledgement) !== '' ? trim($acknowledgement) : null;
                    $decision->timecreated = time();
                    $DB->insert_record('quiz_aigrader_decisions', $decision);

                    // The stored criteria belonged to the suggestion just decided. Cleared, so a
                    // later decision on this response can never be recorded against them.
                    // The context record is written back in full below, so it is cleared on the
                    // object as well as in the table.
                    if ($approvectx && !empty($approvectx->aicriteria)) {
                        $DB->set_field('quiz_aigrader_attempt_ctx', 'aicriteria', null, ['id' => $approvectx->id]);
                        $approvectx->aicriteria = null;
                    }
                } catch (\Exception $decisionerror) {
                    // The mark is already committed, so a failure to write the record must not
                    // abort the response. It is logged loudly because the record is the point.
                    debugging(
                        'AI Essay Grader: decision record write failed: '
                            . $decisionerror->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }

                // Update the attempt context from the APPROVED decision, so the next attempt
                // is graded against what the assessor decided rather than what the AI
                // suggested, and the "previous attempt" panel shows a mark that was awarded.
                try {
                    if ($approvectx) {
                        $approvectx->lastgrade = $grade100;
                        $approvectx->criteriamet = $approvectx->aicriteriamet;
                        $approvectx->feedbacksummary = \quiz_aigrader\local\formatter::previous_summary(
                            \quiz_aigrader\local\formatter::html_to_text($feedbacktext)
                        );
                        $approvectx->humanreview = 0;
                        $approvectx->suggestedtime = null;
                        $approvectx->timemodified = time();
                        $DB->update_record('quiz_aigrader_attempt_ctx', $approvectx);
                    }
                } catch (\Exception $ctxwriteerror) {
                    debugging(
                        'AI Essay Grader: attempt_ctx approve write failed: '
                            . $ctxwriteerror->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }

            // Notification fired after the FIRST teacher Approve click.
            //
            // Root cause: the AI pre-grades all essay questions in batch via "AI Suggest".
            // Each AI-graded question moves from needsgrading to graded state immediately, so
            // requires_grading() returns false for ALL questions before any teacher review.
            // The old state-based check therefore set the all-graded flag on the very first
            // Approve click, and students received one email per question instead of a single
            // email when the entire quiz was done.
            //
            // Fix: switch from a question-STATE check to a grading-LOGS check.
            // quiz_aigrader_grading_logs gets one row per (qubaid, slot) only when a teacher
            // clicks Approve & Save (inserted or updated above using an upsert, so there are no
            // duplicates). We count how many manually gradeable slots exist in this usage versus
            // how many have been teacher-logged, and notify only when every essay is approved.
            $allquestionsgraded = false;
            $notificationsent = false;
            if (isset($usagereloaded) && isset($attempt)) {
                try {
                    // Count only the slots this report can actually approve.
                    //
                    // Counting every manually graded slot meant the notification could never
                    // fire for a learner who left one essay blank, or for a quiz that mixed
                    // essay with any other manually graded question type: those slots are not
                    // listed in this report, so they never gain a log row and the approved
                    // count could never catch up. The learner was simply never told their work
                    // had been marked.
                    $manualslotcount = 0;
                    foreach ($usagereloaded->get_slots() as $checkslot) {
                        $checkqa = $usagereloaded->get_question_attempt($checkslot);
                        if ($checkqa->get_question()->qtype->name() !== 'essay') {
                            continue;
                        }
                        if (trim((string)$checkqa->get_response_summary()) === '') {
                            continue;
                        }
                        $manualslotcount++;
                    }

                    if ($manualslotcount > 0) {
                        // DISTINCT slot, so a re-approval of the same question cannot push the
                        // count past the number of slots and fire the notification early.
                        $approvedcount = (int) $DB->count_records_sql(
                            "SELECT COUNT(DISTINCT slot)
                               FROM {quiz_aigrader_grading_logs}
                              WHERE qubaid = ?",
                            [$qubaid]
                        );
                        $allquestionsgraded = ($approvedcount >= $manualslotcount);
                    }
                } catch (\Exception $checkex) {
                    // The table may not exist on pre-upgrade installs - do not notify.
                    debugging('AI Essay Grader: all-graded check failed: ' . $checkex->getMessage(), DEBUG_DEVELOPER);
                    $allquestionsgraded = false;
                }
            }

            // Send a notification to the student if enabled and all questions are graded.
            if (
                $allquestionsgraded && isset($attempt)
                    && get_config('quiz_aigrader', 'enable_student_notifications')
            ) {
                try {
                    $student = $DB->get_record('user', ['id' => $attempt->userid]);
                    $course = $DB->get_record('course', ['id' => $quiz->course]);

                    if ($student && $course) {
                        $coursecontext = context_course::instance($course->id);

                        // Build the attempt URL so the student can view their feedback.
                        $attempturl = new moodle_url('/mod/quiz/review.php', [
                            'attempt' => $attempt->id,
                        ]);

                        // Prepare the message data.
                        $messagedata = new stdClass();
                        $messagedata->firstname = $student->firstname;
                        $messagedata->quizname = format_string($quiz->name, true, ['context' => $coursecontext]);
                        $messagedata->coursename = format_string($course->fullname, true, ['context' => $coursecontext]);
                        $messagedata->score = $scorelabel;
                        $messagedata->attempturl = $attempturl->out(false);
                        $messagedata->sitename = format_string($SITE->fullname);

                        // Create the message.
                        $message = new \core\message\message();
                        $message->component = 'quiz_aigrader';
                        $message->name = 'grading_complete';
                        $message->userfrom = \core_user::get_noreply_user();
                        $message->userto = $student;
                        $message->subject = get_string('notification_subject', 'quiz_aigrader', $messagedata);
                        $message->fullmessage = get_string('notification_body', 'quiz_aigrader', $messagedata);
                        $message->fullmessageformat = FORMAT_PLAIN;
                        // The HTML body gets its own copy with the learner's name escaped; the
                        // plain-text body keeps it as typed, so "O'Brien" never reads "O&#039;Brien".
                        $htmldata = clone $messagedata;
                        $htmldata->firstname = s($student->firstname);
                        $message->fullmessagehtml = get_string('notification_body_html', 'quiz_aigrader', $htmldata);
                        $message->smallmessage = get_string('notification_subject', 'quiz_aigrader', $messagedata);
                        $message->notification = 1;
                        $message->contexturl = $attempturl;
                        $message->contexturlname = $messagedata->quizname;

                        // Send the message.
                        message_send($message);
                        $notificationsent = true;
                    }
                } catch (\Exception $notifyerror) {
                    // A notification failure should not break the grading response.
                    debugging('AI Essay Grader notification error: ' . $notifyerror->getMessage(), DEBUG_DEVELOPER);
                }
            }

            return [
                'ok' => true,
                'gradedtime' => time(),
                'gradedhuman' => userdate(time()),
                'mark' => $mark,
                'maxmark' => $maxmark,
                'notificationsent' => $notificationsent,
                // The gradebookverified flag is true only when the grade was read back from
                // grade_grades after the push. If it is false, gradebookwarning explains why.
                // The mark and feedback are saved either way - this reports on the gradebook
                // write specifically, so a silent failure is never presented as a success.
                'gradebookverified' => isset($gradebookverified) ? (bool) $gradebookverified : null,
                'gradebookwarning'  => isset($gradebookwarning) ? $gradebookwarning : null,
            ];
        } catch (\Throwable $e) {
            // Catch both Exception and Error (e.g. removed Moodle functions in newer versions).
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            return ['ok' => false, 'message' => get_string('error_savefailed', 'quiz_aigrader')];
        }
    }
}
