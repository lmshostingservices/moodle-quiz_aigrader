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
 * Marking criteria an assessor confirmed for a question.
 *
 * Reading criteria out of free text can never be certain for every way an assessor might
 * write them. So the plugin proposes, and a person confirms: once per question, in the
 * Criteria check panel, the assessor reviews the proposed criteria, edits them if needed,
 * and confirms. Only confirmed criteria drive the per-criterion breakdown, and so the mark.
 * A question whose criteria are not confirmed is given one overall mark.
 *
 * A confirmation holds for the question exactly as it was: if the question text,
 * Information for graders, General feedback, default mark or the question's mark in the
 * quiz change, the criteria must be confirmed again.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirmed {
    /** @var string Where confirmed criteria come from, in the decision record. */
    public const SOURCE = 'confirmed';

    /** @var string The question has confirmed criteria that are in force. */
    public const STATE_CONFIRMED = 'confirmed';
    /** @var string The question has no confirmed criteria. */
    public const STATE_REQUIRED = 'required';
    /** @var string The question changed after its criteria were confirmed. */
    public const STATE_STALE = 'stale';
    /** @var string Confirmation is switched off for the site. */
    public const STATE_OFF = 'off';

    /** @var int The most criteria a question may have. */
    public const MAX_ROWS = 10;
    /** @var int The longest a criterion's wording may be. */
    public const LABEL_LENGTH = 200;

    /**
     * Whether the site requires criteria to be confirmed before they are used.
     *
     * @return bool
     */
    public static function required(): bool {
        $value = get_config('quiz_aigrader', 'criteria_confirmation');
        // On unless switched off: an unset value is the default.
        return $value === false || $value === null || $value === '' || (bool)(int)$value;
    }

    /**
     * A hash of everything the confirmation depends on.
     *
     * @param string $graderinfo Information for graders, as stored.
     * @param string $questiontext The question text, as stored.
     * @param string $generalfeedback General feedback, as stored.
     * @param float $defaultmark The question's default mark.
     * @return string 40 hex characters.
     */
    public static function fields_hash(
        string $graderinfo,
        string $questiontext,
        string $generalfeedback,
        float $defaultmark
    ): string {
        return sha1(json_encode([
            trim($questiontext),
            trim($graderinfo),
            trim($generalfeedback),
            round($defaultmark, 7),
        ]));
    }

    /**
     * The fields the criteria are read from, for a question.
     *
     * @param int $questionid
     * @return \stdClass|null With questiontext, generalfeedback, graderinfo and defaultmark.
     */
    public static function question_fields(int $questionid): ?\stdClass {
        global $DB;
        $record = $DB->get_record_sql(
            "SELECT q.id, q.questiontext, q.generalfeedback, q.defaultmark, eo.graderinfo
               FROM {question} q
          LEFT JOIN {qtype_essay_options} eo ON eo.questionid = q.id
              WHERE q.id = :id",
            ['id' => $questionid]
        );
        if (!$record) {
            return null;
        }
        $record->questiontext = (string)$record->questiontext;
        $record->generalfeedback = (string)$record->generalfeedback;
        $record->graderinfo = (string)($record->graderinfo ?? '');
        $record->defaultmark = (float)$record->defaultmark;
        return $record;
    }

    /**
     * The marks a question carries in a quiz, from its attempts there.
     *
     * A question drawn into random slots can carry a different mark in each; each mark has its
     * own confirmation. Also proves the question belongs to the quiz: empty when no attempt in
     * the quiz used it.
     *
     * @param int $quizid
     * @param int $questionid
     * @return float[] Distinct marks, to two decimal places.
     */
    public static function quiz_maxmarks(int $quizid, int $questionid): array {
        global $DB;
        $marks = $DB->get_fieldset_sql(
            "SELECT DISTINCT qa.maxmark
               FROM {question_attempts} qa
               JOIN {quiz_attempts} quiza ON quiza.uniqueid = qa.questionusageid
              WHERE quiza.quiz = :quizid AND qa.questionid = :questionid",
            ['quizid' => $quizid, 'questionid' => $questionid]
        );
        return array_values(array_unique(array_map(function ($m) {
            return round((float)$m, 2);
        }, $marks)));
    }

    /**
     * Whether a question carries a given mark in a quiz.
     *
     * @param int $quizid
     * @param int $questionid
     * @param float $maxmark
     * @return bool
     */
    public static function quiz_has_mark(int $quizid, int $questionid, float $maxmark): bool {
        foreach (self::quiz_maxmarks($quizid, $questionid) as $mark) {
            if (abs($mark - $maxmark) < 0.005) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check criteria an assessor submitted.
     *
     * The wording is kept exactly as typed, apart from spacing: it is escaped wherever it is
     * shown, so "Cites <3 sources" stays "Cites <3 sources". Two criteria are the same when
     * they reduce to the same key, which is how scores are matched to them, so "Safety: PPE"
     * and "Safety - PPE" cannot both be confirmed.
     *
     * @param mixed $rows Decoded JSON: a list of ['label' => string, 'max' => number].
     * @param float $maxmark The question's mark in the quiz.
     * @return array [the cleaned criteria, a list of error string identifiers]
     */
    public static function validate($rows, float $maxmark): array {
        $errors = [];
        if (!is_array($rows) || !$rows) {
            return [[], ['confirm_error_empty']];
        }
        $clean = [];
        $seen = [];
        $sum = 0.0;
        foreach (array_values($rows) as $row) {
            if (!is_array($row)) {
                $errors[] = 'confirm_error_label';
                continue;
            }
            $label = isset($row['label']) && is_scalar($row['label']) ? (string)$row['label'] : '';
            $label = self::tidy_label($label);
            $max = $row['max'] ?? null;
            if (is_string($max)) {
                $max = str_replace(',', '.', trim($max));
            }
            $key = criteria::key($label);
            if ($label === '' || $key === '') {
                $errors[] = 'confirm_error_label';
                continue;
            }
            if (core_text::strlen($label) > self::LABEL_LENGTH) {
                $errors[] = 'confirm_error_long';
            }
            if (isset($seen[$key])) {
                $errors[] = 'confirm_error_duplicate';
            }
            $seen[$key] = true;
            if (!is_numeric($max) || !is_finite((float)$max) || round((float)$max, 2) <= 0) {
                $errors[] = 'confirm_error_mark';
                continue;
            }
            $max = round((float)$max, 2);
            $sum += $max;
            $clean[] = ['label' => $label, 'max' => $max];
        }
        if (count($rows) > self::MAX_ROWS) {
            $errors[] = 'confirm_error_count';
        }
        if (!$errors && abs($sum - $maxmark) > 0.005) {
            $errors[] = 'confirm_error_total';
        }
        return [$errors ? [] : $clean, array_values(array_unique($errors))];
    }

    /**
     * A criterion's wording with its spacing tidied: control characters and runs of spaces,
     * including non-breaking ones, become single spaces.
     *
     * @param string $label
     * @return string
     */
    public static function tidy_label(string $label): string {
        $label = fix_utf8($label);
        $label = preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{00A0}\x{2000}-\x{200B}\x{202F}\x{3000}\s]+/u', ' ', $label);
        return trim($label);
    }

    /**
     * The confirmation on record for a question at a mark in a quiz.
     *
     * @param int $quizid
     * @param int $questionid
     * @param float $maxmark The question's mark in the quiz.
     * @return \stdClass|null
     */
    public static function get(int $quizid, int $questionid, float $maxmark): ?\stdClass {
        global $DB;
        $records = $DB->get_records('quiz_aigrader_criteria', ['quizid' => $quizid, 'questionid' => $questionid]);
        foreach ($records as $record) {
            if (abs((float)$record->maxmark - $maxmark) < 0.005) {
                return $record;
            }
        }
        return null;
    }

    /**
     * The state of a question's confirmation, and its criteria when in force.
     *
     * @param int $quizid
     * @param int $questionid
     * @param string $fieldshash The question's fields as they are now, see fields_hash().
     * @param float $maxmark The question's mark in the quiz now.
     * @return array ['state' => one of the STATE_ constants, 'criteria' => items or [],
     *     'record' => the stored row or null]
     */
    public static function state(int $quizid, int $questionid, string $fieldshash, float $maxmark): array {
        if (!self::required()) {
            return ['state' => self::STATE_OFF, 'criteria' => [], 'record' => null];
        }
        $record = self::get($quizid, $questionid, $maxmark);
        if (!$record) {
            return ['state' => self::STATE_REQUIRED, 'criteria' => [], 'record' => null];
        }
        $items = self::items($record);
        $sum = criteria::total($items);
        if (
            $record->fieldshash !== $fieldshash
            || abs((float)$record->maxmark - $maxmark) > 0.005
            || !$items
            || abs($sum - $maxmark) > 0.005
        ) {
            return ['state' => self::STATE_STALE, 'criteria' => [], 'record' => $record];
        }
        return ['state' => self::STATE_CONFIRMED, 'criteria' => $items, 'record' => $record];
    }

    /**
     * The stored criteria in the form grading uses.
     *
     * @param \stdClass $record
     * @return array Items with key, label, detail, max and own.
     */
    public static function items(\stdClass $record): array {
        $rows = json_decode((string)$record->criteria, true);
        if (!is_array($rows)) {
            return [];
        }
        $items = [];
        $keys = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['label']) || !isset($row['max'])) {
                return [];
            }
            $key = criteria::key((string)$row['label']);
            if ($key === '') {
                $key = 'criterion';
            }
            $base = $key;
            for ($n = 2; isset($keys[$key]); $n++) {
                $key = $base . '_' . $n;
            }
            $keys[$key] = true;
            $items[] = [
                'key' => $key,
                'label' => (string)$row['label'],
                'detail' => '',
                'max' => (float)$row['max'],
                'own' => true,
            ];
        }
        return $items;
    }

    /**
     * Record criteria an assessor confirmed, replacing any earlier confirmation.
     *
     * @param int $quizid
     * @param int $questionid
     * @param array $criteria Criteria that passed validate().
     * @param float $maxmark
     * @param string $fieldshash
     * @param string $proposedsource Where the proposal came from.
     * @param int $userid Who confirmed.
     * @param bool $bulk Whether it was confirmed with others in one action, unopened.
     * @return \stdClass The stored record.
     */
    public static function save(
        int $quizid,
        int $questionid,
        array $criteria,
        float $maxmark,
        string $fieldshash,
        string $proposedsource,
        int $userid,
        bool $bulk = false
    ): \stdClass {
        global $DB;
        $maxmark = round($maxmark, 2);
        $record = self::get($quizid, $questionid, $maxmark) ?? new \stdClass();
        $record->quizid = $quizid;
        $record->questionid = $questionid;
        $record->criteria = json_encode(array_values($criteria), JSON_UNESCAPED_UNICODE);
        $record->maxmark = $maxmark;
        $record->fieldshash = $fieldshash;
        $record->proposedsource = $proposedsource !== '' ? core_text::substr($proposedsource, 0, 20) : null;
        $record->confirmedby = $userid;
        $record->method = $bulk ? 'bulk' : 'reviewed';
        $record->timeconfirmed = time();
        if (!empty($record->id)) {
            $DB->update_record('quiz_aigrader_criteria', $record);
        } else {
            $record->id = $DB->insert_record('quiz_aigrader_criteria', $record);
        }
        return $record;
    }

    /**
     * Withdraw a question's confirmation at a mark.
     *
     * @param int $quizid
     * @param int $questionid
     * @param float $maxmark
     * @return void
     */
    public static function reset(int $quizid, int $questionid, float $maxmark): void {
        global $DB;
        $record = self::get($quizid, $questionid, $maxmark);
        if ($record) {
            $DB->delete_records('quiz_aigrader_criteria', ['id' => $record->id]);
        }
    }

    /**
     * Apply the confirmation to what the plugin read from the question.
     *
     * With confirmation switched off, the reading is returned as it is. Otherwise the
     * confirmed criteria replace it when in force; when not, no criteria are certain, and
     * the question is marked overall until someone confirms them.
     *
     * @param array $resolved The result of criteria::resolve().
     * @param array $state The result of state().
     * @return array The resolution grading uses, with 'confirmation' set to the state and
     *     'confirmedby' and 'timeconfirmed' when confirmed.
     */
    public static function apply(array $resolved, array $state): array {
        $resolved['confirmation'] = $state['state'];
        $resolved['proposed'] = $resolved['confidence'];
        if ($state['state'] === self::STATE_OFF) {
            return $resolved;
        }
        if ($state['state'] === self::STATE_CONFIRMED) {
            $resolved['confidence'] = criteria::CERTAIN;
            $resolved['source'] = self::SOURCE;
            $resolved['criteria'] = $state['criteria'];
            $resolved['candidates'] = $state['criteria'];
            $resolved['total'] = criteria::total($state['criteria']);
            $resolved['warnings'] = [];
            $resolved['confirmedby'] = (int)$state['record']->confirmedby;
            $resolved['timeconfirmed'] = (int)$state['record']->timeconfirmed;
            return $resolved;
        }
        // Not confirmed: what was read is a proposal only.
        $resolved['criteria'] = [];
        return $resolved;
    }

    /**
     * What to prefill the editor with: the confirmed criteria, or what was read.
     *
     * @param array $resolved The result of criteria::resolve(), before apply().
     * @param \stdClass|null $record The stored confirmation, if any.
     * @return array A list of ['label' => string, 'max' => float|null].
     */
    public static function proposal(array $resolved, ?\stdClass $record): array {
        if ($record) {
            $items = self::items($record);
            if ($items) {
                return array_map(function ($c) {
                    return ['label' => $c['label'], 'max' => $c['max']];
                }, $items);
            }
        }
        // Levels of answer ("2 marks: full answer / 1 mark: partial answer") are not criteria:
        // proposing them as criteria would invite the assessor to confirm a mark band.
        // Nor are acceptable points in a pick-list ("any 2 of these 7"): each is not a criterion.
        if (array_intersect(['bands', 'pointslist'], $resolved['reasons'] ?? [])) {
            return [];
        }
        $source = $resolved['confidence'] === criteria::CERTAIN ? $resolved['criteria'] : $resolved['candidates'];
        $rows = array_map(function ($c) {
            return ['label' => (string)$c['label'], 'max' => isset($c['max']) ? (float)$c['max'] : null];
        }, array_slice($source, 0, self::MAX_ROWS));
        // Weights written as percentages are shared out of the question's mark: 20%, 30% and 50%
        // of a 10-mark question are proposed as 2, 3 and 5.
        $maxmark = (float)($resolved['maxmark'] ?? 0);
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += $row['max'] ?? 0;
        }
        $allmarked = $rows && !in_array(null, array_column($rows, 'max'), true);
        if ($allmarked && abs($sum - 100) < 0.005 && $maxmark > 0 && abs($maxmark - 100) > 0.005) {
            $given = 0.0;
            foreach ($rows as $i => $row) {
                $share = $i === count($rows) - 1 ? round($maxmark - $given, 2) : round($row['max'] * $maxmark / 100, 2);
                $given += $share;
                $rows[$i]['max'] = $share;
            }
        }
        return $rows;
    }
}
