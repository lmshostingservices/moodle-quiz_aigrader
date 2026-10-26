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
 * The grading criteria a learner is marked against.
 *
 * Moved out of ajax.php in 4.5.1. That file defines AJAX_SCRIPT and runs an action
 * router the moment it is included, so nothing in it could ever be reached by a unit
 * test. These are pure functions over their inputs and are now testable.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class criteria {
    /**
     * Split an assessor's rubric into discrete, labelled criteria.
     *
     * The criteria a learner is marked against are the ones the assessor wrote in the
     * question's "Information for graders" field. Until 4.4.1 the plugin displayed a fixed
     * vendor list instead - fifteen generic identifiers such as hazard_identified and
     * explain_process - which belonged to no unit of competency and which an RTO could
     * neither see nor change. Those identifiers remain only as a validation allowlist for
     * what the grading service reports; what the assessor and the learner now read is the
     * assessor's own wording.
     *
     * Recognised shapes, all of which assessors write in practice:
     *   "Grading criteria (marked out of 10 each):"    sets the default maximum
     *   "1. Understanding of the concept: explains..." label before the colon, rest is detail
     *   "- Uses a workplace example (5 marks)"         per-line maximum
     *
     * @param string $graderinfo Plain-text rubric, already flattened by formatter::html_to_text().
     * @return array List of ['key' => string, 'label' => string, 'detail' => string, 'max' => float].
     */
    public static function from_graderinfo($graderinfo): array {
        $graderinfo = (string)$graderinfo;
        if (trim($graderinfo) === '') {
            return [];
        }

        // A heading such as "marked out of 10 each" sets the default for every criterion that
        // does not carry its own figure.
        $defaultmax = 0.0;
        if (preg_match('/out\s+of\s+(\d+(?:\.\d+)?)\s*(?:marks?|points?)?\s*(?:each|per)?/i', $graderinfo, $m)) {
            $defaultmax = (float)$m[1];
        }

        $criteria = [];
        foreach (preg_split('/\n+/', $graderinfo) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // A list item. Assessors number them, letter them, or use a dash,
            // an asterisk, a bullet or an en dash as the marker.
            if (!preg_match('/^(\d+[.)]|[-*\x{2022}\x{2013}]|[a-z][.)])\s+(.*)$/ui', $line, $matches)) {
                continue;
            }

            $text = trim($matches[2]);
            // Ignore fragments too short to be a criterion and headings too long to be one.
            if (core_text::strlen($text) < 3 || core_text::strlen($text) > 300) {
                continue;
            }

            // A maximum written on the line itself wins over the heading's default.
            $max = $defaultmax;
            if (preg_match('/[\(\[]\s*(\d+(?:\.\d+)?)\s*(?:marks?|points?|pts?)?\s*[\)\]]\s*$/i', $text, $mm)) {
                $max = (float)$mm[1];
                $text = trim(core_text::substr($text, 0, core_text::strlen($text) - core_text::strlen($mm[0])));
            }

            // A label followed by a colon splits into the short name and the detail
            // behind it.
            //
            // Only a short leading PHRASE is a label. A character count alone was not
            // enough: "Explains the whole procedure from start to finish including this
            // part: the handover step" has its colon at 69 characters, inside the old
            // 80-character limit, and was split into a sentence-length "label". Real
            // criterion names are a few words - "Understanding of the concept" is four -
            // so the word count is the honest test and the character count the backstop.
            $label = $text;
            $detail = '';
            $colon = core_text::strpos($text, ':');
            if ($colon !== false && $colon >= 3 && $colon <= 60) {
                $candidate = trim(core_text::substr($text, 0, $colon));
                $words = preg_split('/\s+/u', $candidate, -1, PREG_SPLIT_NO_EMPTY);
                if (count($words) <= 8) {
                    $label = $candidate;
                    $detail = trim(core_text::substr($text, $colon + 1));
                }
            }

            if ($label === '') {
                continue;
            }

            $criteria[] = [
                'key'    => core_text::strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label)),
                'label'  => $label,
                'detail' => $detail,
                'max'    => $max > 0 ? $max : 1.0,
            ];
        }

        // A single item is a stray bullet, not a rubric.
        return count($criteria) >= 2 ? $criteria : [];
    }

    /**
     * Merge whatever the grading service reported onto the assessor's own criteria.
     *
     * The assessor's rubric is the spine. A score from the service attaches to the criterion
     * it names; a criterion the service said nothing about is still listed, unscored, so the
     * learner sees the full rubric rather than only the parts the AI happened to comment on.
     * If there is no rubric to attach to, the service's own criteria are returned as they
     * came, which is what happens on a question whose grader information is free prose.
     *
     * @param array $rubric Criteria parsed from the question, each with key, label, detail, max.
     * @param array $scored Criteria reported by the grading service.
     * @param array $met Criterion identifiers the service reported as met.
     * @return array|null Criteria ready for display, or null when there are none.
     */
    public static function merge_scores(array $rubric, array $scored, array $met): ?array {
        if (empty($rubric)) {
            return !empty($scored) ? array_values($scored) : null;
        }

        // Index whatever the service sent by a normalised label or key, so it can be matched
        // against the assessor's wording without depending on exact punctuation.
        $normalise = function ($value): string {
            return core_text::strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string)$value), '_'));
        };
        $byname = [];
        foreach ($scored as $sc) {
            $sc = (array)$sc;
            foreach (['key', 'label', 'name', 'criterion'] as $field) {
                if (!empty($sc[$field])) {
                    $byname[$normalise($sc[$field])] = $sc;
                }
            }
        }
        $metnormalised = array_map($normalise, $met);

        $out = [];
        foreach ($rubric as $criterion) {
            $key = $normalise($criterion['label']);
            $max = (float)$criterion['max'];
            $awarded = null;

            if (isset($byname[$key])) {
                $hit = $byname[$key];
                if (isset($hit['awarded']) && is_numeric($hit['awarded'])) {
                    $awarded = (float)$hit['awarded'];
                }
                if (isset($hit['max']) && is_numeric($hit['max']) && (float)$hit['max'] > 0) {
                    $max = (float)$hit['max'];
                }
            }

            // No per-criterion score from the service: fall back to the met / not-met list,
            // which is a binary judgement and is rendered as a full or empty bar.
            if ($awarded === null) {
                $awarded = in_array($key, $metnormalised, true) ? $max : 0.0;
            }

            $out[] = [
                'key'     => $criterion['key'],
                'label'   => $criterion['label'],
                'awarded' => round(min(max($awarded, 0), $max), 2),
                'max'     => $max,
                // What the learner did on this criterion. Falls back to the assessor's own
                // description of it, so the card is never blank.
                'comment' => isset($byname[$key]['comment'])
                    ? (string)$byname[$key]['comment']
                    : (string)$criterion['detail'],
                // What would earn the remaining marks. Shown only below full marks.
                'improve' => isset($byname[$key]['improve'])
                    ? (string)$byname[$key]['improve']
                    : '',
            ];
        }

        return $out;
    }
}
