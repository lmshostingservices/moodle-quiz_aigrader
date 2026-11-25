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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_aigrader\external\approve_mark::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_aigrader\local\approval::class)]
/**
 * End-to-end tests for approving a mark through the web service (5.2.0).
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\external\approve_mark
 * @covers     \quiz_aigrader\local\approval
 */
final class approve_mark_test extends \externallib_advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $quiz;
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass */
    private $assessor;
    /** @var \stdClass */
    private $learnera;
    /** @var \stdClass */
    private $learnerb;

    /**
     * A quiz with one 4-mark essay, in a course using separate groups.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $this->teacher = $gen->create_and_enrol($this->course, 'editingteacher');
        $this->assessor = $gen->create_and_enrol($this->course, 'teacher');
        $this->learnera = $gen->create_and_enrol($this->course, 'student');
        $this->learnerb = $gen->create_and_enrol($this->course, 'student');
        $ga = $gen->create_group(['courseid' => $this->course->id]);
        $gb = $gen->create_group(['courseid' => $this->course->id]);
        $gen->create_group_member(['groupid' => $ga->id, 'userid' => $this->assessor->id]);
        $gen->create_group_member(['groupid' => $ga->id, 'userid' => $this->learnera->id]);
        $gen->create_group_member(['groupid' => $gb->id, 'userid' => $this->learnerb->id]);

        $quizgen = $gen->get_plugin_generator('mod_quiz');
        $this->quiz = $quizgen->create_instance(['course' => $this->course->id, 'grade' => 100, 'sumgrades' => 4,
            'groupmode' => SEPARATEGROUPS]);
        $qgen = $gen->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category();
        $essay = $qgen->create_question('essay', 'editor', ['category' => $cat->id, 'defaultmark' => 4]);
        quiz_add_quiz_question($essay->id, $this->quiz, 0, 4);
    }

    /**
     * Start an attempt for a learner, and optionally submit it.
     *
     * @param \stdClass $learner The learner.
     * @param bool $finish Whether to submit it.
     * @return \stdClass The quiz_attempts row.
     */
    private function attempt(\stdClass $learner, bool $finish = true): \stdClass {
        global $DB;
        $this->setUser($learner);
        $quizgen = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attempt = $quizgen->create_attempt($this->quiz->id, $learner->id);
        $quizgen->submit_responses($attempt->id, [1 => 'Consultation means sharing information.'], false, $finish);
        return $DB->get_record('quiz_attempts', ['id' => $attempt->id]);
    }

    /**
     * Call the web service as it is called from the browser.
     *
     * @param \stdClass $attempt The attempt.
     * @param string $mark The mark.
     * @return array The cleaned result.
     */
    private function approve(\stdClass $attempt, string $mark): array {
        $result = approve_mark::execute($this->quiz->cmid, (int)$attempt->uniqueid, 1, $mark, '<p>Well done.</p>');
        return external_api::clean_returnvalue(approve_mark::execute_returns(), $result);
    }

    /**
     * An approved mark reaches the attempt, the gradebook and the decision record.
     *
     * @return void
     */
    public function test_mark_reaches_the_gradebook(): void {
        global $DB;
        $attempt = $this->attempt($this->learnera);
        $this->setUser($this->teacher);

        $result = $this->approve($attempt, '3');

        $this->assertTrue($result['ok']);
        $this->assertEquals(3, $result['mark']);
        $this->assertTrue($result['gradebookverified']);
        $this->assertEquals(3, $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attempt->id]));
        $grades = grade_get_grades($this->course->id, 'mod', 'quiz', $this->quiz->id, $this->learnera->id);
        $this->assertEquals(75, (float)$grades->items[0]->grades[$this->learnera->id]->grade);
        $decision = $DB->get_record('quiz_aigrader_decisions', ['userid' => $this->learnera->id], '*', MUST_EXIST);
        $this->assertEquals(3, $decision->finalmark);
        $this->assertEquals($this->teacher->id, $decision->graderid);

        // No suggestion was recorded for this response, so the learner is not told AI drafted it.
        $this->assertStringNotContainsString(\quiz_aigrader\local\transparency::NOTICE_CLASS, $decision->finalfeedback);
    }

    /**
     * When the service made a suggestion, the learner is told so, in what they receive and in the record.
     *
     * @return void
     */
    public function test_learner_told_when_ai_suggested(): void {
        global $DB;
        $attempt = $this->attempt($this->learnera);
        $now = time();
        $DB->insert_record('quiz_aigrader_attempt_ctx', (object)[
            'quizid' => $this->quiz->id,
            'userid' => $this->learnera->id,
            'questionid' => \question_engine::load_questions_usage_by_activity($attempt->uniqueid)
                ->get_question_attempt(1)->get_question_id(),
            'slot' => 1,
            'aigrade' => 75,
            'aifeedback' => '<p>Well done.</p>',
            'suggestedtime' => $now - 60,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $this->setUser($this->teacher);

        $this->assertTrue($this->approve($attempt, '3')['ok']);

        $decision = $DB->get_record('quiz_aigrader_decisions', ['userid' => $this->learnera->id], '*', MUST_EXIST);
        $this->assertStringContainsString(\quiz_aigrader\local\transparency::NOTICE_CLASS, $decision->finalfeedback);
        $this->assertStringContainsString(fullname($this->teacher), $decision->finalfeedback);
        $this->assertEquals(0, $decision->markchanged);
        $comment = \question_engine::load_questions_usage_by_activity($attempt->uniqueid)->get_question_attempt(1)
            ->get_manual_comment()[0];
        $this->assertStringContainsString(\quiz_aigrader\local\transparency::NOTICE_CLASS, $comment);
    }

    /**
     * A mark outside the question's scale is refused, not clamped.
     *
     * @return void
     */
    public function test_out_of_range_mark_is_refused(): void {
        $attempt = $this->attempt($this->learnera);
        $this->setUser($this->teacher);
        $result = $this->approve($attempt, '5');
        $this->assertFalse($result['ok']);
    }

    /**
     * An assessor without access to all groups cannot mark a learner in another group.
     *
     * @return void
     */
    public function test_other_group_is_refused(): void {
        global $DB;
        $attempt = $this->attempt($this->learnerb);
        $this->setUser($this->assessor);
        $result = $this->approve($attempt, '2');
        $this->assertFalse($result['ok']);
        $this->assertSame('group_access', $result['error_code']);
        $this->assertFalse($DB->record_exists('quiz_aigrader_decisions', ['userid' => $this->learnerb->id]));
    }

    /**
     * The assessor can mark a learner in their own group.
     *
     * @return void
     */
    public function test_own_group_is_allowed(): void {
        $attempt = $this->attempt($this->learnera);
        $this->setUser($this->assessor);
        $this->assertTrue($this->approve($attempt, '2')['ok']);
    }

    /**
     * An attempt still in progress cannot be marked.
     *
     * @return void
     */
    public function test_unfinished_attempt_is_refused(): void {
        $attempt = $this->attempt($this->learnera, false);
        $this->setUser($this->teacher);
        $result = $this->approve($attempt, '2');
        $this->assertFalse($result['ok']);
        $this->assertSame('attempt_not_finished', $result['error_code']);
    }

    /**
     * A learner cannot approve a mark at all.
     *
     * @return void
     */
    public function test_learner_cannot_approve(): void {
        $attempt = $this->attempt($this->learnera);
        $this->setUser($this->learnera);
        $this->expectException(\required_capability_exception::class);
        $this->approve($attempt, '4');
    }
}
