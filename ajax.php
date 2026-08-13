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
 * quiz_aigrader file.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

// CRITICAL: Define AJAX_SCRIPT before ANYTHING else
define('AJAX_SCRIPT', true);

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');

// Disable Moodle's error display - we'll handle errors ourselves
@ini_set('display_errors', '0');

try {
    require_once(__DIR__ . '/../../../../config.php');
    require_login(); // AJAX_SCRIPT=true ensures JSON error response, not a redirect.
    require_once($CFG->dirroot . '/mod/quiz/lib.php');
    require_once($CFG->libdir . '/questionlib.php');
    require_once($CFG->libdir . '/filelib.php');

    global $DB, $USER;

    // Manual sesskey validation (doesn't redirect on failure)
    $sesskey = optional_param('sesskey', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — sesskey is a cryptographic token; any cleaning would corrupt it and break confirm_sesskey()
    if (!confirm_sesskey($sesskey)) {
        echo json_encode([
            'ok' => false,
            'message' => 'Session expired. Please refresh the page.',
            'error_code' => 'invalid_sesskey'
        ]);
        exit;
    }

    // Get params with defaults
    $cmid   = optional_param('cmid', 0, PARAM_INT);
    $action = optional_param('action', '', PARAM_ALPHA);

    if (!$cmid) {
        echo json_encode(['ok' => false, 'message' => 'Missing course module ID']);
        exit;
    }

    if (!$action) {
        echo json_encode(['ok' => false, 'message' => 'Missing action parameter']);
        exit;
    }

    // Optional params
    $qubaid = optional_param('qubaid', 0, PARAM_INT);
    $slot   = optional_param('slot', 0, PARAM_INT);

    // Validate course module exists
    $cm = get_coursemodule_from_id('quiz', $cmid, 0, false);
    if (!$cm) {
        echo json_encode(['ok' => false, 'message' => 'Invalid course module']);
        exit;
    }

    // Check user is logged in
    if (!isloggedin() || isguestuser()) {
        echo json_encode([
            'ok' => false,
            'message' => 'Please log in to use AI Essay Grader',
            'error_code' => 'not_logged_in'
        ]);
        exit;
    }

    // Check capability without throwing exception
    $context = context_module::instance($cm->id);
    if (!has_capability('mod/quiz:viewreports', $context)) {
        echo json_encode([
            'ok' => false,
            'message' => 'You do not have permission to view quiz reports',
            'error_code' => 'no_capability'
        ]);
        exit;
    }

    // Explicitly include aiconfig lib.php if available
    $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
    if (file_exists($aiconfiglib)) {
        require_once($aiconfiglib);
    }
    
    // Priority 1: Central Config (recommended for multi-plugin setups)
    $siteid = '';
    $apikey = '';
    if (function_exists('local_aiconfig_get_siteid')) {
        $siteid = trim(local_aiconfig_get_siteid() ?? '');
    }
    if (function_exists('local_aiconfig_get_apikey')) {
        $apikey = trim(local_aiconfig_get_apikey() ?? '');
    }
    
    // Priority 2: Plugin settings as fallback
    if (empty($siteid)) {
        $siteid = trim(get_config('quiz_aigrader', 'siteid') ?? '');
    }
    if (empty($apikey)) {
        $apikey = trim(get_config('quiz_aigrader', 'apikey') ?? '');
    }

    /**
     * Safe HTTP request using Moodle's curl wrapper.
     * Works regardless of allow_url_fopen setting.
     */
    // Release session lock before long-running API calls to prevent blocking other requests.
    \core\session\manager::write_close();

    function aigrader_fetch($url, $post = false, $payload = null) {
        $curl = new \curl();
        // Use 90s timeout for grading (OpenAI can take 60s+ for complex essays)
        $timeout = $post ? 90 : 30;
        $curl->setopt([
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_FOLLOWLOCATION' => true,
        ]);

        if ($post && $payload) {
            $curl->setHeader(['Content-Type: application/json']);
            $body = $curl->post($url, json_encode($payload));
        } else {
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
                'httpcode' => $httpcode
            ];
        }

        return [
            'success' => true,
            'body' => $body,
            'error' => null,
            'httpcode' => $httpcode
        ];
    }

    /* ============================================================
       ACTION: GET CREDITS
       ============================================================ */
    if ($action === 'credits_status' || $action === 'creditsstatus') {

        if (!$siteid || !$apikey) {
            echo json_encode([
                'ok' => false,
                'credits' => null,
                'message' => 'Plugin is not configured. Please set Site ID and API Key in plugin settings.',
                'debug' => [
                    'siteid_set' => !empty($siteid),
                    'apikey_set' => !empty($apikey),
                    'apikey_length' => strlen($apikey)
                ]
            ]);
            exit;
        }

        $url = 'https://lms-labs.com/api/credits?siteId=' .
                urlencode($siteid) . '&apiKey=' . urlencode($apikey);

        $res = aigrader_fetch($url);

        if (!$res['success']) {
            echo json_encode([
                'ok' => false,
                'credits' => null,
                'message' => 'Connection to API failed',
                'debug' => [
                    'error' => $res['error'],
                    'httpcode' => $res['httpcode']
                ]
            ]);
            exit;
        }

        $data = json_decode($res['body'], true);

        if (!$data) {
            echo json_encode([
                'ok' => false,
                'credits' => null,
                'message' => 'Invalid API response',
                'debug' => [
                    'httpcode' => $res['httpcode'],
                    'body_preview' => substr($res['body'], 0, 200)
                ]
            ]);
            exit;
        }

        if (isset($data['error'])) {
            echo json_encode([
                'ok' => false,
                'credits' => null,
                'message' => $data['error'],
                'debug' => [
                    'httpcode' => $res['httpcode'],
                    'apikey_length' => strlen($apikey),
                    'siteid' => $siteid
                ]
            ]);
            exit;
        }

        // Check for unlimited credits first (returned as string "unlimited" or isUnlimited flag)
        $isUnlimited = false;
        if (isset($data['isUnlimited']) && $data['isUnlimited'] === true) {
            $isUnlimited = true;
        } elseif (isset($data['credits']) && $data['credits'] === 'unlimited') {
            $isUnlimited = true;
        }
        
        // Flexible field mapping - use creditsRaw for actual numeric value
        $credits = null;
        if ($isUnlimited) {
            // For unlimited, display "∞" but use -1 internally
            $credits = -1;
        } elseif (isset($data['creditsRaw'])) {
            // Prefer creditsRaw which is always numeric
            $credits = (int)$data['creditsRaw'];
        } elseif (isset($data['credits']) && is_numeric($data['credits'])) {
            $credits = (int)$data['credits'];
        } elseif (isset($data['balance'])) {
            $credits = (int)$data['balance'];
        } elseif (isset($data['creditsRemaining'])) {
            $credits = (int)$data['creditsRemaining'];
        } elseif (isset($data['creditsBalance'])) {
            $credits = (int)$data['creditsBalance'];
        } elseif (isset($data['data']['credits'])) {
            $credits = (int)$data['data']['credits'];
        }

        if ($credits === null) {
            echo json_encode([
                'ok' => false,
                'credits' => null,
                'message' => 'Credits field missing in response',
                'debug' => [
                    'keys_received' => array_keys($data)
                ]
            ]);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'isUnlimited' => $isUnlimited,
            'credits' => $credits,
            'buyurl' => 'https://lms-labs.com/pricing?siteId=' . urlencode($siteid)
        ]);
        exit;
    }

    /* ============================================================
       ACTION: SUGGEST / AI GRADE
       ============================================================ */
    if ($action === 'suggest') {
        // Wrap entire grading action in try/catch to ensure we always return JSON
        try {
            if (!$qubaid || !$slot) {
                echo json_encode(['ok' => false, 'message' => 'Missing qubaid/slot']);
                exit;
            }

            if (!$siteid || !$apikey) {
                echo json_encode(['ok' => false, 'message' => 'Plugin not configured']);
                exit;
            }

            // Load Moodle question data
            try {
            $usage = question_engine::load_questions_usage_by_activity($qubaid);
            $qa = $usage->get_question_attempt($slot);

            // Clean question text: strip HTML tags and decode entities, preserve line breaks
            $rawQuestion = $qa->get_question()->questiontext;
            $question = html_entity_decode(strip_tags($rawQuestion), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $question = preg_replace('/[^\S\n]+/', ' ', $question); // Normalize spaces but keep newlines
            $question = trim($question);
            
            // CRITICAL FIX: Extract graderinfo (assessor instructions/rubric/sample answers)
            // This is the "Information for graders" field in Moodle essay questions
            // Contains marking criteria and sample answers that AI MUST use for consistent grading
            $questionObj = $qa->get_question();
            $graderinfo = '';
            if (isset($questionObj->graderinfo) && !empty($questionObj->graderinfo)) {
                $rawGraderinfo = $questionObj->graderinfo;
                $graderinfo = html_entity_decode(strip_tags($rawGraderinfo), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $graderinfo = preg_replace('/[^\S\n]+/', ' ', $graderinfo);
                $graderinfo = trim($graderinfo);
            }
            // Also try generalfeedback as fallback (some question types use this for rubrics)
            $generalfeedback = '';
            if (isset($questionObj->generalfeedback) && !empty($questionObj->generalfeedback)) {
                $rawGeneralfeedback = $questionObj->generalfeedback;
                $generalfeedback = html_entity_decode(strip_tags($rawGeneralfeedback), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $generalfeedback = preg_replace('/[^\S\n]+/', ' ', $generalfeedback);
                $generalfeedback = trim($generalfeedback);
            }
            
            // Clean answer text: preserve line breaks for AI to analyze structure
            $rawAnswer = $qa->get_response_summary();
            $answer = html_entity_decode(strip_tags($rawAnswer), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $answer = preg_replace('/[^\S\n]+/', ' ', $answer); // Normalize spaces but keep newlines
            $answer = trim($answer);
            $maxmark = $qa->get_max_mark();
            $questionid = $qa->get_question()->id;
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'message' => 'Load error: ' . $e->getMessage()]);
            exit;
        }

        if (trim($answer) === '') {
            echo json_encode(['ok' => false, 'message' => 'No answer submitted']);
            exit;
        }

        // Get quiz ID for server-side settings lookup (ONE API call instead of two)
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        
        // Get the quiz attempt to find the student's user ID and attempt number
        $quizattempt = $DB->get_record('quiz_attempts', ['uniqueid' => $qubaid]);
        $studentid = $quizattempt ? $quizattempt->userid : 0;
        $attemptnum = $quizattempt ? (int)$quizattempt->attempt : 1;
        
        // Get max attempts allowed (0 = unlimited)
        $maxattempts = isset($quiz->attempts) ? (int)$quiz->attempts : 0;
        
        // ============================================================
        // ATTEMPT CONTEXT: Fetch previous attempt history for consistency
        // ============================================================
        $attemptcontext = null;
        $previouscriteriamet = [];
        $previousfeedbacksummary = '';
        $previousgrade = null;
        $autolocked = false;
        $humanreview = false;
        
        if ($studentid > 0) {
            // Look for previous attempt context for this student/question
            // Wrapped in try/catch to handle case where table doesn't exist (pre-upgrade)
            try {
                $attemptcontext = $DB->get_record('quiz_aigrader_attempt_ctx', [
                    'quizid' => $quiz->id,
                    'userid' => $studentid,
                    'questionid' => $questionid,
                    'slot' => $slot
                ]);
            } catch (Exception $e) {
                // Table might not exist yet - continue without attempt context
                $attemptcontext = null;
                debugging('AI Grader: attempt_context table not found, skipping: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            
            if ($attemptcontext) {
                // Decode previously met criteria
                $previouscriteriamet = !empty($attemptcontext->criteriamet) 
                    ? json_decode($attemptcontext->criteriamet, true) 
                    : [];
                $previousfeedbacksummary = $attemptcontext->feedbacksummary ?? '';
                $previousgrade = $attemptcontext->lastgrade;
                $autolocked = (bool)$attemptcontext->autolocked;
                $humanreview = (bool)$attemptcontext->humanreview;
                
                // AUTO-LOCK RULE: If attempt >= 3 and all criteria met, return full marks immediately
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
                            'grade' => $previousgrade
                        ]
                    ]);
                    exit;
                }
                
                // HUMAN REVIEW RULE: If attempt >= 4 and no improvement, flag for human review
                if ($humanreview) {
                    echo json_encode([
                        'ok' => true,
                        'grade' => 'Pending Review',
                        'score' => round($previousgrade / 100 * $maxmark, 1) . '/' . intval($maxmark),
                        'feedback' => get_string('humanreview_feedback', 'quiz_aigrader'),
                        'rubric' => null,
                        'grade100' => $previousgrade,
                        'scaledmark' => round($previousgrade / 100 * $maxmark, 2),
                        'maxmark' => $maxmark,
                        'credits' => null,
                        'humanreview' => true,
                        'attemptnum' => $attemptnum,
                        'maxattempts' => $maxattempts,
                        'previousattempt' => [
                            'feedback' => $previousfeedbacksummary,
                            'grade' => $previousgrade
                        ]
                    ]);
                    exit;
                }
            }
        }

        // Get language parameter - prefer explicit parameter, fallback to user's Moodle language
        $language = optional_param('language', '', PARAM_TEXT);
        if (empty($language)) {
            // Auto-detect from Moodle user's current language setting
            $language = current_language();
            // Convert Moodle language codes to standard format (e.g., 'en_au' -> 'en-AU')
            $language = str_replace('_', '-', $language);
        }
        
        // Check if this is a re-grade (no credit charge)
        $regrade = optional_param('regrade', 0, PARAM_INT);

        // API request - server will fetch extra instructions using quizId
        // Include maxMark so AI grades to the correct scale (not hardcoded 0-3)
        // Include language for multilingual feedback generation
        // Include attempt context for consistent grading across attempts
        // CRITICAL FIX: Include graderinfo (assessor rubric/sample answers) for accurate marking
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
            // CRITICAL: Grader info contains the marking rubric and sample answers
            // AI MUST use this as the authoritative source for marking criteria
            'graderInfo' => $graderinfo,
            'generalFeedback' => $generalfeedback,
            // Attempt context for consistent grading (ChatGPT consistency fix)
            'attemptContext' => [
                'attemptNumber' => $attemptnum,
                'previouslyMetCriteria' => $previouscriteriamet,
                'previousFeedbackSummary' => $previousfeedbacksummary
            ]
        ];

        $res = aigrader_fetch('https://lms-labs.com/api/grade-essay', true, $payload);

        if (!$res['success']) {
            echo json_encode([
                'ok' => false,
                'message' => 'Connection failed',
                'debug' => [
                    'error' => $res['error'],
                    'httpcode' => $res['httpcode']
                ]
            ]);
            exit;
        }

        $data = json_decode($res['body'], true);

        if (!$data) {
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid response from grading API',
                'debug' => [
                    'httpcode' => $res['httpcode'],
                    'body_preview' => substr($res['body'], 0, 200)
                ]
            ]);
            exit;
        }

        // Handle API errors
        if (isset($data['ok']) && !$data['ok']) {
            // Check for insufficient credits (can come as 'error' or 'error_code')
            $errorCode = isset($data['error_code']) ? $data['error_code'] : (isset($data['error']) ? $data['error'] : 'UNKNOWN');
            
            if ($errorCode === 'INSUFFICIENT_CREDITS') {
                echo json_encode([
                    'ok' => false,
                    'error' => 'insufficient_credits',
                    'credits' => isset($data['credits']) ? $data['credits'] : 0,
                    'buyurl' => isset($data['buyUrl']) ? $data['buyUrl'] : 'https://lms-labs.com/pricing'
                ]);
                exit;
            }

            // Pass through detailed error info from API
            $errorResponse = [
                'ok' => false,
                'message' => isset($data['message']) ? $data['message'] : 'Grading failed: ' . $errorCode,
                'error_code' => $errorCode,
            ];
            // Add debug info if available from API
            if (isset($data['details'])) {
                $errorResponse['details'] = $data['details'];
            }
            if (isset($data['response_preview'])) {
                $errorResponse['response_preview'] = $data['response_preview'];
            }
            if (isset($data['timestamp'])) {
                $errorResponse['timestamp'] = $data['timestamp'];
            }
            echo json_encode($errorResponse);
            exit;
        }

        // Build data for output (DO NOT auto-save - teacher must approve first)
        // Score format: "X/Y" where Y matches the question's max mark
        $score = isset($data['score']) ? $data['score'] : '0/' . intval($maxmark);
        $parts = explode('/', $score);
        $num = isset($parts[0]) ? floatval($parts[0]) : 0;
        $den = isset($parts[1]) ? floatval($parts[1]) : $maxmark;
        // Calculate percentage and scale to actual max mark
        $grade100 = $den ? round(($num / $den) * 100) : 0;
        $scaled = round(($grade100 / 100) * $maxmark, 2);

        // Handle case where OpenAI returns feedback as object instead of string
        $feedbacktext = isset($data['feedback']) ? $data['feedback'] : '';
        if (is_array($feedbacktext) || is_object($feedbacktext)) {
            // Convert structured feedback to formatted string
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
        
        // Extract criteria_met and feedback_summary from API response
        $newcriteriamet = isset($data['criteriaMet']) ? $data['criteriaMet'] : [];
        $newfeedbacksummary = isset($data['feedbackSummary']) ? $data['feedbackSummary'] : '';
        
        // ============================================================
        // CHATGPT FIX 1: Protect against empty/malformed criteriaMet
        // Ensure it's always an array - never let malformed data through
        // ============================================================
        if (!is_array($newcriteriamet)) {
            $newcriteriamet = [];
        }
        
        // ============================================================
        // CHATGPT FIX 4: Never let AI invent criteria IDs
        // Validate against known criteria - prevent hallucinated IDs breaking persistence
        // These are the standard RTO competency assessment criteria
        // ============================================================
        $validcriteria = [
            'explain_process', 'example_provided', 'site_specific',
            'hazard_identified', 'control_measure', 'procedure_followed',
            'legislation_referenced', 'workplace_context', 'practical_application',
            'communication_clear', 'documentation_complete', 'safety_awareness',
            'risk_assessment', 'compliance_demonstrated', 'knowledge_applied'
        ];
        
        // ============================================================
        // OPTIONAL POLISH 2: Support question-specific allowedCriteria
        // If API returns allowedCriteria for this question, use it as the filter
        // This makes the validation even tighter per-question
        // ============================================================
        $questioncriteria = isset($data['allowedCriteria']) && is_array($data['allowedCriteria']) 
            ? array_values(array_intersect($data['allowedCriteria'], $validcriteria))
            : $validcriteria;
        
        // ============================================================
        // OPTIONAL POLISH 1: Log rejected criteria IDs for audit
        // Store internally (not shown to students) to diagnose AI drift
        // ============================================================
        $rawcriteriamet = $newcriteriamet;
        $rejectedcriteria = array_values(array_diff($rawcriteriamet, $questioncriteria));
        
        // Log rejected criteria for debugging/audit (only if there are any)
        if (!empty($rejectedcriteria)) {
            debugging('AI Grader: Rejected invalid criteria IDs: ' . implode(', ', $rejectedcriteria) . 
                      ' for question ' . $questionid . ' user ' . $studentid, DEBUG_DEVELOPER);
        }
        
        // Filter to only valid criteria for this question
        $newcriteriamet = array_values(array_intersect($newcriteriamet, $questioncriteria));
        
        // If API didn't return structured criteria, try to parse from feedback
        if (empty($newcriteriamet) && !empty($feedbacktext)) {
            // Extract summary from first 300 chars if not provided
            if (empty($newfeedbacksummary)) {
                $newfeedbacksummary = substr(strip_tags($feedbacktext), 0, 300);
            }
        }
        
        // ============================================================
        // CHATGPT FIX 2: Clamp feedbackSummary to 300 chars
        // Prevents prompt bloat and keeps context stable
        // ============================================================
        $newfeedbacksummary = substr($newfeedbacksummary, 0, 300);
        
        // ============================================================
        // CHATGPT FIX 3: Trust criteria over score
        // If all criteria are met, force grade100 = 100 (competency logic)
        // Criteria > score - this is how competency-based assessment works
        // ============================================================
        $allcriteriacount = count($validcriteria);
        $mergedcriteriacount = count(array_unique(array_merge($previouscriteriamet, $newcriteriamet)));
        
        // If we have a meaningful number of criteria met (at least 3 core ones), trust it
        // This handles cases where AI returns score < 100 but all relevant criteria are met
        if (!empty($newcriteriamet) && $mergedcriteriacount >= 3) {
            // Check if the response indicates all criteria for THIS question are met
            // API should return all criteria IDs relevant to the question
            $apireportedtotal = isset($data['totalCriteria']) ? (int)$data['totalCriteria'] : 0;
            if ($apireportedtotal > 0 && $mergedcriteriacount >= $apireportedtotal) {
                $grade100 = 100;
            }
        }
        
        // ============================================================
        // STORE ATTEMPT CONTEXT: Save for next attempt's consistency
        // ============================================================
        $newautolocked = false;
        $newhumanreview = false;
        
        // AUTO-LOCK RULE: attempt >= 3 AND grade = 100% (all criteria met)
        if ($attemptnum >= 3 && $grade100 >= 100) {
            $newautolocked = true;
        }
        
        // HUMAN REVIEW RULE: attempt >= 4 AND no improvement from previous
        if ($attemptnum >= 4 && $previousgrade !== null && $grade100 <= $previousgrade) {
            $newhumanreview = true;
        }
        
        // ============================================================
        // OPTIONAL POLISH 3: Attempt >= 5 mandatory human review
        // Very ASQA-friendly - ensures extended attempts get human oversight
        // ============================================================
        if ($attemptnum >= 5) {
            $newhumanreview = true;
        }
        
        // Store attempt context for next grading
        // Wrapped in try/catch: if attempt_ctx table is missing (pre-upgrade or failed install),
        // grading still succeeds - attempt context is non-critical metadata.
        if ($studentid > 0) {
            try {
                $now = time();
                
                // Merge newly met criteria with previously met criteria (criteria persist once met)
                $mergedcriteria = array_unique(array_merge($previouscriteriamet, $newcriteriamet));
                
                if ($attemptcontext) {
                    // Update existing record - but DON'T save autolocked/humanreview here
                    // Those flags should only be saved when teacher clicks "Approve"
                    $attemptcontext->attemptnum = $attemptnum;
                    $attemptcontext->criteriamet = json_encode($mergedcriteria);
                    $attemptcontext->feedbacksummary = $newfeedbacksummary;
                    $attemptcontext->lastgrade = $grade100;
                    // DO NOT save autolocked/humanreview during suggest - only on approve
                    $attemptcontext->timemodified = $now;
                    $DB->update_record('quiz_aigrader_attempt_ctx', $attemptcontext);
                } else {
                    // Insert new record - but DON'T set autolocked/humanreview here
                    $newrecord = new stdClass();
                    $newrecord->quizid = $quiz->id;
                    $newrecord->userid = $studentid;
                    $newrecord->questionid = $questionid;
                    $newrecord->slot = $slot;
                    $newrecord->attemptnum = $attemptnum;
                    $newrecord->criteriamet = json_encode($mergedcriteria);
                    $newrecord->feedbacksummary = $newfeedbacksummary;
                    $newrecord->lastgrade = $grade100;
                    // DO NOT save autolocked/humanreview during suggest - only on approve
                    $newrecord->autolocked = 0;
                    $newrecord->humanreview = 0;
                    $newrecord->timecreated = $now;
                    $newrecord->timemodified = $now;
                    $DB->insert_record('quiz_aigrader_attempt_ctx', $newrecord);
                }
            } catch (Exception $e) {
                // Attempt context save failed — log it but do NOT abort grading.
                // This happens if the quiz_aigrader_attempt_ctx table doesn't exist yet
                // (e.g. plugin upgrade hasn't run). Grading result is still returned below.
                debugging('AI Grader: attempt_ctx write failed, skipping: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        
        // Build response with additional context flags
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
                'grade' => $previousgrade
            ]
        ];
        
        // Add flags for UI display
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

        // Return data for teacher review - they will click "Approve" to save
        echo json_encode($response);
        exit;
        
        } catch (Exception $outerException) {
            // Catch any uncaught exception in the grading flow
            echo json_encode([
                'ok' => false,
                'message' => 'Grading error: ' . $outerException->getMessage(),
                'error_code' => 'GRADING_EXCEPTION',
                'debug' => [
                    'file' => $outerException->getFile(),
                    'line' => $outerException->getLine()
                ]
            ]);
            exit;
        } catch (Throwable $outerThrowable) {
            // Catch any fatal errors or throwables
            echo json_encode([
                'ok' => false,
                'message' => 'Fatal error: ' . $outerThrowable->getMessage(),
                'error_code' => 'FATAL_ERROR',
                'debug' => [
                    'file' => $outerThrowable->getFile(),
                    'line' => $outerThrowable->getLine()
                ]
            ]);
            exit;
        }
    }

    /* ============================================================
       ACTION: APPROVE / SAVE GRADE
       ============================================================ */
    if ($action === 'approve') {

        if (!$qubaid || !$slot) {
            echo json_encode(['ok' => false, 'message' => 'Missing qubaid/slot']);
            exit;
        }

        $grade100 = optional_param('grade100', 0, PARAM_FLOAT);
        $feedbacktext = optional_param('feedbackhtml', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — AI-generated HTML feedback; immediately stored in Moodle question engine which handles its own output escaping
        $gradelabel = optional_param('gradelabel', '', PARAM_TEXT);

        try {
            $usage = question_engine::load_questions_usage_by_activity($qubaid);
            $qa = $usage->get_question_attempt($slot);

            $maxmark = $qa->get_max_mark();
            $mark = ($maxmark * $grade100) / 100;

            // Calculate score label using actual max mark (not hardcoded 0-3)
            $scorelabel = round($mark, 1) . '/' . round($maxmark, 1);

            // The JavaScript already formatted the feedback as styled HTML
            // Do NOT use clean_text() - it escapes HTML entities causing double-escaping
            // Just pass the HTML directly - Moodle sanitizes when rendering with FORMAT_HTML

            // Save grade and feedback to Moodle gradebook
            $qa->manual_grade($feedbacktext, $mark, FORMAT_HTML);
            question_engine::save_questions_usage_by_activity($usage);

            // CRITICAL: Update the quiz attempt's total grade
            // Find the quiz attempt that owns this question usage
            $attempt = $DB->get_record('quiz_attempts', ['uniqueid' => $qubaid]);
            if ($attempt) {
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                
                // Recalculate and save the attempt's sumgrades.
                // Delegated transaction ensures the reload + update_record are atomic:
                // if the server crashes mid-write, the attempt row is left consistent
                // (either fully updated or not at all) rather than partially written.
                $sumtransaction = $DB->start_delegated_transaction();
                $usage_reloaded = question_engine::load_questions_usage_by_activity($qubaid);
                $attempt->sumgrades = $usage_reloaded->get_total_mark();
                $DB->update_record('quiz_attempts', $attempt);
                $sumtransaction->allow_commit();
                
                // Update the user's best grade in the gradebook.
                $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
                // Prefer Moodle 4.2+ grade_calculator API; fall back to legacy for older sites.
                // quiz_save_best_grade() was deprecated in MDL-76897 (Moodle 4.2) and triggers
                // a deprecation error even when it still exists. Always use the new API first.
                if (method_exists('\mod_quiz\grade_calculator', 'recompute_final_grade')) {
                    $quizobj = \mod_quiz\quiz_settings::create($quiz->id, $attempt->userid);
                    \mod_quiz\grade_calculator::create($quizobj)->recompute_final_grade();
                } else {
                    quiz_save_best_grade($quiz, $attempt->userid);
                }

                // -------------------------------------------------------
                // COMPLETION ENGINE TRIGGER (v3.9.0 fix)
                // -------------------------------------------------------
                // recompute_final_grade / quiz_save_best_grade update the
                // gradebook row but do NOT notify Moodle's activity-completion
                // subsystem. The completion engine is separate: it only re-runs
                // when update_state() is explicitly called. Without this block
                // the student never appears as a green circle in the course
                // completion report, never appears in the Completion Report
                // table, and "passing grade" completion conditions are never
                // evaluated — even after the teacher clicks Approve & Save.
                //
                // COMPLETION_UNKNOWN forces a full re-evaluation of all
                // completion conditions (grade threshold, view, submit, etc.)
                // rather than hard-coding COMPLETE or INCOMPLETE, so any
                // combination of teacher-configured completion criteria is
                // honoured correctly.
                // -------------------------------------------------------
                try {
                    require_once($CFG->libdir . '/completionlib.php');
                    $completioncourse = $DB->get_record('course', ['id' => $quiz->course], '*', MUST_EXIST);
                    $completion = new completion_info($completioncourse);
                    if ($completion->is_enabled($cm)) {
                        $completion->update_state($cm, COMPLETION_UNKNOWN, $attempt->userid);
                    }
                } catch (\Throwable $completionerr) {
                    // Completion update failure must never abort the approve response —
                    // the grade is already saved. Log for debugging only.
                    debugging('AI Grader: completion update failed: ' . $completionerr->getMessage(), DEBUG_DEVELOPER);
                }

                // Log grading event for time tracking (upsert - update if exists)
                // Wrapped in try/catch to handle case where table doesn't exist (pre-upgrade)
                try {
                    $now = time();
                    $existing = $DB->get_record('quiz_aigrader_grading_logs', [
                        'quizid' => $quiz->id,
                        'qubaid' => $qubaid,
                        'slot' => $slot
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
                    // Table might not exist yet - continue without logging
                    debugging('AI Grader: grading_logs table not found, skipping: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
                
                // Save autolocked/humanreview flags to attempt context (only on approve, not suggest)
                $autolocked = optional_param('autolocked', 0, PARAM_INT);
                $humanreview = optional_param('humanreview', 0, PARAM_INT);
                
                if ($autolocked || $humanreview) {
                    try {
                        $question = $qa->get_question();
                        $questionid = $question->id;
                        $studentid = $attempt->userid;
                        
                        $attemptcontext = $DB->get_record('quiz_aigrader_attempt_ctx', [
                            'quizid' => $quiz->id,
                            'userid' => $studentid,
                            'questionid' => $questionid
                        ]);
                        
                        if ($attemptcontext) {
                            $attemptcontext->autolocked = $autolocked ? 1 : 0;
                            $attemptcontext->humanreview = $humanreview ? 1 : 0;
                            $attemptcontext->timemodified = time();
                            $DB->update_record('quiz_aigrader_attempt_ctx', $attemptcontext);
                        }
                    } catch (Exception $e) {
                        // Table might not exist yet - continue
                        debugging('AI Grader: attempt_context update failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                    }
                }
            }


              // v3.8.1 FIX: Notification fired after the FIRST teacher Approve click.
              //
              // Root cause: The AI pre-grades all essay questions in batch via "AI Suggest".
              // Each AI-graded question moves from needsgrading → graded state immediately,
              // so requires_grading() returns false for ALL questions before any teacher
              // review. The old state-based check therefore set all_questions_graded = true
              // on the very first Approve click (since every other question was already in
              // 'graded' state from the AI). Students then received multiple emails —
              // one per question — instead of a single email when the entire quiz was done.
              //
              // Fix: Switch from question-STATE check to quiz_aigrader_grading_LOGS check.
              // quiz_aigrader_grading_logs gets one row per (qubaid, slot) only when a
              // teacher clicks Approve & Save (inserted/updated at lines above using upsert,
              // so no duplicates). We count how many manually-gradeable slots exist in this
              // usage vs how many have been teacher-logged. Notification fires only when
              // logged_count >= manual_slot_count (i.e. every essay question approved).
              $all_questions_graded = false;
              $notificationsent = false;
              if (isset($usage_reloaded) && isset($attempt)) {
                  try {
                      // Count manually-gradeable slots (question types where is_manual_graded() = true,
                      // e.g. essay). MCQ/truefalse/matching etc. return false and are excluded.
                      $manual_slot_count = 0;
                      foreach ($usage_reloaded->get_slots() as $checkslot) {
                          $checkqa = $usage_reloaded->get_question_attempt($checkslot);
                          if ($checkqa->get_question()->qtype->is_manual_graded()) {
                              $manual_slot_count++;
                          }
                      }

                      if ($manual_slot_count > 0) {
                          // Count teacher-approved slots for this attempt.
                          // Upsert above means no duplicates — one row per slot per attempt.
                          $approved_count = (int) $DB->count_records(
                              'quiz_aigrader_grading_logs',
                              ['qubaid' => $qubaid]
                          );
                          $all_questions_graded = ($approved_count >= $manual_slot_count);
                      }
                  } catch (Exception $checkex) {
                      // Table may not exist on pre-upgrade installs — don't send notification
                      debugging('AI Grader: all-graded check failed: ' . $checkex->getMessage(), DEBUG_DEVELOPER);
                      $all_questions_graded = false;
                  }
              }

              // Send notification to student if enabled and all questions are graded
              if ($all_questions_graded && isset($attempt) && get_config('quiz_aigrader', 'enable_student_notifications')) {
                  try {
                      $student = $DB->get_record('user', ['id' => $attempt->userid]);
                      $course = $DB->get_record('course', ['id' => $quiz->course]);
                      
                      if ($student && $course) {
                          // Build attempt URL for student to view feedback
                          $attempturl = new moodle_url('/mod/quiz/review.php', [
                              'attempt' => $attempt->id
                          ]);
                          
                          // Prepare message data
                          $messagedata = new stdClass();
                          $messagedata->firstname = $student->firstname;
                          $messagedata->quizname = $quiz->name;
                          $messagedata->coursename = $course->fullname;
                          $messagedata->score = $scorelabel;
                          $messagedata->attempturl = $attempturl->out(false);
                          $messagedata->sitename = format_string($GLOBALS['SITE']->fullname);
                          
                          // Create the message
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
                          $message->contexturlname = $quiz->name;
                          
                          // Send the message
                          message_send($message);
                          $notificationsent = true;
                      }
                  } catch (Exception $notifyerror) {
                      // Notification failure should not break the grading response
                      debugging('AI Grader notification error: ' . $notifyerror->getMessage(), DEBUG_DEVELOPER);
                  }
              }

            echo json_encode([
                'ok' => true,
                'gradedtime' => time(),
                'gradedhuman' => userdate(time()),
                'mark' => $mark,
                'maxmark' => $maxmark,
                'notificationsent' => $notificationsent
            ]);
        } catch (\Throwable $e) {
            // Catch both Exception and Error (e.g. removed Moodle functions in newer versions)
            echo json_encode(['ok' => false, 'message' => 'Save error: ' . $e->getMessage()]);
        }

        exit;
    }

    /* ============================================================
       ACTION: LIST DOCUMENTS
       ============================================================ */
    if ($action === 'listdocs') {
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        
        if (!$siteid || !$apikey) {
            echo json_encode(['ok' => false, 'documents' => [], 'message' => 'Plugin not configured']);
            exit;
        }
        
        $url = 'https://lms-labs.com/api/reference-docs?siteId=' .
                urlencode($siteid) . '&apiKey=' . urlencode($apikey) . 
                '&quizId=' . intval($quiz->id);
        
        $res = aigrader_fetch($url);
        
        if (!$res['success']) {
            echo json_encode(['ok' => false, 'documents' => [], 'message' => 'Connection failed']);
            exit;
        }
        
        $data = json_decode($res['body'], true);
        
        if (isset($data['ok']) && $data['ok']) {
            echo json_encode([
                'ok' => true,
                'documents' => isset($data['documents']) ? $data['documents'] : []
            ]);
        } else {
            echo json_encode([
                'ok' => false,
                'documents' => [],
                'message' => isset($data['error']) ? $data['error'] : 'Unknown error'
            ]);
        }
        exit;
    }

    /* ============================================================
       ACTION: UPLOAD DOCUMENT
       ============================================================ */
    if ($action === 'uploaddoc') {
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        
        if (!$siteid || !$apikey) {
            echo json_encode(['ok' => false, 'message' => 'Plugin not configured']);
            exit;
        }
        
        // Check if file was uploaded
        if (empty($_FILES['file'])) {
            echo json_encode(['ok' => false, 'message' => 'No file uploaded']);
            exit;
        }
        
        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'message' => 'Upload error: ' . $file['error']]);
            exit;
        }
        
        // Check file size (10MB limit)
        if ($file['size'] > 10 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'message' => 'File too large (max 10MB)']);
            exit;
        }
        
        // Send file to API using native cURL for reliable multipart uploads
        $ch = curl_init();
        
        $postdata = [
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'quizId' => strval($quiz->id),
            'file' => new \CURLFile($file['tmp_name'], $file['type'], $file['name'])
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://lms-labs.com/api/reference-docs',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postdata,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        
        $body = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        curl_close($ch);
        
        if ($body === false || $httpcode === 0) {
            $errmsg = $curlerror ? $curlerror : 'Connection failed';
            echo json_encode(['ok' => false, 'message' => 'Upload failed: ' . $errmsg]);
            exit;
        }
        
        $data = json_decode($body, true);
        
        if (!$data) {
            echo json_encode(['ok' => false, 'message' => 'Invalid API response (HTTP ' . $httpcode . '): ' . substr($body, 0, 200)]);
            exit;
        }
        
        echo json_encode($data);
        exit;
    }

    /* ============================================================
       ACTION: DELETE DOCUMENT
       ============================================================ */
    if ($action === 'deletedoc') {
        $docid = optional_param('docid', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — opaque document reference ID passed back from the server; may contain URL-safe chars stripped by PARAM_TEXT
        
        if (!$docid) {
            echo json_encode(['ok' => false, 'message' => 'Missing document ID']);
            exit;
        }
        
        if (!$siteid || !$apikey) {
            echo json_encode(['ok' => false, 'message' => 'Plugin not configured']);
            exit;
        }
        
        // Use native cURL for DELETE method (Moodle's curl wrapper doesn't support DELETE properly)
        $url = 'https://lms-labs.com/api/reference-docs/' . urlencode($docid) .
               '?siteId=' . urlencode($siteid) . '&apiKey=' . urlencode($apikey);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        
        $body = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($body === false || $httpcode === 0) {
            echo json_encode(['ok' => false, 'message' => 'Connection failed']);
            exit;
        }
        
        $data = json_decode($body, true);
        
        if (!$data) {
            echo json_encode(['ok' => false, 'message' => 'Invalid API response: ' . substr($body, 0, 100)]);
            exit;
        }
        
        echo json_encode($data);
        exit;
    }

    /* ============================================================
       ACTION: GET SETTINGS (extra instructions)
       ============================================================ */
    if ($action === 'getsettings') {
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        
        if (!$siteid || !$apikey) {
            echo json_encode(['ok' => false, 'settings' => ['extraInstructions' => '', 'feedbackLanguage' => 'en'], 'message' => 'Plugin not configured']);
            exit;
        }
        
        $url = 'https://lms-labs.com/api/quiz-settings?siteId=' .
                urlencode($siteid) . '&apiKey=' . urlencode($apikey) . 
                '&quizId=' . intval($quiz->id);
        
        $res = aigrader_fetch($url);
        
        if (!$res['success']) {
            echo json_encode(['ok' => false, 'settings' => ['extraInstructions' => '', 'feedbackLanguage' => 'en'], 'message' => 'Connection failed']);
            exit;
        }
        
        $data = json_decode($res['body'], true);
        
        if (isset($data['ok']) && $data['ok']) {
            $settings = isset($data['settings']) ? $data['settings'] : [];
            if (!isset($settings['extraInstructions'])) $settings['extraInstructions'] = '';
            if (!isset($settings['feedbackLanguage'])) $settings['feedbackLanguage'] = 'en';
            echo json_encode([
                'ok' => true,
                'settings' => $settings
            ]);
        } else {
            echo json_encode([
                'ok' => false,
                'settings' => ['extraInstructions' => '', 'feedbackLanguage' => 'en'],
                'message' => isset($data['error']) ? $data['error'] : 'Unknown error'
            ]);
        }
        exit;
    }

    /* ============================================================
       ACTION: SAVE SETTINGS (extra instructions)
       ============================================================ */
    if ($action === 'savesettings') {
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $extraInstructions = optional_param('extraInstructions', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — free-text AI prompt; may contain quotes, angle brackets, or newlines that PARAM_TEXT strips; stored in config and sent only to AI API, never rendered as HTML
        $feedbackLanguage = optional_param('feedbackLanguage', 'en', PARAM_TEXT);
        
        if (!$siteid || !$apikey) {
            echo json_encode(['ok' => false, 'message' => 'Plugin not configured']);
            exit;
        }
        
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_POST' => true,
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);
        
        $postdata = json_encode([
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'quizId' => intval($quiz->id),
            'extraInstructions' => $extraInstructions,
            'feedbackLanguage' => $feedbackLanguage
        ]);
        
        $body = $curl->post('https://lms-labs.com/api/quiz-settings', $postdata);
        
        $info = $curl->get_info();
        $httpcode = isset($info['http_code']) ? $info['http_code'] : 0;
        
        if ($body === false || $httpcode === 0) {
            echo json_encode(['ok' => false, 'message' => 'Connection failed']);
            exit;
        }
        
        $data = json_decode($body, true);
        
        if (!$data) {
            echo json_encode(['ok' => false, 'message' => 'Invalid API response']);
            exit;
        }
        
        echo json_encode($data);
        exit;
    }

    /* ============================================================
       ACTION: GET GRADING TIME STATS
       ============================================================ */
    if ($action === 'gradingstats') {
        // Filter parameters
        $filtercourseid = optional_param('courseid', 0, PARAM_INT);
        $filtergraderid = optional_param('graderid', 0, PARAM_INT);
        $filterdatefrom = optional_param('datefrom', 0, PARAM_INT);
        $filterdateto = optional_param('dateto', 0, PARAM_INT);
        
        // Build SQL with filters
        $params = [];
        $where = [];
        
        if ($filtercourseid > 0) {
            $where[] = 'gl.courseid = :courseid';
            $params['courseid'] = $filtercourseid;
        }
        
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
        
        $whereclause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Get grading logs ordered by grader and time
        $sql = "SELECT gl.id, gl.graderid, gl.courseid, gl.quizid, gl.timegraded,
                       u.firstname, u.lastname, c.shortname as coursename
                FROM {quiz_aigrader_grading_logs} gl
                JOIN {user} u ON u.id = gl.graderid
                JOIN {course} c ON c.id = gl.courseid
                $whereclause
                ORDER BY gl.graderid, gl.timegraded ASC";
        
        $logs = $DB->get_records_sql($sql, $params);
        
        // Calculate stats - group by grader and calculate time between consecutive gradings
        $stats = [];
        $graderLogs = [];
        
        foreach ($logs as $log) {
            if (!isset($graderLogs[$log->graderid])) {
                $graderLogs[$log->graderid] = [
                    'name' => fullname($log),
                    'logs' => []
                ];
            }
            $graderLogs[$log->graderid]['logs'][] = $log->timegraded;
        }
        
        $totalEssays = 0;
        $totalTimeSeconds = 0;
        $graderStats = [];
        
        foreach ($graderLogs as $graderid => $data) {
            $times = $data['logs'];
            $count = count($times);
            $totalEssays += $count;
            
            // Calculate session time (time between first and last grading, capped at 10min per gap)
            $sessionTime = 0;
            for ($i = 1; $i < $count; $i++) {
                $gap = $times[$i] - $times[$i - 1];
                // Cap gap at 2 minutes (120s) - longer gaps indicate breaks
                $sessionTime += min($gap, 120);
            }
            
            $totalTimeSeconds += $sessionTime;
            
            $graderStats[] = [
                'id' => $graderid,
                'name' => $data['name'],
                'essays' => $count,
                'timeSeconds' => $sessionTime,
                'timeFormatted' => $sessionTime > 0 ? gmdate('H:i:s', $sessionTime) : '0:00:00',
                'avgSecondsPerEssay' => $count > 1 ? round($sessionTime / ($count - 1)) : 0
            ];
        }
        
        // Get list of courses with grading data for filter dropdown
        $courses = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.shortname, c.fullname
             FROM {quiz_aigrader_grading_logs} gl
             JOIN {course} c ON c.id = gl.courseid
             ORDER BY c.shortname"
        );
        
        // Get list of graders for filter dropdown
        $graders = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
             FROM {quiz_aigrader_grading_logs} gl
             JOIN {user} u ON u.id = gl.graderid
             ORDER BY u.lastname, u.firstname"
        );
        
        $graderList = [];
        foreach ($graders as $g) {
            $graderList[] = ['id' => $g->id, 'name' => fullname($g)];
        }
        
        $courseList = [];
        foreach ($courses as $c) {
            $courseList[] = ['id' => $c->id, 'shortname' => $c->shortname, 'fullname' => $c->fullname];
        }

        // Calculate total time students spent on quiz attempts (cap each attempt at 3 hours to remove outliers)
        $atParams = [];
        $atWhere = ["qa.state = 'finished'", "qa.timefinish > 0", "qa.timestart > 0"];
        if ($filtercourseid > 0) {
            $atWhere[] = 'q.course = :at_courseid';
            $atParams['at_courseid'] = $filtercourseid;
        }
        if ($filterdatefrom > 0) {
            $atWhere[] = 'qa.timefinish >= :at_datefrom';
            $atParams['at_datefrom'] = $filterdatefrom;
        }
        if ($filterdateto > 0) {
            $atWhere[] = 'qa.timefinish <= :at_dateto';
            $atParams['at_dateto'] = $filterdateto;
        }
        $atWhereSql = 'WHERE ' . implode(' AND ', $atWhere);
        $atSql = "SELECT SUM(LEAST(qa.timefinish - qa.timestart, 10800)) AS totaltime
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q ON q.id = qa.quiz
                  $atWhereSql";
        $atResult = $DB->get_record_sql($atSql, $atParams);
        $totalStudentTimeSeconds = (int)($atResult->totaltime ?? 0);

        echo json_encode([
            'ok' => true,
            'totalEssays' => $totalEssays,
            'totalTimeSeconds' => $totalTimeSeconds,
            'totalTimeFormatted' => $totalTimeSeconds > 0 ? gmdate('H:i:s', $totalTimeSeconds) : '0:00:00',
            'avgSecondsPerEssay' => $totalEssays > 1 ? round($totalTimeSeconds / ($totalEssays - 1)) : 0,
            'totalStudentTimeSeconds' => $totalStudentTimeSeconds,
            'totalStudentTimeFormatted' => $totalStudentTimeSeconds > 0 ? gmdate('H:i:s', $totalStudentTimeSeconds) : '0:00:00',
            'graders' => $graderStats,
            'filterOptions' => [
                'courses' => $courseList,
                'graders' => $graderList
            ]
        ]);
        exit;
    }

    /* ============================================================
       UNKNOWN ACTION
       ============================================================ */
    echo json_encode(['ok' => false, 'message' => 'Unknown action: ' . $action]);
    exit;

} catch (moodle_exception $e) {
    // Catch Moodle-specific exceptions
    echo json_encode([
        'ok' => false,
        'message' => 'Moodle error: ' . $e->getMessage(),
        'error_code' => 'moodle_exception'
    ]);
    exit;
} catch (Throwable $e) {
    // Catch all other errors
    echo json_encode([
        'ok' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'error_code' => 'server_error'
    ]);
    exit;
}
