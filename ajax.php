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
 * AJAX endpoint for the AI Essay Grader quiz report.
 *
 * Serves the JavaScript front end of the report: it returns the remote credit
 * balance, requests AI grading suggestions for an essay question attempt, saves
 * an approved grade and feedback back into the question engine and gradebook,
 * manages per-quiz reference documents and grading instructions, and reports
 * grading time statistics.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * Perform an HTTP request against the Essay Grader AI service.
 *
 * Uses Moodle's curl wrapper so proxy settings and security checks are honoured.
 * The API key is sent in an Authorization header. It is also still sent as an apiKey
 * query parameter or payload field, because that is what the service reads today;
 * once the service accepts the header the query parameter can be dropped.
 *
 * @param string $url Absolute URL of the endpoint to call.
 * @param string $apikey API key sent as a bearer token.
 * @param bool $post True to send a POST request, false for GET.
 * @param array|null $payload Data to JSON encode as the POST body.
 * @return array Result with keys success (bool), body (string|null), error (string|null), httpcode (int).
 */
function quiz_aigrader_fetch($url, $apikey, $post = false, $payload = null) {
    $curl = new \curl();
    // Use a 90s timeout for grading (the AI service can take 60s+ for complex essays).
    $timeout = $post ? 90 : 30;
    $curl->setopt([
        'CURLOPT_TIMEOUT' => $timeout,
        'CURLOPT_RETURNTRANSFER' => true,
        'CURLOPT_SSL_VERIFYPEER' => true,
        'CURLOPT_SSL_VERIFYHOST' => 2,
        'CURLOPT_FOLLOWLOCATION' => true,
    ]);

    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $apikey];

    if ($post && $payload) {
        $headers[] = 'Content-Type: application/json';
        $curl->setHeader($headers);
        $body = $curl->post($url, json_encode($payload));
    } else {
        $curl->setHeader($headers);
        $body = $curl->get($url);
    }

    $info = $curl->get_info();
    $httpcode = isset($info['http_code']) ? $info['http_code'] : 0;
    $error = $curl->get_errno() ? $curl->error : null;

    if ($body === false || $httpcode === 0) {
        return [
            'success' => false,
            'body' => null,
            'error' => $error ? $error : 'Connection failed (curl)',
            'httpcode' => $httpcode,
        ];
    }

    return [
        'success' => true,
        'body' => $body,
        'error' => null,
        'httpcode' => $httpcode,
    ];
}

/**
 * Require the plugin's grading capability, answering in JSON rather than throwing.
 *
 * require_capability() raises an exception, which the file-level handler turns into a
 * generic server error - the user is told nothing useful and the button looks broken.
 * Every write action routes through here instead, so a permissions problem always names
 * the capability an administrator needs to grant, and all the write actions share one rule.
 *
 * @param context $context The module context to check in.
 * @return void Exits with a JSON response when the user may not grade.
 */
function quiz_aigrader_require_grading($context) {
    if (has_capability('quiz/aigrader:approve', $context)) {
        return;
    }

    echo json_encode([
        'ok' => false,
        'message' => get_string('error_noapprovepermission', 'quiz_aigrader'),
        'error_code' => 'no_approve_capability',
    ]);
    exit;
}

/**
 * Format a duration in seconds as H:MM:SS without wrapping after 24 hours.
 *
 * @param int $seconds Duration in seconds.
 * @return string Formatted duration, for example 27:04:09.
 */
function quiz_aigrader_format_duration($seconds) {
    $seconds = max(0, (int)$seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    return $hours . ':' . str_pad((string)$minutes, 2, '0', STR_PAD_LEFT)
        . ':' . str_pad((string)$secs, 2, '0', STR_PAD_LEFT);
}

try {
    global $DB, $USER, $SITE;

    if (!confirm_sesskey()) {
        echo json_encode([
            'ok' => false,
            'message' => get_string('error_sessionexpired', 'quiz_aigrader'),
            'error_code' => 'invalid_sesskey',
        ]);
        exit;
    }

    // Get params with defaults.
    $cmid   = optional_param('cmid', 0, PARAM_INT);
    $action = optional_param('action', '', PARAM_ALPHA);

    if (!$cmid || !$action) {
        echo json_encode(['ok' => false, 'message' => get_string('error_missingparams', 'quiz_aigrader')]);
        exit;
    }

    // Optional params.
    $qubaid = optional_param('qubaid', 0, PARAM_INT);
    $slot   = optional_param('slot', 0, PARAM_INT);

    // Validate course module exists.
    $cm = get_coursemodule_from_id('quiz', $cmid, 0, false);
    if (!$cm) {
        echo json_encode(['ok' => false, 'message' => get_string('error_invalidcoursemodule', 'quiz_aigrader')]);
        exit;
    }

    // AJAX_SCRIPT=true ensures a JSON error response rather than a redirect.
    require_login($cm->course, false, $cm);

    $context = context_module::instance($cm->id);
    $PAGE->set_context($context);

    if (isguestuser()) {
        echo json_encode([
            'ok' => false,
            'message' => get_string('error_notloggedin', 'quiz_aigrader'),
            'error_code' => 'not_logged_in',
        ]);
        exit;
    }

    // Read-only baseline capability; write actions additionally require mod/quiz:grade below.
    if (!has_capability('mod/quiz:viewreports', $context)) {
        echo json_encode([
            'ok' => false,
            'message' => get_string('error_nopermission', 'quiz_aigrader'),
            'error_code' => 'no_capability',
        ]);
        exit;
    }

    // Any client supplied question usage id must belong to this quiz, otherwise it is a
    // cross-activity reference and must be rejected before it is used for anything.
    $attemptrec = null;
    if ($qubaid) {
        $attemptrec = $DB->get_record('quiz_attempts', ['uniqueid' => $qubaid]);
        if (!$attemptrec || (int)$attemptrec->quiz !== (int)$cm->instance) {
            // Either the attempt has gone since the page was loaded, or the id belongs to a
            // different activity. Both are refused, and reported rather than thrown so the
            // interface can say something useful.
            echo json_encode([
                'ok' => false,
                'message' => get_string('error_invalidcoursemodule', 'quiz_aigrader'),
                'error_code' => 'invalid_attempt',
            ]);
            exit;
        }
    }

    // Explicitly include the aiconfig lib.php if available.
    $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
    if (file_exists($aiconfiglib)) {
        require_once($aiconfiglib);
    }

    // Priority 1: central config (recommended for multi-plugin setups).
    $siteid = '';
    $apikey = '';
    if (function_exists('local_aiconfig_get_siteid')) {
        $siteid = trim(local_aiconfig_get_siteid() ?? '');
    }
    if (function_exists('local_aiconfig_get_apikey')) {
        $apikey = trim(local_aiconfig_get_apikey() ?? '');
    }

    // Priority 2: plugin settings as fallback.
    if (empty($siteid)) {
        $siteid = trim(get_config('quiz_aigrader', 'siteid') ?? '');
    }
    if (empty($apikey)) {
        $apikey = trim(get_config('quiz_aigrader', 'apikey') ?? '');
    }

    // Base URL of the remote grading service, configurable by the administrator.
    $apibase = trim((string)get_config('quiz_aigrader', 'apiurl'));
    if ($apibase === '') {
        $apibase = 'https://lms-labs.com';
    }
    $apibase = rtrim($apibase, '/');

    // Release the session lock before long-running API calls to prevent blocking other requests.
    \core\session\manager::write_close();

    // Action: get credits.
    if ($action === 'credits_status' || $action === 'creditsstatus') {
        if (!$siteid || !$apikey) {
            echo json_encode([
                'ok' => false,
                'credits' => null,
                'message' => get_string('notconfigured', 'quiz_aigrader'),
            ]);
            exit;
        }

        $url = $apibase . '/api/credits?siteId=' . urlencode($siteid) .
                '&apiKey=' . urlencode($apikey);

        $res = quiz_aigrader_fetch($url, $apikey);

        if (!$res['success']) {
            debugging('AI Grader: credits request failed: ' . $res['error'], DEBUG_DEVELOPER);
            echo json_encode([
                'ok' => false,
                'credits' => null,
                'message' => get_string('connectionfailed', 'quiz_aigrader'),
            ]);
            exit;
        }

        $data = json_decode($res['body'], true);

        if (!$data) {
            debugging('AI Grader: credits response could not be decoded (HTTP ' . $res['httpcode'] . ')', DEBUG_DEVELOPER);
            echo json_encode([
                'ok' => false,
                'credits' => null,
                'message' => get_string('invalidresponse', 'quiz_aigrader'),
            ]);
            exit;
        }

        if (isset($data['error'])) {
            debugging('AI Grader: credits endpoint returned an error (HTTP ' . $res['httpcode'] . ')', DEBUG_DEVELOPER);
            echo json_encode([
                'ok' => false,
                'credits' => null,
                'message' => get_string('error_fetching_credits', 'quiz_aigrader'),
            ]);
            exit;
        }

        // Check for unlimited credits first (returned as the string "unlimited" or an isUnlimited flag).
        $isunlimited = false;
        if (isset($data['isUnlimited']) && $data['isUnlimited'] === true) {
            $isunlimited = true;
        } else if (isset($data['credits']) && $data['credits'] === 'unlimited') {
            $isunlimited = true;
        }

        // Flexible field mapping - use creditsRaw for the actual numeric value.
        $credits = null;
        if ($isunlimited) {
            // For unlimited, the UI displays an infinity sign but -1 is used internally.
            $credits = -1;
        } else if (isset($data['creditsRaw'])) {
            // Prefer creditsRaw which is always numeric.
            $credits = (int)$data['creditsRaw'];
        } else if (isset($data['credits']) && is_numeric($data['credits'])) {
            $credits = (int)$data['credits'];
        } else if (isset($data['balance'])) {
            $credits = (int)$data['balance'];
        } else if (isset($data['creditsRemaining'])) {
            $credits = (int)$data['creditsRemaining'];
        } else if (isset($data['creditsBalance'])) {
            $credits = (int)$data['creditsBalance'];
        } else if (isset($data['data']['credits'])) {
            $credits = (int)$data['data']['credits'];
        }

        if ($credits === null) {
            debugging('AI Grader: credits field missing from response', DEBUG_DEVELOPER);
            echo json_encode([
                'ok' => false,
                'credits' => null,
                'message' => get_string('invalidresponse', 'quiz_aigrader'),
            ]);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'isUnlimited' => $isunlimited,
            'credits' => $credits,
        ]);
        exit;
    }

    // Action: suggest / AI grade.
    if ($action === 'suggest') {
        // Requesting a grade consumes credits and writes plugin table rows.
        quiz_aigrader_require_grading($context);

        // Wrap the entire grading action in try/catch to ensure we always return JSON.
        try {
            if (!$qubaid || !$slot) {
                echo json_encode(['ok' => false, 'message' => get_string('error_missingparams', 'quiz_aigrader')]);
                exit;
            }

            if (!$siteid || !$apikey) {
                echo json_encode(['ok' => false, 'message' => get_string('notconfigured', 'quiz_aigrader')]);
                exit;
            }

            // Load Moodle question data.
            try {
                $usage = question_engine::load_questions_usage_by_activity($qubaid);
                if (!in_array((int)$slot, array_map('intval', $usage->get_slots()), true)) {
                    echo json_encode(['ok' => false, 'message' => get_string('error_invalidslot', 'quiz_aigrader')]);
                    exit;
                }
                $qa = $usage->get_question_attempt($slot);

                // Clean question text: strip HTML tags and decode entities, preserving line breaks.
                $rawquestion = $qa->get_question()->questiontext;
                $question = html_entity_decode(strip_tags($rawquestion), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Normalise spaces but keep newlines.
                $question = preg_replace('/[^\S\n]+/', ' ', $question);
                $question = trim($question);

                // Extract graderinfo (assessor instructions, rubric and sample answers).
                // This is the "Information for graders" field in Moodle essay questions and
                // contains marking criteria the AI must use for consistent grading.
                $questionobj = $qa->get_question();
                $graderinfo = '';
                if (isset($questionobj->graderinfo) && !empty($questionobj->graderinfo)) {
                    $rawgraderinfo = $questionobj->graderinfo;
                    $graderinfo = html_entity_decode(strip_tags($rawgraderinfo), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $graderinfo = preg_replace('/[^\S\n]+/', ' ', $graderinfo);
                    $graderinfo = trim($graderinfo);
                }
                // Also try generalfeedback as a fallback (some question types use this for rubrics).
                $generalfeedback = '';
                if (isset($questionobj->generalfeedback) && !empty($questionobj->generalfeedback)) {
                    $rawgeneralfeedback = $questionobj->generalfeedback;
                    $generalfeedback = html_entity_decode(strip_tags($rawgeneralfeedback), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $generalfeedback = preg_replace('/[^\S\n]+/', ' ', $generalfeedback);
                    $generalfeedback = trim($generalfeedback);
                }

                // Clean answer text: preserve line breaks so the AI can analyse structure.
                $rawanswer = $qa->get_response_summary();
                $answer = html_entity_decode(strip_tags($rawanswer), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Normalise spaces but keep newlines.
                $answer = preg_replace('/[^\S\n]+/', ' ', $answer);
                $answer = trim($answer);
                $maxmark = round((float)$qa->get_max_mark(), 2);
                $questionid = $qa->get_question()->id;
            } catch (Exception $e) {
                debugging($e->getMessage(), DEBUG_DEVELOPER);
                echo json_encode(['ok' => false, 'message' => get_string('error_loadfailed', 'quiz_aigrader')]);
                exit;
            }

            if (trim($answer) === '') {
                echo json_encode(['ok' => false, 'message' => get_string('noanswer', 'quiz_aigrader')]);
                exit;
            }

            // Get the quiz record for the server-side settings lookup (one API call instead of two).
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

            // The attempt has already been validated as belonging to this quiz.
            $quizattempt = $attemptrec;
            $studentid = $quizattempt ? $quizattempt->userid : 0;
            $attemptnum = $quizattempt ? (int)$quizattempt->attempt : 1;

            // Get the maximum number of attempts allowed (0 = unlimited).
            $maxattempts = isset($quiz->attempts) ? (int)$quiz->attempts : 0;

            // Attempt context: fetch previous attempt history for consistency.
            $attemptcontext = null;
            $previouscriteriamet = [];
            $previousfeedbacksummary = '';
            $previousgrade = null;
            $autolocked = false;
            $humanreview = false;

            if ($studentid > 0) {
                // Look for previous attempt context for this student and question. Wrapped in
                // try/catch to handle the case where the table does not exist yet (pre-upgrade).
                try {
                    $attemptcontext = $DB->get_record('quiz_aigrader_attempt_ctx', [
                        'quizid' => $quiz->id,
                        'userid' => $studentid,
                        'questionid' => $questionid,
                        'slot' => $slot,
                    ]);
                } catch (Exception $e) {
                    // Table might not exist yet - continue without attempt context.
                    $attemptcontext = null;
                    debugging('AI Grader: attempt_ctx table not found: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }

                if ($attemptcontext) {
                    // Decode previously met criteria.
                    $previouscriteriamet = !empty($attemptcontext->criteriamet)
                        ? json_decode($attemptcontext->criteriamet, true)
                        : [];
                    $previousfeedbacksummary = $attemptcontext->feedbacksummary ?? '';
                    $previousgrade = $attemptcontext->lastgrade;
                    $autolocked = (bool)$attemptcontext->autolocked;
                    $humanreview = (bool)$attemptcontext->humanreview;

                    // Auto-lock rule: if the attempt is locked, return full marks immediately.
                    if ($autolocked) {
                        echo json_encode([
                            'ok' => true,
                            'grade' => 'Full Marks',
                            'score' => intval($maxmark) . '/' . intval($maxmark),
                            'feedback' => get_string('autolock_feedback', 'quiz_aigrader'),
                            'rubric' => null,
                            'grade100' => 100,
                            'scaledmark' => $maxmark,
                            'maxmark' => $maxmark,
                            'credits' => null,
                            'autolocked' => true,
                            'attemptnum' => $attemptnum,
                            'maxattempts' => $maxattempts,
                            'previousattempt' => [
                                'feedback' => $previousfeedbacksummary,
                                'grade' => $previousgrade,
                            ],
                        ]);
                        exit;
                    }

                    // Human review rule: the attempt has been flagged for assessor review.
                    if ($humanreview) {
                        $reviewmark = round($previousgrade / 100 * $maxmark, 2);
                        echo json_encode([
                            'ok' => true,
                            'grade' => 'Pending Review',
                            'score' => intval($reviewmark) . '/' . intval($maxmark),
                            'feedback' => get_string('humanreview_feedback', 'quiz_aigrader'),
                            'rubric' => null,
                            'grade100' => $previousgrade,
                            'scaledmark' => $reviewmark,
                            'maxmark' => $maxmark,
                            'credits' => null,
                            'humanreview' => true,
                            'attemptnum' => $attemptnum,
                            'maxattempts' => $maxattempts,
                            'previousattempt' => [
                                'feedback' => $previousfeedbacksummary,
                                'grade' => $previousgrade,
                            ],
                        ]);
                        exit;
                    }
                }
            }

            // Language: prefer the explicit parameter, fall back to the user's Moodle language.
            $language = optional_param('language', '', PARAM_ALPHANUMEXT);
            if (empty($language)) {
                // Auto-detect from the Moodle user's current language setting and convert
                // Moodle language codes to the standard format (e.g. 'en_au' -> 'en-AU').
                $language = str_replace('_', '-', current_language());
            }

            // Check whether this is a re-grade (no credit charge).
            $regrade = optional_param('regrade', 0, PARAM_INT);

            // API request - the server fetches extra instructions using quizId.
            // maxMark is included so the AI grades to the correct scale, language drives
            // multilingual feedback, and attemptContext keeps grading consistent across
            // attempts. graderInfo carries the assessor rubric and sample answers.
            $payload = [
                'siteId' => $siteid,
                'apiKey' => $apikey,
                'questionText' => $question,
                'studentAnswer' => $answer,
                'quizId' => intval($quiz->id),
                'maxMark' => floatval($maxmark),
                'language' => $language,
                'regrade' => $regrade ? true : false,
                'metadata' => ['qubaid' => $qubaid, 'slot' => $slot, 'quizId' => $quiz->id],
                'graderInfo' => $graderinfo,
                'generalFeedback' => $generalfeedback,
                'attemptContext' => [
                    'attemptNumber' => $attemptnum,
                    'previouslyMetCriteria' => $previouscriteriamet,
                    'previousFeedbackSummary' => $previousfeedbacksummary,
                ],
            ];

            $res = quiz_aigrader_fetch($apibase . '/api/grade-essay', $apikey, true, $payload);

            if (!$res['success']) {
                debugging('AI Grader: grading request failed: ' . $res['error'], DEBUG_DEVELOPER);
                echo json_encode([
                    'ok' => false,
                    'message' => get_string('connectionfailed', 'quiz_aigrader'),
                ]);
                exit;
            }

            $data = json_decode($res['body'], true);

            if (!$data) {
                debugging('AI Grader: grading response could not be decoded (HTTP ' . $res['httpcode'] . ')', DEBUG_DEVELOPER);
                echo json_encode([
                    'ok' => false,
                    'message' => get_string('invalidresponse', 'quiz_aigrader'),
                ]);
                exit;
            }

            // Handle API errors.
            if (isset($data['ok']) && !$data['ok']) {
                // Check for insufficient credits (can come as 'error' or 'error_code').
                $errorcode = isset($data['error_code'])
                    ? $data['error_code']
                    : (isset($data['error']) ? $data['error'] : 'UNKNOWN');

                if ($errorcode === 'INSUFFICIENT_CREDITS') {
                    echo json_encode([
                        'ok' => false,
                        'error' => 'insufficient_credits',
                        'credits' => isset($data['credits']) ? $data['credits'] : 0,
                    ]);
                    exit;
                }

                debugging('AI Grader: grading API returned error code ' . $errorcode, DEBUG_DEVELOPER);
                echo json_encode([
                    'ok' => false,
                    'message' => get_string('error_grading', 'quiz_aigrader'),
                    'error_code' => clean_param($errorcode, PARAM_ALPHANUMEXT),
                ]);
                exit;
            }

            // Build data for output. The grade is not saved until the teacher approves it.
            // Score format: "X/Y" where Y matches the question's max mark.
            $score = isset($data['score']) ? $data['score'] : '0/' . intval($maxmark);
            $parts = explode('/', $score);
            $num = isset($parts[0]) ? floatval($parts[0]) : 0;
            $den = isset($parts[1]) ? floatval($parts[1]) : $maxmark;
            // Calculate the percentage and scale it to the actual max mark.
            $grade100 = $den ? round(($num / $den) * 100) : 0;
            $scaled = round(($grade100 / 100) * $maxmark, 2);

            // Handle the case where the AI returns feedback as an object instead of a string.
            $feedbacktext = isset($data['feedback']) ? $data['feedback'] : '';
            if (is_array($feedbacktext) || is_object($feedbacktext)) {
                // Convert structured feedback to a formatted string.
                $parts = [];
                foreach ((array)$feedbacktext as $key => $value) {
                    $header = ucwords(str_replace('_', ' ', $key));
                    if (is_array($value)) {
                        $items = implode("\n- ", $value);
                        $parts[] = "$header:\n- $items";
                    } else {
                        $parts[] = "$header:\n$value";
                    }
                }
                $feedbacktext = implode("\n\n", $parts);
            }
            $gradelabel = isset($data['grade']) ? $data['grade'] : '';

            // Extract criteriaMet and feedbackSummary from the API response.
            $newcriteriamet = isset($data['criteriaMet']) ? $data['criteriaMet'] : [];
            $newfeedbacksummary = isset($data['feedbackSummary']) ? $data['feedbackSummary'] : '';

            // Protect against empty or malformed criteriaMet - it must always be an array.
            if (!is_array($newcriteriamet)) {
                $newcriteriamet = [];
            }

            // Never let the AI invent criteria IDs. Validate against the known criteria so
            // hallucinated IDs cannot break persistence. These are the standard RTO
            // competency assessment criteria.
            $validcriteria = [
                'explain_process', 'example_provided', 'site_specific',
                'hazard_identified', 'control_measure', 'procedure_followed',
                'legislation_referenced', 'workplace_context', 'practical_application',
                'communication_clear', 'documentation_complete', 'safety_awareness',
                'risk_assessment', 'compliance_demonstrated', 'knowledge_applied',
            ];

            // If the API returns allowedCriteria for this question, use it as the filter so
            // validation is tighter per question.
            $questioncriteria = isset($data['allowedCriteria']) && is_array($data['allowedCriteria'])
                ? array_values(array_intersect($data['allowedCriteria'], $validcriteria))
                : $validcriteria;

            // Log rejected criteria IDs for audit. Stored internally, never shown to students.
            $rawcriteriamet = $newcriteriamet;
            $rejectedcriteria = array_values(array_diff($rawcriteriamet, $questioncriteria));

            if (!empty($rejectedcriteria)) {
                debugging('AI Grader: Rejected invalid criteria IDs: ' . implode(', ', $rejectedcriteria) .
                          ' for question ' . $questionid . ' user ' . $studentid, DEBUG_DEVELOPER);
            }

            // Filter down to only the valid criteria for this question.
            $newcriteriamet = array_values(array_intersect($newcriteriamet, $questioncriteria));

            // If the API did not return structured criteria, try to derive a summary from the feedback.
            if (empty($newcriteriamet) && !empty($feedbacktext)) {
                if (empty($newfeedbacksummary)) {
                    $newfeedbacksummary = substr(strip_tags($feedbacktext), 0, 300);
                }
            }

            // Clamp feedbackSummary to 300 characters to prevent prompt bloat.
            $newfeedbacksummary = substr($newfeedbacksummary, 0, 300);

            // Trust criteria over score: if all criteria are met, force grade100 to 100.
            // This is how competency-based assessment works.
            $allcriteriacount = count($validcriteria);
            $mergedcriteriacount = count(array_unique(array_merge($previouscriteriamet, $newcriteriamet)));

            // If a meaningful number of criteria are met (at least three core ones), trust it.
            // This handles cases where the AI returns a score below 100 but all relevant
            // criteria for the question are met.
            if (!empty($newcriteriamet) && $mergedcriteriacount >= 3) {
                $apireportedtotal = isset($data['totalCriteria']) ? (int)$data['totalCriteria'] : 0;
                if ($apireportedtotal > 0 && $mergedcriteriacount >= $apireportedtotal) {
                    $grade100 = 100;
                }
            }

            // Store the attempt context so the next attempt is graded consistently.
            $newautolocked = false;
            $newhumanreview = false;

            // Auto-lock rule: attempt >= 3 and grade = 100% (all criteria met).
            if ($attemptnum >= 3 && $grade100 >= 100) {
                $newautolocked = true;
            }

            // Human review rule: attempt >= 4 and no improvement on the previous attempt.
            if ($attemptnum >= 4 && $previousgrade !== null && $grade100 <= $previousgrade) {
                $newhumanreview = true;
            }

            // Attempt >= 5 always triggers mandatory human review, which keeps extended
            // attempt sequences under human oversight.
            if ($attemptnum >= 5) {
                $newhumanreview = true;
            }

            // Store the attempt context for the next grading run. Wrapped in try/catch: if the
            // attempt_ctx table is missing (pre-upgrade or failed install) grading still
            // succeeds, because attempt context is non-critical metadata.
            if ($studentid > 0) {
                try {
                    $now = time();

                    // Merge newly met criteria with previously met criteria (criteria persist once met).
                    $mergedcriteria = array_unique(array_merge($previouscriteriamet, $newcriteriamet));

                    if ($attemptcontext) {
                        // Update the existing record. The autolocked and humanreview flags are
                        // deliberately not saved here - they are only written on approve.
                        $attemptcontext->attemptnum = $attemptnum;
                        $attemptcontext->criteriamet = json_encode($mergedcriteria);
                        $attemptcontext->feedbacksummary = $newfeedbacksummary;
                        $attemptcontext->lastgrade = $grade100;
                        $attemptcontext->timemodified = $now;
                        $DB->update_record('quiz_aigrader_attempt_ctx', $attemptcontext);
                    } else {
                        // Insert a new record, again without the autolocked or humanreview flags.
                        $newrecord = new stdClass();
                        $newrecord->quizid = $quiz->id;
                        $newrecord->userid = $studentid;
                        $newrecord->questionid = $questionid;
                        $newrecord->slot = $slot;
                        $newrecord->attemptnum = $attemptnum;
                        $newrecord->criteriamet = json_encode($mergedcriteria);
                        $newrecord->feedbacksummary = $newfeedbacksummary;
                        $newrecord->lastgrade = $grade100;
                        $newrecord->autolocked = 0;
                        $newrecord->humanreview = 0;
                        $newrecord->timecreated = $now;
                        $newrecord->timemodified = $now;
                        $DB->insert_record('quiz_aigrader_attempt_ctx', $newrecord);
                    }
                } catch (Exception $e) {
                    // Attempt context save failed - log it but do not abort grading. This happens
                    // if the quiz_aigrader_attempt_ctx table does not exist yet (for example the
                    // plugin upgrade has not run). The grading result is still returned below.
                    debugging('AI Grader: attempt_ctx write failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            // Build the response with the additional context flags.
            $response = [
                'ok' => true,
                'grade' => $gradelabel,
                'score' => $score,
                'feedback' => $feedbacktext,
                'rubric' => isset($data['rubric']) ? $data['rubric'] : null,
                'grade100' => $grade100,
                'scaledmark' => $scaled,
                'maxmark' => $maxmark,
                'credits' => isset($data['credits']) ? $data['credits'] : null,
                'attemptnum' => $attemptnum,
                'maxattempts' => $maxattempts,
                'previousattempt' => [
                    'feedback' => $previousfeedbacksummary,
                    'grade' => $previousgrade,
                ],
            ];

            // Add the flags used for UI display.
            if ($newautolocked) {
                $response['autolocked'] = true;
                $response['grade'] = 'Full Marks';
                $response['grade100'] = 100;
                $response['scaledmark'] = $maxmark;
                $response['score'] = intval($maxmark) . '/' . intval($maxmark);
                $response['feedback'] = get_string('autolock_feedback', 'quiz_aigrader') . "\n\n" . $feedbacktext;
            }

            if ($newhumanreview) {
                $response['humanreview'] = true;
                $response['feedback'] = get_string('humanreview_notice', 'quiz_aigrader') . "\n\n" . $feedbacktext;
            }

            // Return the data for teacher review - they click "Approve" to save it.
            echo json_encode($response);
            exit;
        } catch (Exception $outerexception) {
            // Catch any uncaught exception in the grading flow.
            debugging($outerexception->getMessage(), DEBUG_DEVELOPER);
            echo json_encode([
                'ok' => false,
                'message' => get_string('error_grading', 'quiz_aigrader'),
                'error_code' => 'GRADING_EXCEPTION',
            ]);
            exit;
        } catch (Throwable $outerthrowable) {
            // Catch any fatal errors or throwables.
            debugging($outerthrowable->getMessage(), DEBUG_DEVELOPER);
            echo json_encode([
                'ok' => false,
                'message' => get_string('error_grading', 'quiz_aigrader'),
                'error_code' => 'FATAL_ERROR',
            ]);
            exit;
        }
    }

    // Action: approve / save grade.
    if ($action === 'approve') {
        // Writing a grade is gated on the plugin's own capability, not on the read capability
        // that merely opens the report.
        quiz_aigrader_require_grading($context);

        if (!$qubaid || !$slot) {
            echo json_encode(['ok' => false, 'message' => get_string('error_missingparams', 'quiz_aigrader')]);
            exit;
        }

        $grade100 = optional_param('grade100', 0, PARAM_FLOAT);
        // Rich HTML feedback produced by the grader UI. It must arrive raw because param
        // cleaning would strip the markup. It is NOT passed through clean_text() here:
        // clean_text() removes the inline SVG icons the feedback cards are built from, and
        // the value is stored as FORMAT_HTML, so Moodle sanitises it through format_text()
        // every time it is rendered.
        // phpcs:ignore moodle.Commenting.InlineComment.NotCapital
        $feedbacktext = optional_param('feedbackhtml', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW - HTML feedback.
        $gradelabel = optional_param('gradelabel', '', PARAM_TEXT);

        try {
            $usage = question_engine::load_questions_usage_by_activity($qubaid);
            if (!in_array((int)$slot, array_map('intval', $usage->get_slots()), true)) {
                echo json_encode(['ok' => false, 'message' => get_string('error_invalidslot', 'quiz_aigrader')]);
                exit;
            }
            $qa = $usage->get_question_attempt($slot);

            $maxmark = round((float)$qa->get_max_mark(), 2);
            $mark = round(($maxmark * $grade100) / 100, 2);

            // Calculate the score label using the actual max mark.
            // number_format(), not format_float(): this string is parsed back with floatval()
            // elsewhere, and format_float() would use the site's decimal separator.
            $scorelabel = number_format($mark, 2, '.', '') . '/' . number_format($maxmark, 2, '.', '');

            // Save the grade and feedback into the question engine.
            $qa->manual_grade($feedbacktext, $mark, FORMAT_HTML);
            question_engine::save_questions_usage_by_activity($usage);

            // Update the quiz attempt's total grade. The attempt owning this usage has already
            // been validated as belonging to this course module.
            $attempt = $attemptrec;
            if ($attempt) {
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');

                // Recalculate and save the attempt's sumgrades.
                // The delegated transaction ensures the reload and update_record are atomic:
                // if the server crashes mid-write the attempt row is left consistent
                // (either fully updated or not at all) rather than partially written.
                $sumtransaction = $DB->start_delegated_transaction();
                $usagereloaded = question_engine::load_questions_usage_by_activity($qubaid);
                $attempt->sumgrades = $usagereloaded->get_total_mark();
                $DB->update_record('quiz_attempts', $attempt);
                $sumtransaction->allow_commit();

                // Update the user's best grade in the gradebook.
                $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);

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
                    debugging('AI Grader could not log the manual grading event: '
                        . $eventerror->getMessage(), DEBUG_DEVELOPER);
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
                        'AI Grader: gradebook verification failed for userid '
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
                    $completion = new completion_info($completioncourse);
                    if ($completion->is_enabled($cm)) {
                        $completion->update_state($cm, COMPLETION_UNKNOWN, $attempt->userid);
                    }
                } catch (\Throwable $completionerr) {
                    // A completion update failure must never abort the approve response, since
                    // the grade is already saved. Log for debugging only.
                    debugging('AI Grader: completion update failed: ' . $completionerr->getMessage(), DEBUG_DEVELOPER);
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
                } catch (Exception $e) {
                    // Table might not exist yet - continue without logging.
                    debugging('AI Grader: grading_logs table not found: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }

                // The autolocked and humanreview flags are decided by the suggest action, which
                // is the only place that knows the previous attempt's grade and can apply the
                // "no improvement on the previous attempt" part of the rule. They are read from
                // the stored attempt context here rather than recomputed, because recomputing
                // without the previous grade would flag every attempt-4 grade below 100% for
                // human review and lock a student who had in fact improved out of AI grading on
                // their next attempt. They are deliberately not taken from the client either.
                $autolocked = false;
                $humanreview = false;
                try {
                    $attemptcontext = $DB->get_record('quiz_aigrader_attempt_ctx', [
                        'quizid' => $quiz->id,
                        'userid' => $attempt->userid,
                        'questionid' => $qa->get_question_id(),
                        'slot' => $slot,
                    ]);
                    if ($attemptcontext) {
                        $autolocked = (bool)$attemptcontext->autolocked;
                        $humanreview = (bool)$attemptcontext->humanreview;
                    }
                } catch (Exception $e) {
                    // Table might not exist yet - continue.
                    debugging('AI Grader: attempt_ctx read failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
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
                    // Count manually gradeable slots (question types where is_manual_graded()
                    // returns true, e.g. essay). MCQ, truefalse and matching are excluded.
                    $manualslotcount = 0;
                    foreach ($usagereloaded->get_slots() as $checkslot) {
                        $checkqa = $usagereloaded->get_question_attempt($checkslot);
                        if ($checkqa->get_question()->qtype->is_manual_graded()) {
                            $manualslotcount++;
                        }
                    }

                    if ($manualslotcount > 0) {
                        // Count teacher-approved slots for this attempt. The upsert above means
                        // there are no duplicates - one row per slot per attempt.
                        $approvedcount = (int) $DB->count_records(
                            'quiz_aigrader_grading_logs',
                            ['qubaid' => $qubaid]
                        );
                        $allquestionsgraded = ($approvedcount >= $manualslotcount);
                    }
                } catch (Exception $checkex) {
                    // The table may not exist on pre-upgrade installs - do not notify.
                    debugging('AI Grader: all-graded check failed: ' . $checkex->getMessage(), DEBUG_DEVELOPER);
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
                        $message->fullmessagehtml = get_string('notification_body_html', 'quiz_aigrader', $messagedata);
                        $message->smallmessage = get_string('notification_subject', 'quiz_aigrader', $messagedata);
                        $message->notification = 1;
                        $message->contexturl = $attempturl;
                        $message->contexturlname = $messagedata->quizname;

                        // Send the message.
                        message_send($message);
                        $notificationsent = true;
                    }
                } catch (Exception $notifyerror) {
                    // A notification failure should not break the grading response.
                    debugging('AI Grader notification error: ' . $notifyerror->getMessage(), DEBUG_DEVELOPER);
                }
            }

            echo json_encode([
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
            ]);
        } catch (\Throwable $e) {
            // Catch both Exception and Error (e.g. removed Moodle functions in newer versions).
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            echo json_encode(['ok' => false, 'message' => get_string('error_savefailed', 'quiz_aigrader')]);
        }

        exit;
    }

    // Action: list documents.
    if ($action === 'listdocs') {
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        if (!$siteid || !$apikey) {
            echo json_encode([
                'ok' => false,
                'documents' => [],
                'message' => get_string('notconfigured', 'quiz_aigrader'),
            ]);
            exit;
        }

        $url = $apibase . '/api/reference-docs?siteId=' . urlencode($siteid) .
                '&apiKey=' . urlencode($apikey) .
                '&quizId=' . intval($quiz->id);

        $res = quiz_aigrader_fetch($url, $apikey);

        if (!$res['success']) {
            debugging('AI Grader: reference-docs request failed: ' . $res['error'], DEBUG_DEVELOPER);
            echo json_encode([
                'ok' => false,
                'documents' => [],
                'message' => get_string('connectionfailed', 'quiz_aigrader'),
            ]);
            exit;
        }

        $data = json_decode($res['body'], true);

        if (isset($data['ok']) && $data['ok']) {
            echo json_encode([
                'ok' => true,
                'documents' => isset($data['documents']) ? $data['documents'] : [],
            ]);
        } else {
            echo json_encode([
                'ok' => false,
                'documents' => [],
                'message' => get_string('invalidresponse', 'quiz_aigrader'),
            ]);
        }
        exit;
    }

    // Action: upload document.
    if ($action === 'uploaddoc') {
        quiz_aigrader_require_grading($context);

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        if (!$siteid || !$apikey) {
            echo json_encode(['ok' => false, 'message' => get_string('notconfigured', 'quiz_aigrader')]);
            exit;
        }

        // The browser posts the reference document as multipart/form-data from an AMD module,
        // so there is no Moodle form instance to read it from. The upload is therefore taken
        // from PHP's own upload handler and validated below: is_uploaded_file(), a cleaned
        // filename, an extension allowlist and a server-derived MIME type.
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            echo json_encode(['ok' => false, 'message' => get_string('error_nofile', 'quiz_aigrader')]);
            exit;
        }

        $file = $_FILES['file'];

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'message' => get_string('error_uploadfailed', 'quiz_aigrader')]);
            exit;
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            echo json_encode(['ok' => false, 'message' => get_string('error_uploadfailed', 'quiz_aigrader')]);
            exit;
        }

        // Check the file size (10MB limit).
        if (!empty($file['size']) && $file['size'] > 10 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'message' => get_string('error_filetoolarge', 'quiz_aigrader')]);
            exit;
        }

        // Never trust the browser-supplied name or MIME type.
        $filename = clean_param($file['name'], PARAM_FILE);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowedextensions = ['pdf', 'docx', 'doc', 'txt', 'md'];
        if ($filename === '' || !in_array($extension, $allowedextensions, true)) {
            echo json_encode(['ok' => false, 'message' => get_string('error_invalidfiletype', 'quiz_aigrader')]);
            exit;
        }
        $mimetype = mimeinfo('type', $filename);

        // Send the file to the API. Moodle's curl wrapper supports multipart uploads when a
        // \CURLFile instance is passed in the parameter array.
        $curl = new \curl();
        $curl->setHeader(['Accept: application/json', 'Authorization: Bearer ' . $apikey]);
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 120,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ]);

        $postdata = [
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'quizId' => strval($quiz->id),
            'file' => new \CURLFile($file['tmp_name'], $mimetype, $filename),
        ];

        $body = $curl->post($apibase . '/api/reference-docs', $postdata);

        $info = $curl->get_info();
        $httpcode = isset($info['http_code']) ? $info['http_code'] : 0;

        if ($body === false || $httpcode === 0) {
            debugging('AI Grader: document upload failed: ' . $curl->error, DEBUG_DEVELOPER);
            echo json_encode(['ok' => false, 'message' => get_string('connectionfailed', 'quiz_aigrader')]);
            exit;
        }

        $data = json_decode($body, true);

        if (!$data) {
            debugging('AI Grader: upload response could not be decoded (HTTP ' . $httpcode . ')', DEBUG_DEVELOPER);
            echo json_encode(['ok' => false, 'message' => get_string('invalidresponse', 'quiz_aigrader')]);
            exit;
        }

        echo json_encode($data);
        exit;
    }

    // Action: delete document.
    if ($action === 'deletedoc') {
        quiz_aigrader_require_grading($context);

        // Opaque document identifier issued by the remote service. PARAM_ALPHANUMEXT silently
        // strips characters it does not allow rather than rejecting, so an id containing a dot
        // or a slash would be mangled and the wrong document deleted, or none at all. It is
        // only ever url-encoded into a request path, never used in SQL or output.
        // phpcs:ignore moodle.Commenting.InlineComment.NotCapital
        $docid = optional_param('docid', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW - opaque remote id.


        if (!$docid) {
            echo json_encode(['ok' => false, 'message' => get_string('error_missingdocid', 'quiz_aigrader')]);
            exit;
        }

        if (!$siteid || !$apikey) {
            echo json_encode(['ok' => false, 'message' => get_string('notconfigured', 'quiz_aigrader')]);
            exit;
        }

        $url = $apibase . '/api/reference-docs/' . urlencode($docid) .
               '?siteId=' . urlencode($siteid) .
               '&apiKey=' . urlencode($apikey);

        $curl = new \curl();
        $curl->setHeader(['Accept: application/json', 'Authorization: Bearer ' . $apikey]);
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ]);

        $body = $curl->delete($url);

        $info = $curl->get_info();
        $httpcode = isset($info['http_code']) ? $info['http_code'] : 0;

        if ($body === false || $httpcode === 0) {
            debugging('AI Grader: document delete failed: ' . $curl->error, DEBUG_DEVELOPER);
            echo json_encode(['ok' => false, 'message' => get_string('connectionfailed', 'quiz_aigrader')]);
            exit;
        }

        $data = json_decode($body, true);

        if (!$data) {
            debugging('AI Grader: delete response could not be decoded (HTTP ' . $httpcode . ')', DEBUG_DEVELOPER);
            echo json_encode(['ok' => false, 'message' => get_string('invalidresponse', 'quiz_aigrader')]);
            exit;
        }

        echo json_encode($data);
        exit;
    }

    // Action: get settings (extra instructions).
    if ($action === 'getsettings') {
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        $defaultsettings = ['extraInstructions' => '', 'feedbackLanguage' => 'en'];

        if (!$siteid || !$apikey) {
            echo json_encode([
                'ok' => false,
                'settings' => $defaultsettings,
                'message' => get_string('notconfigured', 'quiz_aigrader'),
            ]);
            exit;
        }

        $url = $apibase . '/api/quiz-settings?siteId=' . urlencode($siteid) .
                '&apiKey=' . urlencode($apikey) .
                '&quizId=' . intval($quiz->id);

        $res = quiz_aigrader_fetch($url, $apikey);

        if (!$res['success']) {
            debugging('AI Grader: quiz-settings request failed: ' . $res['error'], DEBUG_DEVELOPER);
            echo json_encode([
                'ok' => false,
                'settings' => $defaultsettings,
                'message' => get_string('connectionfailed', 'quiz_aigrader'),
            ]);
            exit;
        }

        $data = json_decode($res['body'], true);

        if (isset($data['ok']) && $data['ok']) {
            $settings = isset($data['settings']) ? $data['settings'] : [];
            if (!isset($settings['extraInstructions'])) {
                $settings['extraInstructions'] = '';
            }
            if (!isset($settings['feedbackLanguage'])) {
                $settings['feedbackLanguage'] = 'en';
            }
            echo json_encode([
                'ok' => true,
                'settings' => $settings,
            ]);
        } else {
            echo json_encode([
                'ok' => false,
                'settings' => $defaultsettings,
                'message' => get_string('invalidresponse', 'quiz_aigrader'),
            ]);
        }
        exit;
    }

    // Action: save settings (extra instructions).
    if ($action === 'savesettings') {
        quiz_aigrader_require_grading($context);

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        // Free-text AI prompt: may contain quotes, angle brackets or newlines that PARAM_TEXT
        // would strip. It is stored remotely and sent only to the AI API, never rendered as HTML.
        // Free-text marking instructions. They must stay raw: PARAM_TEXT ends in strip_tags(),
        // which silently eats everything from a "<" onwards, so an instruction such as
        // "award 0 if the word count is <200" would be stored mutilated with no error. The
        // value is stored remotely and sent only to the AI service, never rendered as HTML.
        // phpcs:ignore moodle.Commenting.InlineComment.NotCapital
        $extrainstructions = optional_param('extraInstructions', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW - AI prompt text.

        $feedbacklanguage = optional_param('feedbackLanguage', 'en', PARAM_ALPHANUMEXT);

        if (!$siteid || !$apikey) {
            echo json_encode(['ok' => false, 'message' => get_string('notconfigured', 'quiz_aigrader')]);
            exit;
        }

        $payload = [
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'quizId' => intval($quiz->id),
            'extraInstructions' => $extrainstructions,
            'feedbackLanguage' => $feedbacklanguage,
        ];

        $res = quiz_aigrader_fetch($apibase . '/api/quiz-settings', $apikey, true, $payload);

        if (!$res['success']) {
            debugging('AI Grader: quiz-settings save failed: ' . $res['error'], DEBUG_DEVELOPER);
            echo json_encode(['ok' => false, 'message' => get_string('connectionfailed', 'quiz_aigrader')]);
            exit;
        }

        $data = json_decode($res['body'], true);

        if (!$data) {
            debugging('AI Grader: save-settings response could not be decoded', DEBUG_DEVELOPER);
            echo json_encode(['ok' => false, 'message' => get_string('invalidresponse', 'quiz_aigrader')]);
            exit;
        }

        echo json_encode($data);
        exit;
    }

    // Action: get grading time stats.
    if ($action === 'gradingstats') {
        // Statistics are scoped to the course this report is being viewed in. Only a site
        // administrator may widen the scope to another course, otherwise the filter is forced
        // back to the current course so no cross-course data can ever be returned.
        $filtercourseid = optional_param('courseid', 0, PARAM_INT);
        $filtergraderid = optional_param('graderid', 0, PARAM_INT);
        $filterdatefrom = optional_param('datefrom', 0, PARAM_INT);
        $filterdateto = optional_param('dateto', 0, PARAM_INT);

        $canselectcourse = has_capability('moodle/site:config', context_system::instance());
        if (!$canselectcourse || $filtercourseid <= 0) {
            $filtercourseid = (int)$cm->course;
        }

        // Build the SQL filters. The course restriction is always applied.
        $params = ['courseid' => $filtercourseid];
        $where = ['gl.courseid = :courseid'];

        if ($filtergraderid > 0) {
            $where[] = 'gl.graderid = :graderid';
            $params['graderid'] = $filtergraderid;
        }

        if ($filterdatefrom > 0) {
            $where[] = 'gl.timegraded >= :datefrom';
            $params['datefrom'] = $filterdatefrom;
        }

        if ($filterdateto > 0) {
            $where[] = 'gl.timegraded <= :dateto';
            $params['dateto'] = $filterdateto;
        }

        $whereclause = 'WHERE ' . implode(' AND ', $where);

        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false);

        // Get the grading logs ordered by grader and time.
        $sql = "SELECT gl.id, gl.graderid, gl.courseid, gl.quizid, gl.timegraded,
                       c.shortname AS coursename, {$userfields->selects}
                  FROM {quiz_aigrader_grading_logs} gl
                  JOIN {user} u ON u.id = gl.graderid
                  JOIN {course} c ON c.id = gl.courseid
                $whereclause
              ORDER BY gl.graderid, gl.timegraded ASC";

        $logs = $DB->get_records_sql($sql, $params);

        // Group by grader and calculate the time between consecutive gradings.
        $graderlogs = [];

        foreach ($logs as $log) {
            if (!isset($graderlogs[$log->graderid])) {
                $graderlogs[$log->graderid] = [
                    'name' => fullname($log),
                    'logs' => [],
                ];
            }
            $graderlogs[$log->graderid]['logs'][] = $log->timegraded;
        }

        $totalessays = 0;
        $totaltimeseconds = 0;
        $graderstats = [];

        foreach ($graderlogs as $graderid => $graderdata) {
            $times = $graderdata['logs'];
            $count = count($times);
            $totalessays += $count;

            // Session time is the sum of gaps between consecutive gradings.
            $sessiontime = 0;
            for ($i = 1; $i < $count; $i++) {
                $gap = $times[$i] - $times[$i - 1];
                // Cap each gap at 2 minutes (120s) - longer gaps indicate breaks.
                $sessiontime += min($gap, 120);
            }

            $totaltimeseconds += $sessiontime;

            $graderstats[] = [
                'id' => $graderid,
                'name' => $graderdata['name'],
                'essays' => $count,
                'timeSeconds' => $sessiontime,
                'timeFormatted' => quiz_aigrader_format_duration($sessiontime),
                'avgSecondsPerEssay' => $count > 1 ? round($sessiontime / ($count - 1)) : 0,
            ];
        }

        // Get the list of courses with grading data for the filter dropdown. Non-administrators
        // only ever see the course they are working in.
        if ($canselectcourse) {
            $courses = $DB->get_records_sql(
                "SELECT DISTINCT c.id, c.shortname, c.fullname
                   FROM {quiz_aigrader_grading_logs} gl
                   JOIN {course} c ON c.id = gl.courseid
               ORDER BY c.shortname"
            );
        } else {
            $courses = $DB->get_records_sql(
                "SELECT DISTINCT c.id, c.shortname, c.fullname
                   FROM {quiz_aigrader_grading_logs} gl
                   JOIN {course} c ON c.id = gl.courseid
                  WHERE c.id = :courseid
               ORDER BY c.shortname",
                ['courseid' => $filtercourseid]
            );
        }

        // Get the list of graders for the filter dropdown, restricted to the same scope.
        $graderparams = ['courseid' => $filtercourseid];
        $graderwhere = 'WHERE gl.courseid = :courseid';
        $graders = $DB->get_records_sql(
            "SELECT DISTINCT u.id {$userfields->selects}
               FROM {quiz_aigrader_grading_logs} gl
               JOIN {user} u ON u.id = gl.graderid
             $graderwhere
           ORDER BY u.lastname, u.firstname",
            $graderparams
        );

        $graderlist = [];
        foreach ($graders as $g) {
            $graderlist[] = ['id' => $g->id, 'name' => fullname($g)];
        }

        $courselist = [];
        foreach ($courses as $c) {
            $coursecontext = context_course::instance($c->id);
            $courselist[] = [
                'id' => $c->id,
                'shortname' => format_string($c->shortname, true, ['context' => $coursecontext]),
                'fullname' => format_string($c->fullname, true, ['context' => $coursecontext]),
            ];
        }

        // Total time students spent on quiz attempts, capping each attempt at 3 hours to
        // remove outliers. CASE WHEN is used instead of LEAST for cross-database support.
        $atparams = ['at_courseid' => $filtercourseid];
        $atwhere = ["qa.state = 'finished'", "qa.timefinish > 0", "qa.timestart > 0", 'q.course = :at_courseid'];
        if ($filterdatefrom > 0) {
            $atwhere[] = 'qa.timefinish >= :at_datefrom';
            $atparams['at_datefrom'] = $filterdatefrom;
        }
        if ($filterdateto > 0) {
            $atwhere[] = 'qa.timefinish <= :at_dateto';
            $atparams['at_dateto'] = $filterdateto;
        }
        $atwheresql = 'WHERE ' . implode(' AND ', $atwhere);
        $atsql = "SELECT SUM(CASE WHEN (qa.timefinish - qa.timestart) < 10800
                                  THEN (qa.timefinish - qa.timestart)
                                  ELSE 10800 END) AS totaltime
                    FROM {quiz_attempts} qa
                    JOIN {quiz} q ON q.id = qa.quiz
                  $atwheresql";
        $atresult = $DB->get_record_sql($atsql, $atparams);
        $totalstudenttimeseconds = (int)($atresult->totaltime ?? 0);

        echo json_encode([
            'ok' => true,
            'totalEssays' => $totalessays,
            'totalTimeSeconds' => $totaltimeseconds,
            'totalTimeFormatted' => quiz_aigrader_format_duration($totaltimeseconds),
            'avgSecondsPerEssay' => $totalessays > 1 ? round($totaltimeseconds / ($totalessays - 1)) : 0,
            'totalStudentTimeSeconds' => $totalstudenttimeseconds,
            'totalStudentTimeFormatted' => quiz_aigrader_format_duration($totalstudenttimeseconds),
            'graders' => $graderstats,
            'filterOptions' => [
                'courses' => $courselist,
                'graders' => $graderlist,
            ],
        ]);
        exit;
    }

    // Unknown action.
    echo json_encode(['ok' => false, 'message' => get_string('error_unknownaction', 'quiz_aigrader')]);
    exit;
} catch (moodle_exception $e) {
    // Catch Moodle-specific exceptions.
    debugging($e->getMessage(), DEBUG_DEVELOPER);
    echo json_encode([
        'ok' => false,
        'message' => get_string('error_servererror', 'quiz_aigrader'),
        'error_code' => 'moodle_exception',
    ]);
    exit;
} catch (Throwable $e) {
    // Catch all other errors.
    debugging($e->getMessage(), DEBUG_DEVELOPER);
    echo json_encode([
        'ok' => false,
        'message' => get_string('error_servererror', 'quiz_aigrader'),
        'error_code' => 'server_error',
    ]);
    exit;
}
