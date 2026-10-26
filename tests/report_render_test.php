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

namespace quiz_aigrader;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/report/aigrader/report.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/questionlib.php');

/**
 * Renders the marking queue against real data and asserts on the markup.
 *
 * Everything else in this suite tests a function in isolation. This renders the page an
 * assessor actually opens, which is where a restructure breaks: the queue became a table
 * of expandable rows in 4.4.1 and the criteria came from the question in 4.5.0, and
 * neither change is visible to a unit test of a helper.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader_report
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_aigrader_report::class)]
final class report_render_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private $course;

    /** @var \stdClass The quiz. */
    private $quiz;

    /** @var \stdClass The course module. */
    private $cm;

    /**
     * A course with a quiz, one essay question carrying a three-part rubric, and one
     * finished student attempt.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $dg = $this->getDataGenerator();
        $this->course = $dg->create_course();
        $this->quiz = $dg->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $this->course->id,
            'grade' => 100.0,
            'sumgrades' => 10,
        ]);

        $qgen = $dg->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category();
        $essay = $qgen->create_question('essay', null, [
            'category' => $cat->id,
            'name' => 'Active listening',
            'questiontext' => [
                'text' => 'Explain how active listening can prevent mistakes. Give one example.',
                'format' => FORMAT_HTML,
            ],
            'graderinfo' => [
                'text' => '<p><strong>Grading criteria (marked out of 10 each):</strong></p><ol>'
                    . '<li>Understanding of the concept: explains what active listening is.</li>'
                    . '<li>Use of a workplace example: gives a specific example.</li>'
                    . '<li>Clarity and structure: a clear, well-ordered answer.</li></ol>',
                'format' => FORMAT_HTML,
            ],
            'defaultmark' => 10,
        ]);
        quiz_add_quiz_question($essay->id, $this->quiz);
        $this->cm = get_coursemodule_from_instance('quiz', $this->quiz->id);

        $student = $dg->create_user(['firstname' => 'Sarah', 'lastname' => 'Mitchell']);
        $dg->enrol_user($student->id, $this->course->id, 'student');
        $this->submit_attempt(
            $student,
            'Active listening means giving the speaker your full attention. In our kitchen, '
                . 'when the head chef calls an order, I repeat it back. Last week a table had '
                . 'a shellfish allergy and repeating it back caught it.'
        );
    }

    /**
     * Start, answer and finish one attempt.
     *
     * @param \stdClass $user The student.
     * @param string $answer What they wrote.
     */
    private function submit_attempt(\stdClass $user, string $answer): void {
        $quizobj = \mod_quiz\quiz_settings::create($this->quiz->id, $user->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $attempt = quiz_create_attempt($quizobj, 1, false, time(), false, $user->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, time());
        quiz_attempt_save_started($quizobj, $quba, $attempt);
        $att = \mod_quiz\quiz_attempt::create($attempt->id);
        $att->process_submitted_actions(time(), false, [
            1 => ['answer' => $answer, 'answerformat' => FORMAT_HTML],
        ]);
        // Moodle 5.1 split process_finish() into submitting the attempt and grading it, and
        // deprecated the old call. Using whichever the running version provides keeps the
        // suite silent on 5.1 and later without dropping 4.4 and 4.5, where only the old
        // one exists: a test that trips debugging() on a supported version is a test that
        // stops telling you anything on that version.
        if (method_exists($att, 'process_submit') && method_exists($att, 'process_grade_submission')) {
            $att->process_submit(time(), false);
            $att->process_grade_submission(time());
        } else {
            $att->process_finish(time(), false);
        }
    }

    /**
     * Render the report and return its HTML.
     *
     * @return string
     */
    private function render(): string {
        global $PAGE;
        // A fresh page. Creating the attempt in setUp() runs format_text(), which
        // initialises the theme, and Moodle then refuses any further set_course() on
        // that page. Replacing it is a harness concern, not a plugin one.
        $PAGE = new \moodle_page();
        $PAGE->set_context(\context_module::instance($this->cm->id));
        $PAGE->set_course($this->course);
        $PAGE->set_url(new \moodle_url(
            '/mod/quiz/report.php',
            ['id' => $this->cm->id, 'mode' => 'aigrader']
        ));

        $report = new \quiz_aigrader_report();
        ob_start();
        try {
            $report->display($this->quiz, $this->cm, $this->course);
        } finally {
            $html = ob_get_clean();
        }
        return $html;
    }

    /**
     * The page renders at all. A restructure that throws would fail here and nowhere else
     * in this suite.
     */
    public function test_report_renders_without_error(): void {
        $html = $this->render();
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('aigrader-container', $html);
    }

    /**
     * The queue is a table, and the learner has a row in it.
     */
    public function test_queue_renders_as_a_table_with_the_learner(): void {
        $html = $this->render();

        $this->assertStringContainsString('aigrader-queue', $html);
        $this->assertStringContainsString('aigrader-qrow', $html);
        $this->assertStringContainsString('Sarah Mitchell', $html);
        $this->assertStringContainsString('aigrader-q-status', $html);
    }

    /**
     * Each response is one tbody holding a summary row and a detail row. Getting this
     * wrong once already produced a nested, never-closed tbody.
     */
    public function test_queue_markup_is_well_formed(): void {
        $html = $this->render();

        $this->assertSame(
            substr_count($html, '<tbody'),
            substr_count($html, '</tbody>'),
            'every tbody must be closed'
        );
        $this->assertSame(
            substr_count($html, '<table'),
            substr_count($html, '</table>'),
            'every table must be closed'
        );
        // One response: one summary row, one detail row, plus the header row.
        $this->assertSame(1, substr_count($html, 'aigrader-qrow'));
        $this->assertSame(1, substr_count($html, 'aigrader-qdetail'));
        // The detail row is closed until the assessor opens it.
        $this->assertMatchesRegularExpression('/aigrader-qdetail[^>]*hidden/', $html);
    }

    /**
     * The learner's own question and answer reach the card.
     */
    public function test_detail_row_carries_the_response(): void {
        $html = $this->render();

        $this->assertStringContainsString('active listening', strtolower($html));
        $this->assertStringContainsString('shellfish allergy', $html);
        $this->assertStringContainsString('aigrader-card-body', $html);
        $this->assertStringContainsString('aigrader-col-response', $html);
        $this->assertStringContainsString('aigrader-col-feedback', $html);
    }

    /**
     * A blank response is not listed: there is nothing to mark, and listing it would stop
     * the learner's "all graded" notification ever firing.
     */
    public function test_blank_response_is_not_listed(): void {
        $dg = $this->getDataGenerator();
        $blank = $dg->create_user(['firstname' => 'Empty', 'lastname' => 'Answer']);
        $dg->enrol_user($blank->id, $this->course->id, 'student');
        $this->submit_attempt($blank, '');

        $html = $this->render();

        $this->assertStringContainsString('Sarah Mitchell', $html);
        $this->assertStringNotContainsString('Empty Answer', $html);
    }

    /**
     * Two learners, two rows.
     */
    public function test_each_learner_gets_a_row(): void {
        $dg = $this->getDataGenerator();
        $second = $dg->create_user(['firstname' => 'James', 'lastname' => 'Chen']);
        $dg->enrol_user($second->id, $this->course->id, 'student');
        $this->submit_attempt($second, 'When a customer complains I listen first and repeat back.');

        $html = $this->render();

        $this->assertSame(2, substr_count($html, 'aigrader-qrow'));
        $this->assertStringContainsString('Sarah Mitchell', $html);
        $this->assertStringContainsString('James Chen', $html);
    }

    /**
     * The question's own grading criteria are what the module is told to mark against.
     */
    public function test_question_criteria_are_parsed_from_graderinfo(): void {
        global $DB;

        $question = $DB->get_record_sql(
            "SELECT q.* FROM {question} q
               JOIN {qtype_essay_options} o ON o.questionid = q.id
              WHERE q.qtype = 'essay'",
            [],
            IGNORE_MULTIPLE
        );
        $options = $DB->get_record('qtype_essay_options', ['questionid' => $question->id]);

        $plain = local\formatter::html_to_text($options->graderinfo);
        $criteria = local\criteria::from_graderinfo($plain);

        $this->assertCount(3, $criteria);
        $this->assertSame('Understanding of the concept', $criteria[0]['label']);
        $this->assertSame('Use of a workplace example', $criteria[1]['label']);
        $this->assertSame('Clarity and structure', $criteria[2]['label']);
        $this->assertEqualsWithDelta(10.0, $criteria[0]['max'], 0.001);
    }
}
