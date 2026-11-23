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

use quiz_aigrader\local\access;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/tests/helpers.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_aigrader\local\access::class)]
/**
 * Tests for who may mark which attempt from the AI Essay Grader endpoint (5.1.0).
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\local\access
 */
final class access_test extends \advanced_testcase {
    /**
     * An attempt still in progress cannot be marked; a finished one can.
     *
     * @return void
     */
    public function test_only_finished_attempts(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);
        $context = \context_module::instance($cm->id);
        $learner = $this->getDataGenerator()->create_user();

        $attempt = (object)['userid' => $learner->id, 'state' => 'inprogress'];
        $this->assertSame(access::ATTEMPT_NOT_FINISHED, access::attempt_problem($attempt, $cm, $context));

        $attempt->state = 'overdue';
        $this->assertSame(access::ATTEMPT_NOT_FINISHED, access::attempt_problem($attempt, $cm, $context));

        $attempt->state = 'finished';
        $this->assertNull(access::attempt_problem($attempt, $cm, $context));
    }

    /**
     * Under separate groups, an assessor without access to all groups marks only their own groups.
     *
     * @return void
     */
    public function test_separate_groups(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $quiz = $generator->create_module('quiz', ['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);
        $context = \context_module::instance($cm->id);

        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);

        $assessor = $generator->create_and_enrol($course, 'teacher');
        $manager = $generator->create_and_enrol($course, 'editingteacher');
        $learnera = $generator->create_and_enrol($course, 'student');
        $learnerb = $generator->create_and_enrol($course, 'student');
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $assessor->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $learnera->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $learnerb->id]);

        $attempta = (object)['userid' => $learnera->id, 'state' => 'finished'];
        $attemptb = (object)['userid' => $learnerb->id, 'state' => 'finished'];

        $this->setUser($assessor);
        $this->assertNull(access::attempt_problem($attempta, $cm, $context));
        $this->assertSame(access::GROUP_ACCESS, access::attempt_problem($attemptb, $cm, $context));

        // An editing teacher holds moodle/site:accessallgroups and may mark either.
        $this->setUser($manager);
        $this->assertNull(access::attempt_problem($attempta, $cm, $context));
        $this->assertNull(access::attempt_problem($attemptb, $cm, $context));
    }

    /**
     * Only essay questions are marked here.
     *
     * @return void
     */
    public function test_only_essays(): void {
        $essay = new \question_attempt(\test_question_maker::make_question('essay', 'editor'), 0);
        $this->assertNull(access::question_problem($essay));

        $truefalse = new \question_attempt(\test_question_maker::make_question('truefalse', 'true'), 0);
        $this->assertSame(access::NOT_ESSAY, access::question_problem($truefalse));
    }

    /**
     * Every problem has a message an assessor can read.
     *
     * @return void
     */
    public function test_every_problem_has_a_message(): void {
        foreach ([access::ATTEMPT_NOT_FINISHED, access::GROUP_ACCESS, access::NOT_ESSAY] as $problem) {
            $this->assertTrue(get_string_manager()->string_exists(access::message_id($problem), 'quiz_aigrader'));
        }
    }
}
