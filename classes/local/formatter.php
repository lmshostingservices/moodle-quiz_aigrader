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

use core_text;

/**
 * Small formatting helpers shared by the grading screens.
 *
 * Moved out of ajax.php in 4.5.1. That file defines AJAX_SCRIPT and runs an action
 * router the moment it is included, so nothing in it could ever be reached by a unit
 * test. These are pure functions over their inputs and are now testable.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class formatter {
    /**
     * Format a mark and a maximum mark as a score label.
     *
     * Both values are formatted rather than cast with intval(), which rendered a 2.5-mark
     * question as "2/2" in the assessor's view and in the student notification. The separator
     * is always a full stop because these labels are parsed back with floatval() elsewhere.
     *
     * @param float $mark The mark awarded.
     * @param float $maxmark The question's maximum mark.
     * @return string e.g. '2.50/2.50'.
     */
    public static function score_label($mark, $maxmark): string {
        return number_format((float)$mark, 2, '.', '') . '/' . number_format((float)$maxmark, 2, '.', '');
    }

    /**
     * Flatten editor HTML to plain text without destroying its structure.
     *
     * strip_tags() was used here until 4.4.0, with a comment claiming line breaks were
     * preserved. Atto and TinyMCE write paragraphs and lists as markup, not as newlines, so
     * stripping the tags ran a well-structured answer into a single line and reduced a
     * numbered list of performance criteria in the rubric to one sentence. The AI was then
     * asked to comment on the organisation of text whose organisation had been removed on the
     * way out, and two learners who wrote identically in different editors were presented
     * differently.
     *
     * @param string $html Raw HTML from a question or response field.
     * @return string Plain text with paragraphs, list items and rows on their own lines.
     */
    public static function html_to_text($html): string {
        $html = (string)$html;
        if (trim($html) === '') {
            return '';
        }

        // Moodle's html_to_text() keeps paragraphs and list items as newlines, but not a closing
        // div. The criteria breakdown the plugin writes into the learner's feedback is built from
        // divs, so without this a saved summary read "Understanding of the concept0 / 4 This
        // criterion..." as one run-on line in the next attempt's "Previous attempt" panel.
        $html = preg_replace('~</div\s*>~i', "</div>\n", $html);

        // Adjacent inline elements, such as a criterion's name and its score, would otherwise
        // run together ("concept0 / 4"). A space is safe: runs of spaces are collapsed below.
        $html = preg_replace('~</span\s*>~i', '</span> ', $html);

        // The width argument is 0 so it does not hard-wrap, and links are not footnoted because a
        // URL list is noise to the grader.
        $text = html_to_text($html, 0, false);

        // Collapse runs of spaces and tabs but never newlines, then collapse runs of more than
        // two blank lines so the payload stays compact.
        $text = preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Shorten text to a limit without cutting a word in half.
     *
     * The summary saved after approval is shown in the next attempt's "Previous attempt"
     * panel and sent to the grading service. A hard character cut left a fragment such as
     * "Name the n" at the end of the panel; this cuts at the last space and marks the cut.
     *
     * @param string $text Plain text.
     * @param int $limit Maximum length in characters, including the ellipsis.
     * @return string The text, or a shortened copy ending in an ellipsis.
     */
    public static function summarise(string $text, int $limit = 300): string {
        $text = trim($text);
        if (\core_text::strlen($text) <= $limit) {
            return $text;
        }
        $cut = \core_text::substr($text, 0, $limit - 1);
        $space = \core_text::strrpos($cut, ' ');
        if ($space !== false && $space > $limit / 2) {
            $cut = \core_text::substr($cut, 0, $space);
        }
        return rtrim($cut, " \n,;:-") . "\u{2026}";
    }

    /**
     * Summarise approved feedback for the next attempt's "Previous attempt" panel.
     *
     * The panel and the grading service both get at most 300 characters. Cutting the full
     * feedback at that length kept the first two criteria and lost the rest, so the panel
     * showed two of five marks. This keeps every criterion's mark line first, each with a
     * short version of its comment while that still fits, and only then any other text.
     *
     * @param string $text Approved feedback as plain text, from html_to_text().
     * @param int $limit Maximum length in characters.
     * @return string The summary.
     */
    public static function previous_summary(string $text, int $limit = 300): string {
        $criteria = [];
        $current = null;
        foreach (preg_split('/\n+/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'marked against your grading criteria') !== false) {
                continue;
            }
            if (preg_match('~^(.+?)\s+(\d+(?:\.\d+)?)\s*/\s*(\d+(?:\.\d+)?)$~u', $line)) {
                $criteria[] = ['score' => $line, 'comment' => ''];
                $current = count($criteria) - 1;
            } else if ($current !== null && $criteria[$current]['comment'] === '') {
                $criteria[$current]['comment'] = $line;
            } else {
                $current = null;
            }
        }
        if (!$criteria) {
            return self::summarise($text, $limit);
        }
        $scores = implode("\n", array_column($criteria, 'score'));
        if (\core_text::strlen($scores) >= $limit) {
            return self::summarise($scores, $limit);
        }
        // Share what is left between the comments.
        $room = intdiv($limit - \core_text::strlen($scores) - 2 * count($criteria), count($criteria));
        $lines = [];
        foreach ($criteria as $c) {
            $lines[] = $c['score'];
            // A comment is kept only whole: a clipped "This is not shown in the…" says less
            // than the mark beside it.
            if ($c['comment'] !== '' && \core_text::strlen($c['comment']) <= $room) {
                $lines[] = $c['comment'];
            }
        }
        return self::summarise(implode("\n", $lines), $limit);
    }

    /**
     * Human-readable name for a feedback language code.
     *
     * Falls back to the code itself rather than guessing, so an unexpected value shows as
     * what it is instead of silently reading as English.
     *
     * @param string $code Language code such as 'th' or 'en-AU'.
     * @return string Display name, or '' when there is nothing useful to show.
     */
    public static function language_label($code): string {
        $code = trim((string)$code);
        if ($code === '') {
            return '';
        }
        $base = core_text::strtolower(explode('-', str_replace('_', '-', $code))[0]);
        $known = ['en', 'es', 'fr', 'ar', 'zh', 'ja', 'th', 'vi', 'hi', 'id', 'pt'];
        if (in_array($base, $known, true)) {
            return get_string('language_' . $base, 'quiz_aigrader');
        }
        return core_text::strtoupper($code);
    }
}
