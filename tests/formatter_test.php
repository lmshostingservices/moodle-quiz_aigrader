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

use quiz_aigrader\local\formatter;

/**
 * Tests for the formatting helpers behind the grading screens.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\local\formatter
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_aigrader\local\formatter::class)]
final class formatter_test extends \advanced_testcase {
    /**
     * A score label never truncates the mark. Before 4.4.0 these were cast with intval(),
     * which rendered a 2.5-mark question as "2/2" in the assessor's view and in the
     * learner's notification.
     */
    public function test_score_label_keeps_fractional_marks(): void {
        $this->assertSame('2.50/2.50', formatter::score_label(2.5, 2.5));
        $this->assertSame('0.50/0.50', formatter::score_label(0.5, 0.5));
        $this->assertSame('7.00/10.00', formatter::score_label(7, 10));
        $this->assertSame('0.00/3.00', formatter::score_label(0, 3));
    }

    /**
     * The separator is always a full stop, whatever the site's locale, because these
     * labels are parsed back with floatval() elsewhere.
     */
    public function test_score_label_separator_is_locale_independent(): void {
        $this->assertStringContainsString('.', formatter::score_label(1.5, 3));
        $this->assertStringNotContainsString(',', formatter::score_label(1.5, 3));
    }

    /**
     * Editor markup keeps its structure on the way to the grading service. Until 4.4.0
     * strip_tags() ran a numbered list of performance criteria into a single sentence,
     * which is why only one overall mark ever came back.
     */
    public function test_html_to_text_preserves_list_structure(): void {
        $html = '<p>Grading criteria:</p><ol><li>First criterion</li>'
            . '<li>Second criterion</li><li>Third criterion</li></ol>';

        $text = formatter::html_to_text($html);

        $this->assertStringContainsString('First criterion', $text);
        $this->assertStringContainsString('Second criterion', $text);
        $this->assertStringContainsString('Third criterion', $text);
        // Three list items must not end up on one line.
        $this->assertGreaterThanOrEqual(3, substr_count(trim($text), "\n") + 1);
    }

    /**
     * Paragraphs separate. A learner's well-structured answer must not arrive as one
     * run-on line, or the AI's remarks about its organisation are meaningless.
     */
    public function test_html_to_text_separates_paragraphs(): void {
        $text = formatter::html_to_text('<p>First para.</p><p>Second para.</p>');

        $this->assertStringContainsString('First para.', $text);
        $this->assertStringContainsString('Second para.', $text);
        $this->assertStringNotContainsString('First para.Second para.', $text);
    }

    /**
     * Runs of spaces collapse, runs of blank lines are capped, and the result is trimmed.
     */
    public function test_html_to_text_tidies_whitespace(): void {
        $text = formatter::html_to_text('<p>lots     of      space</p>');

        $this->assertStringContainsString('lots of space', $text);
        $this->assertSame(trim($text), $text);
        $this->assertStringNotContainsString("\n\n\n", formatter::html_to_text(
            '<p>a</p><p></p><p></p><p></p><p>b</p>'
        ));
    }

    /**
     * Nothing in, nothing out.
     */
    public function test_html_to_text_handles_empty(): void {
        $this->assertSame('', formatter::html_to_text(''));
        $this->assertSame('', formatter::html_to_text('   '));
    }

    /**
     * The language pill names the language an assessor is approving feedback in.
     */
    public function test_language_label(): void {
        $this->assertSame(
            get_string('language_th', 'quiz_aigrader'),
            formatter::language_label('th')
        );
        $this->assertSame(
            get_string('language_en', 'quiz_aigrader'),
            formatter::language_label('en')
        );
    }

    /**
     * A region suffix still resolves to the base language.
     */
    public function test_language_label_handles_regions(): void {
        $expected = get_string('language_en', 'quiz_aigrader');
        $this->assertSame($expected, formatter::language_label('en-AU'));
        $this->assertSame($expected, formatter::language_label('en_US'));
    }

    /**
     * An unrecognised code shows as itself rather than being guessed at. Silently
     * labelling an unknown language "English" would tell an assessor something false
     * about what they are approving.
     */
    public function test_language_label_does_not_guess(): void {
        $this->assertSame('ZZ', formatter::language_label('zz'));
        $this->assertSame('', formatter::language_label(''));
        $this->assertSame('', formatter::language_label('   '));
    }
}
