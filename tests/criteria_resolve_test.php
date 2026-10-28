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

use quiz_aigrader\local\criteria;
use quiz_aigrader\local\formatter;

/**
 * Tests for finding a question's criteria wherever its author put them (5.0.4).
 *
 * Each case is a shape found in real RTO question banks. The first is the question that
 * exposed the problem: criteria in the question text, a sample answer in General feedback,
 * Information for graders empty, and a default mark of 1 against a rubric worth 2.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\local\criteria
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_aigrader\local\criteria::class)]
final class criteria_resolve_test extends \advanced_testcase {
    /** @var array The recommended range used throughout. */
    public const LIMITS = ['min' => 3, 'max' => 5];

    /** The question that exposed the problem. */
    public const SAM_QUESTION = "Sam notices a hazard on site. Describe one action he should take and explain why it is "
        . "important for safety.\n\nMarking Criteria (2 marks total)\n\n"
        . "1 mark: Describe one action Sam should take (e.g. report or control the hazard).\n"
        . "1 mark: Explain why this action is important.";

    /** Its General feedback. */
    public const SAM_FEEDBACK = "General Feedback\nSample Answer\n\n1 mark: Describe one action Sam should take.\n\n"
        . "Sam should report the hazard to his supervisor or follow the workplace reporting process.\n\n"
        . "1 mark: Explain why this action is important.\n\n"
        . "This is important because reporting hazards helps prevent accidents and keeps workers safe.";

    /**
     * Return the warning codes from a resolution.
     *
     * @param array $r Result of criteria::resolve().
     * @return array Codes.
     */
    private function codes(array $r): array {
        return array_column($r['warnings'], 'code');
    }

    /**
     * Criteria in the question text are found when the grader information is empty.
     */
    public function test_criteria_in_question_text(): void {
        $r = criteria::resolve('', self::SAM_QUESTION, self::SAM_FEEDBACK, 2.0, self::LIMITS);

        $this->assertSame(criteria::SOURCE_QUESTIONTEXT, $r['source']);
        $this->assertCount(2, $r['criteria']);
        $this->assertSame('Describe one action Sam should take', $r['criteria'][0]['label']);
        $this->assertSame('e.g. report or control the hazard', $r['criteria'][0]['detail']);
        $this->assertSame('Explain why this action is important', $r['criteria'][1]['label']);
        $this->assertEqualsWithDelta(1.0, $r['criteria'][0]['max'], 0.001);
        $this->assertEqualsWithDelta(2.0, $r['total'], 0.001);
        $this->assertEmpty($this->codes($r), 'a two-mark question with two criteria needs no warning');
    }

    /**
     * The question's own mark disagreeing with its rubric is reported, never hidden.
     */
    public function test_rubric_total_disagrees_with_question_mark(): void {
        $r = criteria::resolve('', self::SAM_QUESTION, self::SAM_FEEDBACK, 1.0, self::LIMITS);

        $this->assertSame(criteria::SOURCE_QUESTIONTEXT, $r['source']);
        $this->assertContains('total_mismatch', $this->codes($r));
        $this->assertNotContains('too_few', $this->codes($r), 'a one-mark question cannot hold three criteria');
    }

    /**
     * Criteria only in General feedback are found there, and proposed rather than used as
     * written: General feedback is shown to learners and often mixes a sample answer in.
     */
    public function test_criteria_in_general_feedback(): void {
        $r = criteria::resolve(
            '',
            'Sam notices a hazard on site. Describe one action he should take and explain why it is important.',
            self::SAM_FEEDBACK,
            2.0,
            self::LIMITS
        );

        $this->assertSame(criteria::SOURCE_GENERALFEEDBACK, $r['source']);
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertCount(2, $r['candidates']);
        $this->assertSame('Describe one action Sam should take', $r['candidates'][0]['label']);
    }

    /**
     * The grader information wins over the other fields.
     */
    public function test_grader_information_takes_priority(): void {
        $r = criteria::resolve(
            "1. Identifies the hazard (1 mark)\n2. Selects a control (1 mark)\n3. Explains the reason (1 mark)",
            self::SAM_QUESTION,
            self::SAM_FEEDBACK,
            3.0,
            self::LIMITS
        );

        $this->assertSame(criteria::SOURCE_GRADERINFO, $r['source']);
        $this->assertCount(3, $r['criteria']);
    }

    /**
     * A question with no criteria anywhere says so.
     */
    public function test_no_criteria_anywhere(): void {
        $r = criteria::resolve('', 'Describe a time you resolved a conflict at work.', '', 10.0, self::LIMITS);

        $this->assertSame(criteria::SOURCE_NONE, $r['source']);
        $this->assertSame([], $r['criteria']);
        $this->assertContains('none_found', $this->codes($r));
    }

    /**
     * Numbered sub-questions in the question text are not a rubric.
     */
    public function test_sub_questions_are_not_criteria(): void {
        $r = criteria::resolve(
            '',
            "Answer the following:\n1. What is a hazard?\n2. What is a risk?\n3. Who is responsible for WHS?",
            '',
            6.0,
            self::LIMITS
        );

        $this->assertSame(criteria::SOURCE_NONE, $r['source']);
    }

    /**
     * Sub-questions that carry their own marks are the marking structure.
     */
    public function test_marked_sub_questions_are_criteria(): void {
        $r = criteria::resolve(
            '',
            "a) Identify two hazards in the scenario (2 marks)\nb) Recommend a control for each (2 marks)\n"
                . "c) Explain who you would report to (1 mark)",
            '',
            5.0,
            self::LIMITS
        );

        $this->assertSame(criteria::SOURCE_QUESTIONTEXT, $r['source']);
        $this->assertCount(3, $r['criteria']);
        $this->assertSame('Identify two hazards in the scenario', $r['criteria'][0]['label']);
        $this->assertEqualsWithDelta(5.0, $r['total'], 0.001);
        $this->assertEmpty($this->codes($r));
    }

    /**
     * Too many marked lines in the question text are not taken as a rubric.
     */
    public function test_long_marked_list_in_question_text_is_ignored(): void {
        $lines = [];
        for ($i = 1; $i <= 8; $i++) {
            $lines[] = "$i. Define term number $i (1 mark)";
        }
        $r = criteria::resolve('', implode("\n", $lines), '', 8.0, self::LIMITS);

        // The assessor's marking structure, but not a rubric to use as written: the grading
        // service condenses it, and nothing is presented as certain.
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertSame([], $r['criteria']);
        $this->assertContains('source_ignored', $this->codes($r));
    }

    /**
     * An assessor's own long rubric is kept whole and flagged, never trimmed.
     */
    public function test_long_rubric_in_grader_information_is_kept_and_flagged(): void {
        $lines = [];
        for ($i = 1; $i <= 7; $i++) {
            $lines[] = "- Criterion number $i (1 mark)";
        }
        $r = criteria::resolve(implode("\n", $lines), '', '', 7.0, self::LIMITS);

        $this->assertSame(criteria::SOURCE_GRADERINFO, $r['source']);
        $this->assertCount(7, $r['criteria']);
        $this->assertContains('too_many', $this->codes($r));
    }

    /**
     * Two criteria on a ten-mark question is thin and is flagged.
     */
    public function test_too_few_for_the_marks(): void {
        $r = criteria::resolve("- Explains the concept (5 marks)\n- Gives an example (5 marks)", '', '', 10.0, self::LIMITS);

        $this->assertContains('too_few', $this->codes($r));
        $this->assertNotContains('total_mismatch', $this->codes($r));
    }

    /**
     * A marking guide written as a table.
     */
    public function test_table_rubric(): void {
        $html = '<p>Marking guide</p><table><thead><tr><th>Criteria</th><th>Marks</th></tr></thead><tbody>'
            . '<tr><td>Identifies the hazard</td><td>2</td></tr>'
            . '<tr><td>Selects an appropriate control</td><td>2</td></tr>'
            . '<tr><td>Explains the reporting process</td><td>1</td></tr>'
            . '<tr><td><strong>Total</strong></td><td>5</td></tr></tbody></table>';
        $r = criteria::resolve(formatter::rubric_text($html), '', '', 5.0, self::LIMITS);

        $this->assertSame(criteria::SOURCE_GRADERINFO, $r['source']);
        $this->assertCount(3, $r['criteria']);
        $this->assertSame('Identifies the hazard', $r['criteria'][0]['label']);
        $this->assertEqualsWithDelta(2.0, $r['criteria'][0]['max'], 0.001);
        $this->assertEqualsWithDelta(5.0, $r['total'], 0.001);
        $this->assertEmpty($this->codes($r));
    }

    /**
     * A table with a criterion, a description and a mark.
     */
    public function test_three_column_table(): void {
        $html = '<table><tr><th>#</th><th>Criterion</th><th>Description</th><th>Marks</th></tr>'
            . '<tr><td>1</td><td>Hazard</td><td>Names a hazard in the scenario</td><td>1</td></tr>'
            . '<tr><td>2</td><td>Control</td><td>Gives a control from the hierarchy</td><td>2</td></tr></table>';
        $r = criteria::resolve(formatter::rubric_text($html), '', '', 3.0, self::LIMITS);

        $this->assertCount(2, $r['criteria']);
        $this->assertSame('Hazard', $r['criteria'][0]['label']);
        $this->assertSame('Names a hazard in the scenario', $r['criteria'][0]['detail']);
        $this->assertEqualsWithDelta(2.0, $r['criteria'][1]['max'], 0.001);
    }

    /**
     * Bullets in a sample answer under the rubric are not extra criteria.
     */
    public function test_sample_answer_bullets_are_not_criteria(): void {
        $gi = "Marking criteria:\n1. Identifies a hazard (1 mark)\n2. Recommends a control (1 mark)\n3. Explains why (1 mark)\n\n"
            . "Sample answer:\n- The wet floor is a slip hazard\n- Place a sign and mop it up\n- It prevents falls";
        $r = criteria::resolve($gi, '', '', 3.0, self::LIMITS);

        $this->assertCount(3, $r['criteria']);
        $this->assertSame('Explains why', $r['criteria'][2]['label']);
    }

    /**
     * A grader information field holding only a sample answer has no criteria in it.
     */
    public function test_sample_answer_alone_is_not_a_rubric(): void {
        $r = criteria::resolve("Model answer:\n- Report it\n- Isolate the area", '', '', 2.0, self::LIMITS);

        // A benchmark is marking guidance, but not a list of criteria.
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertSame(criteria::SOURCE_GRADERINFO, $r['source']);
        $this->assertSame([], $r['criteria']);
    }

    /**
     * Criteria without marks of their own are not used as written, even under a declared total:
     * how the assessor meant the total to divide is not written down. The plugin's own reading,
     * the total shared equally, goes to the grading service as a hint only.
     */
    public function test_declared_total_over_unmarked_criteria_is_uncertain(): void {
        $r = criteria::resolve(
            "Marking criteria (9 marks total)\n- Identifies hazards\n- Assesses the risk\n- Selects controls",
            '',
            '',
            9.0,
            self::LIMITS
        );

        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertSame([], $r['criteria']);
        $this->assertContains('unmarked', $r['reasons']);
        $this->assertCount(3, $r['candidates']);
        $this->assertEqualsWithDelta(3.0, $r['candidates'][0]['max'], 0.001);
    }

    /**
     * A rubric is used as written only when its marks add up to the question's mark, in the
     * quiz or as the question was written.
     */
    public function test_marks_must_add_up(): void {
        $rubric = "- Identifies the hazard (2 marks)\n- Selects a control (2 marks)\n- Reports it (1 mark)";
        $this->assertSame(criteria::CERTAIN, criteria::resolve($rubric, '', '', 5.0, self::LIMITS)['confidence']);
        // Weighted differently in this quiz, but adding up to the question's own default mark.
        $this->assertSame(criteria::CERTAIN, criteria::resolve($rubric, '', '', 10.0, self::LIMITS, 5.0)['confidence']);
        $r = criteria::resolve($rubric, '', '', 4.0, self::LIMITS, 4.0);
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertContains('total', $r['reasons']);
        $this->assertContains('total_mismatch', $this->codes($r));
    }

    /**
     * A declared total the items do not add up to is reported.
     */
    public function test_declared_total_mismatch(): void {
        $r = criteria::resolve(
            "Marking criteria (10 marks total)\n- Identifies hazards (2 marks)\n- Selects controls (3 marks)\n"
                . "- Explains the process (2 marks)",
            '',
            '',
            10.0,
            self::LIMITS
        );

        $this->assertContains('declared_mismatch', $this->codes($r));
    }

    /**
     * Labelled criteria, as VET performance criteria are written.
     */
    public function test_labelled_performance_criteria(): void {
        $r = criteria::resolve(
            '',
            "Describe how you would prepare for the meeting.\n\nPerformance criteria\n"
                . "PC 1.1 Identifies the purpose of the meeting (1 mark)\nPC 1.2 Confirms attendees (1 mark)\n"
                . "PC 1.3 Prepares an agenda (1 mark)",
            '',
            3.0,
            self::LIMITS
        );

        $this->assertSame(criteria::SOURCE_QUESTIONTEXT, $r['source']);
        $this->assertCount(3, $r['criteria']);
        $this->assertSame('Confirms attendees', $r['criteria'][1]['label']);
    }

    /**
     * Marks after a dash, beside a label, and in brackets first.
     */
    public function test_mark_positions(): void {
        $r = criteria::resolve(
            "Identifies two hazards \u{2013} 2 marks\nKnowledge (3 marks): explains the hierarchy of control\n"
                . "(1 mark) States who to report to",
            '',
            '',
            6.0,
            self::LIMITS
        );

        $this->assertCount(3, $r['criteria']);
        $this->assertSame('Identifies two hazards', $r['criteria'][0]['label']);
        $this->assertEqualsWithDelta(2.0, $r['criteria'][0]['max'], 0.001);
        $this->assertSame('Knowledge', $r['criteria'][1]['label']);
        $this->assertSame('explains the hierarchy of control', $r['criteria'][1]['detail']);
        $this->assertEqualsWithDelta(3.0, $r['criteria'][1]['max'], 0.001);
        $this->assertSame('States who to report to', $r['criteria'][2]['label']);
    }

    /**
     * A long criterion keeps a short label and loses nothing.
     */
    public function test_long_criterion_is_shortened_without_loss(): void {
        $long = 'Provides a detailed explanation of the consultation processes used in the workplace including '
            . 'health and safety committees and representatives and how issues are raised and resolved';
        $r = criteria::resolve("1. $long (1 mark)\n2. Gives an example (1 mark)", '', '', 2.0, self::LIMITS);

        $this->assertLessThanOrEqual(criteria::LABEL_MAX, \core_text::strlen($r['criteria'][0]['label']));
        $this->assertSame($long, $r['criteria'][0]['detail']);
    }

    /**
     * A criterion written twice may mean two of it, or be a slip: never dropped, but not used
     * as written either. A repeat in a sample answer, which restates the rubric above it, is
     * just a repeat.
     */
    public function test_duplicates_are_uncertain_but_sample_repeats_are_not(): void {
        $r = criteria::resolve(
            "1 mark: Describe one action\n1 mark: Explain why\n1 mark: Describe one action",
            '',
            '',
            3.0,
            self::LIMITS
        );
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertContains('duplicate', $r['reasons']);
        $this->assertCount(3, $r['candidates']);

        $r = criteria::resolve(
            "1 mark: Describe one action\n1 mark: Explain why\nSample answer:\n1 mark: Describe one action",
            '',
            '',
            2.0,
            self::LIMITS
        );
        // Marked lines in a sample answer are examples, so the field is not certain; the repeat
        // is still dropped from what is proposed.
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertNotContains('duplicate', $r['reasons']);
        $this->assertCount(2, $r['candidates']);
    }

    /**
     * A "Total" line is not a criterion.
     */
    public function test_total_line_is_not_a_criterion(): void {
        $r = criteria::resolve("- Hazard (2 marks)\n- Control (2 marks)\nTotal: 4 marks", '', '', 4.0, self::LIMITS);

        $this->assertCount(2, $r['criteria']);
        $this->assertEqualsWithDelta(4.0, $r['declaredtotal'], 0.001);
        $this->assertNotContains('declared_mismatch', $this->codes($r));
        $this->assertNotContains('total_mismatch', $this->codes($r));
    }

    /**
     * Thai criteria in the question text.
     */
    public function test_multibyte_question_text(): void {
        $r = criteria::resolve(
            '',
            "อธิบายขั้นตอนการรายงานอันตราย\n1 คะแนน: ระบุอันตราย\n- อธิบายเหตุผล (1 คะแนน)",
            '',
            2.0,
            self::LIMITS
        );

        $this->assertCount(2, $r['criteria']);
        $this->assertSame('อธิบายเหตุผล', $r['criteria'][1]['label']);
    }

    /**
     * Derived criteria inside the range are accepted and marked as derived.
     */
    public function test_service_criteria_accepted(): void {
        $out = criteria::from_service([
            ['label' => 'Identifies an action', 'max' => 1, 'awarded' => 1, 'comment' => 'Reported it'],
            ['label' => 'Explains why', 'max' => 1, 'awarded' => 0.5],
            ['label' => 'Links to safety', 'max' => 1, 'awarded' => 5],
        ], self::LIMITS, 3.0, 'infer', 'Describe one action Sam should take and explain why it matters for safety.');

        $this->assertCount(3, $out);
        $this->assertTrue($out[0]['inferred']);
        $this->assertEqualsWithDelta(1.0, $out[2]['awarded'], 0.001, 'awarded is clamped to max');
    }

    /**
     * Derived criteria outside the range are refused, not trimmed.
     */
    public function test_service_criteria_refused_outside_range(): void {
        $many = [];
        for ($i = 1; $i <= 7; $i++) {
            $many[] = ['label' => "Criterion $i", 'max' => 1];
        }
        $this->assertSame([], criteria::from_service($many, self::LIMITS));
        $this->assertSame([], criteria::from_service([['label' => 'Only one', 'max' => 1]], self::LIMITS));
        $this->assertSame([], criteria::from_service([['nolabel' => 1], 'junk', ['label' => '']], self::LIMITS));
    }

    /**
     * The service's internal identifiers never become criteria a learner sees.
     */
    public function test_service_identifiers_are_refused(): void {
        $this->assertSame([], criteria::from_service([
            ['key' => 'hazard_identified', 'max' => 1],
            ['key' => 'control_measure', 'max' => 1],
            ['key' => 'legislation_referenced', 'max' => 1],
        ], self::LIMITS));
        $this->assertSame([], criteria::from_service([
            ['label' => 'Hazard identified', 'max' => 1],
            ['label' => 'Control measure', 'max' => 1],
            ['label' => 'Legislation referenced', 'max' => 1],
        ], self::LIMITS), 'the vendor list is refused however it is written');
        $this->assertSame([], criteria::from_service([
            ['label' => 'some_internal_id', 'max' => 1],
            ['label' => 'another_one', 'max' => 1],
            ['label' => 'third_id', 'max' => 1],
        ], self::LIMITS));
    }

    /**
     * Scores named differently from the rubric attach in order, only when nothing matches by name.
     */
    public function test_merge_by_position(): void {
        $rubric = criteria::from_graderinfo("1. Identifies the hazard\n2. Selects a control");
        $merged = criteria::merge_scores($rubric, [
            ['label' => 'Hazard', 'awarded' => 1, 'max' => 1],
            ['label' => 'Control', 'awarded' => 0, 'max' => 1],
        ], []);
        $this->assertEqualsWithDelta(1.0, $merged[0]['awarded'], 0.001);

        $partial = criteria::merge_scores($rubric, [
            ['label' => 'Identifies the hazard', 'awarded' => 0, 'max' => 1],
            ['label' => 'Something else', 'awarded' => 1, 'max' => 1],
        ], []);
        $this->assertEqualsWithDelta(0.0, $partial[1]['awarded'], 0.001, 'no positional match once a name matched');
    }

    /**
     * The site's range is clamped to something usable.
     */
    public function test_limits_are_clamped(): void {
        $this->resetAfterTest();
        $this->assertSame(['min' => 3, 'max' => 5], criteria::limits());

        set_config('criteria_min', 4, 'quiz_aigrader');
        set_config('criteria_max', 2, 'quiz_aigrader');
        $this->assertSame(['min' => 4, 'max' => 4], criteria::limits());

        set_config('criteria_min', 50, 'quiz_aigrader');
        set_config('criteria_max', 50, 'quiz_aigrader');
        $this->assertSame(['min' => 10, 'max' => 10], criteria::limits());
    }

    /**
     * An unknown policy falls back to deriving criteria.
     */
    public function test_policy_default(): void {
        $this->resetAfterTest();
        $this->assertSame(criteria::POLICY_INFER, criteria::policy());
        set_config('nocriteria_policy', 'manual', 'quiz_aigrader');
        $this->assertSame(criteria::POLICY_MANUAL, criteria::policy());
        set_config('nocriteria_policy', 'nonsense', 'quiz_aigrader');
        $this->assertSame(criteria::POLICY_INFER, criteria::policy());
    }

    /**
     * The instruction for derived criteria carries the range and the question's mark.
     */
    public function test_inference_instruction(): void {
        $text = criteria::inference_instruction(self::LIMITS, 2.5);

        $this->assertStringContainsString('between 3 and 5', $text);
        $this->assertStringContainsString('sum to 2.5', $text);
    }
}
