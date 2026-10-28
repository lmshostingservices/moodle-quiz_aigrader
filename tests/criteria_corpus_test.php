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
 * Real-world RTO question layouts and the outcome an assessor would expect.
 *
 * fixtures/criteria_corpus.json was written blind, from the specification of
 * how criteria are to be read and without sight of the code that reads them, so they test
 * the behaviour rather than restate the implementation. fixtures/criteria_layouts.json holds the
 * layouts an independent review used to try to break the reader, and fixtures/criteria_review.json
 * those a second review wrote against the certainty contract. Each names what it represents.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\local\criteria
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_aigrader\local\criteria::class)]
final class criteria_corpus_test extends \advanced_testcase {
    /**
     * Every case resolves to the outcome, source, count, marks, labels and warnings it expects.
     *
     * The property that matters most is checked first: a breakdown is presented as certain
     * only when it is right. A layout that cannot be read with certainty must come back
     * uncertain, so its guidance goes to the grading service to be condensed and checked,
     * and never as a guessed set of criteria.
     */
    public function test_corpus(): void {
        foreach (['criteria_corpus.json', 'criteria_layouts.json', 'criteria_review.json'] as $file) {
            $cases = json_decode(file_get_contents(__DIR__ . '/fixtures/' . $file), true);
            $this->assertNotEmpty($cases, $file);

            foreach ($cases as $case) {
                $r = criteria::for_fields(
                    $case['graderinfo'],
                    $case['questiontext'],
                    $case['generalfeedback'],
                    (float)$case['maxmark']
                );
                $expect = $case['expect'];
                $id = "$file: " . $case['id'] . ' (' . $case['why'] . ')';

                if (!empty($expect['not_certain'])) {
                    // A layout an independent review wrote to break the reader. Whatever else it
                    // is, it must never be presented as a certain breakdown.
                    $this->assertNotSame(criteria::CERTAIN, $r['confidence'], $id);
                    $this->assertSame([], $r['criteria'], $id);
                    continue;
                }

                $this->assertSame($expect['confidence'], $r['confidence'], $id);
                $this->assertSame($expect['source'], $r['source'], $id);
                if ($expect['confidence'] === criteria::CERTAIN) {
                    if (isset($expect['count'])) {
                        $this->assertCount($expect['count'], $r['criteria'], $id);
                    }
                    foreach ($expect['maxes'] ?? [] as $i => $max) {
                        $this->assertEqualsWithDelta($max, $r['criteria'][$i]['max'], 0.011, "$id criterion $i");
                    }
                    foreach ($expect['labels'] ?? [] as $i => $label) {
                        $this->assertSame($label, $r['criteria'][$i]['label'], "$id criterion $i");
                    }
                    foreach ($expect['labels_contain'] ?? [] as $i => $text) {
                        $this->assertNotFalse(
                            \core_text::strpos(
                                \core_text::strtolower($r['criteria'][$i]['label']),
                                \core_text::strtolower($text)
                            ),
                            "$id criterion $i label '{$r['criteria'][$i]['label']}' should contain '$text'"
                        );
                    }
                } else {
                    $this->assertSame([], $r['criteria'], "$id: no criteria are presented unless certain");
                }
                $codes = array_column($r['warnings'], 'code');
                foreach ($expect['warnings'] ?? [] as $code) {
                    $this->assertContains($code, $codes, $id);
                }
            }
        }
    }

    /**
     * A criterion with its examples nested beneath it, in Thai.
     */
    public function test_nested_examples_in_thai(): void {
        $r = criteria::for_fields(
            '<ol><li>ระบุอันตราย เช่น:<ul><li>พื้นเปียก</li><li>พื้นผิวร้อน</li></ul>(1 คะแนน)</li>'
                . '<li>ประเมินความเสี่ยง (1 คะแนน)</li><li>เสนอมาตรการควบคุม (1 คะแนน)</li></ol>',
            '',
            '',
            3.0
        );
        $this->assertCount(3, $r['criteria']);
        $this->assertStringContainsString('พื้นเปียก', $r['criteria'][0]['label'] . ' ' . $r['criteria'][0]['detail']);
    }
}
