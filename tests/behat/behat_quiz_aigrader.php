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
 * Behat steps for the AI Essay Grader quiz report.
 *
 * @package    quiz_aigrader
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');

/**
 * Behat steps for the AI Essay Grader quiz report.
 *
 * @package    quiz_aigrader
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_quiz_aigrader extends behat_base {
    /**
     * Convert page names to URLs for steps like 'When I am on the "Quiz 1" "quiz_aigrader > Report" page'.
     *
     * Recognised page names are:
     * | Report | The AI Essay Grader marking queue of the quiz with that name or idnumber. |
     *
     * @param string $type The page type, for example "Report".
     * @param string $identifier The quiz name or idnumber.
     * @return moodle_url The URL of that page.
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch (strtolower($type)) {
            case 'report':
                $cm = $this->get_cm_by_quiz_name($identifier);
                return new moodle_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'aigrader']);
            default:
                throw new Exception('Unrecognised quiz_aigrader page type "' . $type . '."');
        }
    }

    /**
     * Find a quiz course module by name or idnumber.
     *
     * @param string $identifier The quiz name or idnumber.
     * @return stdClass The course module.
     */
    protected function get_cm_by_quiz_name(string $identifier): stdClass {
        global $DB;
        $quiz = $DB->get_record('quiz', ['name' => $identifier]);
        if (!$quiz) {
            $cm = $DB->get_record('course_modules', ['idnumber' => $identifier], '*', MUST_EXIST);
            return get_coursemodule_from_id('quiz', $cm->id, 0, false, MUST_EXIST);
        }
        return get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
    }
}
