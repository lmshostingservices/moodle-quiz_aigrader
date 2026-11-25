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
     * Point the plugin at the stand-in grading service and remove the review delay.
     *
     * @Given /^the Essay Grader service is simulated$/
     * @return void
     */
    public function the_essay_grader_service_is_simulated(): void {
        set_config('apiurl', \quiz_aigrader\local\service_stub::URL, 'quiz_aigrader');
        set_config('siteid', 'behat-site', 'quiz_aigrader');
        set_config('apikey', \quiz_aigrader\local\service_stub::KEY, 'quiz_aigrader');
        set_config('min_review_time', 0, 'quiz_aigrader');
    }

    /**
     * Make the stand-in service refuse the site's key.
     *
     * @Given /^the Essay Grader service refuses the site key$/
     * @return void
     */
    public function the_essay_grader_service_refuses_the_site_key(): void {
        $this->the_essay_grader_service_is_simulated();
        set_config('apikey', 'not-the-right-key', 'quiz_aigrader');
    }

    /**
     * Pause AI suggestions for the whole site.
     *
     * @Given /^AI suggestions are paused on the site$/
     * @return void
     */
    public function ai_suggestions_are_paused_on_the_site(): void {
        set_config('ai_paused', 1, 'quiz_aigrader');
    }

    /**
     * Open a learner's marking card in the queue and ask for a suggestion.
     *
     * @When /^I ask the Essay Grader to suggest a mark for "(?P<fullname_string>(?:[^"]|\\")*)"$/
     * @param string $fullname The learner's full name as shown in the queue.
     * @return void
     */
    public function i_ask_the_essay_grader_to_suggest_a_mark_for(string $fullname): void {
        $row = $this->row_xpath($fullname);
        $this->execute('behat_general::i_click_on', [$row . "//button[contains(@class,'aigrader-expand-btn')]", 'xpath_element']);
        $this->execute('behat_general::i_click_on', [$row . "//button[contains(@class,'aigrader-grade-btn')]", 'xpath_element']);
    }

    /**
     * The queue rows for one learner.
     *
     * @param string $fullname The learner's full name.
     * @return string An XPath expression.
     */
    protected function row_xpath(string $fullname): string {
        $literal = behat_context_helper::escape($fullname);
        return "//tbody[contains(concat(' ', normalize-space(@class), ' '), ' aigrader-card ')]"
            . "[.//td[contains(@class, 'aigrader-q-student')][normalize-space(.) = $literal]]";
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
