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

#[\PHPUnit\Framework\Attributes\CoversClass(design::class)]
/**
 * Tests for the Moodle Design System support.
 *
 * @package    quiz_aigrader
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\local\design
 */
final class design_test extends \advanced_testcase {
    /**
     * Core ships the MDS tokens from 5.3, and the fallback class appears only before that.
     */
    public function test_root_classes_follow_core(): void {
        global $CFG;
        $this->resetAfterTest();
        $branch = $CFG->branch;

        $CFG->branch = '501';
        $this->assertFalse(design::core_has_tokens());
        $this->assertSame('aigrader-container ' . design::FALLBACK_CLASS, design::root_classes('aigrader-container'));

        $CFG->branch = '503';
        $this->assertTrue(design::core_has_tokens());
        $this->assertSame('aigrader-container', design::root_classes('aigrader-container'));

        $CFG->branch = $branch;
    }

    /**
     * The stylesheet scopes the MDS tokens to the fallback class and never to the page.
     */
    public function test_stylesheet_scopes_the_tokens(): void {
        global $CFG;
        $css = file_get_contents($CFG->dirroot . '/mod/quiz/report/aigrader/styles.css');
        $this->assertStringContainsString('.' . design::FALLBACK_CLASS . " {\n    --mds-", $css);
        $this->assertStringNotContainsString(':root', $css);
        $this->assertDoesNotMatchRegularExpression('/^\s*(html|body)\b/m', $css);
    }
}
