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
 * test. These are pure functions over their inputs and are testable.
 *
 * 5.0.4: criteria are found wherever the question author put them, and the plugin only
 * presents a breakdown it is certain of. The card turns criteria scores into the mark, so a
 * wrong criterion or a wrong weight is a wrong mark; a breakdown the plugin cannot read with
 * certainty is never guessed at. resolve() returns one of three outcomes:
 *
 *   certain    A rubric whose criteria and weights are unambiguous. Used as written.
 *   uncertain  The assessor wrote marking guidance, but in a layout that cannot be read with
 *              certainty: mark bands, pick-lists, percentages, "/2", nested marked lists,
 *              marks on a separate line, and so on. The grading service is asked to condense
 *              that guidance into a short set of criteria, and every criterion it returns is
 *              checked against the assessor's own text before it is used (from_service()).
 *   none       Nothing written. The site's policy decides: criteria derived from the
 *              question and labelled as such, one overall mark, or marking by hand.
 *
 * Whatever goes wrong downstream, the fallback is one overall mark, never an invented
 * breakdown.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class criteria {
    /** @var string Criteria were read from the question's "Information for graders". */
    public const SOURCE_GRADERINFO = 'graderinfo';
    /** @var string Criteria were read from the question text itself. */
    public const SOURCE_QUESTIONTEXT = 'questiontext';
    /** @var string Criteria were read from the question's General feedback. */
    public const SOURCE_GENERALFEEDBACK = 'generalfeedback';
    /** @var string The grading service condensed the assessor's own guidance into criteria. */
    public const SOURCE_EXTRACTED = 'extracted';
    /** @var string The grading service derived criteria because the question had none. */
    public const SOURCE_INFERRED = 'inferred';
    /** @var string No criteria anywhere: marked holistically, or left for manual marking. */
    public const SOURCE_NONE = 'none';

    /** @var string A rubric read with certainty. */
    public const CERTAIN = 'certain';
    /** @var string Marking guidance that cannot be read with certainty. */
    public const UNCERTAIN = 'uncertain';
    /** @var string No marking guidance. */
    public const NONE = 'none';

    /** @var string No criteria found: ask the service to derive them, clearly labelled. */
    public const POLICY_INFER = 'infer';
    /** @var string No criteria found: one overall mark, no per-criterion breakdown. */
    public const POLICY_HOLISTIC = 'holistic';
    /** @var string No criteria found: do not suggest a mark; the assessor marks by hand. */
    public const POLICY_MANUAL = 'manual';

    /** @var int Recommended fewest criteria per question. */
    public const DEFAULT_MIN = 3;
    /** @var int Recommended most criteria per question. */
    public const DEFAULT_MAX = 5;

    /** @var int Longest label shown on a criterion; the rest moves to its detail line. */
    public const LABEL_MAX = 90;

    /**
     * The grading service's internal criterion identifiers.
     *
     * Used only to validate what the service reports as met. They belong to no unit of
     * competency, and nothing derived from them is ever shown to an assessor or a learner:
     * from_service() refuses them unless the question itself uses those words.
     */
    public const VENDOR_IDS = [
        'explain_process', 'example_provided', 'site_specific',
        'hazard_identified', 'control_measure', 'procedure_followed',
        'legislation_referenced', 'workplace_context', 'practical_application',
        'communication_clear', 'documentation_complete', 'safety_awareness',
        'risk_assessment', 'compliance_demonstrated', 'knowledge_applied',
    ];

    /** @var string The units a mark is written in. */
    public const UNIT = '(?:marks?|mks?|points?|pts?|\x{0E04}\x{0E30}\x{0E41}\x{0E19}\x{0E19}|\x{0111}i\x{1EC3}m|puntos?|punkte?|'
        . '\x{5206})';

    /**
     * Anything that still looks like marks once a line's marks have been read. A line with a
     * second figure, a unit not read ("2m", "2pts"), or marks in words is not read with certainty.
     */
    public const MARKS_RESIDUE = '/\d+(?:[.,]\d+)?\s*(?:marks?|mks?|m|pts?|points?|\x{5206}|'
        . '\x{0E04}\x{0E30}\x{0E41}\x{0E19}\x{0E19}|'
        . '\x{0111}i\x{1EC3}m)(?![\p{L}])|\b(?:one|two|three|four|five|six|seven|eight|nine|ten|half)\s+marks?\b/iu';

    /**
     * Words that open a line which is an instruction to the marker, not something the response
     * is marked on: "Do not award...", "Accept any...", "Bonus:", "OR ...".
     */
    public const INSTRUCTION_LEAD = '/^(?:do\s+not|don\W?t|note|nb|n\.b\.|accept|also\s+accept|deduct|minus|subtract|penalty|bonus|'
        . 'optional|extra\s+credit|or|either|if|only|alternatively|award|penalis|penaliz|half\s+marks?|partial\s+credit|'
        . 'where|learners?\s+who|students?\s+who|instead)\b/i';

    /**
     * Lines that are alternatives, a superseded version, or an exception, whatever marks they
     * carry: "OR ...", "(or)", "Previous version", "Option 2", "-1 mark for ...".
     */
    public const NOT_A_CRITERION = '/^(?:bonus|optional|extra\s+credit|or|either|alternatively|instead|\(or\)|option\s+\w+\b|'
        . 'previous\s+version|superseded|penalis|penaliz|deduct|minus|subtract|-\s*\d|where\b|when\s+(?:the|a|an)\b|'
        . 'if\s+(?:the|a|an|only|no)\b|(?:learners?|students?|candidates?)\s+who\b|partial\s+credit|half\s+marks?|'
        . 'max(?:imum)?\s+(?:for|of|if)\b)/i';

    /**
     * A heading or note that sets out alternatives or a version: "(or)", "Option 2", "Learners
     * who chose Scenario B:", "Previous version (superseded)", "Learners may instead answer:".
     * Criteria read across alternatives would be added together, so the field is not certain.
     */
    public const ALTERNATIVE = '/^\(?\s*or\s*\)?\s*:?$|^either\b|^option\s+\w+\s*:?$|\bsuperseded\b|'
        . '\b(?:previous|old|earlier|alternative|another)\s+(?:version|rubric|criteria|marking)\b|'
        . '\b(?:may|can|could|might)\s+(?:\w+\s+)?instead\b|^alternatively\b|^instead\b|'
        . '\b(?:chose|choose|chooses|choosing|selected|selects|attempt(?:s|ed)?)\s+(?:scenario|option|case|task|version)\b|'
        . '^(?:scenario|case\s+study|version|option)\s+[a-z0-9]+\s*:?$/i';

    /**
     * A line that is a total, once its marks are read: "Total (10 marks)", "Question total - 10".
     */
    public const TOTAL_TEXT = '/^(?:(?:question|grand|sub-?)\s*)?total(?:\s+(?:marks?|score|points?))?(?:\s+for\s+(?:this\s+)?'
        . '(?:question|task|part|section)(?:\s*[a-z0-9.]+)?)?\s*:?$|^marks?\s+total$|^sum$|^altogether$|^question\s+value$|'
        . '^marks?\s+available$|^max(?:imum)?\s+marks?(?:\s+for\s+this\s+\w+)?$|^overall(?:\s+total)?$/i';

    /**
     * Words that open a section of marking criteria.
     */
    public const CRITERIA_HEADING = '/\b(criteria|criterion|rubric|marking\s+(guide|scheme|key)|mark\s+scheme|'
        . 'assessment\s+guide|marked\s+on|assessed\s+on|marks?\s+(awarded|allocation|breakdown))\b/i';

    /**
     * Words that open a sample or model answer. Its unmarked lines are what a good answer
     * says, not what it is marked against.
     */
    public const SAMPLE_HEADING = '/\b(sample|model|example|exemplar|suggested|expected|possible|benchmark)\s+'
        . '(answers?|responses?)\b|\banswer\s+(guide|key)\b|^answers?\s*:?\s*$|^benchmarks?\b|'
        . '\bindicative\s+content\b|\bsatisfactory\s+(response|answer)\b|\bgood\s+answer\b|\banswer\s+looks\s+like\b|'
        . '\banswers?\s+(should|must)\s+(include|contain|cover)\b|\bwhat\s+to\s+look\s+for\b|^example\s+response\b/i';

    /**
     * Words that introduce examples of an answer rather than criteria for it.
     */
    public const EXAMPLES_LEAD = '/\b(for\s+example|for\s+instance|examples?\b|such\s+as|e\.g\.|may\s+include|could\s+include|'
        . 'might\s+include|can\s+include|possible\s+answers?|acceptable\s+answers?|answers?\s+(may|could|might)\b|'
        . 'not\s+limited\s+to)/i';

    /**
     * Headings that close a rubric: what follows is not something the response is marked on.
     */
    public const OTHER_HEADING = '/^(notes?|assessor\s+notes?|notes?\s+(to|for)\s+(the\s+)?assessors?|instructions?|'
        . 'submission|submission\s+requirements?|requirements?|format|resources?|references?|comments?|'
        . 'feedback|general\s+feedback|reasonable\s+adjustments?|conditions?|marking\s+notes?|assessor\s+instructions?|'
        . 'do\s+not\s+accept|also\s+accept|mapping|common\s+errors?|bonus|optional)\b/i';

    /**
     * A pick-list lead: "identify any 2 of", "at least three of the following".
     */
    public const PICK = '/\b(?:any|at\s+least|a\s+minimum\s+of|minimum\s+of|choose|select|only|first|up\s+to)\s+'
        . '(\d+|one|two|three|four|five|six|seven|eight|nine|ten)\b(?!\s*(?:marks?|points?))|'
        . '\b(\d+|one|two|three|four|five|six|seven|eight|nine|ten)\s+(?:of\s+(?:these|the\s+following|them)|'
        . 'from\s+(?:the\s+list|these|the\s+following))\b/i';

    /**
     * Mark notations that are not read with certainty. Each sends the field to the uncertain path.
     */
    public const UNSURE_NOTATIONS = [
        'percent' => '/\d\s*%/',
        'fraction' => '/[\x{00BC}-\x{00BE}\x{2150}-\x{215E}]/u',
        'slash' => '/(?:^|\s|_)\/\s*\d+(?:\.\d+)?\s*$/u',
        'marks_colon' => '/\bmarks?\s*:\s*\d/i',
        'times' => '/\d\s*[x\x{00D7}]\s*\d+(?:\.\d+)?\s*(?:marks?|points?)/iu',
        'range' => '/\d+(?:\.\d+)?\s*[-\x{2013}]\s*\d+(?:\.\d+)?\s*(?:marks?|points?)/iu',
        'each' => '/\d+(?:\.\d+)?\s*(?:marks?|points?)\s+(?:each|per)\b/i',
        'midline' => '/\p{L}\s*[\(\[]\s*\d+(?:\.\d+)?\s*(?:marks?|points?)\s*[\)\]]\s+(?![\x{2013}\x{2014}:-])\p{L}/iu',
    ];

    /**
     * The site's recommended range for the number of criteria on a question.
     *
     * @return array ['min' => int, 'max' => int], always with 1 <= min <= max <= 10.
     */
    public static function limits(): array {
        $min = (int)get_config('quiz_aigrader', 'criteria_min');
        $max = (int)get_config('quiz_aigrader', 'criteria_max');
        $min = $min > 0 ? min($min, 10) : self::DEFAULT_MIN;
        $max = $max > 0 ? min($max, 10) : self::DEFAULT_MAX;
        if ($max < $min) {
            $max = $min;
        }
        return ['min' => $min, 'max' => $max];
    }

    /**
     * What to do with a question that carries no marking guidance anywhere.
     *
     * @return string One of the POLICY_ constants.
     */
    public static function policy(): string {
        $policy = (string)get_config('quiz_aigrader', 'nocriteria_policy');
        $valid = [self::POLICY_INFER, self::POLICY_HOLISTIC, self::POLICY_MANUAL];
        return in_array($policy, $valid, true) ? $policy : self::POLICY_INFER;
    }

    /**
     * Resolve criteria from a question's raw editor fields.
     *
     * The one entry point for grading, approval and the report page, so all three always
     * agree about a question.
     *
     * @param string $graderinfohtml Information for graders, as stored.
     * @param string $questionhtml Question text, as stored.
     * @param string $feedbackhtml General feedback, as stored.
     * @param float $maxmark The question's maximum mark in this quiz.
     * @param float $defaultmark The question's own default mark; 0 when not known.
     * @return array As resolve().
     */
    public static function for_fields(
        string $graderinfohtml,
        string $questionhtml,
        string $feedbackhtml,
        float $maxmark,
        float $defaultmark = 0.0
    ): array {
        return self::resolve(
            formatter::rubric_text($graderinfohtml),
            formatter::rubric_text($questionhtml),
            formatter::rubric_text($feedbackhtml),
            $maxmark,
            null,
            $defaultmark
        );
    }

    /**
     * Find a question's marking criteria wherever its author put them.
     *
     * Sources are tried in order: Information for graders, then the question text, then
     * General feedback. Information for graders is read leniently: a list there is the
     * criteria, because that is what the field is for, and anything written there at all is
     * marking guidance. The question text and General feedback are read strictly: a line
     * counts only when it carries its own marks or sits under a heading that names criteria,
     * so a list of sub-questions or scenario facts is not mistaken for a rubric.
     *
     * A rubric is used as written only when every criterion carries its own marks, nothing in
     * it is ambiguous, and its marks add up to the question's mark. That is the whole contract,
     * and it is deliberately strict: the card turns criteria scores into the mark, so anything
     * short of it would be the plugin guessing at weights. Everything else the assessor wrote
     * is uncertain, and goes to the grading service to be condensed and checked.
     *
     * The first source holding marking guidance decides the outcome, except that guidance with
     * no marks in it at all - a sample answer, examples, an unmarked list - does not outrank a
     * clean rubric in a later field.
     *
     * @param string $graderinfo Plain text of Information for graders.
     * @param string $questiontext Plain text of the question.
     * @param string $generalfeedback Plain text of General feedback.
     * @param float $maxmark The question's maximum mark in this quiz.
     * @param array|null $limits ['min' => int, 'max' => int]; the site's when null.
     * @param float $defaultmark The question's own default mark; 0 when not known.
     * @return array {
     *     confidence: string, source: string, criteria: array (certain only), candidates: array,
     *     total: float, maxmark: float, declaredtotal: float|null, guidancetext: string,
     *     reasons: string[], warnings: array of ['code' => string, 'a' => mixed]
     * }
     */
    public static function resolve(
        string $graderinfo,
        string $questiontext,
        string $generalfeedback,
        float $maxmark,
        ?array $limits = null,
        float $defaultmark = 0.0
    ): array {
        $limits = $limits ?? self::limits();
        $warnings = [];
        $fallback = null;
        $pending = null;
        $markedonly = null;
        // The marks a rubric must add up to. The question's own default mark when it has one, so a
        // rubric counted twice cannot pass by matching a quiz that weights the question double.
        // The quiz's mark only when the default is unknown or Moodle's untouched 1, which means
        // the author set the marks in the quiz instead.
        $adds = function (float $total) use ($maxmark, $defaultmark): bool {
            if ($defaultmark > 0 && abs($total - $defaultmark) <= 0.005) {
                return true;
            }
            $usequiz = $defaultmark <= 0 || abs($defaultmark - 1.0) <= 0.005;
            return $usequiz && $maxmark > 0 && abs($total - $maxmark) <= 0.005;
        };

        $sources = [
            self::SOURCE_GRADERINFO => [$graderinfo, false],
            self::SOURCE_QUESTIONTEXT => [$questiontext, true],
            self::SOURCE_GENERALFEEDBACK => [$generalfeedback, true],
        ];

        foreach ($sources as $source => [$text, $strict]) {
            $parsed = self::parse($text, $strict);
            $items = $parsed['items'];
            if (!$parsed['guidance'] && count($items) < 2) {
                continue;
            }

            $reasons = $parsed['reasons'];
            if ($parsed['pointslist'] !== null) {
                $warnings[] = ['code' => 'points_list', 'a' => (object)[
                    'source' => $source, 'count' => $parsed['pointslist']->count, 'cap' => $parsed['pointslist']->cap,
                ]];
                $reasons[] = 'pointslist';
            }

            $weighted = self::apply_weights($items, $parsed['declaredtotal']);
            $total = self::total($weighted);

            if (empty($reasons) && count($items) >= 2) {
                // Marked lines in a question's own text, with no heading or total to say they
                // are a rubric, are more likely sub-questions or figures when there are many of
                // them or one is worth more than the whole question.
                if ($strict && !$parsed['headed']) {
                    $toolarge = false;
                    foreach ($weighted as $item) {
                        if ($maxmark > 0 && $item['max'] > $maxmark + 0.005) {
                            $toolarge = true;
                        }
                    }
                    if (count($items) > $limits['max'] || $toolarge) {
                        $warnings[] = ['code' => 'source_ignored', 'a' => (object)[
                            'source' => $source, 'count' => count($items),
                        ]];
                        $fallback = $fallback ?? ['source' => $source, 'parsed' => $parsed, 'text' => $text,
                            'weighted' => $weighted, 'total' => $total];
                        continue;
                    }
                }

                $declared = $parsed['declaredtotal'];
                if ($declared !== null && abs($declared - $total) > 0.005) {
                    // The question says its criteria add up to one figure and they add up to
                    // another. Something in the rubric has not been read as written.
                    $warnings[] = ['code' => 'declared_mismatch', 'a' => (object)[
                        'declared' => self::fmt($declared), 'total' => self::fmt($total),
                    ]];
                    $reasons[] = 'declared';
                }
            }

            // The contract for using a rubric as written: every criterion carries its own marks,
            // and they add up to the question's mark. Anything short of that is not a guess the
            // plugin makes; the guidance goes to the grading service to be condensed and checked.
            if (empty($reasons) && count($items) >= 2) {
                if (!$parsed['explicit']) {
                    $reasons[] = 'unmarked';
                } else if (!$adds($total)) {
                    $warnings[] = ['code' => 'total_mismatch', 'a' => (object)[
                        'total' => self::fmt($total), 'maxmark' => self::fmt($maxmark),
                    ]];
                    $reasons[] = 'total';
                }
            }

            if (empty($reasons) && count($items) >= 2) {
                if (count($items) > $limits['max']) {
                    $warnings[] = ['code' => 'too_many', 'a' => (object)[
                        'count' => count($items), 'min' => $limits['min'], 'max' => $limits['max'],
                    ]];
                } else if (count($items) < $limits['min'] && $maxmark >= $limits['min']) {
                    // A two-mark question with two criteria is right; a ten-mark question with
                    // two is thin. Only flag the second.
                    $warnings[] = ['code' => 'too_few', 'a' => (object)[
                        'count' => count($items), 'min' => $limits['min'], 'max' => $limits['max'],
                    ]];
                }
                return self::outcome(
                    self::CERTAIN,
                    $source,
                    $weighted,
                    $weighted,
                    $total,
                    $maxmark,
                    $parsed['declaredtotal'],
                    $text,
                    [],
                    $warnings
                );
            }

            if (!$parsed['guidance'] && empty($parsed['reasons']) && $parsed['pointslist'] === null) {
                continue;
            }
            if (empty($reasons)) {
                $reasons[] = 'layout';
            }
            $uncertain = ['source' => $source, 'weighted' => $weighted, 'total' => $total, 'text' => $text,
                'declared' => $parsed['declaredtotal'], 'reasons' => array_values(array_unique($reasons)),
                'explicit' => $parsed['explicit'] && count($items) >= 2];
            // Guidance with no marks in it at all - a sample answer, examples, an unmarked list -
            // does not outrank a clean rubric in a later field. Anything with marks, a pick-list
            // or a marking instruction does: the assessor wrote it where they wrote it.
            if (!$parsed['markish']) {
                // Of two fields without marks, the one that lists criteria is the better guide:
                // "You will be marked on:" in the question outranks examples for the grader.
                if ($pending === null || (count($pending['weighted']) < 2 && count($items) >= 2)) {
                    $pending = $uncertain;
                }
                continue;
            }
            // Marks written about, but no criteria to propose: notes such as "Do not accept 'keep
            // it secret' (1 mark)". A later field that does list criteria is the better proposal.
            if (
                count($weighted) === 0 && empty($parsed['reasons']) && $parsed['pointslist'] === null
                && !$parsed['loosemarks']
            ) {
                $markedonly = $markedonly ?? $uncertain;
                continue;
            }
            return self::uncertain($uncertain, $maxmark, $warnings);
        }

        if ($pending !== null && ($markedonly === null || count($pending['weighted']) > 0)) {
            return self::uncertain($pending, $maxmark, $warnings);
        }
        if ($markedonly !== null) {
            return self::uncertain($markedonly, $maxmark, $warnings);
        }

        if ($fallback !== null) {
            // Only marked sub-questions were found. They are the assessor's marking structure,
            // but too many, or too large, to be read as criteria with certainty.
            $warnings[] = ['code' => 'uncertain', 'a' => (object)[
                'source' => $fallback['source'], 'reason' => 'subquestions',
            ]];
            return self::outcome(
                self::UNCERTAIN,
                $fallback['source'],
                [],
                $fallback['weighted'],
                $fallback['total'],
                $maxmark,
                $fallback['parsed']['declaredtotal'],
                $fallback['text'],
                ['subquestions'],
                $warnings
            );
        }

        $warnings[] = ['code' => 'none_found', 'a' => null];
        return self::outcome(self::NONE, self::SOURCE_NONE, [], [], 0.0, $maxmark, null, '', [], $warnings);
    }

    /**
     * An uncertain resolution.
     *
     * @param array $u ['source', 'weighted', 'total', 'text', 'declared', 'reasons'].
     * @param float $maxmark The question's mark.
     * @param array $warnings Warnings so far.
     * @return array
     */
    private static function uncertain(array $u, float $maxmark, array $warnings): array {
        $warnings[] = ['code' => 'uncertain', 'a' => (object)['source' => $u['source'], 'reason' => $u['reasons'][0]]];
        return self::outcome(
            self::UNCERTAIN,
            $u['source'],
            [],
            $u['weighted'],
            $u['total'],
            $maxmark,
            $u['declared'],
            $u['text'],
            $u['reasons'],
            $warnings,
            $u['explicit'] ?? false
        );
    }

    /**
     * Assemble a resolution.
     *
     * @param string $confidence One of CERTAIN, UNCERTAIN, NONE.
     * @param string $source Where the guidance is.
     * @param array $criteria Criteria to use as written (certain only).
     * @param array $candidates What was read, certain or not; a hint for the grading service.
     * @param float $total Sum of the candidates' marks.
     * @param float $maxmark The question's mark.
     * @param float|null $declared A declared total.
     * @param string $text The guidance, as plain text.
     * @param array $reasons Why the guidance is uncertain.
     * @param array $warnings Warnings.
     * @return array
     */
    private static function outcome(
        string $confidence,
        string $source,
        array $criteria,
        array $candidates,
        float $total,
        float $maxmark,
        ?float $declared,
        string $text,
        array $reasons,
        array $warnings,
        bool $weighted = false
    ): array {
        return [
            'confidence' => $confidence,
            'source' => $source,
            'criteria' => $criteria,
            'candidates' => $candidates,
            'total' => $confidence === self::CERTAIN ? $total : 0.0,
            'maxmark' => $maxmark,
            'declaredtotal' => $declared,
            'guidancetext' => $text,
            'reasons' => $reasons,
            'warnings' => $warnings,
            // Whether every candidate carries the assessor's own figure, so the service's weights
            // can be checked against them.
            'candidatesweighted' => $weighted,
        ];
    }

    /**
     * Turn resolution warnings into sentences for the assessor.
     *
     * @param array $warnings As in the result of resolve().
     * @return array List of ['code' => string, 'message' => string].
     */
    public static function warning_messages(array $warnings): array {
        $out = [];
        foreach ($warnings as $warning) {
            $a = $warning['a'] ?? null;
            if (is_object($a)) {
                $a = clone $a;
                if (isset($a->source)) {
                    $a->source = get_string('criteriasource_' . $a->source, 'quiz_aigrader');
                }
                if (isset($a->reason)) {
                    $a->reason = get_string('criteriareason_' . $a->reason, 'quiz_aigrader');
                }
            }
            $out[] = [
                'code' => $warning['code'],
                'message' => get_string('criteriawarn_' . $warning['code'], 'quiz_aigrader', $a),
            ];
        }
        return $out;
    }

    /**
     * The line on the assessor's card that says where the criteria came from.
     *
     * @param string $source Where the criteria shown came from: a field, SOURCE_EXTRACTED,
     *     SOURCE_INFERRED or SOURCE_NONE.
     * @param string $guidance The field the assessor's guidance is in, for condensed criteria.
     * @param bool $hasguidance Whether the question has marking guidance, read or not.
     * @return string
     */
    public static function context_line(string $source, string $guidance, bool $hasguidance = false): string {
        if ($source === self::SOURCE_EXTRACTED) {
            return get_string(
                'criteria_from_extracted',
                'quiz_aigrader',
                get_string('criteriasource_' . $guidance, 'quiz_aigrader')
            );
        }
        if ($source === self::SOURCE_INFERRED) {
            return get_string('criteria_from_inferred', 'quiz_aigrader');
        }
        if ($source === self::SOURCE_NONE) {
            return $hasguidance
                ? get_string(
                    'criteria_guidance_holistic',
                    'quiz_aigrader',
                    get_string('criteriasource_' . $guidance, 'quiz_aigrader')
                )
                : get_string('criteria_none_holistic', 'quiz_aigrader');
        }
        return get_string('criteria_from', 'quiz_aigrader', get_string('criteriasource_' . $source, 'quiz_aigrader'));
    }

    /**
     * A fingerprint of the criteria a decision was made against, for the decision record.
     *
     * @param array $resolved With 'source' and 'criteria'.
     * @return string SHA-1.
     */
    public static function fingerprint(array $resolved): string {
        return sha1(json_encode([
            'source' => $resolved['source'],
            'criteria' => array_map(function ($c) {
                return [(string)($c['label'] ?? ''), (float)($c['max'] ?? 0)];
            }, $resolved['criteria']),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Split an assessor's rubric into discrete, labelled criteria.
     *
     * Kept for the callers and tests written against 4.5.1. This is the lenient reading of
     * the grader information alone, certain or not; resolve() is what grading uses.
     *
     * @param string $graderinfo Plain-text rubric, already flattened by formatter::html_to_text().
     * @return array List of ['key' => string, 'label' => string, 'detail' => string, 'max' => float].
     */
    public static function from_graderinfo($graderinfo): array {
        $parsed = self::parse((string)$graderinfo, false);
        if (count($parsed['items']) < 2) {
            // A single item is a stray bullet, not a rubric.
            return [];
        }
        return self::apply_weights($parsed['items'], $parsed['declaredtotal']);
    }

    /**
     * Read criteria out of one block of plain text.
     *
     * Two passes. The first classifies each line on its own: a list item, a heading, a heading
     * that carries a total, a table row, a lead-in such as "identify any two of the
     * following:", a marking instruction, a total, or prose. The second reads the lines in
     * order, keeping track of the section each is in, and decides which items are criteria.
     * Anything that cannot be read with certainty is recorded as a reason, and resolve() then
     * treats the field as uncertain rather than guess.
     *
     * @param string $text Plain text, from formatter::rubric_text().
     * @param bool $strict Accept only lines that carry marks or sit under a criteria heading.
     * @return array {
     *     items: array, declaredtotal: float|null, pointslist: object|null, reasons: string[],
     *     guidance: bool, headed: bool
     * }
     */
    public static function parse(string $text, bool $strict): array {
        $result = ['items' => [], 'declaredtotal' => null, 'pointslist' => null, 'reasons' => [],
            'guidance' => false, 'headed' => false, 'markish' => false, 'explicit' => false, 'loosemarks' => false];

        $plain = trim(str_replace(formatter::NESTED_MARKS, '', $text));
        if ($plain === '') {
            return $result;
        }
        // Anything written in Information for graders is guidance for the marker.
        $result['guidance'] = !$strict;

        $defaultmax = 0.0;
        if (preg_match('/out\s+of\s+(\d+(?:\.\d+)?)\s*(?:marks?|points?)?\s*(?:each|per\b)/i', $plain, $m)) {
            $defaultmax = (float)$m[1];
        }

        $records = self::classify($text, $strict);
        foreach ($records as $r) {
            if (
                in_array($r['type'], ['total', 'marksonly', 'instruction', 'headmarked', 'nested'], true)
                || $r['max'] !== null || $r['zero'] || $r['pick'] !== null || $r['permark']
                || preg_match(self::MARKS_RESIDUE, $r['line'])
            ) {
                $result['markish'] = true;
            }
        }
        $reasons = [];
        $section = 'neutral';
        $table = null;
        $explicittotal = null;
        $subtotal = 0.0;
        $subtotals = 0;
        $pickn = null;
        $permark = false;
        $permarkfigure = null;
        $markprose = false;
        $closedbyextra = false;
        $items = [];
        $count = count($records);

        for ($i = 0; $i < $count; $i++) {
            $rec = $records[$i];
            $next = $records[$i + 1] ?? null;
            if ($rec['type'] !== 'tablerow' && $rec['type'] !== 'tablehead') {
                $table = null;
            }

            switch ($rec['type']) {
                case 'nested':
                    $reasons[] = 'nested';
                    $result['guidance'] = true;
                    continue 2;

                case 'total':
                    if ($rec['total'] !== null) {
                        $explicittotal = $explicittotal ?? $rec['total'];
                    }
                    $result['guidance'] = true;
                    continue 2;

                case 'marksonly':
                    $reasons[] = 'layout';
                    $result['guidance'] = true;
                    continue 2;

                case 'instruction':
                    // A marking instruction, such as award 1 mark for each correct hazard, up to 3.
                    $permark = true;
                    $result['loosemarks'] = true;
                    $result['guidance'] = true;
                    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:marks?|points?)\s+(?:for|per)\b/i', $rec['line'], $pm)) {
                        $permarkfigure = (float)$pm[1];
                    }
                    if ($rec['total'] !== null) {
                        $explicittotal = $explicittotal ?? $rec['total'];
                    }
                    if ($rec['pick'] !== null) {
                        $pickn = $rec['pick'];
                    }
                    continue 2;

                case 'heading':
                case 'prose':
                    if ($rec['total'] !== null) {
                        $explicittotal = $explicittotal ?? $rec['total'];
                    }
                    $lead = $rec['type'] === 'heading' || $rec['colon'];
                    if (preg_match(self::ALTERNATIVE, $rec['line'])) {
                        $reasons[] = 'instruction';
                    }
                    // A note that talks about marks, such as "full marks are 2 and 3" or "2 marks
                    // for each hazard": it may change what the criteria are worth.
                    if (
                        $rec['pick'] === null && $rec['total'] === null && preg_match('/\d/', $rec['line'])
                        && preg_match('/\b(?:marks?|points?|score|weight(?:ing)?s?)\b/i', $rec['line'])
                        && !preg_match(self::CRITERIA_HEADING, $rec['line'])
                    ) {
                        $markprose = true;
                    }
                    if ($rec['pick'] !== null && ($rec['colon'] || ($next && $next['type'] === 'item'))) {
                        $pickn = $rec['pick'];
                        $result['guidance'] = true;
                    }
                    if ($rec['permark'] && ($rec['colon'] || ($next && $next['type'] === 'item'))) {
                        $permark = true;
                    }
                    if (!$lead) {
                        // A line of text without marks or a list marker right beside a criterion,
                        // in a rubric: part of the criterion split by a line break, or a note.
                        // Either way the criterion as read is not the criterion as written.
                        $previous = $records[$i - 1] ?? null;
                        $after = $previous && $previous['type'] === 'item';
                        $before = $next && $next['type'] === 'item';
                        // In the question text, a whole sentence just before the criteria is the
                        // task for the learner ("Review the attached roster."), not a criterion
                        // broken across lines.
                        if ($strict && !$after && preg_match('/[.?!:]\s*$/u', $rec['line'])) {
                            $before = false;
                        }
                        if (($after || $before) && (!$strict || $section === 'criteria')) {
                            $reasons[] = 'layout';
                        }
                        continue 2;
                    }
                    $line = $rec['line'];
                    if (preg_match(self::CRITERIA_HEADING, $line) && !preg_match(self::SAMPLE_HEADING, $line)) {
                        $section = 'criteria';
                        $result['headed'] = true;
                        $result['guidance'] = true;
                    } else if (preg_match(self::SAMPLE_HEADING, $line)) {
                        $section = 'sample';
                        if ($strict) {
                            // A sample answer in General feedback is a benchmark for the marker.
                            $result['guidance'] = true;
                        }
                    } else if ($rec['colon'] && preg_match(self::EXAMPLES_LEAD, $line)) {
                        $section = 'sample';
                    } else if (preg_match(self::OTHER_HEADING, $line) && ($rec['colon'] || self::words($line) <= 4)) {
                        $section = 'closed';
                        // Bonus, optional and "do not accept" lines are plainly outside the rubric.
                        $closedbyextra = (bool)preg_match('/^(bonus|optional|extra|do\s+not\s+accept|also\s+accept)\b/i', $line);
                    } else if ($rec['colon'] && $section !== 'closed') {
                        $section = 'neutral';
                    }
                    continue 2;

                case 'headmarked':
                    // A heading with a total, such as Part A, Question 1 or Marking criteria with marks.
                    // A heading if items follow it; a "Part A" with nothing under it is itself
                    // a criterion. A criteria heading is always a heading.
                    $hasitems = (bool)preg_match(self::CRITERIA_HEADING, $rec['line']);
                    for ($j = $i + 1; $j < $count && !$hasitems; $j++) {
                        $t = $records[$j]['type'];
                        if (
                            $t === 'headmarked'
                            || ($t === 'heading' && $records[$j]['colon'] && preg_match(self::OTHER_HEADING, $records[$j]['line']))
                        ) {
                            break;
                        }
                        if ($t === 'item' || $t === 'tablerow') {
                            $hasitems = true;
                        }
                    }
                    if ($hasitems) {
                        $subtotal += $rec['max'];
                        $subtotals++;
                        $result['headed'] = true;
                        $result['guidance'] = true;
                        if ($section !== 'sample') {
                            $section = 'criteria';
                        }
                        continue 2;
                    }
                    $rec['type'] = 'item';
                    break;

                case 'tablehead':
                    $table = $rec;
                    if ($rec['rubric']) {
                        $result['headed'] = true;
                        $result['guidance'] = true;
                    }
                    continue 2;
            }

            // Items and table rows.
            if ($section === 'closed') {
                if ($rec['max'] === null || $rec['zero'] || $closedbyextra || $rec['type'] !== 'item') {
                    continue;
                }
                // Marks under a heading that closes the rubric: part of the rubric or not, the
                // marks as read may not be the marks as written. The line stays in what is
                // proposed, so the assessor decides.
                $reasons[] = 'ignored';
            }
            if ($rec['type'] === 'tablerow') {
                $rubrictable = $table !== null && $table['rubric'];
                $rec = self::table_row($rec, $table);
                if ($rec === null) {
                    continue;
                }
                if ($rec['total'] !== null) {
                    $explicittotal = $explicittotal ?? $rec['total'];
                    continue;
                }
                $accept = $rubrictable || $section === 'criteria' || (!$strict && $table === null && $rec['max'] !== null);
                if (!$accept) {
                    continue;
                }
            } else {
                if ($rec['max'] === null && !$rec['zero']) {
                    if ($section === 'sample') {
                        continue;
                    }
                    if ($strict && $section !== 'criteria') {
                        continue;
                    }
                }
            }

            if ($rec['unsure'] !== null || !empty($rec['residue'])) {
                $reasons[] = 'notation';
            }
            // An instruction to the marker rather than a criterion: "Do not award marks for
            // spelling", "Accept any reasonable hazard", "Bonus: ...", "OR names a control". A line
            // with its own marks that starts "Do not..." is a criterion ("Do not touch the person
            // until the power is isolated (1 mark)"); bonus, optional and alternatives never are.
            if (
                preg_match(self::NOT_A_CRITERION, $rec['text'])
                || ($rec['max'] === null && preg_match(self::INSTRUCTION_LEAD, $rec['text']))
            ) {
                $reasons[] = 'instruction';
            }
            // Marked lines in a sample answer, or lines that open with "e.g." or "such as", are
            // examples of an answer, whatever marks they carry.
            if ($section === 'sample' || preg_match('/^(?:e\.g\.|eg\b|such\s+as|for\s+example|example\b)/i', $rec['text'])) {
                $reasons[] = 'layout';
            }
            // A criterion that starts in lower case is the end of a sentence begun on another line.
            $previous = $records[$i - 1] ?? null;
            // The line before ends mid-sentence ("... owed under", "... in the scenario and"):
            // this criterion is the second half of one broken across lines.
            if (
                $previous && $previous['type'] !== 'tablerow' && $previous['type'] !== 'tablehead'
                && !($previous['type'] === 'item' && $previous['max'] !== null)
                && preg_match('/\b(?:and|or|but|of|the|a|an|to|in|on|at|for|with|under|by|from|that|which|'
                    . 'as|its|their|whether|including|than|into|about)\s*$/iu', $previous['line'])
            ) {
                $reasons[] = 'layout';
            }
            if (
                preg_match('/^\p{Ll}/u', $rec['text']) && $rec['type'] !== 'tablerow' && $previous
                && ($previous['type'] === 'prose' || ($previous['type'] === 'item' && $previous['max'] === null))
                && !preg_match('/[.?!:;]\s*$/u', $previous['line'])
            ) {
                $reasons[] = 'layout';
            }
            // Two sentences under one figure: two criteria sharing marks, or one split oddly.
            $sentences = preg_split('/[.!?]\s+(?=\p{Lu})/u', $rec['text']);
            if (count($sentences) > 1 && self::words($sentences[0]) >= 2 && self::words(end($sentences)) >= 2) {
                $reasons[] = 'layout';
            }
            // Marks with a minus sign: a deduction, not a criterion ("Poor spelling -1 mark").
            if (preg_match('/(?:^|\s)[-\x{2212}]\d|\x{2212}\s*\d/u', $rec['line'])) {
                $reasons[] = 'instruction';
            }
            if (!empty($rec['multinumber'])) {
                // A row with several figures and no marks column: a matrix of levels.
                $reasons[] = 'layout';
            }
            $body = $rec['text'];
            if (core_text::strlen($body) < 3 || core_text::strlen($body) > 300) {
                continue;
            }
            $rec['section'] = $section;
            $rec['part'] = $subtotals;
            $items[] = $rec;
        }

        // Mark bands, in either direction: "0 marks: no answer / 1 mark: partial / 2 marks: full".
        if (self::has_band($items)) {
            $reasons[] = 'bands';
        }

        $items = array_values(array_filter($items, function ($item) {
            return !$item['zero'];
        }));

        // Some criteria with marks and some without, and nothing to share a total by.
        $marked = 0;
        foreach ($items as $item) {
            if ($item['max'] !== null) {
                $marked++;
            }
        }
        if ($marked > 0 && $marked < count($items) && $defaultmax <= 0) {
            $reasons[] = 'mixed';
        }
        $result['loosemarks'] = $result['loosemarks'] || $markprose;
        // Criteria with their own marks beside a note about marks.
        if ($marked > 0 && $markprose) {
            $reasons[] = 'instruction';
        }
        // A note such as "2 marks for each hazard" beside criteria worth something else.
        if ($permarkfigure !== null) {
            foreach ($items as $item) {
                if ($item['max'] !== null && abs($item['max'] - $permarkfigure) > 0.005) {
                    $reasons[] = 'mixed';
                    break;
                }
            }
        }
        // Criteria in two scripts: the same rubric written twice, in two languages.
        $scripts = [];
        foreach ($items as $item) {
            $latin = preg_match_all('/[a-z]/i', $item['text']);
            $other = preg_match_all('/[^\p{Latin}\P{L}]/u', $item['text']);
            $script = $other > $latin ? 'other' : 'latin';
            $scripts[$script] = ($scripts[$script] ?? 0) + 1;
        }
        if (count($scripts) > 1 && count(array_unique($scripts)) === 1) {
            $reasons[] = 'layout';
        }
        // Unmarked criteria under more than one part total: how each part's marks divide among
        // its criteria is not written down.
        if ($subtotals > 1 && $marked < count($items) && $defaultmax <= 0) {
            $reasons[] = 'mixed';
        }

        // A pick-list: "identify any two of the following" over a longer list. Every list line
        // counts, including ones the strict reading set aside.
        $listcount = max(count($items), count(array_filter($records, function ($r) {
            return $r['type'] === 'item';
        })));
        if ($pickn !== null && $pickn > 0 && $listcount > $pickn) {
            $result['pointslist'] = (object)['count' => $listcount, 'cap' => (string)$pickn];
        } else if ($permark && $explicittotal !== null && $listcount > 0) {
            $sum = 0.0;
            foreach ($items as $item) {
                $sum += $item['max'] ?? 1.0;
            }
            if ($sum > $explicittotal + 0.005) {
                $result['pointslist'] = (object)['count' => $listcount, 'cap' => self::fmt($explicittotal)];
            }
        }

        // The declared total: written out, or the sum of the headings' subtotals.
        if ($explicittotal !== null && $subtotals > 0 && abs($explicittotal - $subtotal) > 0.005) {
            $reasons[] = 'declared';
        }
        $result['declaredtotal'] = $explicittotal ?? ($subtotals > 0 ? $subtotal : null);

        // Every criterion carries its own marks, or the field gives one figure for each.
        $result['explicit'] = $defaultmax > 0 || ($marked === count($items) && $marked > 0);
        // The same criterion written twice outside a sample answer: two of it, or a slip.
        $signatures = [];
        foreach ($items as $item) {
            if ($item['section'] !== 'sample') {
                // Within one part: "Accuracy" in Part A and again in Part B are two criteria.
                $signatures[] = $item['part'] . '|' . self::loose_key($item['text']);
            }
        }
        if (count($signatures) !== count(array_unique($signatures))) {
            $reasons[] = 'duplicate';
        }
        $result['items'] = self::label_items($items, $defaultmax);
        $result['reasons'] = array_values(array_unique($reasons));
        if (count($result['items']) >= 2 || $result['pointslist'] !== null || in_array('bands', $result['reasons'], true)) {
            $result['guidance'] = true;
        }
        return $result;
    }

    /**
     * A key that two near copies of a criterion share: "Explains a control" and "Explains the
     * controls".
     *
     * @param string $text
     * @return string
     */
    private static function loose_key(string $text): string {
        $words = preg_split('/[^\p{L}\p{N}]+/u', core_text::strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, function ($w) {
            return !in_array($w, ['a', 'an', 'the', 'any', 'one', 'their', 'its', 'of'], true);
        });
        return implode(' ', array_map(function ($w) {
            return core_text::strlen($w) > 3 ? preg_replace('/(?:es|s)$/u', '', $w) : $w;
        }, $words));
    }

    /**
     * First pass: classify each line on its own.
     *
     * @param string $text Plain text.
     * @param bool $strict Whether the field is read strictly.
     * @return array Records.
     */
    private static function classify(string $text, bool $strict): array {
        $records = [];
        foreach (preg_split('/\n+/', $text) as $line) {
            $line = self::utrim($line);
            if ($line === '') {
                continue;
            }
            $base = ['line' => $line, 'text' => '', 'max' => null, 'zero' => false, 'total' => null, 'pick' => null,
                'permark' => false, 'colon' => (bool)preg_match('/:\s*$/', $line), 'unsure' => self::unsure($line)];

            if (core_text::strpos($line, formatter::NESTED_MARKS) !== false) {
                $records[] = ['type' => 'nested'] + $base;
                continue;
            }

            // A total on a line of its own: "Total: 10 marks", "TOTAL 10".
            if (preg_match('/^total\b\s*(?:marks?|points?|score)?\s*[:=\-]?\s*(\d+(?:\.\d+)?)/i', $line, $m)) {
                $records[] = ['type' => 'total', 'total' => (float)$m[1]] + $base;
                continue;
            }
            // A line giving only the marks available, such as Marks: 3.
            if (preg_match('/^marks?\s*:\s*(\d+(?:\.\d+)?)\s*$/i', $line, $m)) {
                $records[] = ['type' => 'total', 'total' => (float)$m[1]] + $base;
                continue;
            }

            if (core_text::strpos($line, ' | ') !== false || preg_match('/\|\s*$/', $line)) {
                // Cells keep their positions, empty ones included, so a marks column is found
                // where its header says it is.
                $cells = array_map([self::class, 'utrim'], explode('|', preg_replace('/\s*\|\s*$/', '', $line)));
                if (implode('', $cells) === '') {
                    continue;
                }
                $head = self::table_header($cells);
                if ($head !== null) {
                    $records[] = ['type' => 'tablehead'] + $head + $base;
                } else {
                    $records[] = ['type' => 'tablerow', 'cells' => $cells] + $base;
                }
                continue;
            }

            $pick = null;
            if (preg_match(self::PICK, $line, $m)) {
                $pick = self::number(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''));
            }
            $permarkpattern = '\d+(?:\.\d+)?\s*(?:marks?|points?)\s+(?:for|per)\s+(?:each|every|any|correct)\b';
            $permark = (bool)preg_match('/\b' . $permarkpattern . '/i', $line);

            // A marking instruction rather than a criterion.
            if (
                preg_match('/^(?:award|give|allocate|allow)\b.*\b(?:marks?|points?)\b/i', $line)
                || preg_match('/^' . $permarkpattern . '/i', $line)
            ) {
                $records[] = ['type' => 'instruction', 'total' => self::declared_total($line), 'pick' => $pick] + $base;
                continue;
            }

            // A line that is only marks: "(2 marks)".
            if (preg_match('/^[\(\[]?\s*\d+(?:\.\d+)?\s*' . self::UNIT . '\s*[\)\]]?\s*\.?$/iu', $line)) {
                $records[] = ['type' => 'marksonly'] + $base;
                continue;
            }

            $c = self::candidate($line, $strict);
            if ($c === null) {
                $type = self::is_heading_line($line) ? 'heading' : 'prose';
                $records[] = ['type' => $type, 'total' => self::declared_total($line), 'pick' => $pick,
                    'permark' => $permark] + $base;
                continue;
            }
            if (self::is_heading_text($c['headtext'])) {
                if ($c['max'] !== null) {
                    $records[] = ['type' => 'headmarked', 'max' => $c['max'], 'zero' => $c['zero'], 'text' => $c['text']] + $base;
                } else {
                    $records[] = ['type' => 'heading', 'total' => self::declared_total($line), 'pick' => $pick] + $base;
                }
                continue;
            }
            if (preg_match(self::TOTAL_TEXT, self::tidy($c['headtext']))) {
                // A total once its marks are read, such as Total (10 marks) or 10 marks total.
                $records[] = ['type' => 'total', 'total' => $c['max'] ?? self::declared_total($line)] + $base;
                continue;
            }
            $records[] = ['type' => 'item', 'text' => $c['text'], 'max' => $c['max'], 'zero' => $c['zero'],
                'residue' => $c['residue']] + $base;
        }
        return $records;
    }

    /**
     * Which uncertain notation, if any, a line uses.
     *
     * @param string $line
     * @return string|null
     */
    private static function unsure(string $line): ?string {
        foreach (self::UNSURE_NOTATIONS as $name => $pattern) {
            if (preg_match($pattern, $line)) {
                return $name;
            }
        }
        // More than one mark figure on one line: "1 mark - partial; 2 marks - detailed".
        if (preg_match_all('/\d+(?:\.\d+)?\s*' . self::UNIT . '/iu', $line) > 1) {
            return 'multiple';
        }
        return null;
    }

    /**
     * Whether a run of marked items is a mark band.
     *
     * @param array $items Accepted item records in order.
     * @return bool
     */
    private static function has_band(array $items): bool {
        $levelwords = '/\b(no|nil|partial(ly)?|full(y)?|complete(ly)?|incomplete|limited|some|thorough|basic|pass|credit|'
            . 'distinction|high\s+distinction|level\s+\d|'
            . 'detailed|adequate|excellent|good|poor|weak|strong|minimal|clear|comprehensive|sound|'
            . 'satisfactory|unsatisfactory)\b|\bnot\s+(attempted|answered)\b|\bincorrect\b/i';
        // A zero line is a note to the marker ("0 marks: not attempted"). It makes a band only
        // together with a run of levels; on its own beside real criteria it is just dropped.
        $haszero = false;
        foreach ($items as $item) {
            $haszero = $haszero || $item['zero'];
        }
        $items = array_values(array_filter($items, function ($item) {
            return !$item['zero'];
        }));
        $count = count($items);
        for ($i = 0; $i < $count; $i++) {
            foreach ([1, -1] as $direction) {
                $j = $i;
                while (
                    $j + 1 < $count && $items[$j]['max'] !== null && $items[$j + 1]['max'] !== null
                    && ($items[$j + 1]['max'] - $items[$j]['max']) * $direction > 0
                ) {
                    $j++;
                }
                if ($j > $i) {
                    $levels = 0;
                    for ($k = $i; $k <= $j; $k++) {
                        if (preg_match($levelwords, $items[$k]['text'])) {
                            $levels++;
                        }
                    }
                    if ($levels >= 2 || ($haszero && $levels >= 1)) {
                        return true;
                    }
                    // Three or more rising (or falling) figures that all describe the same thing:
                    // "Names the hazard (1) / Describes the hazard (2) / Explains the hazard (3)".
                    $run = array_slice($items, $i, $j - $i + 1);
                    $figures = array_map(function ($item) {
                        return (float)$item['max'];
                    }, $run);
                    if ($direction < 0) {
                        $figures = array_reverse($figures);
                    }
                    $stepped = $figures === array_map('floatval', range(1, count($figures)));
                    if ($j - $i >= 2 && $stepped && self::common_word($run)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Whether every item shares a content word of five letters or more.
     *
     * @param array $items
     * @return bool
     */
    private static function common_word(array $items): bool {
        $common = null;
        foreach ($items as $item) {
            $words = [];
            foreach (preg_split('/[^\p{L}]+/u', core_text::strtolower($item['text']), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                if (core_text::strlen($w) >= 5) {
                    $words[preg_replace('/(?:es|s)$/u', '', $w)] = true;
                }
            }
            $common = $common === null ? $words : array_intersect_key($common, $words);
        }
        return !empty($common);
    }

    /**
     * Give accepted items their labels, keys and default marks.
     *
     * Every criterion the assessor wrote is kept. The only lines dropped are exact repeats in a
     * sample answer, which restate a criterion rather than add one.
     *
     * @param array $records Accepted item records.
     * @param float $defaultmax "Marked out of N each".
     * @return array Items.
     */
    private static function label_items(array $records, float $defaultmax): array {
        $unique = [];
        $seen = [];
        foreach ($records as $rec) {
            $signature = self::key($rec['text']) . '|' . ($rec['max'] ?? '');
            if (isset($seen[$signature]) && $rec['section'] === 'sample') {
                continue;
            }
            $seen[$signature] = true;
            $split = self::label_and_detail($rec['text']);
            if ($split[0] === '') {
                continue;
            }
            $unique[] = [$rec, $split];
        }

        // Criteria that share a short name are all shown in full, so the assessor can tell
        // them apart; any still alike after that are numbered.
        $count = [];
        foreach ($unique as [, [$label]]) {
            $count[core_text::strtolower($label)] = ($count[core_text::strtolower($label)] ?? 0) + 1;
        }
        $items = [];
        $labels = [];
        $keys = [];
        foreach ($unique as [$rec, [$label, $detail]]) {
            if ($count[core_text::strtolower($label)] > 1) {
                $full = self::tidy($rec['text']);
                $label = self::cap($full);
                $detail = $label === $full ? '' : $full;
            }
            $base = $label;
            for ($n = 2; isset($labels[core_text::strtolower($label)]); $n++) {
                $suffix = ' #' . $n;
                $room = self::LABEL_MAX - core_text::strlen($suffix);
                $label = (core_text::strlen($base) > $room
                    ? preg_replace('/[\s,;:-]+$/u', '', core_text::substr($base, 0, $room - 1)) . "\u{2026}"
                    : $base) . $suffix;
            }
            $labels[core_text::strtolower($label)] = true;

            $key = self::key($label);
            if ($key === '') {
                continue;
            }
            $basekey = $key;
            for ($n = 2; isset($keys[$key]); $n++) {
                $key = $basekey . '_' . $n;
            }
            $keys[$key] = true;

            $items[] = [
                'key' => $key,
                'label' => $label,
                'detail' => $detail,
                'max' => $rec['max'] ?? ($defaultmax > 0 ? $defaultmax : null),
                // Whether the assessor wrote this criterion's figure, for checking the service.
                'own' => $rec['max'] !== null || $defaultmax > 0,
            ];
        }
        return $items;
    }

    /**
     * Read one line as a possible criterion.
     *
     * @param string $line A trimmed, non-empty line that is not a table row.
     * @param bool $strict Whether the line comes from the question text or General feedback.
     * @return array|null ['text', 'headtext', 'max', 'zero', 'marker'], or null when the line is
     *     neither a list item nor carries marks.
     */
    private static function candidate(string $line, bool $strict): ?array {
        $marker = false;
        $unit = self::UNIT;

        // A list item. Numbered (1. 1) 1.1), lettered (a. a) (a)), roman (ii. iv)), or a bullet:
        // a dash, an asterisk, a bullet, Word's Symbol-font dot or "o", a tick box or a tick.
        $markers = '(?:(?:\d+(?:\.\d+)+\.?|\d+[.)])(?!\s*' . $unit . ')|[a-h][.)]|(?:i{1,3}|iv|v|vi{1,3}|ix|x)[.)]|'
            . '\([a-z0-9]{1,4}\)|[-*\x{2022}\x{2013}\x{25AA}\x{25E6}\x{00B7}\x{2610}\x{2611}\x{2713}\x{2714}'
            . '\x{25BA}\x{27A2}\x{2192}\x{25B6}]|o(?=\s+\p{Lu}))';
        // A second marker after the first, as when a Word bullet "o" survives inside a list item.
        for ($pass = 0; $pass < 2 && preg_match('/^' . $markers . '\s+(.*)$/ui', $line, $m); $pass++) {
            $marker = true;
            $line = trim($m[1]);
        }

        [$body, $max] = self::take_marks($line, $strict);
        // A percentage weighting: read as a figure for the hint sent to the grading service and
        // for checking its weights; the line stays uncertain (see UNSURE_NOTATIONS).
        $percent = '/^(.*?)\s*(?:[\(\[]\s*(\d+(?:[.,]\d+)?)\s*%\s*[\)\]]|[\x{2013}\x{2014}-]\s*(\d+(?:[.,]\d+)?)\s*%)\s*\.?$/u';
        if ($max === null && preg_match($percent, $body, $pm)) {
            $body = trim($pm[1]);
            $max = self::num($pm[2] !== '' ? $pm[2] : $pm[3]);
        }
        // Marks followed by "for": the criterion is what follows, as in 2 marks for identifying.
        if (preg_match('/^for\s+(.+)$/iu', $body, $m)) {
            $body = core_text::strtoupper(core_text::substr($m[1], 0, 1)) . core_text::substr($m[1], 1);
        }
        $headtext = $body;

        // A labelled item: "Criterion 2:", "PC 1.2", "Step 3)".
        if (
            preg_match(
                '/^(?:criterion|criteria|pc|performance\s+criterion|step|element)\s*'
                    . '(?:\d+(?:\.\d+)*[a-z]?|[a-z])\s*[:.)\x{2013}\x{2014}-]?\s+(.+)$/ui',
                $body,
                $m
            )
        ) {
            $marker = true;
            $body = trim($m[1]);
        }

        if (!$marker && $max === null) {
            return null;
        }
        $zero = $max !== null && $max <= 0;
        return ['text' => $body, 'headtext' => $headtext, 'max' => $zero ? 0.0 : $max, 'zero' => $zero, 'marker' => $marker,
            'residue' => (bool)preg_match(self::MARKS_RESIDUE, $body)];
    }

    /**
     * Take the marks off a line, wherever they are written.
     *
     * @param string $line The line, list marker removed.
     * @param bool $strict Whether the line comes from the question text or General feedback.
     * @return array [the line without its marks, the marks or null]
     */
    private static function take_marks(string $line, bool $strict): array {
        $unit = self::UNIT;
        // Marks at the start of the line, bare or in brackets.
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*' . $unit . '\s*[:.)\x{2013}\x{2014}=-]?\s*(.+)$/ui', $line, $m)) {
            return [trim($m[2]), self::num($m[1])];
        }
        if (preg_match('/^[\(\[]\s*(\d+(?:[.,]\d+)?)\s*' . $unit . '\s*[\)\]]\s*[:\x{2013}\x{2014}-]?\s*(.+)$/ui', $line, $m)) {
            return [trim($m[2]), self::num($m[1])];
        }
        // Marks beside a label: "Knowledge (4 marks): explains the concept".
        if (
            preg_match(
                '/^(.{2,60}?)\s*[\(\[]\s*(\d+(?:[.,]\d+)?)\s*' . $unit . '\s*[\)\]]\s*[:\x{2013}\x{2014}-]\s*(.+)$/ui',
                $line,
                $m
            )
        ) {
            return [trim($m[1]) . ': ' . trim($m[3]), self::num($m[2])];
        }
        // Marks at the end of the line, in brackets or after a dash, an equals sign or a colon.
        $patterns = [
            '/\s*[\(\[]\s*(?:up\s+to\s+)?(\d+(?:[.,]\d+)?)\s*' . $unit . '\s*[\)\]]\s*\.?\s*$/ui',
            '/\s+[\x{2013}\x{2014}=-]\s*(\d+(?:[.,]\d+)?)\s*' . $unit . '\s*\.?\s*$/ui',
            '/\s*:\s*(\d+(?:[.,]\d+)?)\s*' . $unit . '\s*\.?\s*$/ui',
        ];
        // A bare bracketed figure, "... [2]", is marks only in Information for graders and only
        // when small. In a question's text "(2011)" is a year and "[150]" a word count.
        if (!$strict) {
            $patterns[] = '/\s*[\(\[]\s*(\d{1,2}(?:\.\d+)?)\s*[\)\]]\s*\.?\s*$/u';
        }
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $m)) {
                if (self::num($m[1]) > 20 && !preg_match('/' . $unit . '/iu', $m[0])) {
                    continue;
                }
                return [trim(core_text::substr($line, 0, core_text::strlen($line) - core_text::strlen($m[0]))), self::num($m[1])];
            }
        }
        return [$line, null];
    }

    /**
     * Read a table header row, if this is one.
     *
     * @param array $cells Non-empty cells.
     * @return array|null ['rubric' => bool, 'markscol' => int|null], or null when not a header.
     */
    private static function table_header(array $cells): ?array {
        $headerwords = '/^(criteria|criterion|marking\s+criteria|assessment\s+criteria|performance\s+criteria|'
            . 'description|descriptor|marks?|marks?\s+available|available\s+marks?|max(imum)?(\s+marks?)?|points?|'
            . 'score|weighting|weight|evidence|(assessor\s+)?(notes?|comments?|feedback)|s|ns|c|nyc|'
            . 'satisfactory|not\s+satisfactory|competent|not\s+yet\s+competent|yes|no|#|no\.|item|task|question|'
            . '.*checklist|observation|result)$/i';
        $rubricwords = '/^(criteria|criterion|marking\s+criteria|assessment\s+criteria|performance\s+criteria|marks?|'
            . 'marks?\s+available|available\s+marks?|points?|score|max(imum)?(\s+marks?)?|s|ns|satisfactory|competent|'
            . 'nyc|.*checklist)$/i';
        $rubric = false;
        $markscol = null;
        $figurecols = 0;
        foreach ($cells as $i => $cell) {
            if ($cell === '') {
                continue;
            }
            if (!preg_match($headerwords, $cell)) {
                return null;
            }
            $markshead = '/^(marks?|points?|score|weighting|weight|max(imum)?(\s+marks?)?|marks?\s+available|'
                . 'available\s+marks?)$/i';
            if (preg_match($markshead, $cell)) {
                $figurecols++;
            }
            if (preg_match($rubricwords, $cell)) {
                $rubric = true;
            }
            // The column holding each criterion's maximum. "Max" or "Marks available" wins over
            // "Score" or "Points", which an assessor may fill in per learner.
            if (preg_match('/^(max(imum)?(\s+marks?)?|marks?\s+available|available\s+marks?)$/i', $cell)) {
                $markscol = $i;
                $priority = 3;
            } else if (preg_match('/^marks?$/i', $cell) && ($priority ?? 0) < 2) {
                $markscol = $i;
                $priority = 2;
            } else if (preg_match('/^(points?|score)$/i', $cell) && ($priority ?? 0) < 1) {
                $markscol = $i;
                $priority = 1;
            }
        }
        // Two columns of figures and neither is plainly the maximum ("Points" and "Weighting",
        // "Score" and "Points"): which is the criterion's weight is not certain.
        $ambiguous = $figurecols > 1 && ($priority ?? 0) < 3;
        return ['rubric' => $rubric, 'markscol' => $markscol, 'ambiguous' => $ambiguous];
    }

    /**
     * Read a table row against its header.
     *
     * @param array $rec The row record.
     * @param array|null $table The header record, if the table had one.
     * @return array|null The row as an item record, or null for a row with nothing in it.
     */
    private static function table_row(array $rec, ?array $table): ?array {
        $cells = $rec['cells'];
        $markscol = $table['markscol'] ?? null;
        $max = null;
        $texts = [];
        $total = null;
        $numbers = 0;
        foreach ($cells as $i => $cell) {
            if ($cell === '') {
                continue;
            }
            if (preg_match('/\b(sub-?)?total\b|marks\s+for\s+this\s+question|^overall\b|^sum$|^max(imum)?\s+marks?\b/i', $cell)) {
                if (preg_match('/(\d+(?:\.\d+)?)/', implode(' ', $cells), $m)) {
                    $total = (float)$m[1];
                }
                return ['total' => $total ?? 0.0] + $rec;
            }
            if (preg_match('/\d\s*%/', $cell)) {
                $rec['unsure'] = 'percent';
            }
            if (preg_match('/^(\d+(?:[.,]\d+)?)\s*' . self::UNIT . '?$/iu', $cell, $m)) {
                if (!empty($texts) || $markscol !== null) {
                    $numbers++;
                }
                $m[1] = (string)self::num($m[1]);
                if ($markscol !== null) {
                    if ($i === $markscol) {
                        $max = (float)$m[1];
                    }
                } else if (!empty($texts) && $max === null) {
                    // With no header, the first number after the label is the marks; a number
                    // before it is a row number.
                    $max = (float)$m[1];
                }
                continue;
            }
            // A row marker, a tick box or a tick.
            if (preg_match('/^(\d+[.)]?|[a-z][.)]|\([a-z0-9]{1,3}\)|[\x{2610}\x{2611}\x{2612}\x{2713}\x{2714}xX\/\-])$/u', $cell)) {
                continue;
            }
            if (preg_match('/^(yes|no|s|ns|c|nyc|n\/a)$/i', $cell)) {
                continue;
            }
            $texts[] = $cell;
        }
        if (empty($texts)) {
            return null;
        }
        if (($markscol === null && $numbers > 1) || !empty($table['ambiguous'])) {
            $rec['multinumber'] = true;
        }
        $text = $texts[0];
        if (count($texts) > 1 && core_text::strlen($texts[0]) <= 60) {
            // A label cell followed by a description cell: the second cell is the detail.
            $text = $texts[0] . ': ' . $texts[1];
        }
        $zero = $max !== null && $max <= 0;
        return ['text' => $text, 'max' => $zero ? 0.0 : $max, 'zero' => $zero, 'total' => null] + $rec;
    }

    /**
     * A mark as written, with a decimal point or a decimal comma.
     *
     * @param string $value
     * @return float
     */
    private static function num(string $value): float {
        return (float)str_replace(',', '.', $value);
    }

    /**
     * A number written as digits or a word, one to ten.
     *
     * @param string $value
     * @return int|null
     */
    private static function number(string $value): ?int {
        if (ctype_digit($value)) {
            return (int)$value;
        }
        $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
            'eight' => 8, 'nine' => 9, 'ten' => 10];
        return $words[core_text::strtolower($value)] ?? null;
    }

    /**
     * Whether a line with no marks and no list marker is a heading.
     *
     * Short, and either ending in a colon or without closing punctuation. "Marking criteria"
     * and "Marking Criteria (2 marks total)" are headings; "Your answer will be assessed against
     * the rubric." is a sentence and opens nothing.
     *
     * @param string $line A trimmed line.
     * @return bool
     */
    private static function is_heading_line(string $line): bool {
        if (core_text::strlen($line) > 80 || self::words($line) > 10) {
            return false;
        }
        return preg_match('/:\s*$/', $line) || !preg_match('/[.?!]\s*$/', $line);
    }

    /**
     * Whether the text of a line, marks removed, is a heading rather than a criterion.
     *
     * @param string $text The line's text with its marks removed.
     * @return bool
     */
    private static function is_heading_text(string $text): bool {
        $text = self::tidy($text);
        if ($text === '' || self::words($text) > 8) {
            return false;
        }
        return (bool)preg_match(
            '/^((marking|grading|assessment|performance)\s+)?(criteria|criterion|rubric|marking\s+(guide|scheme|key)|'
                . 'mark\s+scheme)(\s*\([^)]*\))?(\s+\w+)?$|'
                . '^(question|q|part|section|task|activity)\s*[a-z0-9]+(?:\.[a-z0-9]+)*\b'
                . '(\s*[:\x{2013}\x{2014}-]\s*[^.:]{0,60})?$|'
                . '^(sample|model|example|suggested)\s+(answer|response)s?$/iu',
            $text
        );
    }

    /**
     * A total declared on a line: "(2 marks total)", "Total: 10 marks", "marked out of 20",
     * "max 3 marks", "up to 3 marks". A figure in ordinary prose - "lifts a total of 25 kg",
     * "3 out of 5 hazards" - is not a total of marks.
     *
     * @param string $line One line.
     * @return float|null The total, or null when the line declares none.
     */
    private static function declared_total(string $line): ?float {
        $unit = '(?:marks?|points?)';
        $patterns = [
            '/(\d+(?:\.\d+)?)\s*' . $unit . '\s*(?:in\s+)?total\b/i',
            '/\btotal\s*(?:of\s*)?[:=\-]?\s*(\d+(?:\.\d+)?)\s*' . $unit . '/i',
            '/\bout\s+of\s+(\d+(?:\.\d+)?)\s*' . $unit . '(?!\s*(?:each|per\b))/i',
            '/\bmarked\s+out\s+of\s+(\d+(?:\.\d+)?)\b(?!\s*' . $unit . '?\s*(?:each|per\b))/i',
            '/\b(?:max(?:imum)?\.?|up\s+to)\s*(?:of\s*)?(\d+(?:\.\d+)?)\s*' . $unit . '/i',
            '/^\W*(?:marks?\s+)?(?:total|altogether)(?:\s+(?:marks?|points?))?(?:\s+available)?\s*[:=\-\x{2013}\x{2014}]?\s*'
                . '(\d+(?:\.\d+)?)\W*$/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $m) && (float)$m[1] > 0) {
                return (float)$m[1];
            }
        }
        return null;
    }

    /**
     * Split a criterion into a short label and the detail behind it.
     *
     * A label is what the assessor and the learner scan: a few words. Everything else -
     * examples, clarifications, the second half of a long sentence - moves to the detail
     * line under it, so nothing the assessor wrote is lost.
     *
     * @param string $text The criterion's text, marks already removed.
     * @return array [label, detail]
     */
    private static function label_and_detail(string $text): array {
        $text = trim($text);
        [$label, $detail] = self::split_label($text);

        // Still too long to scan, however it was split: keep the opening as the label and
        // the whole criterion as the detail, so nothing the assessor wrote is lost.
        if (core_text::strlen($label) > self::LABEL_MAX) {
            $label = self::cap($label);
            $detail = $text;
        }
        return [$label, $detail];
    }

    /**
     * Shorten a label to LABEL_MAX at a word boundary, marking the cut.
     *
     * @param string $label
     * @return string
     */
    private static function cap(string $label): string {
        if (core_text::strlen($label) <= self::LABEL_MAX) {
            return $label;
        }
        $cut = core_text::substr($label, 0, self::LABEL_MAX - 1);
        $space = core_text::strrpos($cut, ' ');
        if ($space !== false && $space > self::LABEL_MAX / 2) {
            $cut = core_text::substr($cut, 0, $space);
        }
        return preg_replace('/[\s,;:-]+$/u', '', $cut) . "\u{2026}";
    }

    /**
     * The split itself, before the length cap. See label_and_detail().
     *
     * @param string $text The criterion's text, marks already removed and trimmed.
     * @return array [label, detail]
     */
    private static function split_label(string $text): array {
        // A label followed by a colon splits into the short name and the detail behind it.
        //
        // Only a short leading PHRASE is a label. A character count alone was not enough:
        // "Explains the whole procedure from start to finish including this part: the
        // handover step" has its colon at 69 characters and was split into a sentence-length
        // "label". Real criterion names are a few words, so the word count is the honest test
        // and the character count the backstop.
        $colon = core_text::strpos($text, ':');
        if ($colon !== false && $colon >= 3 && $colon <= 60) {
            $candidate = trim(core_text::substr($text, 0, $colon));
            if (self::words($candidate) <= 8) {
                return [self::tidy($candidate), trim(core_text::substr($text, $colon + 1))];
            }
        }

        // An example or aside in brackets: "Describe one action (e.g. report the hazard)".
        if (preg_match('/^(.{8,}?)\s*\((.+)\)\s*\.?$/us', $text, $m) && self::words($m[1]) >= 2) {
            return [self::tidy($m[1]), trim($m[2])];
        }

        // An aside after a dash or semicolon, or an inline "e.g.".
        $aside = '/^(.{8,}?)\s*(?:\s[\x{2013}\x{2014}-]\s|;\s|,?\s+(?:e\.g\.|eg\b|for example|such as)\s*)(.+)$/ui';
        if (preg_match($aside, $text, $m)) {
            if (self::words($m[1]) >= 2 && self::words($m[1]) <= 12) {
                return [self::tidy($m[1]), trim($m[2])];
            }
        }

        return [self::tidy($text), ''];
    }

    /**
     * Give items without their own marks a weight.
     *
     * A declared total is shared among the items that have no figure of their own, with any
     * rounding remainder on the last so the shares add up exactly; with no total, each counts
     * as one mark. The question's own maximum is not used here: if the rubric and the question
     * disagree, that is reported, not hidden.
     *
     * @param array $items Parsed items, 'max' possibly null.
     * @param float|null $declared A total declared in the text.
     * @return array Items with a positive 'max'.
     */
    private static function apply_weights(array $items, ?float $declared): array {
        $known = 0.0;
        $unknown = [];
        foreach ($items as $i => $item) {
            if ($item['max'] === null) {
                $unknown[] = $i;
            } else {
                $known += $item['max'];
            }
        }

        $shares = array_fill_keys($unknown, 1.0);
        if ($unknown && $declared !== null && $declared - $known > 0) {
            $pool = $declared - $known;
            $each = round($pool / count($unknown), 2);
            $assigned = 0.0;
            foreach ($unknown as $n => $i) {
                $shares[$i] = $n === count($unknown) - 1 ? round($pool - $assigned, 2) : $each;
                $assigned += $each;
            }
        }

        foreach ($items as $i => $item) {
            $items[$i]['max'] = $item['max'] === null ? $shares[$i] : (float)$item['max'];
        }
        return $items;
    }

    /**
     * Sum of the criteria's maximums.
     *
     * @param array $items Criteria with 'max'.
     * @return float
     */
    public static function total(array $items): float {
        $total = 0.0;
        foreach ($items as $item) {
            $total += (float)$item['max'];
        }
        return round($total, 2);
    }

    /**
     * Accept criteria the grading service returned for a question without a certain rubric.
     *
     * Two cases. 'extract': the assessor wrote guidance the plugin could not read with
     * certainty, and the service condensed it; every criterion must be found in that guidance,
     * so the service cannot add a requirement the assessor did not write. 'infer': the question
     * had no guidance and the service derived criteria from the question; a name from the
     * service's internal vocabulary (VENDOR_IDS) is refused unless the question itself uses
     * those words.
     *
     * Either way the set is all or nothing. One malformed entry, bare identifier, repeated name
     * or criterion not found in the reference refuses the whole set, as does a count outside the
     * recommended range: dropping a criterion would drop its marks and re-weight the rest, which
     * can turn a partly-failed response into full marks. An accepted set is scaled to the
     * question's mark. A refused set leaves the response with one overall mark.
     *
     * @param array $scored Criteria as the service returned them.
     * @param array|null $limits ['min' => int, 'max' => int]; the site's when null.
     * @param float $maxmark The question's maximum mark; 0 when unknown.
     * @param string $mode 'infer' or 'extract'.
     * @param string $reference The text the criteria are checked against: the assessor's
     *     guidance for 'extract', the question for 'infer'.
     * @param array $hints The assessor's own criteria with their figures, when every one had
     *     one; the service's weights must match them if it returns as many criteria.
     * @return array Clean criteria, each marked 'inferred' or 'extracted', or an empty array.
     */
    public static function from_service(
        array $scored,
        ?array $limits = null,
        float $maxmark = 0.0,
        string $mode = 'infer',
        string $reference = '',
        array $hints = []
    ): array {
        $limits = $limits ?? self::limits();
        $out = [];
        $seen = [];
        foreach ($scored as $sc) {
            if (!is_array($sc) && !is_object($sc)) {
                return [];
            }
            $sc = (array)$sc;
            $raw = '';
            foreach (['label', 'name', 'criterion'] as $field) {
                if (isset($sc[$field]) && is_scalar($sc[$field]) && trim((string)$sc[$field]) !== '') {
                    $raw = trim((string)$sc[$field]);
                    break;
                }
            }
            if ($raw === '') {
                return [];
            }
            [$label, $detail] = self::label_and_detail($raw);
            $isidentifier = function ($value) {
                return (bool)preg_match('/^[a-z0-9]+(_[a-z0-9]+)+$/', trim((string)$value));
            };
            if ($isidentifier($raw) || $isidentifier($label)) {
                // An identifier where a name should be.
                return [];
            }
            if ($mode === 'extract') {
                if (!self::found_in($raw, $reference)) {
                    // Not something the assessor wrote.
                    return [];
                }
            } else if (
                (in_array(self::key($label), self::VENDOR_IDS, true) || in_array(self::key($raw), self::VENDOR_IDS, true))
                && !self::found_in($raw, $reference)
            ) {
                return [];
            } else if (!self::shares_word($raw, $reference)) {
                // A derived criterion must be about something the question asks: it shares at
                // least one significant word with it. "Communication is clear and professional"
                // on a question about hazards does not.
                return [];
            }
            if ($mode === 'extract' && (!isset($sc['max']) || !is_numeric($sc['max']) || (float)$sc['max'] <= 0)) {
                // Restating the assessor's guidance without a weight would be guessing one.
                return [];
            }
            $key = self::key($label);
            if ($key === '' || isset($seen[$key])) {
                return [];
            }
            $seen[$key] = true;

            $max = isset($sc['max']) && is_numeric($sc['max']) && (float)$sc['max'] > 0 ? (float)$sc['max'] : 1.0;
            $awarded = isset($sc['awarded']) && is_numeric($sc['awarded']) ? (float)$sc['awarded'] : 0.0;
            $out[] = [
                'key' => $key,
                'label' => $label,
                'awarded' => min(max($awarded, 0), $max),
                'max' => $max,
                'comment' => isset($sc['comment']) && is_scalar($sc['comment']) ? (string)$sc['comment'] : $detail,
                'improve' => isset($sc['improve']) && is_scalar($sc['improve']) ? (string)$sc['improve'] : '',
                'inferred' => $mode !== 'extract',
                'extracted' => $mode === 'extract',
            ];
        }

        // The fewest a set may have: the site's minimum, unless the question is worth fewer
        // marks than that, and never fewer than two.
        $need = $limits['min'];
        if ($maxmark > 0) {
            $need = min($need, (int)floor($maxmark));
        }
        $need = max(2, $need);
        if (count($out) < $need || count($out) > $limits['max']) {
            return [];
        }

        // Scale to the question's mark, so the numbers on the card are the question's own.
        $sum = 0.0;
        foreach ($out as $c) {
            $sum += $c['max'];
        }

        // Every criterion the assessor gave a figure (marks or a percentage) must be represented
        // in the service's set, with the same share of the total: within five percentage points
        // of the assessor's share among the criteria that had figures. A marked criterion left
        // out, or re-weighted, refuses the set.
        if ($mode === 'extract') {
            $own = array_values(array_filter($hints, function ($h) {
                return !empty($h['own']) && (float)($h['max'] ?? 0) > 0;
            }));
            if ($own) {
                $hintsum = array_sum(array_map(function ($h) {
                    return (float)$h['max'];
                }, $own));
                $used = [];
                $matched = [];
                foreach ($own as $h) {
                    $found = null;
                    foreach ($out as $i => $c) {
                        if (!isset($used[$i]) && self::found_in($c['label'], $h['label'] . ' ' . ($h['detail'] ?? ''))) {
                            $found = $i;
                            break;
                        }
                    }
                    if ($found === null) {
                        return [];
                    }
                    $used[$found] = true;
                    $matched[] = [$found, (float)$h['max']];
                }
                $matchedsum = 0.0;
                foreach ($matched as [$i]) {
                    $matchedsum += $out[$i]['max'];
                }
                foreach ($matched as [$i, $hmax]) {
                    if ($matchedsum <= 0 || abs($out[$i]['max'] / $matchedsum - $hmax / $hintsum) > 0.05) {
                        return [];
                    }
                }
            }
        }
        $factor = ($maxmark > 0 && $sum > 0 && abs($sum - $maxmark) > 0.005) ? $maxmark / $sum : 1.0;
        foreach ($out as $i => $c) {
            $out[$i]['max'] = round($c['max'] * $factor, 2);
            $out[$i]['awarded'] = round($c['awarded'] * $factor, 2);
        }
        return $out;
    }

    /**
     * Whether a criterion, as the grading service wrote it, is found in a text.
     *
     * The whole of what the service wrote is checked, not only the part before a colon, so a
     * requirement cannot be slipped in behind a familiar name. In a script that separates words
     * with spaces, most of the significant words must appear in the text, matched on a stem long
     * enough that "identifies" finds "identify" but "presentation" does not find "present"; and
     * at least one long word, or two words, must match, so a label of common short words is not
     * enough. In a script written without spaces (Thai, Chinese, Japanese) most of the label's
     * character pairs must appear.
     *
     * @param string $label A criterion as the service wrote it.
     * @param string $text The text to look in.
     * @return bool
     */
    public static function found_in(string $label, string $text): bool {
        $text = core_text::strtolower($text);
        $label = core_text::strtolower(trim($label));
        if (trim($text) === '' || $label === '') {
            return false;
        }
        $stop = ['this', 'that', 'with', 'from', 'what', 'when', 'where', 'which', 'their', 'there', 'they', 'them',
            'have', 'been', 'were', 'will', 'would', 'should', 'could', 'does', 'each', 'also', 'into', 'about',
            'your', 'than', 'then', 'only', 'some', 'such', 'other', 'these', 'those', 'learner', 'learners',
            'response', 'answer', 'student', 'candidate', 'marks', 'mark', 'clearly', 'correctly', 'appropriate',
            'and', 'the', 'for', 'of', 'to', 'in', 'on', 'a', 'an', 'or', 'is', 'are', 'be', 'by', 'at', 'as', 'it'];
        $textwords = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $nospace = '/[\p{Thai}\p{Han}\p{Hiragana}\p{Katakana}\p{Lao}\p{Khmer}\p{Myanmar}]/u';

        $significant = 0;
        $found = 0;
        $long = 0;
        foreach (preg_split('/[^\p{L}\p{N}\p{M}]+/u', $label, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (preg_match($nospace, $word)) {
                // Character pairs, so a phrase is matched whether or not it is split the same way.
                $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
                $chars = array_values(array_filter($chars, function ($c) {
                    return !preg_match('/\p{M}/u', $c);
                }));
                if (count($chars) < 2) {
                    continue;
                }
                for ($k = 0; $k + 1 < count($chars); $k++) {
                    $significant++;
                    if (core_text::strpos(preg_replace('/\p{M}/u', '', $text), $chars[$k] . $chars[$k + 1]) !== false) {
                        $found++;
                    }
                }
                if (count($chars) >= 4) {
                    $long++;
                }
                continue;
            }
            $length = core_text::strlen($word);
            if ($length < 3 || in_array($word, $stop, true) || preg_match('/^\d+$/', $word)) {
                continue;
            }
            $significant++;
            $stemlength = max(5, $length - 3);
            $stem = core_text::substr($word, 0, min($length, $stemlength));
            foreach ($textwords as $tw) {
                if (core_text::substr($tw, 0, core_text::strlen($stem)) === $stem) {
                    $found++;
                    if ($length >= 6) {
                        $long++;
                    }
                    break;
                }
            }
        }
        if ($significant === 0) {
            return false;
        }
        if ($found < (int)ceil($significant * 0.6)) {
            return false;
        }
        // A match on common short words alone is not a match.
        return $long >= 1 || $found >= 2;
    }

    /**
     * Whether a phrase shares at least one significant word with a text.
     *
     * @param string $phrase
     * @param string $text
     * @return bool
     */
    public static function shares_word(string $phrase, string $text): bool {
        $text = core_text::strtolower($text);
        $textwords = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $common = ['learner', 'learners', 'response', 'answer', 'student', 'clearly', 'correctly', 'appropriate',
            'which', 'their', 'there', 'where', 'would', 'should', 'could', 'about', 'other', 'these', 'those'];
        foreach (preg_split('/[^\p{L}\p{N}\p{M}]+/u', core_text::strtolower($phrase), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (preg_match('/[\p{Thai}\p{Han}\p{Hiragana}\p{Katakana}]/u', $word)) {
                if (core_text::strlen($word) >= 2 && core_text::strpos($text, $word) !== false) {
                    return true;
                }
                continue;
            }
            if (core_text::strlen($word) < 5 || in_array($word, $common, true)) {
                continue;
            }
            $stem = core_text::substr($word, 0, max(5, core_text::strlen($word) - 3));
            foreach ($textwords as $tw) {
                if (core_text::substr($tw, 0, core_text::strlen($stem)) === $stem) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Merge whatever the grading service reported onto the assessor's own criteria.
     *
     * The assessor's rubric is the spine, and its weights are the assessor's: a score from the
     * service attaches to the criterion it names and is scaled to that criterion's own maximum,
     * so a service that scores a two-mark criterion out of ten cannot make it worth ten. A
     * criterion the service said nothing about is still listed, unscored, so the learner sees
     * the full rubric. With no rubric, the service's own criteria are returned as they came;
     * grading uses from_service() for that case, which validates them.
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

        // Each score the service sent is matched to one criterion, in order: by the key the
        // plugin sent, then by name, ignoring punctuation and the " #2" a repeated name carries.
        // A score is used once, so two criteria with the same name ("Accuracy" in Part A and in
        // Part B) each get their own.
        $entries = [];
        foreach ($scored as $sc) {
            if (is_array($sc) || is_object($sc)) {
                $entries[] = (array)$sc;
            }
        }
        $names = function (array $sc): array {
            $out = [];
            foreach (['key', 'label', 'name', 'criterion'] as $field) {
                if (!empty($sc[$field]) && is_scalar($sc[$field])) {
                    $out[] = self::normalise(preg_replace('/\s*#\d+$/', '', (string)$sc[$field]));
                    $out[] = self::normalise((string)$sc[$field]);
                }
            }
            return array_unique($out);
        };
        $used = [];
        $hits = [];
        foreach ($rubric as $i => $criterion) {
            $wanted = array_unique([
                self::normalise($criterion['key'] ?? ''),
                self::normalise($criterion['label']),
                self::normalise(preg_replace('/\s*#\d+$/', '', $criterion['label'])),
            ]);
            $hits[$i] = null;
            foreach ($entries as $e => $sc) {
                if (!isset($used[$e]) && array_intersect($wanted, $names($sc))) {
                    $hits[$i] = $sc;
                    $used[$e] = true;
                    break;
                }
            }
        }
        // A score named differently is matched only when its name is found in the criterion and
        // its maximum, if given, is the criterion's. Never by position: a service that scores the
        // criteria in another order would otherwise move marks between them.
        foreach ($rubric as $i => $criterion) {
            if ($hits[$i] !== null) {
                continue;
            }
            foreach ($entries as $e => $sc) {
                if (isset($used[$e])) {
                    continue;
                }
                $name = '';
                foreach (['label', 'name', 'criterion'] as $field) {
                    if (!empty($sc[$field]) && is_scalar($sc[$field])) {
                        $name = (string)$sc[$field];
                        break;
                    }
                }
                $samemax = !isset($sc['max']) || !is_numeric($sc['max'])
                    || abs((float)$sc['max'] - (float)$criterion['max']) <= 0.005;
                if ($name !== '' && $samemax && self::found_in($name, $criterion['label'] . ' ' . ($criterion['detail'] ?? ''))) {
                    $hits[$i] = $sc;
                    $used[$e] = true;
                    break;
                }
            }
        }
        $metnormalised = array_map([self::class, 'normalise'], $met);

        $out = [];
        foreach ($rubric as $i => $criterion) {
            $key = self::normalise($criterion['label']);
            $max = (float)$criterion['max'];
            $awarded = null;
            $hit = $hits[$i];

            if ($hit !== null && isset($hit['awarded']) && is_numeric($hit['awarded'])) {
                $awarded = (float)$hit['awarded'];
                $servicemax = isset($hit['max']) && is_numeric($hit['max']) ? (float)$hit['max'] : 0.0;
                if ($servicemax > 0 && abs($servicemax - $max) > 0.005) {
                    $awarded = $awarded * $max / $servicemax;
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
                'comment' => isset($hit['comment']) && is_scalar($hit['comment'])
                    ? (string)$hit['comment']
                    : (string)$criterion['detail'],
                // What would earn the remaining marks. Shown only below full marks.
                'improve' => isset($hit['improve']) && is_scalar($hit['improve'])
                    ? (string)$hit['improve']
                    : '',
            ];
        }

        return $out;
    }

    /**
     * The instruction sent to the grading service when it is asked to derive criteria.
     *
     * @param array $limits ['min' => int, 'max' => int].
     * @param float $maxmark The question's maximum mark.
     * @return string
     */
    public static function inference_instruction(array $limits, float $maxmark): string {
        return 'The question has no marking criteria. Before marking, derive between ' . $limits['min']
            . ' and ' . $limits['max'] . ' criteria from what the question asks the learner to do: one per '
            . 'distinct task or required element, each a short plain-English phrase of no more than eight '
            . 'words beginning with a verb (for example "Identifies the hazard"). Use fewer than '
            . $limits['min'] . ' only when the question asks for fewer distinct things. Give each a max so '
            . 'that the maxes sum to ' . self::fmt($maxmark) . '. Mark the response against them and return '
            . 'them in criteria with label, max, awarded, comment and improve. Do not invent requirements '
            . 'the question does not state.';
    }

    /**
     * The instruction sent when the service is asked to condense the assessor's own guidance.
     *
     * @param array $limits ['min' => int, 'max' => int].
     * @param float $maxmark The question's maximum mark.
     * @param string $source Which field holds the guidance.
     * @return string
     */
    public static function extraction_instruction(array $limits, float $maxmark, string $source): string {
        $field = [
            self::SOURCE_GRADERINFO => 'graderInfo',
            self::SOURCE_QUESTIONTEXT => 'questionText',
            self::SOURCE_GENERALFEEDBACK => 'generalFeedback',
        ][$source] ?? 'graderInfo';
        return 'The assessor\'s marking guidance is in ' . $field . ', written in a form that could not be read '
            . 'as a list of criteria automatically. Before marking, restate that guidance as between '
            . $limits['min'] . ' and ' . $limits['max'] . ' criteria, using fewer only when the guidance has '
            . 'fewer distinct requirements. Use the assessor\'s own words for each criterion name, a short '
            . 'phrase of no more than eight words. Keep the assessor\'s weightings: where the guidance gives '
            . 'marks, percentages or bands, carry them over, and where it lists acceptable points of which any '
            . 'N earn the marks, express that as one criterion. Give each a max so that the maxes sum to '
            . self::fmt($maxmark) . '. Do not add any requirement the guidance does not state. Mark the response '
            . 'against them and return them in criteria with label, max, awarded, comment and improve.';
    }

    /**
     * A stable identifier for a criterion's label.
     *
     * @param string $label
     * @return string
     */
    public static function key(string $label): string {
        return trim(core_text::strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '_', $label)), '_');
    }

    /**
     * Normalise a label or key for matching.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalise($value): string {
        return self::key((string)$value);
    }

    /**
     * Trim whitespace including the non-breaking spaces editors put in empty cells and lines.
     *
     * @param string $text
     * @return string
     */
    private static function utrim(string $text): string {
        return preg_replace('/^[\s\x{00A0}\x{2007}\x{202F}]+|[\s\x{00A0}\x{2007}\x{202F}]+$/u', '', $text) ?? trim($text);
    }

    /**
     * Strip trailing punctuation from a label.
     *
     * @param string $label
     * @return string
     */
    private static function tidy(string $label): string {
        // A regular expression, not rtrim(): rtrim() works on bytes, and a multibyte dash in
        // its character list strips the trailing bytes of a Thai or Chinese label.
        return trim(preg_replace('/[\s.,;:\x{2013}\x{2014}-]+$/u', '', trim($label)));
    }

    /**
     * Count the words in a phrase.
     *
     * @param string $text
     * @return int
     */
    private static function words(string $text): int {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }

    /**
     * Format a mark for a message: no trailing zeros.
     *
     * @param float $value
     * @return string
     */
    public static function fmt(float $value): string {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
