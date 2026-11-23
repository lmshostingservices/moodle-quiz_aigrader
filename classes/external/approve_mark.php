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

namespace quiz_aigrader\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use quiz_aigrader\local\access;
use quiz_aigrader\local\approval;

/**
 * Web service: approve an assessor's mark for one essay response.
 *
 * The AMD module calls this through core/ajax, so the session key, the login and the
 * parameter types are checked by Moodle's external API before any of the plugin's own checks.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class approve_mark extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Quiz course module id'),
            'qubaid' => new external_value(PARAM_INT, 'Question usage id of the attempt'),
            'slot' => new external_value(PARAM_INT, 'Slot of the essay question'),
            'mark' => new external_value(PARAM_TEXT, 'The absolute mark chosen'),
            'feedbackhtml' => new external_value(PARAM_RAW, 'Feedback for the learner, stored as FORMAT_HTML', VALUE_DEFAULT, ''),
            'policymode' => new external_value(PARAM_ALPHA, 'Full-marks feedback policy applied', VALUE_DEFAULT, ''),
            'criteria' => new external_value(PARAM_RAW, 'Per-criterion scores as JSON', VALUE_DEFAULT, ''),
            'acknowledgement' => new external_value(PARAM_TEXT, 'Reason, when the attempt needs one', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Approve the mark.
     *
     * @param int $cmid Quiz course module id.
     * @param int $qubaid Question usage id.
     * @param int $slot Slot.
     * @param string $mark Absolute mark.
     * @param string $feedbackhtml Feedback HTML.
     * @param string $policymode Full-marks policy.
     * @param string $criteria Per-criterion scores as JSON.
     * @param string $acknowledgement Reason for a flagged attempt.
     * @return array The result.
     */
    public static function execute(
        int $cmid,
        int $qubaid,
        int $slot,
        string $mark,
        string $feedbackhtml = '',
        string $policymode = '',
        string $criteria = '',
        string $acknowledgement = ''
    ): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'cmid',
            'qubaid',
            'slot',
            'mark',
            'feedbackhtml',
            'policymode',
            'criteria',
            'acknowledgement'
        ));

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/quiz:viewreports', $context);
        require_capability('quiz/aigrader:approve', $context);

        $attempt = $DB->get_record('quiz_attempts', ['uniqueid' => $params['qubaid']]);
        if (!$attempt || (int)$attempt->quiz !== (int)$cm->instance) {
            return ['ok' => false, 'message' => get_string('error_invalidcoursemodule', 'quiz_aigrader'),
                'error_code' => 'invalid_attempt'];
        }
        $problem = access::attempt_problem($attempt, $cm, $context);
        if ($problem !== null) {
            return ['ok' => false, 'message' => get_string(access::message_id($problem), 'quiz_aigrader'),
                'error_code' => $problem];
        }

        $result = approval::approve(
            $cm,
            $context,
            $attempt,
            $params['qubaid'],
            $params['slot'],
            $params['mark'],
            null,
            $params['feedbackhtml'],
            $params['acknowledgement'],
            $params['policymode'],
            $params['criteria']
        );
        // Only the described keys go back; nulls are left out so optional fields stay optional.
        return array_filter(array_intersect_key($result, self::execute_returns()->keys), function ($v) {
            return $v !== null;
        });
    }

    /**
     * Describe the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the mark was saved'),
            'message' => new external_value(PARAM_TEXT, 'Why not, when it was not', VALUE_OPTIONAL),
            'error_code' => new external_value(PARAM_ALPHANUMEXT, 'Machine-readable reason', VALUE_OPTIONAL),
            'needsacknowledgement' => new external_value(PARAM_BOOL, 'A reason is needed', VALUE_OPTIONAL),
            'retryafter' => new external_value(PARAM_INT, 'Seconds until approval is allowed', VALUE_OPTIONAL),
            'mark' => new external_value(PARAM_FLOAT, 'The mark saved', VALUE_OPTIONAL),
            'maxmark' => new external_value(PARAM_FLOAT, 'The question mark', VALUE_OPTIONAL),
            'gradedtime' => new external_value(PARAM_INT, 'When it was saved', VALUE_OPTIONAL),
            'gradedhuman' => new external_value(PARAM_TEXT, 'When it was saved, for display', VALUE_OPTIONAL),
            'notificationsent' => new external_value(PARAM_BOOL, 'Whether the learner was notified', VALUE_OPTIONAL),
            'gradebookverified' => new external_value(PARAM_BOOL, 'Whether the gradebook was read back', VALUE_OPTIONAL),
            'gradebookwarning' => new external_value(PARAM_TEXT, 'Why the gradebook was not verified', VALUE_OPTIONAL),
        ]);
    }
}
