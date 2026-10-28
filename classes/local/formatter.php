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
     * A line rubric_text() writes where a list nested in a list item carries its own marks: a
     * multi-level rubric that criteria::parse() does not read with certainty.
     */
    public const NESTED_MARKS = "\u{2060}[nested-marked-list]\u{2060}";

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
     * Flatten a rubric field to plain text, keeping table rows readable as criteria.
     *
     * RTO marking guides are very often tables - a criterion in one column, its marks in the
     * next. html_to_text() runs a table's cells together, so "Identifies the hazard" and "2"
     * became "Identifies the hazard2" and the row could not be read as a criterion. Here each
     * row becomes one line with its cells joined by " | ", which criteria::parse() reads.
     * Used only for reading criteria; what is sent to the grading service is unchanged.
     *
     * @param string $html Raw HTML from a question field.
     * @return string Plain text.
     */
    public static function rubric_text($html): string {
        $html = (string)$html;
        if (trim($html) === '') {
            return '';
        }

        // Struck-out and hidden text is not part of the rubric the assessor meant, and a list
        // inside a list item belongs to that item: "Identifies hazards, such as:" with the
        // examples nested beneath it is one criterion, not four.
        if (
            preg_match('~<li\b.*?<(ul|ol)\b~is', $html)
            || preg_match('~<(s|strike|del)\b|\bhidden\b|display\s*:\s*none|visibility\s*:\s*hidden|line-through~i', $html)
        ) {
            $html = self::flatten_nested_lists($html);
        }

        // Full-width brackets and colons, as typed in Chinese and Japanese, read as their
        // ordinary forms so "（2分）" is read as marks.
        $html = str_replace(['（', '）', '［', '］', '：'], ['(', ')', '[', ']', ':'], $html);

        // Word splits a sentence into many spans, sometimes mid-word ("Identif" "ies"), and
        // html_to_text() is told to space adjacent spans apart. In a rubric the spans carry no
        // structure, so they are removed before conversion and the words stay whole.
        $html = preg_replace('~</?(span|font|o:p)\b[^>]*>~i', '', $html);

        // Moodle's converter writes bold text and headings in capitals, so a criterion whose
        // name the assessor put in bold, as most are, would read in capitals on the card. The
        // bold and heading tags are therefore removed first, which keeps the author's own case.
        $html = preg_replace('~</?(strong|b)\b[^>]*>~i', '', $html);
        $html = preg_replace('~<(/?)h[1-6]\b[^>]*>~i', '<$1p>', $html);

        if (stripos($html, '<td') !== false || stripos($html, '<th') !== false) {
            // Each cell ends with a separator, each row with a line break. Paragraphs and
            // breaks inside a cell become spaces so a row stays on one line.
            $html = preg_replace_callback('~<t([dh])\b[^>]*>(.*?)</t\1\s*>~is', function ($m) {
                $cell = preg_replace('~<\s*(br|/p|/div|/li)\b[^>]*>~i', ' ', $m[2]);
                return '<span>' . $cell . ' | </span>';
            }, $html);
            $html = preg_replace('~</tr\s*>~i', "</tr><br>", $html);
        }
        // Non-breaking spaces, which Word and the editors put after list numbers and in empty
        // cells, become ordinary spaces so a list marker and its text are recognised.
        $text = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', self::html_to_text($html));
        return trim(preg_replace('/[^\S\n]+/u', ' ', $text));
    }

    /**
     * Fold each list nested inside a list item into that item's text.
     *
     * Unmarked sub-items are examples or detail of their parent, and are written into it, in
     * brackets, before the parent's own marks: "Identifies two hazards (wet floor, pallet
     * jack) (2 marks)". Sub-items that carry their own marks are a multi-level rubric; they
     * are kept as lines, and the NESTED_MARKS line marks the field as not read with certainty.
     *
     * @param string $html Editor HTML.
     * @return string The HTML with nested lists written inline, or unchanged if it cannot be parsed.
     */
    private static function flatten_nested_lists(string $html): string {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8"?><div id="aigrader-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $html;
        }
        $xpath = new \DOMXPath($doc);
        $hidden = '//s | //strike | //del | //*[@hidden] | //*[contains(translate(@style, " ", ""), "display:none")]'
            . ' | //*[contains(translate(@style, " ", ""), "visibility:hidden")]'
            . ' | //*[contains(translate(@style, " ", ""), "line-through")]';
        foreach (iterator_to_array($xpath->query($hidden)) as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
        // Innermost first, so a list nested three deep folds into its parent before that folds.
        $nested = iterator_to_array($xpath->query('//li//ul | //li//ol'));
        $marks = '(?:\d+(?:[.,]\d+)?\s*(?:marks?|mks?|points?|pts?|'
            . '\x{0E04}\x{0E30}\x{0E41}\x{0E19}\x{0E19}|\x{0111}i\x{1EC3}m|\x{5206})'
            . '(?![\p{L}]))';
        foreach (array_reverse($nested) as $list) {
            if (!$list->parentNode) {
                continue;
            }
            $parts = [];
            foreach ($list->childNodes as $child) {
                if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'li') {
                    $text = trim(preg_replace('/\s+/u', ' ', $child->textContent));
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            }

            if (preg_match('/' . $marks . '/iu', implode(' ', $parts))) {
                $fragment = $doc->createDocumentFragment();
                foreach ($parts as $part) {
                    $fragment->appendChild($doc->createElement('br'));
                    $fragment->appendChild($doc->createTextNode('- ' . $part));
                }
                $fragment->appendChild($doc->createElement('br'));
                $fragment->appendChild($doc->createTextNode(self::NESTED_MARKS));
                $fragment->appendChild($doc->createElement('br'));
                $list->parentNode->replaceChild($fragment, $list);
                continue;
            }

            // The parent item's own text, without the nested list.
            $item = $list->parentNode;
            while ($item && !($item instanceof \DOMElement && strtolower($item->nodeName) === 'li')) {
                $item = $item->parentNode;
            }
            $list->parentNode->removeChild($list);
            if (!$item || empty($parts)) {
                continue;
            }
            $own = trim(preg_replace('/\s+/u', ' ', $item->textContent));
            $folded = implode(', ', $parts);
            $trailing = '/^(.*?)\s*([\(\[]\s*(?:up\s+to\s+)?' . $marks . '\s*[\)\]]\.?|\s[\x{2013}\x{2014}=-]\s*' . $marks
                . '\.?)\s*$/iu';
            $lead = '/[,\s]*(?:such\s+as|e\.g\.|eg|for\s+example|including|for\s+instance)?\s*:?\s*$/iu';
            if (preg_match($trailing, $own, $m)) {
                $own = preg_replace($lead, '', trim($m[1])) . ' (' . $folded . ') ' . trim($m[2]);
            } else {
                $own = preg_replace($lead, '', $own) . ' (' . $folded . ')';
            }
            while ($item->firstChild) {
                $item->removeChild($item->firstChild);
            }
            $item->appendChild($doc->createTextNode($own));
        }
        $root = $doc->getElementById('aigrader-root');
        if (!$root) {
            return $html;
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
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
