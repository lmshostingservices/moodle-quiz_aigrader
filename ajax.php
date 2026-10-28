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
 * Build the JSON body for a server-side failure.
 *
 * Returns the same generic message to the browser in every case, adding the underlying
 * reason only where developer debugging is switched on, so nothing about the server is
 * disclosed on a production site.
 *
 * @param Throwable $e The failure being reported.
 * @param string $errorcode Short machine-readable code identifying where it happened.
 * @return array The response body, ready to json_encode().
 */
function quiz_aigrader_error_response(Throwable $e, string $errorcode): array {
    global $CFG;

    $response = [
        'ok' => false,
        'message' => get_string('error_servererror', 'quiz_aigrader'),
        'error_code' => $errorcode,
    ];

    // On a site running developer debugging, return the real reason as well. Without this
    // every server-side fault reaches the browser as the same generic sentence, which reads
    // like a network problem and sends the investigation in the wrong direction. Guarded so
    // nothing about the server is ever disclosed on a production site.
    if (!empty($CFG->debugdeveloper)) {
        $response['debuginfo'] = $e->getMessage();
        if ($e instanceof moodle_exception && !empty($e->debuginfo)) {
            $response['debuginfo'] .= ' | ' . $e->debuginfo;
        }
    }

    return $response;
}

/**
 * Require the plugin's grading capability, answering in JSON rather than throwing.
 *
 * require_capability() raises an exception, which the file-level handler turns into a
 * generic server error - the user is told nothing useful and the button looks broken.
 * Every write action routes through here instead, so a permissions problem always names
 * the capability an administrator needs to grant, and all the write actions share one rule.
 *
 * @param context $context Module context the request is acting in.
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
                $question = \quiz_aigrader\local\formatter::html_to_text($qa->get_question()->questiontext);

                // Extract graderinfo (assessor instructions, rubric and sample answers).
                // This is the "Information for graders" field in Moodle essay questions and
                // contains marking criteria the AI must use for consistent grading.
                $questionobj = $qa->get_question();
                $graderinfo = '';
                if (isset($questionobj->graderinfo) && !empty($questionobj->graderinfo)) {
                    $graderinfo = \quiz_aigrader\local\formatter::html_to_text($questionobj->graderinfo);
                }
                // Also try generalfeedback as a fallback (some question types use this for rubrics).
                $generalfeedback = '';
                if (isset($questionobj->generalfeedback) && !empty($questionobj->generalfeedback)) {
                    $generalfeedback = \quiz_aigrader\local\formatter::html_to_text($questionobj->generalfeedback);
                }
                // The three fields as stored, for finding the criteria. Read from the raw HTML
                // so a marking guide written as a table keeps its rows.
                $rawgraderinfo = isset($questionobj->graderinfo) ? (string)$questionobj->graderinfo : '';
                $rawquestiontext = (string)$questionobj->questiontext;
                $rawgeneralfeedback = isset($questionobj->generalfeedback) ? (string)$questionobj->generalfeedback : '';

                // Clean answer text: preserve line breaks so the AI can analyse structure.
                $rawanswer = (string)$qa->get_response_summary();
                $answer = \quiz_aigrader\local\formatter::html_to_text($rawanswer);

                // Refuse to grade an attachment-only response.
                //
                // get_response_summary() returns the FILE NAMES for an attachment response,
                // not its contents, so until 4.4.0 the grading service was handed the string
                // "evidence.pdf" and asked to assess it - and returned a mark and feedback
                // for it. Marking a learner on a filename is an assessment validity failure,
                // and a silent one. The assessor is told to read and mark it by hand.
                $responsetext = '';
                $attachmentcount = 0;
                foreach ($qa->get_last_qt_data() as $responsekey => $responsevalue) {
                    if ($responsekey === 'answer') {
                        $responsetext = \quiz_aigrader\local\formatter::html_to_text((string)$responsevalue);
                    } else if ($responsekey === 'attachments' && !empty($responsevalue)) {
                        $attachmentcount = 1;
                    }
                }
                if ($responsetext === '' && $attachmentcount > 0) {
                    echo json_encode([
                        'ok' => false,
                        'message' => get_string('error_attachmentonly', 'quiz_aigrader'),
                        'error_code' => 'ATTACHMENT_ONLY',
                        'attachmentonly' => true,
                    ]);
                    exit;
                }
                // Prefer the question's own answer field over the rendered summary, which
                // decorates the response for display.
                if ($responsetext !== '') {
                    $answer = $responsetext;
                }
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

                    // Both flags are advisory from 4.4.0 and deliberately do NOT short-circuit
                    // grading.
                    //
                    // Until 4.3.0 an attempt carrying either flag returned here without the
                    // response ever being assessed: the auto-lock branch returned full marks
                    // for an answer nobody had read, and the human-review branch returned a
                    // mark derived from a DIFFERENT attempt's percentage, preselected in the
                    // assessor's dropdown behind a one-click approve. A learner on attempt 4
                    // could submit a single character and be presented as full marks. Neither
                    // is defensible in a competency-based assessment, and neither survives an
                    // audit that asks what evidence supports the mark.
                    //
                    // Every response is now assessed on its own merits. The flags travel to
                    // the UI as context for the assessor - what was established on earlier
                    // attempts, and whether this decision needs explicit acknowledgement -
                    // and the human-review flag is enforced on approve, not here.
                }
            }

            // The criteria this response is marked against, from wherever the question's
            // author put them: Information for graders, then the question text, then General
            // feedback. Until 5.0.4 only the first was read, so a question with its criteria
            // in the question text was marked with no criteria at all. See criteria::resolve().
            $resolved = \quiz_aigrader\local\criteria::for_fields(
                $rawgraderinfo,
                $rawquestiontext,
                $rawgeneralfeedback,
                (float)$maxmark,
                isset($questionobj->defaultmark) ? (float)$questionobj->defaultmark : 0.0
            );
            // What was read is a proposal. Unless the site has switched confirmation off, the
            // breakdown uses only criteria an assessor confirmed for this question in the
            // Criteria check panel; see confirmed::apply().
            $confirmstate = \quiz_aigrader\local\confirmed::state(
                (int)$quiz->id,
                (int)$questionid,
                \quiz_aigrader\local\confirmed::fields_hash(
                    $rawgraderinfo,
                    $rawquestiontext,
                    $rawgeneralfeedback,
                    isset($questionobj->defaultmark) ? (float)$questionobj->defaultmark : 0.0
                ),
                (float)$maxmark
            );
            $resolved = \quiz_aigrader\local\confirmed::apply($resolved, $confirmstate);
            $unconfirmed = in_array(
                $resolved['confirmation'],
                [\quiz_aigrader\local\confirmed::STATE_REQUIRED, \quiz_aigrader\local\confirmed::STATE_STALE],
                true
            );
            //
            // Three outcomes. A rubric read with certainty is used as written ('rubric'). Marking
            // guidance that cannot be read with certainty goes to the service to be condensed
            // into criteria, which are then checked against the guidance ('extract'). With no
            // guidance at all, the site's policy decides: derive criteria from the question
            // ('infer'), one overall mark ('holistic'), or mark by hand.
            $rubriccriteria = $resolved['criteria'];
            $criterialimits = \quiz_aigrader\local\criteria::limits();
            $criteriapolicy = \quiz_aigrader\local\criteria::policy();
            if ($resolved['confidence'] === \quiz_aigrader\local\criteria::CERTAIN && !$unconfirmed) {
                $criteriamode = 'rubric';
            } else if ($unconfirmed) {
                // Criteria nobody has confirmed never drive the breakdown: one overall mark.
                $criteriamode = 'holistic';
            } else if ($resolved['confidence'] === \quiz_aigrader\local\criteria::UNCERTAIN) {
                $criteriamode = 'extract';
            } else if ($criteriapolicy === \quiz_aigrader\local\criteria::POLICY_INFER) {
                $criteriamode = 'infer';
            } else {
                $criteriamode = 'holistic';
            }

            // A site that marks only against written criteria: no suggestion, and no charge,
            // for a question with no marking guidance. The assessor marks it by hand.
            if ($unconfirmed && $criteriapolicy === \quiz_aigrader\local\criteria::POLICY_MANUAL) {
                // A site that marks only against criteria: these are not confirmed yet.
                echo json_encode([
                    'ok' => false,
                    'message' => get_string(
                        $resolved['confirmation'] === \quiz_aigrader\local\confirmed::STATE_STALE
                            ? 'error_criteriastale' : 'error_criteriaunconfirmed',
                        'quiz_aigrader'
                    ),
                    'error_code' => 'NO_CRITERIA',
                    'nocriteria' => true,
                ]);
                exit;
            }
            if (
                $resolved['confidence'] === \quiz_aigrader\local\criteria::NONE
                && $criteriapolicy === \quiz_aigrader\local\criteria::POLICY_MANUAL
            ) {
                echo json_encode([
                    'ok' => false,
                    'message' => get_string('error_nocriteria', 'quiz_aigrader'),
                    'error_code' => 'NO_CRITERIA',
                    'nocriteria' => true,
                ]);
                exit;
            }

            // Language: prefer the explicit parameter, fall back to the user's Moodle language.
            $language = optional_param('language', '', PARAM_ALPHANUMEXT);
            if (empty($language)) {
                // Auto-detect from the Moodle user's current language setting and convert
                // Moodle language codes to the standard format (e.g. 'en_au' -> 'en-AU').
                $language = str_replace('_', '-', current_language());
            }

            // Whether this is a re-grade, and so free, is decided by the server.
            //
            // The browser used to send this flag and it was passed straight through to the
            // billing call, so anyone holding the approve capability could request free
            // grading indefinitely by sending regrade=1. A re-grade is a second or later
            // suggestion for the same response: the attempt context already holds a
            // suggestion recorded against this same attempt number. A new attempt is new
            // work and is charged.
            $regrade = ($attemptcontext
                && $attemptcontext->aigrade !== null
                && (int)$attemptcontext->attemptnum === $attemptnum);

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
                // The rubric split into discrete criteria. The assessor writes them as a
                // list in "Information for graders"; until 4.4.0 strip_tags() ran that list
                // into a single sentence before it was sent, so there was nothing for the
                // service to score against line by line.
                'rubricCriteria' => $rubriccriteria,
                // Where those criteria came from, what they add up to, and how to mark:
                // 'rubric' (mark against rubricCriteria), 'extract' (restate the assessor's
                // guidance in criteriaSource as criteria first, see criteriaInstruction; the
                // plugin's own reading is in criteriaCandidates, as a hint only), 'infer'
                // (derive criteria from the question first) or 'holistic' (one overall mark).
                'criteriaSource' => $resolved['source'],
                // Nothing nobody confirmed is sent as criteria: an unconfirmed question is
                // marked overall against its guidance, not against the plugin's proposal.
                'criteriaCandidates' => $unconfirmed ? [] : array_map(function ($c) {
                    return ['label' => $c['label'], 'detail' => $c['detail'], 'max' => $c['max']];
                }, $resolved['candidates']),
                'rubricTotal' => $resolved['total'],
                'criteriaMode' => $criteriamode,
                'criteriaLimits' => $criterialimits,
                // What we want back per criterion. The service does not have to honour
                // this yet - the plugin renders the assessor's criteria either way and
                // the assessor scores them - but when it does, the numbers and the
                // per-criterion feedback arrive together.
                'criteriaResponseFormat' => [
                    'awarded' => 'number, 0 to the criterion max',
                    'comment' => 'what the learner did on this criterion',
                    'improve' => 'what would earn the remaining marks, omitted at full marks',
                ],
                // The question's own constraints, so the service can mark to them.
                'wordLimits' => [
                    'min' => isset($questionobj->minwordlimit) ? (int)$questionobj->minwordlimit : null,
                    'max' => isset($questionobj->maxwordlimit) ? (int)$questionobj->maxwordlimit : null,
                ],
                'generalFeedback' => $generalfeedback,
                'attemptContext' => [
                    'attemptNumber' => $attemptnum,
                    'previouslyMetCriteria' => $previouscriteriamet,
                    'previousFeedbackSummary' => $previousfeedbacksummary,
                ],
            ];

            if ($criteriamode === 'infer') {
                $payload['criteriaInstruction'] = \quiz_aigrader\local\criteria::inference_instruction(
                    $criterialimits,
                    (float)$maxmark
                );
            } else if ($criteriamode === 'extract') {
                $payload['criteriaInstruction'] = \quiz_aigrader\local\criteria::extraction_instruction(
                    $criterialimits,
                    (float)$maxmark,
                    $resolved['source']
                );
            }

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
            $score = isset($data['score']) ? $data['score'] : \quiz_aigrader\local\formatter::score_label(0, $maxmark);
            $parts = explode('/', $score);
            $num = isset($parts[0]) ? floatval($parts[0]) : 0;
            $den = isset($parts[1]) ? floatval($parts[1]) : $maxmark;
            // Calculate the percentage and scale it to the actual max mark.
            $grade100 = $den ? round(($num / $den) * 100) : 0;
            $scaled = round(($grade100 / 100) * $maxmark, 2);

            // Handle the case where the AI returns feedback as an object instead of a string.
            //
            // When it does, the structure is kept as well as flattened. The browser's section
            // detection is a set of English phrase patterns, so on a site using any of the
            // other feedback languages the plugin offers it recognises no headings at all:
            // the full-marks policy then filters nothing and the whole card layout silently
            // falls back to flat text. Structured sections are language-independent, so they
            // are used whenever the service provides them and the patterns are the fallback.
            $feedbacksections = [];
            $sectiontypemap = [
                'strengths' => 'success', 'what_you_did_well' => 'success', 'whatyoudidwell' => 'success',
                'good' => 'success', 'positives' => 'success',
                'improvements' => 'warning', 'areas_for_improvement' => 'warning',
                'areasforimprovement' => 'warning', 'needs_improvement' => 'warning', 'gaps' => 'warning',
                'suggestions' => 'info', 'next_steps' => 'info', 'nextsteps' => 'info',
                'recommendations' => 'info', 'tips' => 'info',
            ];

            $feedbacktext = isset($data['feedback']) ? $data['feedback'] : '';
            if (is_array($feedbacktext) || is_object($feedbacktext)) {
                foreach ((array)$feedbacktext as $sectionkey => $sectionvalue) {
                    $normalised = strtolower(str_replace([' ', '-'], '_', (string)$sectionkey));
                    $feedbacksections[] = [
                        'key' => $normalised,
                        'type' => $sectiontypemap[$normalised] ?? 'info',
                        'heading' => ucwords(str_replace('_', ' ', (string)$sectionkey)),
                        'items' => is_array($sectionvalue)
                            ? array_values(array_map('strval', $sectionvalue))
                            : [(string)$sectionvalue],
                    ];
                }

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
            $validcriteria = \quiz_aigrader\local\criteria::VENDOR_IDS;

            // If the API returns allowedCriteria for this question, use it as the filter so
            // validation is tighter per question.
            $questioncriteria = isset($data['allowedCriteria']) && is_array($data['allowedCriteria'])
                ? array_values(array_intersect($data['allowedCriteria'], $validcriteria))
                : $validcriteria;

            // Log rejected criteria IDs for audit. Stored internally, never shown to students.
            $rawcriteriamet = $newcriteriamet;
            $rejectedcriteria = array_values(array_diff($rawcriteriamet, $questioncriteria));

            if (!empty($rejectedcriteria)) {
                debugging(
                    'AI Grader: Rejected invalid criteria IDs: '
                        . implode(', ', $rejectedcriteria)
                        . ' for question ' . $questionid . ' user ' . $studentid,
                    DEBUG_DEVELOPER
                );
            }

            // Filter down to only the valid criteria for this question.
            $newcriteriamet = array_values(array_intersect($newcriteriamet, $questioncriteria));

            // If the API did not return structured criteria, try to derive a summary from the feedback.
            if (empty($newcriteriamet) && !empty($feedbacktext)) {
                if (empty($newfeedbacksummary)) {
                    $newfeedbacksummary = \quiz_aigrader\local\formatter::summarise(
                        \quiz_aigrader\local\formatter::html_to_text($feedbacktext)
                    );
                }
            }

            // Clamp feedbackSummary to 300 characters to prevent prompt bloat.
            // core_text::substr, not substr: a byte-based cut lands mid-character on any
            // multibyte script and sends a broken final character to the grading service.
            $newfeedbacksummary = core_text::substr($newfeedbacksummary, 0, 300);

            // Criteria met across all attempts so far, reported to the assessor as history.
            //
            // Releases up to 4.3.0 used this count to force the mark to 100% whenever three
            // or more criteria had accumulated and the service's own total was no higher.
            // That awarded full marks on THIS attempt's response for criteria demonstrated on
            // earlier ones, so a thin answer could carry full marks with nothing in the record
            // to explain it, and the threshold of three was a number with no basis in any
            // RTO's assessment strategy. The cumulative record is kept, because carrying
            // demonstrated competency forward is legitimate practice; silently converting it
            // into a mark is not. The assessor decides, with the history in front of them.
            $mergedcriteriacount = count(array_unique(array_merge($previouscriteriamet, $newcriteriamet)));
            $apireportedtotal = isset($data['totalCriteria']) ? (int)$data['totalCriteria'] : 0;
            $allcriteriademonstrated = ($apireportedtotal > 0 && $mergedcriteriacount >= $apireportedtotal);

            // Competency and review signals.
            //
            // The thresholds are site settings rather than the hard-coded 3, 4 and 5 of
            // earlier releases, and both default to off. A threshold that the vendor picked
            // is not a defensible basis for a competency decision; one the RTO sets to match
            // its own assessment strategy is.
            $competencythreshold = (int)get_config('quiz_aigrader', 'competency_attempt_threshold');
            $reviewthreshold = (int)get_config('quiz_aigrader', 'humanreview_attempt_threshold');

            $newautolocked = false;
            $newhumanreview = false;

            // Competency established: every criterion the service recognises for this question
            // has now been demonstrated across the attempt sequence. Advisory only - it shows
            // the assessor what has been established, and never sets or forces a mark.
            if ($competencythreshold > 0 && $attemptnum >= $competencythreshold && $allcriteriademonstrated) {
                $newautolocked = true;
            }

            // Human review: the learner has reached the configured attempt threshold, or has
            // made no measurable progress on an extended attempt sequence. Approving under
            // this flag requires the assessor to acknowledge it and state a reason, which is
            // stored on the decision record.
            if ($reviewthreshold > 0 && $attemptnum >= $reviewthreshold) {
                $newhumanreview = true;
            }
            if (
                $reviewthreshold > 0 && $attemptnum >= max(1, $reviewthreshold - 1)
                    && $previousgrade !== null && $grade100 <= $previousgrade
            ) {
                $newhumanreview = true;
            }

            // Criteria met across this and earlier attempts. Set here so the response below has
            // it even when there is no learner record to merge with.
            $mergedcriteria = array_values(array_unique(array_merge($previouscriteriamet, $newcriteriamet)));

            // Store the attempt context for the next grading run. Wrapped in try/catch: if the
            // attempt_ctx table is missing (pre-upgrade or failed install) grading still
            // succeeds, because attempt context is non-critical metadata.
            if ($studentid > 0) {
                try {
                    $now = time();

                    // Merge newly met criteria with previously met criteria (criteria persist once met).
                    $mergedcriteria = array_unique(array_merge($previouscriteriamet, $newcriteriamet));

                    // What the AI said, recorded as the AI's opinion.
                    //
                    // lastgrade, criteriamet and feedbacksummary are NOT written here. Until
                    // 4.3.0 they were, which meant the consistency chain every later attempt
                    // was graded against - and the "previous attempt" panel the next assessor
                    // read - was built entirely from AI suggestions, with every human
                    // correction discarded. A learner marked down by an assessor was told on
                    // their next attempt that those criteria were already satisfied. Those
                    // three columns are now written by the approve action, from the decision
                    // the assessor actually made.
                    if ($attemptcontext) {
                        $attemptcontext->attemptnum = $attemptnum;
                        $attemptcontext->aigrade = $grade100;
                        $attemptcontext->aicriteriamet = json_encode(array_values($mergedcriteria));
                        $attemptcontext->aifeedback = $feedbacktext;
                        $attemptcontext->suggestedtime = $now;
                        $attemptcontext->autolocked = $newautolocked ? 1 : 0;
                        $attemptcontext->humanreview = $newhumanreview ? 1 : 0;
                        $attemptcontext->timemodified = $now;
                        $DB->update_record('quiz_aigrader_attempt_ctx', $attemptcontext);
                    } else {
                        $newrecord = new stdClass();
                        $newrecord->quizid = $quiz->id;
                        $newrecord->userid = $studentid;
                        $newrecord->questionid = $questionid;
                        $newrecord->slot = $slot;
                        $newrecord->attemptnum = $attemptnum;
                        $newrecord->criteriamet = null;
                        $newrecord->feedbacksummary = null;
                        $newrecord->lastgrade = null;
                        $newrecord->aigrade = $grade100;
                        $newrecord->aicriteriamet = json_encode(array_values($mergedcriteria));
                        $newrecord->aifeedback = $feedbacktext;
                        $newrecord->suggestedtime = $now;
                        $newrecord->autolocked = $newautolocked ? 1 : 0;
                        $newrecord->humanreview = $newhumanreview ? 1 : 0;
                        $newrecord->timecreated = $now;
                        $newrecord->timemodified = $now;
                        $DB->insert_record('quiz_aigrader_attempt_ctx', $newrecord);
                    }
                } catch (dml_write_exception $e) {
                    // Two concurrent suggests for the same slot both found no row and both
                    // inserted; the unique index rejected this one. Retry as an update rather
                    // than losing the context, which is what swallowing this did before.
                    try {
                        $existing = $DB->get_record('quiz_aigrader_attempt_ctx', [
                            'quizid' => $quiz->id,
                            'userid' => $studentid,
                            'questionid' => $questionid,
                            'slot' => $slot,
                        ]);
                        if ($existing) {
                            $existing->attemptnum = $attemptnum;
                            $existing->aigrade = $grade100;
                            $existing->aicriteriamet = json_encode(array_values($mergedcriteria));
                            $existing->aifeedback = $feedbacktext;
                            $existing->suggestedtime = time();
                            $existing->autolocked = $newautolocked ? 1 : 0;
                            $existing->humanreview = $newhumanreview ? 1 : 0;
                            $existing->timemodified = time();
                            $DB->update_record('quiz_aigrader_attempt_ctx', $existing);
                        }
                    } catch (Exception $retryerror) {
                        debugging('AI Grader: attempt_ctx retry failed: ' . $retryerror->getMessage(), DEBUG_DEVELOPER);
                    }
                } catch (Exception $e) {
                    // Attempt context save failed - log it but do not abort grading. This happens
                    // if the quiz_aigrader_attempt_ctx table does not exist yet (for example the
                    // plugin upgrade has not run). The grading result is still returned below.
                    debugging('AI Grader: attempt_ctx write failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            // The criteria the assessor scores and the learner sees.
            //
            // A certain rubric: the question's own criteria, with the service's scores attached
            // and scaled to the assessor's weights. Condensed or derived criteria: the service's
            // set, accepted only if it is well formed, within the recommended range, and - for
            // condensed guidance - found in the assessor's own text. In every other case no
            // breakdown at all: one overall mark. Until 5.0.4 a question without criteria fell
            // back to a fixed list of fifteen vendor identifiers, most shown at zero.
            $servicecriteria = isset($data['criteria']) && is_array($data['criteria']) ? $data['criteria'] : [];
            $criteriawarnings = $resolved['warnings'];
            $criteriasource = $resolved['source'];
            $displaycriteria = null;
            if ($criteriamode === 'rubric') {
                $displaycriteria = \quiz_aigrader\local\criteria::merge_scores(
                    $rubriccriteria,
                    $servicecriteria,
                    $newcriteriamet
                );
            } else if ($criteriamode === 'extract' || $criteriamode === 'infer') {
                $reference = $criteriamode === 'extract'
                    ? $resolved['guidancetext']
                    : \quiz_aigrader\local\formatter::rubric_text($rawquestiontext);
                $accepted = \quiz_aigrader\local\criteria::from_service(
                    $servicecriteria,
                    $criterialimits,
                    (float)$maxmark,
                    $criteriamode,
                    $reference,
                    $criteriamode === 'extract' ? $resolved['candidates'] : []
                );
                if (!empty($accepted)) {
                    $displaycriteria = $accepted;
                    $criteriasource = $criteriamode === 'extract'
                        ? \quiz_aigrader\local\criteria::SOURCE_EXTRACTED
                        : \quiz_aigrader\local\criteria::SOURCE_INFERRED;
                } else {
                    $criteriawarnings[] = [
                        'code' => $criteriamode === 'extract' ? 'extract_unavailable' : 'inferred_unavailable',
                        'a' => null,
                    ];
                    $criteriasource = \quiz_aigrader\local\criteria::SOURCE_NONE;
                }
            } else {
                $criteriasource = \quiz_aigrader\local\criteria::SOURCE_NONE;
            }

            // The breakdown must agree with the mark it sits beside. The card opens on the service's
            // overall mark; a breakdown implying a different one is either the service's own
            // inconsistency (condensed or derived criteria: refused, one overall mark) or worth
            // the assessor's attention (the question's own criteria: shown, with a warning).
            if (!empty($displaycriteria) && (float)$maxmark > 0) {
                $sumawarded = 0.0;
                $summax = 0.0;
                foreach ($displaycriteria as $c) {
                    $sumawarded += (float)$c['awarded'];
                    $summax += (float)$c['max'];
                }
                $implied = $summax > 0 ? round($sumawarded / $summax * (float)$maxmark, 2) : 0.0;
                // More than ten percentage points apart.
                if (abs($implied - (float)$scaled) / (float)$maxmark > 0.10 + 0.0001) {
                    if ($criteriamode === 'rubric') {
                        $criteriawarnings[] = ['code' => 'breakdown_mismatch', 'a' => (object)[
                            'breakdown' => \quiz_aigrader\local\criteria::fmt($implied),
                            'suggested' => \quiz_aigrader\local\criteria::fmt((float)$scaled),
                            'maxmark' => \quiz_aigrader\local\criteria::fmt((float)$maxmark),
                        ]];
                    } else {
                        $displaycriteria = null;
                        $criteriasource = \quiz_aigrader\local\criteria::SOURCE_NONE;
                        $criteriawarnings[] = [
                            'code' => $criteriamode === 'extract' ? 'extract_unavailable' : 'inferred_unavailable',
                            'a' => null,
                        ];
                    }
                }
            }

            // What this suggestion was measured against, kept server-side and tied to this exact
            // response, so the decision record shows the criteria the assessor was shown - even
            // if the question is edited before it is approved. Never taken from the browser.
            $shown = !empty($displaycriteria) ? $displaycriteria : [];
            if ($studentid > 0) {
                try {
                    $DB->set_field(
                        'quiz_aigrader_attempt_ctx',
                        'aicriteria',
                        json_encode([
                            'qubaid' => (int)$qubaid,
                            'slot' => (int)$slot,
                            'attempt' => (int)$attemptnum,
                            'mode' => $criteriamode,
                            'source' => $criteriasource,
                            'fingerprint' => !empty($shown)
                                ? \quiz_aigrader\local\criteria::fingerprint(['source' => $criteriasource, 'criteria' => $shown])
                                : null,
                            'criteria' => array_map(function ($c) {
                                return ['label' => $c['label'], 'max' => $c['max']];
                            }, $shown),
                        ], JSON_UNESCAPED_UNICODE),
                        ['quizid' => $quiz->id, 'userid' => $studentid, 'questionid' => $questionid, 'slot' => $slot]
                    );
                } catch (Exception $e) {
                    debugging('AI Grader: suggestion criteria not stored: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }

            // The line on the card that says what the mark is measured against.
            if ($resolved['confirmation'] === \quiz_aigrader\local\confirmed::STATE_CONFIRMED && !empty($displaycriteria)) {
                $confirmer = core_user::get_user($resolved['confirmedby']);
                $criteriacontext = get_string('criteria_from_confirmed', 'quiz_aigrader', (object)[
                    'name' => $confirmer ? fullname($confirmer) : get_string('unknownuser'),
                    'date' => userdate($resolved['timeconfirmed'], get_string('strftimedatefullshort', 'langconfig')),
                ]);
            } else if ($unconfirmed) {
                $criteriacontext = get_string(
                    $resolved['confirmation'] === \quiz_aigrader\local\confirmed::STATE_STALE
                        ? 'criteria_stale_holistic' : 'criteria_unconfirmed_holistic',
                    'quiz_aigrader'
                );
            } else {
                $criteriacontext = \quiz_aigrader\local\criteria::context_line(
                    $criteriasource,
                    $resolved['source'],
                    $resolved['confidence'] === \quiz_aigrader\local\criteria::UNCERTAIN
                );
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
                // Carried so the approve action can record what the AI suggested alongside
                // what the assessor decided, and so the two can be compared in the record.
                'aigrade100' => $grade100,
                'aimark' => $scaled,
                'criteriamet' => array_values($mergedcriteria),
                'criteriatotal' => $apireportedtotal,
                // Every criterion in force for this question. The question's own criteria only:
                // the vendor identifiers above validate what the service reports and are never
                // shown to anyone.
                'criteriaall' => !empty($rubriccriteria) ? array_column($rubriccriteria, 'key') : [],
                // The assessor's own criteria. The card lists these whether or not the
                // grading service scored them, so what a learner is marked against is always
                // the rubric their assessor wrote.
                'rubriccriteria' => !empty($rubriccriteria) ? $rubriccriteria : null,
                // Per-criterion scores, when the grading service provides them, merged onto
                // the assessor's labels so the two can never disagree about what was marked.
                'criteria' => $displaycriteria,
                // Where the criteria came from, shown on the card so the assessor knows what
                // the mark is measured against, and anything the assessor should check.
                'criteriasource' => $criteriasource,
                'criteriamode' => $criteriamode,
                'criteriarubrictotal' => $resolved['total'],
                // Unconfirmed, the card says so in its context line; how the guidance was read is
                // for the Marking criteria check, not the card.
                'criteriawarnings' => $unconfirmed ? [] : \quiz_aigrader\local\criteria::warning_messages($criteriawarnings),
                'criteriacontext' => $criteriacontext,
                // Whether this question's criteria are confirmed, so the card can say so.
                'criteriaconfirmation' => $resolved['confirmation'],
                'suggestedtime' => time(),
                'modelversion' => isset($data['modelVersion']) ? (string)$data['modelVersion'] : null,
                'requestid' => isset($data['requestId']) ? (string)$data['requestId'] : null,
                // Shown as a pill beside the feedback, so an assessor who does not read
                // the language can see at a glance which one they are approving.
                'languagelabel' => \quiz_aigrader\local\formatter::language_label($language),
                // Present only when the grading service returned structured feedback. The
                // browser prefers this over pattern-matching the prose.
                'feedbacksections' => !empty($feedbacksections) ? $feedbacksections : null,
            ];

            // Advisory flags. Neither changes the mark.
            //
            // Until 4.3.0 the competency flag rewrote grade, grade100, scaledmark and score
            // to full marks here, so the assessor was shown 100% preselected for a response
            // the service had graded lower. The notices are prepended to the feedback for the
            // assessor to read; the mark they are shown is the mark this response earned.
            if ($newautolocked) {
                $response['autolocked'] = true;
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

        // The assessor's chosen mark, as an absolute mark.
        //
        // Releases up to 4.3.0 sent an integer percentage and reconstructed the mark from it,
        // which cannot represent 2/3 or 5/7: a selected 2/3 was stored as 2.01 and 5/7 as
        // 4.97, so the gradebook did not hold the mark the assessor picked. grade100 is still
        // accepted so that a browser holding a cached copy of the previous AMD build keeps
        // working until its cache clears, but it is never the preferred source.
        // PARAM_TEXT, not raw: the value is an absolute mark and is rejected below
        // unless it is numeric. The empty default is what distinguishes "not supplied"
        // from a deliberate zero, which a numeric param type could not express.
        $marksupplied = optional_param('mark', '', PARAM_TEXT);
        $legacygrade100 = optional_param('grade100', null, PARAM_FLOAT);
        // Rich HTML feedback produced by the grader UI. It must arrive raw because param
        // cleaning would strip the markup. It is NOT passed through clean_text() here:
        // clean_text() removes the inline SVG icons the feedback cards are built from, and
        // the value is stored as FORMAT_HTML, so Moodle sanitises it through format_text()
        // every time it is rendered.
        // phpcs:ignore moodle.Commenting.InlineComment.NotCapital
        $feedbacktext = optional_param('feedbackhtml', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW - HTML feedback.
        $gradelabel = optional_param('gradelabel', '', PARAM_TEXT);
        // The assessor's stated reason, required only when approving under a human-review
        // flag. Its presence is never taken as proof the flag applied - the server decides
        // that from its own stored context.
        $acknowledgement = optional_param('acknowledgement', '', PARAM_TEXT);
        // Which full-marks feedback policy the browser applied, recorded on the decision so
        // the delivered feedback can be explained later.
        $policymode = optional_param('policymode', '', PARAM_ALPHA);
        // The assessor's per-criterion scores, as JSON. Recorded verbatim on the decision:
        // this is the breakdown behind the mark and it is the thing an auditor asks to see.
        $criteriajson = optional_param('criteria', '', PARAM_RAW_TRIMMED); // pipeline-ignore: PARAM_RAW - JSON blob.

        try {
            $usage = question_engine::load_questions_usage_by_activity($qubaid);
            if (!in_array((int)$slot, array_map('intval', $usage->get_slots()), true)) {
                echo json_encode(['ok' => false, 'message' => get_string('error_invalidslot', 'quiz_aigrader')]);
                exit;
            }
            $qa = $usage->get_question_attempt($slot);

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
            } catch (Throwable $criteriaerror) {
                debugging('AI Grader: criteria could not be resolved for the record: '
                    . $criteriaerror->getMessage(), DEBUG_DEVELOPER);
            }

            if ($marksupplied !== '') {
                if (!is_numeric($marksupplied)) {
                    echo json_encode([
                        'ok' => false,
                        'message' => get_string('error_markinvalid', 'quiz_aigrader'),
                    ]);
                    exit;
                }
                $mark = round((float)$marksupplied, 2);
            } else if ($legacygrade100 !== null) {
                $mark = round(($maxmark * $legacygrade100) / 100, 2);
            } else {
                echo json_encode([
                    'ok' => false,
                    'message' => get_string('error_missingparams', 'quiz_aigrader'),
                ]);
                exit;
            }

            // Reject an out-of-range mark rather than clamping it. A mark outside the
            // question's scale means the request did not come from the grading UI, and
            // quietly storing a clamped value would hide that from the assessor and from
            // the decision record.
            if ($mark < 0 || $mark > $maxmark) {
                echo json_encode([
                    'ok' => false,
                    'message' => get_string('error_markoutofrange', 'quiz_aigrader', (object)[
                        'mark' => format_float($mark, -1),
                        'maxmark' => format_float($maxmark, -1),
                    ]),
                ]);
                exit;
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
            } catch (Exception $ctxerror) {
                debugging('AI Grader: attempt_ctx read failed: ' . $ctxerror->getMessage(), DEBUG_DEVELOPER);
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
                    echo json_encode([
                        'ok' => false,
                        'message' => get_string('review_too_soon', 'quiz_aigrader', (object)[
                            'required' => $minreviewtime,
                            'actual' => $reviewseconds,
                        ]),
                        'error_code' => 'REVIEW_TOO_SOON',
                        'retryafter' => $minreviewtime - $reviewseconds,
                    ]);
                    exit;
                }
            }

            // Human review: refuse the approval until the assessor acknowledges it and says
            // why. Previously this flag was written as a literal 0, never read, and surfaced
            // only as a sentence in a textarea the assessor could delete.
            $underhumanreview = $approvectx ? (bool)$approvectx->humanreview : false;
            if ($underhumanreview && trim($acknowledgement) === '') {
                echo json_encode([
                    'ok' => false,
                    'message' => get_string('humanreview_acknowledgement_required', 'quiz_aigrader'),
                    'error_code' => 'ACKNOWLEDGEMENT_REQUIRED',
                    'needsacknowledgement' => true,
                ]);
                exit;
            }

            // Calculate the score label using the actual max mark.
            // number_format(), not format_float(): this string is parsed back with floatval()
            // elsewhere, and format_float() would use the site's decimal separator.
            $scorelabel = \quiz_aigrader\local\formatter::score_label($mark, $maxmark);

            // Save the grade and feedback into the question engine.
            $qa->manual_grade($feedbacktext, $mark, FORMAT_HTML);
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
                        'AI Grader: could not acquire sumgrades lock: '
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
                        'AI Grader could not log the manual grading event: '
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
                    $decision->finalfeedback = $feedbacktext;
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
                                debugging('AI Grader: posted criteria do not match the suggestion; not recorded.', DEBUG_DEVELOPER);
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
                } catch (Exception $decisionerror) {
                    // The mark is already committed, so a failure to write the record must not
                    // abort the response. It is logged loudly because the record is the point.
                    debugging(
                        'AI Grader: decision record write failed: '
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
                } catch (Exception $ctxwriteerror) {
                    debugging(
                        'AI Grader: attempt_ctx approve write failed: '
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
    if ($action === 'confirmcriteria' || $action === 'resetcriteria') {
        // Confirming a question's criteria sets what every response to it is marked against, in
        // every group: setting the marking scheme, not marking. It has its own capability.
        if (!has_capability('quiz/aigrader:confirmcriteria', $context)) {
            echo json_encode([
                'ok' => false,
                'message' => get_string('confirm_error_capability', 'quiz_aigrader'),
                'error_code' => 'no_confirm_capability',
            ]);
            exit;
        }

        $questionid = required_param('questionid', PARAM_INT);
        $qmaxmark = round(required_param('maxmark', PARAM_FLOAT), 2);
        // When the assessor opened the editor: the confirmation they were looking at, or 0.
        $since = optional_param('since', 0, PARAM_INT);
        $bulk = optional_param('bulk', 0, PARAM_BOOL);
        $quizid = (int)$cm->instance;

        // The question must have been attempted in this quiz at this mark; the criteria must
        // add up to it. A question drawn into random slots has a confirmation for each mark.
        $fields = \quiz_aigrader\local\confirmed::quiz_has_mark($quizid, $questionid, $qmaxmark)
            ? \quiz_aigrader\local\confirmed::question_fields($questionid)
            : null;
        if (!$fields) {
            echo json_encode([
                'ok' => false,
                'message' => get_string('confirm_error_question', 'quiz_aigrader'),
                'error_code' => 'invalid_question',
            ]);
            exit;
        }

        // Someone else confirmed or withdrew these criteria after this assessor opened them:
        // refuse, rather than silently replace their work.
        $existing = \quiz_aigrader\local\confirmed::get($quizid, $questionid, $qmaxmark);
        $current = $existing ? (int)$existing->timeconfirmed : 0;
        if ($current !== $since) {
            echo json_encode([
                'ok' => false,
                'message' => get_string('confirm_error_changed', 'quiz_aigrader'),
                'error_code' => 'changed',
            ]);
            exit;
        }

        if ($action === 'resetcriteria') {
            \quiz_aigrader\local\confirmed::reset($quizid, $questionid, $qmaxmark);
            echo json_encode(['ok' => true, 'state' => \quiz_aigrader\local\confirmed::STATE_REQUIRED, 'since' => 0]);
            exit;
        }

        // The criteria as JSON, checked field by field in validate().
        $submitted = json_decode(required_param('criteria', PARAM_RAW), true); // pipeline-ignore: PARAM_RAW - JSON blob.
        [$clean, $errors] = \quiz_aigrader\local\confirmed::validate($submitted, $qmaxmark);
        if ($errors) {
            echo json_encode([
                'ok' => false,
                'message' => implode(' ', array_map(function ($code) use ($qmaxmark) {
                    return get_string($code, 'quiz_aigrader', \quiz_aigrader\local\criteria::fmt($qmaxmark));
                }, $errors)),
                'error_code' => 'invalid_criteria',
                'errors' => $errors,
            ]);
            exit;
        }

        $proposed = \quiz_aigrader\local\criteria::for_fields(
            $fields->graderinfo,
            $fields->questiontext,
            $fields->generalfeedback,
            $qmaxmark,
            $fields->defaultmark
        );
        try {
            $record = \quiz_aigrader\local\confirmed::save(
                $quizid,
                $questionid,
                $clean,
                $qmaxmark,
                \quiz_aigrader\local\confirmed::fields_hash(
                    $fields->graderinfo,
                    $fields->questiontext,
                    $fields->generalfeedback,
                    $fields->defaultmark
                ),
                (string)$proposed['source'],
                (int)$USER->id,
                (bool)$bulk
            );
        } catch (dml_write_exception $e) {
            // Two assessors confirming the same question at the same moment: one wins.
            echo json_encode([
                'ok' => false,
                'message' => get_string('confirm_error_changed', 'quiz_aigrader'),
                'error_code' => 'changed',
            ]);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'state' => \quiz_aigrader\local\confirmed::STATE_CONFIRMED,
            'since' => (int)$record->timeconfirmed,
            'criteria' => array_map(function ($c) {
                return ['label' => $c['label'], 'max' => $c['max']];
            }, $clean),
            'confirmedtext' => get_string($bulk ? 'criteriacheck_bulkby' : 'criteriacheck_confirmedby', 'quiz_aigrader', (object)[
                'name' => fullname($USER),
                'date' => userdate($record->timeconfirmed, get_string('strftimedatefullshort', 'langconfig')),
            ]),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

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
        // A setting that only hides the button is not a setting. An administrator who
        // turns reference documents off means documents must not leave the site, so the
        // upload is refused here as well.
        if (!get_config('quiz_aigrader', 'allow_reference_documents')) {
            echo json_encode([
                'ok' => false,
                'message' => get_string('error_documentsdisabled', 'quiz_aigrader'),
            ]);
            exit;
        }

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
        // The comma is required: get_sql() is called with $leadingcomma = false, so the
        // selects string does not carry one of its own.
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
                // Divided by the number of essays, not by the number of gaps between them.
                // Dividing by count-1 reported a two-essay session at double the true
                // average. The first essay's time is not measurable by this method - there is
                // no earlier approval to measure from - so this figure understates slightly
                // on short sessions rather than overstating on all of them.
                'avgSecondsPerEssay' => $count > 0 ? round($sessiontime / $count) : 0,
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
            "SELECT DISTINCT u.id, {$userfields->selects}
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
            'avgSecondsPerEssay' => $totalessays > 0 ? round($totaltimeseconds / $totalessays) : 0,
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
    echo json_encode(quiz_aigrader_error_response($e, 'moodle_exception'));
    exit;
} catch (Throwable $e) {
    // Catch all other errors.
    debugging($e->getMessage(), DEBUG_DEVELOPER);
    echo json_encode(quiz_aigrader_error_response($e, 'server_error'));
    exit;
}
