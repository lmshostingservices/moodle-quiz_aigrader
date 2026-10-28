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

/**
 * Question layouts that a first version of the 5.0.4 reader got wrong.
 *
 * Each was found by an independent review before release. Every one would have changed a
 * learner's mark: the card scales the mark as criteria awarded over criteria total, so a
 * wrong criterion or a wrong weight is a wrong mark. All go through criteria::for_fields(),
 * the same path grading uses, from the HTML as Moodle stores it.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\local\criteria
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_aigrader\local\criteria::class)]
final class criteria_regression_test extends \advanced_testcase {
    /** A question the service's derived criteria are checked against. */
    public const SAM_REFERENCE = 'Sam sees a hazard. Describe one action he should take, explain why, and link it to safety.';

    /**
     * Resolve from HTML fields.
     *
     * @param string $gi Information for graders.
     * @param string $qt Question text.
     * @param string $gf General feedback.
     * @param float $max Question mark.
     * @return array
     */
    private function r(string $gi, string $qt, string $gf, float $max): array {
        return criteria::for_fields($gi, $qt, $gf, $max);
    }

    /**
     * Warning codes.
     *
     * @param array $r
     * @return array
     */
    private function codes(array $r): array {
        return array_column($r['warnings'], 'code');
    }

    /**
     * A year in brackets is not 2011 marks.
     */
    public function test_year_in_brackets_is_not_marks(): void {
        $r = $this->r('', '<p>a) Identify two hazards (2 marks)</p>'
            . '<p>b) Explain your duties under the Work Health and Safety Act (2011).</p>', '', 4.0);
        foreach ($r['criteria'] as $c) {
            $this->assertLessThanOrEqual(4.0, $c['max']);
        }
        $this->assertNotEquals(2013.0, $r['total']);
    }

    /**
     * A bracketed word count is not marks.
     */
    public function test_bracketed_figure_in_question_text_is_not_marks(): void {
        $r = $this->r('', '<p>1. Describe the process [150]</p><p>2. Explain why [50]</p>', '', 4.0);
        $this->assertSame(criteria::SOURCE_NONE, $r['source']);
    }

    /**
     * A data table in the question text is data.
     */
    public function test_data_table_in_question_text_is_not_a_rubric(): void {
        $r = $this->r(
            '',
            '<p>Review the incident register below.</p><table><tr><th>Month</th><th>Incidents</th></tr>'
            . '<tr><td>January</td><td>3</td></tr><tr><td>February</td><td>5</td></tr>'
            . '<tr><td>March</td><td>2</td></tr></table><p>Describe the trend.</p>',
            '<p>Marking guide:</p><ul><li>Describes the trend (2 marks)</li><li>Recommends a control (2 marks)</li></ul>',
            4.0
        );
        $this->assertSame(criteria::SOURCE_GENERALFEEDBACK, $r['source'], 'the real rubric in General feedback is reached');
        $this->assertCount(2, $r['criteria']);
    }

    /**
     * A risk matrix in the question text is data.
     */
    public function test_risk_matrix_is_not_a_rubric(): void {
        $r = $this->r('', '<p>Rate each hazard.</p><table><tr><td>Hazard</td><td>Likelihood</td><td>Consequence</td></tr>'
            . '<tr><td>Wet floor</td><td>4</td><td>2</td></tr><tr><td>Forklift</td><td>2</td><td>5</td></tr></table>', '', 5.0);
        $this->assertSame(criteria::SOURCE_NONE, $r['source']);
    }

    /**
     * "Question 1 (4 marks)" is a heading with a total.
     */
    public function test_question_heading_with_marks_is_a_total(): void {
        $r = $this->r('', '<p>Question 1 (4 marks)</p><p>a) Identify two hazards (2 marks)</p>'
            . '<p>b) Explain one control for each (2 marks)</p>', '', 4.0);
        $this->assertSame(criteria::SOURCE_QUESTIONTEXT, $r['source']);
        $this->assertCount(2, $r['criteria']);
        $this->assertEqualsWithDelta(4.0, $r['total'], 0.001);
        $this->assertNotContains('declared_mismatch', $this->codes($r));
    }

    /**
     * "Marking criteria (2 marks)" is a heading, in either field.
     */
    public function test_criteria_heading_with_marks_is_a_total(): void {
        $body = '<p>Marking criteria (2 marks)</p><ul><li>Describes action (1 mark)</li><li>Explains why (1 mark)</li></ul>';
        foreach (['gi' => [$body, ''], 'qt' => ['', $body]] as $where => [$gi, $qt]) {
            $r = $this->r($gi, $qt, '', 2.0);
            $this->assertSame(criteria::CERTAIN, $r['confidence'], $where);
            $this->assertCount(2, $r['criteria'], $where);
            $this->assertSame('Describes action', $r['criteria'][0]['label'], $where);
            $this->assertEqualsWithDelta(2.0, $r['total'], 0.001, $where);
        }
        // Under the heading's total but without marks of their own: the split is not a guess.
        $r = $this->r('<p>Marking criteria (2 marks)</p><ul><li>Describes action</li><li>Explains why</li></ul>', '', '', 2.0);
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertCount(2, $r['candidates']);
        $this->assertSame('Describes action', $r['candidates'][0]['label']);
    }

    /**
     * Two criteria sharing a short name are both kept and told apart.
     */
    public function test_shared_short_name_keeps_both(): void {
        $r = $this->r('<ul><li>Explains: why the hazard must be reported (1 mark)</li>'
            . '<li>Explains: how the control reduces risk (1 mark)</li><li>Identifies: the hazard (1 mark)</li></ul>', '', '', 3.0);
        $this->assertCount(3, $r['criteria']);
        $this->assertNotSame($r['criteria'][0]['label'], $r['criteria'][1]['label']);
        $this->assertStringContainsString('reported', $r['criteria'][0]['label']);
        $this->assertEqualsWithDelta(3.0, $r['total'], 0.001);
    }

    /**
     * Two long criteria that start alike are both kept, with distinct labels.
     */
    public function test_long_criteria_sharing_an_opening_are_both_kept(): void {
        $stem = 'Describes the procedure the worker must follow when reporting an incident to the supervisor in writing';
        $r = $this->r("<ol><li>$stem within 24 hours (2 marks)</li><li>$stem within 48 hours for near misses (2 marks)</li>"
            . '<li>Names the form used (1 mark)</li></ol>', '', '', 5.0);
        $this->assertCount(3, $r['criteria']);
        $this->assertNotSame($r['criteria'][0]['label'], $r['criteria'][1]['label']);
        $this->assertStringContainsString('24 hours', $r['criteria'][0]['detail']);
        $this->assertStringContainsString('48 hours', $r['criteria'][1]['detail']);
    }

    /**
     * A mark band is one judgement, not three criteria.
     */
    public function test_mark_band_is_not_criteria(): void {
        $band = '<ul><li>0 marks: no answer</li><li>1 mark: partial answer</li><li>2 marks: full answer</li></ul>';
        $r = $this->r($band, '', '', 2.0);
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertSame([], $r['criteria']);
    }

    /**
     * A band without a zero line, written as levels of quality.
     */
    public function test_quality_band_is_not_criteria(): void {
        $r = $this->r('<p>1 mark: basic explanation with limited detail</p><p>2 marks: detailed explanation</p>'
            . '<p>3 marks: thorough explanation with a workplace example</p>', '', '', 3.0);
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertSame([], $r['criteria']);
    }

    /**
     * Rising marks on real criteria are not a band.
     */
    public function test_rising_marks_on_criteria_are_kept(): void {
        $r = $this->r(
            '<p>1 mark: Identify the hazard</p><p>2 marks: Assess the risk</p><p>3 marks: Recommend controls</p>',
            '',
            '',
            6.0
        );
        $this->assertCount(3, $r['criteria']);
        $this->assertEqualsWithDelta(6.0, $r['total'], 0.001);
    }

    /**
     * A shared total adds up exactly, with no false warning.
     */
    public function test_shared_total_has_no_rounding_warning(): void {
        $r = $this->r('<p>Marking criteria (10 marks total)</p><ul><li>Identifies hazards</li><li>Explains controls</li>'
            . '<li>Refers to legislation</li></ul>', '', '', 10.0);
        $this->assertEqualsWithDelta(10.0, array_sum(array_column($r['candidates'], 'max')), 0.0001);
        $this->assertNotContains('declared_mismatch', $this->codes($r));

        $r = $this->r(
            '<p>Total: 1 mark</p><ul><li>Identifies hazard</li><li>Explains control</li><li>Cites act</li></ul>',
            '',
            '',
            1.0
        );
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($r['candidates'], 'max')), 0.0001);
        $this->assertNotContains('declared_mismatch', $this->codes($r));
    }

    /**
     * Figures in prose are not a total of marks.
     */
    public function test_prose_figures_are_not_totals(): void {
        $r = $this->r('', '<p>A worker lifts a total of 25 kg.</p><p>1 mark: Identify the hazard</p>'
            . '<p>1 mark: Explain the control</p>', '', 2.0);
        $this->assertNull($r['declaredtotal']);
        $this->assertEmpty($this->codes($r));
    }

    /**
     * A pick-list is not a set of criteria.
     */
    public function test_pick_list_is_not_criteria(): void {
        $r = $this->r('<p>Learner must correctly identify at least 3 out of 5 hazards:</p><ul><li>Wet floor</li>'
            . '<li>Trailing cable</li><li>Poor lighting</li><li>Blocked exit</li><li>Unguarded machine</li></ul>', '', '', 3.0);
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertSame([], $r['criteria']);
        $this->assertContains('points_list', $this->codes($r));
    }

    /**
     * Marks for each point, with a cap.
     */
    public function test_marks_for_each_with_a_cap_is_not_criteria(): void {
        $r = $this->r('<p>Award 1 mark for each correct point (max 3 marks):</p><ul><li>Report</li><li>Isolate</li>'
            . '<li>Sign</li><li>Clean</li><li>Record</li><li>Review</li></ul>', '', '', 3.0);
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertSame([], $r['criteria']);
        $this->assertContains('points_list', $this->codes($r));
    }

    /**
     * A list of examples is not a set of criteria.
     */
    public function test_examples_are_not_criteria(): void {
        $r = $this->r('<p>The learner should list a total of 4 hazards, for example:</p><ul><li>Wet floor</li>'
            . '<li>Trailing cable</li><li>Poor lighting</li><li>Blocked exit</li></ul>', '', '', 4.0);
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertSame([], $r['criteria']);
    }

    /**
     * A sentence mentioning the rubric opens no criteria section.
     */
    public function test_rubric_mentioned_in_a_sentence(): void {
        $r = $this->r('', '<p>Your answer will be assessed against the rubric.</p><ol><li>What is a hazard?</li>'
            . '<li>What is a risk?</li><li>What is a control?</li></ol>', '', 3.0);
        $this->assertSame(criteria::SOURCE_NONE, $r['source']);
    }

    /**
     * A Satisfactory / Not Satisfactory table with empty tick boxes.
     */
    public function test_satisfactory_table(): void {
        $r = $this->r('<table><tr><th>Criteria</th><th>S</th><th>NS</th></tr><tr><td>Identifies two hazards</td><td></td>'
            . '<td></td></tr><tr><td>Explains one control</td><td></td><td></td></tr><tr><td>References WHS Act</td>'
            . '<td></td><td></td></tr></table>', '', '', 3.0);
        // A competency checklist has no marks per criterion, so it is condensed and checked.
        $this->assertSame(criteria::SOURCE_GRADERINFO, $r['source']);
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertCount(3, $r['candidates']);
        $this->assertSame('Identifies two hazards', $r['candidates'][0]['label']);
    }

    /**
     * Bold criterion names keep the author's case.
     */
    public function test_bold_labels_keep_their_case(): void {
        $r = $this->r(
            '<ol><li><strong>Understanding of the concept:</strong> explains it (1 mark)</li>'
            . '<li><strong>Workplace example:</strong> gives one (1 mark)</li><li><b>Clarity:</b> plain English (1 mark)</li></ol>',
            '',
            '',
            3.0
        );
        $this->assertSame('Understanding of the concept', $r['criteria'][0]['label']);
        $this->assertSame('Clarity', $r['criteria'][2]['label']);
    }

    /**
     * The service's derived set must meet the minimum, unless the question is too small.
     */
    public function test_service_minimum(): void {
        $two = [['label' => 'Identifies an action', 'max' => 1], ['label' => 'Explains why', 'max' => 1]];
        $question = self::SAM_REFERENCE;
        $this->assertSame([], criteria::from_service($two, ['min' => 3, 'max' => 5], 10.0, 'infer', $question));
        $this->assertCount(2, criteria::from_service($two, ['min' => 3, 'max' => 5], 2.0, 'infer', $question));
    }

    /**
     * The service's derived set is scaled to the question's mark.
     */
    public function test_service_set_is_scaled(): void {
        $out = criteria::from_service([
            ['label' => 'Identifies an action', 'max' => 5, 'awarded' => 5],
            ['label' => 'Explains why', 'max' => 5, 'awarded' => 0],
            ['label' => 'Links to safety', 'max' => 5, 'awarded' => 2.5],
        ], ['min' => 3, 'max' => 5], 3.0, 'infer', self::SAM_REFERENCE);
        $this->assertCount(3, $out);
        $this->assertEqualsWithDelta(1.0, $out[0]['max'], 0.001);
        $this->assertEqualsWithDelta(1.0, $out[0]['awarded'], 0.001);
        $this->assertEqualsWithDelta(0.5, $out[2]['awarded'], 0.001);
    }

    /**
     * A vendor identifier behind a colon or with marks attached is still refused.
     */
    public function test_vendor_identifier_with_detail_is_refused(): void {
        $limits = ['min' => 3, 'max' => 5];
        $out = criteria::from_service([
            ['label' => 'hazard_identified: names the hazard', 'max' => 1],
            ['label' => 'Hazard identified (2 marks)', 'max' => 1],
            ['label' => 'Knowledge applied - uses an example', 'max' => 1],
        ], $limits, 3.0, 'infer', 'Describe what you would do about the noise in the workshop.');
        $this->assertSame([], $out);
    }

    /**
     * A plain-English criterion that happens to match the vendor vocabulary is kept when the
     * question itself asks for it.
     */
    public function test_vendor_words_the_question_uses_are_kept(): void {
        $out = criteria::from_service(
            [
            ['label' => 'Risk assessment', 'max' => 1],
            ['label' => 'Selects a control', 'max' => 1],
            ['label' => 'Explains the reporting process', 'max' => 1],
            ],
            ['min' => 3, 'max' => 5],
            3.0,
            'infer',
            'Complete a risk assessment for the task, select a control and explain how you would report it.'
        );
        $this->assertCount(3, $out);
        $this->assertSame('Risk assessment', $out[0]['label']);
    }

    /**
     * Criteria condensed from the assessor's guidance must be found in it.
     */
    public function test_extracted_criteria_must_come_from_the_guidance(): void {
        $guidance = "2 marks - complete explanation of the purpose with a workplace example\n"
            . "1 mark - partial explanation of the purpose\n0 marks - not attempted";
        $ok = criteria::from_service([
            ['label' => 'Explains the purpose', 'max' => 1, 'awarded' => 1],
            ['label' => 'Gives a workplace example', 'max' => 1, 'awarded' => 0],
        ], ['min' => 3, 'max' => 5], 2.0, 'extract', $guidance);
        $this->assertCount(2, $ok);
        $this->assertTrue($ok[0]['extracted']);

        $invented = criteria::from_service([
            ['label' => 'Explains the purpose', 'max' => 1],
            ['label' => 'Uses correct spelling and grammar', 'max' => 1],
        ], ['min' => 3, 'max' => 5], 2.0, 'extract', $guidance);
        $this->assertSame([], $invented, 'a criterion the assessor did not write leaves too few, so none are used');
    }

    /**
     * The assessor's weights stand; a service score out of a different maximum is scaled.
     */
    public function test_service_cannot_change_a_criterion_weight(): void {
        $rubric = criteria::from_graderinfo("- Identifies hazard (2 marks)\n- Reports (1 mark)");
        $merged = criteria::merge_scores($rubric, [
            ['label' => 'Identifies hazard', 'max' => 10, 'awarded' => 10],
            ['label' => 'Reports', 'max' => 1, 'awarded' => 0],
        ], []);
        $this->assertEqualsWithDelta(2.0, $merged[0]['max'], 0.001);
        $this->assertEqualsWithDelta(2.0, $merged[0]['awarded'], 0.001);

        $half = criteria::merge_scores($rubric, [['label' => 'Identifies hazard', 'max' => 10, 'awarded' => 5]], []);
        $this->assertEqualsWithDelta(1.0, $half[0]['awarded'], 0.001);
    }

    /**
     * Matching a criterion to text allows for different word endings, not different words.
     */
    public function test_found_in(): void {
        $text = 'The learner should identify the hazard and explain how they would report it.';
        $this->assertTrue(criteria::found_in('Identifies the hazard', $text));
        $this->assertTrue(criteria::found_in('Explains reporting', $text));
        $this->assertFalse(criteria::found_in('Cites the legislation', $text));
        $this->assertFalse(criteria::found_in('', $text));
        $this->assertTrue(criteria::found_in('ระบุอันตราย', 'เกณฑ์: ระบุอันตราย และรายงาน'));
    }

    /**
     * Ordinary criteria with "each" or "maximum" or "Total" in their wording are kept whole.
     */
    public function test_criteria_wording_is_not_mistaken_for_instructions(): void {
        $r = $this->r('<ol><li>Identifies each hazard and the maximum safe lifting weight (2 marks)</li>'
            . '<li>Describes a control for each hazard (2 marks)</li>'
            . '<li>Total quality checks are explained (1 mark)</li></ol>', '', '', 5.0);
        $this->assertSame(criteria::CERTAIN, $r['confidence']);
        $this->assertCount(3, $r['criteria']);
        $this->assertNull($r['declaredtotal']);
    }

    /**
     * Notes to the assessor after a rubric are not criteria.
     */
    public function test_notes_after_a_rubric_are_not_criteria(): void {
        $r = $this->r('<p>Marking criteria</p><ul><li>Identifies the hazard (1 mark)</li><li>Describes a control (1 mark)</li>'
            . '<li>States who to report to (1 mark)</li></ul><p>Notes to assessor:</p><ul><li>Accept any reasonable hazard</li>'
            . '<li>Learner may answer in dot points</li></ul>', '', '', 3.0);
        $this->assertSame(criteria::CERTAIN, $r['confidence']);
        $this->assertCount(3, $r['criteria']);
    }

    /**
     * One criterion the service invented refuses the whole set. Dropping it would re-weight
     * the rest: 2 + 2 + 2 + 4 with the 4 failed, trimmed to three, reads as full marks.
     */
    public function test_one_bad_criterion_refuses_the_set(): void {
        $guidance = "Identifies hazards in the workplace\nAssesses the risk of each hazard\n"
            . "Selects controls from the hierarchy\nConsults with the health and safety representative";
        $set = [
            ['label' => 'Identifies hazards', 'max' => 2, 'awarded' => 2],
            ['label' => 'Assesses the risk', 'max' => 2, 'awarded' => 2],
            ['label' => 'Selects controls', 'max' => 2, 'awarded' => 2],
            ['label' => 'Talks things over with staff', 'max' => 4, 'awarded' => 0],
        ];
        $this->assertSame([], criteria::from_service($set, ['min' => 3, 'max' => 5], 10.0, 'extract', $guidance));

        $set[3]['label'] = 'Identifies hazards.';
        $this->assertSame(
            [],
            criteria::from_service($set, ['min' => 3, 'max' => 5], 10.0, 'extract', $guidance),
            'a repeated name refuses the set'
        );
    }

    /**
     * Matching against the assessor's text is not fooled by common words or short stems.
     */
    public function test_found_in_resists_padding(): void {
        $guidance = 'The learner should present a professional workplace report that refers to the WHS Act, '
            . 'identifies the hazards and describes the process used to consult workers.';
        $this->assertFalse(criteria::found_in('Presentation', $guidance));
        $this->assertFalse(criteria::found_in('Referencing', $guidance));
        $this->assertFalse(criteria::found_in('Hazards: names five hazards and rates each with a risk matrix', $guidance));
        $this->assertTrue(criteria::found_in('Identifies the hazards', $guidance));
        $this->assertTrue(criteria::found_in('Describes the consultation process', $guidance));
        $this->assertFalse(criteria::found_in('Giải thích luật lao động', 'Xác định mối nguy và biện pháp kiểm soát'));
        $this->assertTrue(criteria::found_in('识别危险', '识别至少两个危险并说明控制措施'));
    }

    /**
     * Two criteria with the same name each get their own score, in order.
     */
    public function test_repeated_names_keep_their_own_scores(): void {
        $r = $this->r('<p>Part A (4 marks)</p><ul><li>Accuracy (3 marks)</li><li>Clarity (1 mark)</li></ul>'
            . '<p>Part B (6 marks)</p><ul><li>Accuracy (5 marks)</li><li>Clarity (1 mark)</li></ul>', '', '', 10.0);
        $this->assertSame(criteria::CERTAIN, $r['confidence']);
        $merged = criteria::merge_scores($r['criteria'], [
            ['label' => 'Accuracy', 'max' => 3, 'awarded' => 3],
            ['label' => 'Clarity', 'max' => 1, 'awarded' => 1],
            ['label' => 'Accuracy', 'max' => 5, 'awarded' => 2],
            ['label' => 'Clarity', 'max' => 1, 'awarded' => 0],
        ], []);
        $this->assertSame([3.0, 1.0, 2.0, 0.0], array_column($merged, 'awarded'));
    }

    /**
     * Struck-out and hidden criteria are not part of the rubric.
     */
    public function test_struck_and_hidden_criteria_are_ignored(): void {
        $r = $this->r('<ul><li>Identifies hazard (2 marks)</li><li><s>Explains risk (3 marks)</s></li>'
            . '<li style="display: none">Old criterion (4 marks)</li><li>Recommends control (3 marks)</li></ul>', '', '', 5.0);
        $this->assertSame(criteria::CERTAIN, $r['confidence']);
        $this->assertSame(['Identifies hazard', 'Recommends control'], array_column($r['criteria'], 'label'));
    }

    /**
     * Where the assessor weighted each criterion, the service's restatement must keep those
     * weights: 30% / 50% / 20% restated as equal thirds is refused.
     */
    public function test_service_must_keep_the_assessors_weights(): void {
        $html = '<ol><li>Identifies hazards (30%)</li><li>Describes controls (50%)</li><li>Explains reporting (20%)</li></ol>';
        $r = $this->r($html, '', '', 10.0);
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertTrue($r['candidatesweighted']);
        $this->assertSame([30.0, 50.0, 20.0], array_column($r['candidates'], 'max'));

        $equal = [
            ['label' => 'Identifies hazards', 'max' => 1, 'awarded' => 1],
            ['label' => 'Describes controls', 'max' => 1, 'awarded' => 1],
            ['label' => 'Explains reporting', 'max' => 1, 'awarded' => 1],
        ];
        $this->assertSame([], criteria::from_service(
            $equal,
            ['min' => 3, 'max' => 5],
            10.0,
            'extract',
            $r['guidancetext'],
            $r['candidates']
        ));

        $kept = [
            ['label' => 'Identifies hazards', 'max' => 3, 'awarded' => 3],
            ['label' => 'Describes controls', 'max' => 5, 'awarded' => 2],
            ['label' => 'Explains reporting', 'max' => 2, 'awarded' => 2],
        ];
        $out = criteria::from_service($kept, ['min' => 3, 'max' => 5], 10.0, 'extract', $r['guidancetext'], $r['candidates']);
        $this->assertSame([3.0, 5.0, 2.0], array_column($out, 'max'));
    }

    /**
     * Assessor notes whose only marks sit in a "Do not accept" list do not hide criteria the
     * question text gives with their marks.
     */
    public function test_notes_do_not_hide_criteria_in_the_question(): void {
        $r = criteria::for_fields(
            '<p><strong>Notes for assessors</strong></p><p>The Privacy Act 1988.</p><p><strong>Do not accept:</strong></p>'
                . '<ul><li>"Keep it secret" without further explanation (1 mark)</li></ul>',
            '<p>Explain your obligations.</p><p>(a) Name the legislation that applies (1 mark)<br>'
                . '(b) Describe two obligations it places on you (2 marks)</p>',
            '',
            3.0,
            3.0
        );
        $this->assertSame(criteria::SOURCE_QUESTIONTEXT, $r['source']);
        $this->assertCount(2, $r['criteria']);

        // But a note that sets its own weights keeps the field uncertain.
        $r = criteria::for_fields(
            '<p>Weight the hazard identification at 4 marks and the explanation at 1 mark.</p>',
            '<p>Marking criteria</p><ul><li>Accuracy (2 marks)</li><li>Use of examples (3 marks)</li></ul>',
            '',
            5.0,
            5.0
        );
        $this->assertNotSame(criteria::CERTAIN, $r['confidence']);
    }

    /**
     * A marked line under a closing heading is kept in the proposal, and makes the field
     * uncertain.
     */
    public function test_marked_line_under_a_closing_heading_is_proposed(): void {
        $r = criteria::for_fields(
            '<p><strong>Marking criteria</strong></p><ul><li>Recognises the limits (1 mark)</li><li>Reports it (1 mark)</li></ul>'
                . '<p><strong>Requirements</strong></p><ul><li>Explains the situation to the client (1 mark)</li></ul>',
            '',
            '',
            3.0,
            3.0
        );
        $this->assertSame(criteria::UNCERTAIN, $r['confidence']);
        $this->assertContains('ignored', $r['reasons']);
        $this->assertCount(3, $r['candidates']);
    }
}
