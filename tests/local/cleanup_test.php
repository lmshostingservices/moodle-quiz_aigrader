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

#[\PHPUnit\Framework\Attributes\CoversClass(cleanup::class)]
/**
 * Tests that the plugin's records go when what they describe is deleted.
 *
 * @package    quiz_aigrader
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\local\cleanup
 */
final class cleanup_test extends \advanced_testcase {
    /**
     * Add one row to each table for a quiz, learner and grader.
     *
     * @param int $quizid
     * @param int $userid
     * @param int $graderid
     * @param int $qubaid
     * @return void
     */
    private function seed(int $quizid, int $userid, int $graderid, int $qubaid): void {
        global $DB;
        $now = time();
        $DB->insert_record('quiz_aigrader_attempt_ctx', (object)['quizid' => $quizid, 'userid' => $userid,
            'questionid' => 1, 'slot' => 1, 'attemptnum' => 1, 'timecreated' => $now, 'timemodified' => $now]);
        $DB->insert_record('quiz_aigrader_decisions', (object)['quizid' => $quizid, 'courseid' => 1, 'userid' => $userid,
            'graderid' => $graderid, 'qubaid' => $qubaid, 'slot' => 1, 'questionid' => 1, 'attemptnum' => 1,
            'maxmark' => 4, 'finalmark' => 3, 'acknowledgement' => 'Checked', 'timecreated' => $now]);
        $DB->insert_record('quiz_aigrader_grading_logs', (object)['quizid' => $quizid, 'courseid' => 1,
            'graderid' => $graderid, 'qubaid' => $qubaid, 'slot' => 1, 'timegraded' => $now]);
        $DB->insert_record('quiz_aigrader_criteria', (object)['quizid' => $quizid, 'questionid' => 1, 'criteria' => '[]',
            'maxmark' => 4, 'confirmedby' => $graderid, 'timeconfirmed' => $now]);
    }

    /**
     * Deleting a quiz removes its records, and only its records.
     */
    public function test_quiz_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $other = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $learner = $this->getDataGenerator()->create_user();
        $grader = $this->getDataGenerator()->create_user();
        $this->seed($quiz->id, $learner->id, $grader->id, 9001);
        $this->seed($other->id, $learner->id, $grader->id, 9002);

        // The event Moodle fires when an activity is deleted. Fired directly: the function that
        // deletes a module moved in Moodle 5.2, and this test runs on 4.4 to 5.3.
        \core\event\course_module_deleted::create([
            'courseid' => $course->id,
            'context' => \context_module::instance($quiz->cmid),
            'objectid' => $quiz->cmid,
            'other' => ['modulename' => 'quiz', 'instanceid' => $quiz->id],
        ])->trigger();

        foreach (cleanup::QUIZ_TABLES as $table) {
            $this->assertSame(0, $DB->count_records($table, ['quizid' => $quiz->id]), $table);
            $this->assertSame(1, $DB->count_records($table, ['quizid' => $other->id]), $table);
        }
    }

    /**
     * Deleting a user removes their records and their name from other people's.
     */
    public function test_user_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $learner = $this->getDataGenerator()->create_user();
        $grader = $this->getDataGenerator()->create_user();
        $this->seed($quiz->id, $learner->id, $grader->id, 9001);

        delete_user($grader);

        // The learner's decision stays as their evidence, without the grader.
        $decision = $DB->get_record('quiz_aigrader_decisions', ['userid' => $learner->id], '*', MUST_EXIST);
        $this->assertEquals(0, $decision->graderid);
        $this->assertNull($decision->acknowledgement);
        $this->assertSame(0, $DB->count_records('quiz_aigrader_grading_logs', ['graderid' => $grader->id]));
        $this->assertSame(0, $DB->count_records('quiz_aigrader_criteria', ['confirmedby' => $grader->id]));

        delete_user($learner);
        $this->assertSame(0, $DB->count_records('quiz_aigrader_decisions', ['userid' => $learner->id]));
        $this->assertSame(0, $DB->count_records('quiz_aigrader_attempt_ctx', ['userid' => $learner->id]));
    }

    /**
     * The daily task removes what the events cannot reach, such as a quiz deleted with its course.
     */
    public function test_remove_orphans(): void {
        global $DB;
        $this->resetAfterTest();
        $learner = $this->getDataGenerator()->create_user();
        $grader = $this->getDataGenerator()->create_user();
        // A quiz id that does not exist, as after a course deletion.
        $this->seed(987654, $learner->id, $grader->id, 987654);

        $this->assertGreaterThan(0, cleanup::remove_orphans());
        foreach (cleanup::QUIZ_TABLES as $table) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
        $this->assertSame(0, cleanup::remove_orphans());
    }
}
