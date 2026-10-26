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
 * Tests for reading an assessor's grading criteria out of a question.
 *
 * The criteria a learner is marked against are the ones the assessor wrote in the
 * question's "Information for graders" field. Before 4.4.1 the plugin displayed a fixed
 * vendor list instead, so these tests exist to keep the real rubric as the source.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\local\criteria
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_aigrader\local\criteria::class)]
final class criteria_test extends \advanced_testcase {
    /**
     * A numbered rubric with a shared maximum in the heading, which is how assessors
     * most often write one.
     */
    public function test_numbered_rubric_with_shared_maximum(): void {
        $rubric = "Grading criteria (marked out of 10 each):\n"
            . "1. Understanding of the concept: explains what active listening is and how it prevents mistakes.\n"
            . "2. Use of a workplace example: gives a specific, realistic example from the learner's own work.\n"
            . "3. Clarity and structure: a clear, well-ordered answer in plain English.";

        $result = criteria::from_graderinfo($rubric);

        $this->assertCount(3, $result);
        $this->assertSame('Understanding of the concept', $result[0]['label']);
        $this->assertSame('Use of a workplace example', $result[1]['label']);
        $this->assertSame('Clarity and structure', $result[2]['label']);
        $this->assertEqualsWithDelta(10.0, $result[0]['max'], 0.001);
        $this->assertEqualsWithDelta(10.0, $result[2]['max'], 0.001);
        $this->assertStringContainsString('prevents mistakes', $result[0]['detail']);
    }

    /**
     * However many criteria the assessor writes, that many come back. Ten criteria must
     * produce ten entries, because ten entries is what becomes ten progress bars.
     */
    public function test_every_criterion_is_returned(): void {
        foreach ([2, 3, 5, 10, 15] as $count) {
            $lines = ["Grading criteria (marked out of 5 each):"];
            for ($i = 1; $i <= $count; $i++) {
                $lines[] = "$i. Criterion $i: does the thing numbered $i.";
            }
            $result = criteria::from_graderinfo(implode("\n", $lines));
            $this->assertCount($count, $result, "a $count-criterion rubric must yield $count criteria");
        }
    }

    /**
     * A maximum written on the line itself wins over the heading's default.
     */
    public function test_per_line_maximum(): void {
        $result = criteria::from_graderinfo(
            "- Uses a workplace example (5 marks)\n- Cites legislation (15 marks)"
        );

        $this->assertCount(2, $result);
        $this->assertSame('Uses a workplace example', $result[0]['label']);
        $this->assertEqualsWithDelta(5.0, $result[0]['max'], 0.001);
        $this->assertEqualsWithDelta(15.0, $result[1]['max'], 0.001);
    }

    /**
     * The bullet styles assessors actually use.
     */
    public function test_bullet_styles(): void {
        foreach (["1.", "1)", "-", "*", "a)", "a."] as $marker) {
            $result = criteria::from_graderinfo("$marker First thing\n$marker Second thing");
            $this->assertCount(2, $result, "marker '$marker' should be recognised");
        }
    }

    /**
     * Free prose is not a rubric. Inventing criteria from a paragraph would put words in
     * the assessor's mouth and mark a learner against something nobody wrote.
     */
    public function test_prose_yields_nothing(): void {
        $this->assertSame([], criteria::from_graderinfo(
            'Mark this holistically against the unit requirements.'
        ));
        $this->assertSame([], criteria::from_graderinfo(''));
        $this->assertSame([], criteria::from_graderinfo('   '));
    }

    /**
     * One bullet is a stray bullet, not a set of criteria.
     */
    public function test_single_item_is_not_a_rubric(): void {
        $this->assertSame([], criteria::from_graderinfo('1. The only thing I wrote'));
    }

    /**
     * A line with no colon is the whole label, with no detail.
     */
    public function test_label_without_detail(): void {
        $result = criteria::from_graderinfo("1. Identifies the hazard\n2. Selects a control");

        $this->assertCount(2, $result);
        $this->assertSame('Identifies the hazard', $result[0]['label']);
        $this->assertSame('', $result[0]['detail']);
    }

    /**
     * A colon deep inside a sentence is punctuation, not a label separator.
     */
    public function test_mid_sentence_colon_is_not_a_label(): void {
        $line = '1. Explains the whole procedure from start to finish including this part: the handover step';
        $result = criteria::from_graderinfo($line . "\n2. Second criterion");

        $this->assertStringContainsString('handover', $result[0]['label']);
        $this->assertSame('', $result[0]['detail']);
    }

    /**
     * Scores from the grading service attach to the assessor's criteria by name.
     */
    public function test_merge_attaches_service_scores(): void {
        $rubric = criteria::from_graderinfo(
            "Grading criteria (marked out of 10 each):\n"
            . "1. Understanding of the concept: explains it.\n"
            . "2. Use of a workplace example: gives one.\n"
            . "3. Clarity and structure: reads well."
        );

        $merged = criteria::merge_scores($rubric, [
            ['label' => 'Understanding of the concept', 'awarded' => 9, 'max' => 10,
                'comment' => 'Accurate.'],
            ['label' => 'Clarity and structure', 'awarded' => 7, 'max' => 10],
        ], []);

        $this->assertCount(3, $merged);
        $this->assertEqualsWithDelta(9.0, $merged[0]['awarded'], 0.001);
        $this->assertSame('Accurate.', $merged[0]['comment']);
        // Not mentioned by the service, so still listed and still unscored.
        $this->assertEqualsWithDelta(0.0, $merged[1]['awarded'], 0.001);
        $this->assertEqualsWithDelta(7.0, $merged[2]['awarded'], 0.001);
    }

    /**
     * With no per-criterion scores, the met/not-met list drives a full or empty bar.
     * That is what happens today, before the grading service returns scores.
     */
    public function test_merge_falls_back_to_met_list(): void {
        $rubric = criteria::from_graderinfo(
            "1. Identifies the hazard: names it.\n2. Selects a control: picks one."
        );

        $merged = criteria::merge_scores($rubric, [], ['identifies_the_hazard']);

        $this->assertCount(2, $merged);
        $this->assertEqualsWithDelta(1.0, $merged[0]['awarded'], 0.001);
        $this->assertEqualsWithDelta(0.0, $merged[1]['awarded'], 0.001);
    }

    /**
     * A score outside the criterion's range is clamped, never stored as given.
     */
    public function test_merge_clamps_out_of_range_scores(): void {
        $rubric = criteria::from_graderinfo(
            "1. Identifies the hazard (1 mark)\n2. Selects a control (10 marks)"
        );

        $merged = criteria::merge_scores($rubric, [
            ['label' => 'Identifies the hazard', 'awarded' => 9999, 'max' => 1],
            ['label' => 'Selects a control', 'awarded' => -40, 'max' => 10],
        ], []);

        $this->assertEqualsWithDelta(1.0, $merged[0]['awarded'], 0.001);
        $this->assertEqualsWithDelta(0.0, $merged[1]['awarded'], 0.001);
    }

    /**
     * With no rubric to attach to, whatever the service sent is passed through, and an
     * empty response yields null rather than an empty block.
     */
    public function test_merge_without_a_rubric(): void {
        $this->assertNull(criteria::merge_scores([], [], []));

        $passed = criteria::merge_scores([], [['label' => 'X', 'awarded' => 1, 'max' => 2]], []);
        $this->assertCount(1, $passed);
    }

    /**
     * Multibyte labels survive. An RTO marking in Thai must get Thai criterion names.
     */
    public function test_multibyte_labels(): void {
        $result = criteria::from_graderinfo(
            "1. ความเข้าใจในแนวคิด: อธิบายได้ชัดเจน\n2. ตัวอย่างในที่ทำงาน: ยกตัวอย่างได้"
        );

        $this->assertCount(2, $result);
        $this->assertSame('ความเข้าใจในแนวคิด', $result[0]['label']);
        $this->assertSame('อธิบายได้ชัดเจน', $result[0]['detail']);
    }
}
