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

namespace quiz_aigrader\local;

#[\PHPUnit\Framework\Attributes\CoversClass(transparency::class)]
/**
 * Tests for the learner notice and the pause switch.
 *
 * @package    quiz_aigrader
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\local\transparency
 */
final class transparency_test extends \advanced_testcase {
    /**
     * The notice is on by default, names the grader, carries the marker class, and is added once.
     */
    public function test_learner_notice(): void {
        $this->resetAfterTest();
        $grader = $this->getDataGenerator()->create_user(['firstname' => 'Sarah', 'lastname' => 'Mitchell']);

        $this->assertTrue(transparency::learner_notice_enabled());
        $html = transparency::add_learner_notice('<p>Well done.</p>', $grader);
        $this->assertStringStartsWith('<p>Well done.</p>', $html);
        $this->assertStringContainsString(transparency::NOTICE_CLASS, $html);
        $this->assertStringContainsString('Sarah Mitchell', $html);
        $this->assertSame($html, transparency::add_learner_notice($html, $grader));

        // The notice survives Moodle's HTML cleaning, so the learner sees it and software can find it.
        $cleaned = format_text($html, FORMAT_HTML, ['context' => \context_system::instance()]);
        $this->assertStringContainsString(transparency::NOTICE_CLASS, $cleaned);
        $this->assertStringContainsString('drafted with the help of AI', $cleaned);
    }

    /**
     * A site can turn the notice off.
     */
    public function test_learner_notice_off(): void {
        $this->resetAfterTest();
        set_config('learner_ai_notice', '0', 'quiz_aigrader');
        $grader = $this->getDataGenerator()->create_user();
        $this->assertFalse(transparency::learner_notice_enabled());
        $this->assertSame('<p>Well done.</p>', transparency::add_learner_notice('<p>Well done.</p>', $grader));
    }

    /**
     * Suggestions run until the site pauses them.
     */
    public function test_paused(): void {
        $this->resetAfterTest();
        $this->assertFalse(transparency::paused());
        set_config('ai_paused', 1, 'quiz_aigrader');
        $this->assertTrue(transparency::paused());
    }
}
