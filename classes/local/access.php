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

/**
 * Whether the current user may mark a given attempt from the AI Essay Grader.
 *
 * The report page lists only finished attempts from the groups the assessor can see, but the
 * AJAX endpoint accepts any question usage id that belongs to the quiz. These checks make the
 * endpoint enforce the same rules as the page, and the same rules core applies on
 * mod/quiz/comment.php: the attempt must be finished, and under separate groups the learner
 * must be in a group the assessor can access.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /** @var string The attempt is not finished. */
    public const ATTEMPT_NOT_FINISHED = 'attempt_not_finished';

    /** @var string The learner is outside every group the assessor may access. */
    public const GROUP_ACCESS = 'group_access';

    /** @var string The question is not an essay. */
    public const NOT_ESSAY = 'not_essay';

    /**
     * Why the current user may not mark this attempt, or null when they may.
     *
     * @param \stdClass $attempt The quiz_attempts row, already checked to belong to the quiz.
     * @param \stdClass|\cm_info $cm The quiz course module.
     * @param \context_module $context The quiz context.
     * @return string|null One of the class constants, or null.
     */
    public static function attempt_problem(\stdClass $attempt, $cm, \context_module $context): ?string {
        if ($attempt->state !== 'finished') {
            return self::ATTEMPT_NOT_FINISHED;
        }

        $groupmode = groups_get_activity_groupmode($cm);
        if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
            $allowed = groups_get_activity_allowed_groups($cm);
            $learnergroups = groups_get_all_groups($cm->course, (int)$attempt->userid, $cm->groupingid);
            if (empty(array_intersect_key($allowed, $learnergroups))) {
                return self::GROUP_ACCESS;
            }
        }

        return null;
    }

    /**
     * Why this question may not be marked here, or null when it may.
     *
     * The plugin marks essay responses only; every other question type in the same attempt
     * is marked by the quiz itself or by core's manual grading.
     *
     * @param \question_attempt $qa The question attempt.
     * @return string|null self::NOT_ESSAY, or null.
     */
    public static function question_problem(\question_attempt $qa): ?string {
        return $qa->get_question()->qtype->name() === 'essay' ? null : self::NOT_ESSAY;
    }

    /**
     * The language string that explains a problem to the assessor.
     *
     * @param string $problem One of the class constants.
     * @return string The string identifier in quiz_aigrader.
     */
    public static function message_id(string $problem): string {
        $map = [
            self::ATTEMPT_NOT_FINISHED => 'error_attemptnotfinished',
            self::GROUP_ACCESS => 'error_groupaccess',
            self::NOT_ESSAY => 'error_notessay',
        ];
        return $map[$problem] ?? 'error_nopermission';
    }
}
