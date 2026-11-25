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

namespace quiz_aigrader\report;

#[\PHPUnit\Framework\Attributes\CoversClass(service::class)]
/**
 * Tests for the oversight and accuracy figures on the activity report.
 *
 * @package    quiz_aigrader
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\report\service
 */
final class accuracy_test extends \advanced_testcase {
    /**
     * Record one approval.
     *
     * @param int $userid The learner.
     * @param float $aimark The AI's suggested mark.
     * @param float $finalmark The approved mark.
     * @return void
     */
    private function decision(int $userid, float $aimark, float $finalmark): void {
        global $DB;
        $DB->insert_record('quiz_aigrader_decisions', (object)[
            'quizid' => 1, 'courseid' => 1, 'userid' => $userid, 'graderid' => 2, 'qubaid' => 1, 'slot' => 1,
            'questionid' => 1, 'attemptnum' => 1, 'maxmark' => 4, 'aimark' => $aimark, 'finalmark' => $finalmark,
            'markchanged' => abs($aimark - $finalmark) > 0.001 ? 1 : 0, 'timecreated' => time(),
        ]);
    }

    /**
     * Differences are averaged as a share of the mark, split by learner language and direction.
     */
    public function test_accuracy_by_language(): void {
        $this->resetAfterTest();
        global $DB;
        $en = $this->getDataGenerator()->create_user();
        $fr = $this->getDataGenerator()->create_user();
        // Set directly: the generator refuses a language pack the test site does not have.
        $DB->set_field('user', 'lang', 'en', ['id' => $en->id]);
        $DB->set_field('user', 'lang', 'fr', ['id' => $fr->id]);
        $this->decision($en->id, 3, 3);
        $this->decision($en->id, 4, 3);
        $this->decision($en->id, 2, 3);
        $this->decision($fr->id, 4, 2);

        $stats = service::get_accuracy_stats(0, time() + 10);
        $this->assertCount(2, $stats);
        $this->assertSame('en', $stats[0]['lang']);
        $this->assertSame(3, $stats[0]['decisions']);
        $this->assertEqualsWithDelta(2 / 3 * 0.25, $stats[0]['avgdiff'], 0.0001);
        $this->assertSame(1, $stats[0]['aihigher']);
        $this->assertSame(1, $stats[0]['ailower']);
        $this->assertEqualsWithDelta(0.5, $stats[1]['avgdiff'], 0.0001);

        $oversight = service::get_oversight_stats(0, time() + 10);
        $this->assertSame(4, $oversight['decisions']);
        $this->assertSame(3, $oversight['markschanged']);
    }
}
