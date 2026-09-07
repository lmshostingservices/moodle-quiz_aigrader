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

/**
 * Unit tests for the quiz_aigrader privacy provider.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_aigrader;

use context_module;
use context_system;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use quiz_aigrader\privacy\provider;

/**
 * Unit tests for the quiz_aigrader privacy provider.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass The course used by the fixture. */
    protected $course;

    /** @var \stdClass The quiz used by the fixture. */
    protected $quiz;

    /** @var \stdClass The quiz course module. */
    protected $cm;

    /** @var \stdClass The student whose answer was graded. */
    protected $student;

    /** @var \stdClass The teacher who approved the grade. */
    protected $teacher;

    /**
     * Create a course, a quiz, a student and a teacher, and populate the plugin tables.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->quiz = $generator->create_module('quiz', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('quiz', $this->quiz->id);
        $this->student = $generator->create_user();
        $this->teacher = $generator->create_user();

        $now = time();

        $DB->insert_record('quiz_aigrader_attempt_ctx', (object) [
            'quizid' => $this->quiz->id,
            'userid' => $this->student->id,
            'questionid' => 1,
            'slot' => 1,
            'attemptnum' => 1,
            'criteriamet' => '[1,2]',
            'feedbacksummary' => 'Identified the hazard clearly.',
            'lastgrade' => 66.67,
            'humanreview' => 0,
            'autolocked' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $DB->insert_record('quiz_aigrader_grading_logs', (object) [
            'quizid' => $this->quiz->id,
            'courseid' => $this->course->id,
            'graderid' => $this->teacher->id,
            'qubaid' => 1,
            'slot' => 1,
            'timegraded' => $now,
        ]);

        $DB->insert_record('quiz_aigrader_schedules', (object) [
            'userid' => $this->teacher->id,
            'frequency' => 'weekly',
            'recipients' => 'someone@example.com',
            'lastrun' => null,
            'nextrun' => $now + DAYSECS,
            'enabled' => 1,
            'format' => 'excel',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * The metadata collection describes all three tables and the external service.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('quiz_aigrader'));
        $items = $collection->get_collection();

        $names = [];
        foreach ($items as $item) {
            $names[] = $item->get_name();
        }

        $this->assertContains('quiz_aigrader_attempt_ctx', $names);
        $this->assertContains('quiz_aigrader_grading_logs', $names);
        $this->assertContains('quiz_aigrader_schedules', $names);
        $this->assertContains('essaygraderai_api', $names);
    }

    /**
     * The saved filter preferences are declared and exported.
     *
     * @return void
     */
    public function test_export_user_preferences(): void {
        $this->setUser($this->teacher);

        set_user_preference('quiz_aigrader_datefrom_' . $this->cm->id, 1767225600);
        set_user_preference('quiz_aigrader_report_graderid', 7);

        provider::export_user_preferences($this->teacher->id);

        $writer = writer::with_context(context_system::instance());
        $this->assertTrue($writer->has_any_data());

        // The assertObjectHasProperty() assertion needs PHPUnit 10.1, which Moodle 4.2 does not ship.
        $preferences = (array) $writer->get_user_preferences('quiz_aigrader');
        $this->assertArrayHasKey('quiz_aigrader_datefrom_' . $this->cm->id, $preferences);
        $this->assertArrayHasKey('quiz_aigrader_report_graderid', $preferences);
    }

    /**
     * A graded student is reported against the quiz module context.
     *
     * @return void
     */
    public function test_get_contexts_for_student(): void {
        $contextlist = provider::get_contexts_for_userid($this->student->id);

        // The get_contextids() call returns the ids as strings, so compare as integers.
        $contextids = array_map('intval', $contextlist->get_contextids());

        $this->assertContains((int) context_module::instance($this->cm->id)->id, $contextids);
        $this->assertNotContains((int) context_system::instance()->id, $contextids);
    }

    /**
     * A marker with a schedule is reported against both the module and system contexts.
     *
     * @return void
     */
    public function test_get_contexts_for_teacher(): void {
        $contextlist = provider::get_contexts_for_userid($this->teacher->id);

        // The get_contextids() call returns the ids as strings, so compare as integers.
        $contextids = array_map('intval', $contextlist->get_contextids());

        $this->assertContains((int) context_module::instance($this->cm->id)->id, $contextids);
        $this->assertContains((int) context_system::instance()->id, $contextids);
    }

    /**
     * Both the student and the marker are found in the quiz module context.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $context = context_module::instance($this->cm->id);
        $userlist = new userlist($context, 'quiz_aigrader');
        provider::get_users_in_context($userlist);

        $userids = array_map('intval', $userlist->get_userids());
        $this->assertContains((int) $this->student->id, $userids);
        $this->assertContains((int) $this->teacher->id, $userids);
    }

    /**
     * Exporting a student writes their grading context to the module context.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        $context = context_module::instance($this->cm->id);

        $contextlist = new approved_contextlist($this->student, 'quiz_aigrader', [$context->id]);
        provider::export_user_data($contextlist);

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * Deleting all data in a module context removes the module rows but not the schedules.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context(context_module::instance($this->cm->id));

        $this->assertEquals(0, $DB->count_records('quiz_aigrader_attempt_ctx'));
        $this->assertEquals(0, $DB->count_records('quiz_aigrader_grading_logs'));
        $this->assertEquals(1, $DB->count_records('quiz_aigrader_schedules'));
    }

    /**
     * Deleting one user removes only their rows.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $context = context_module::instance($this->cm->id);
        $contextlist = new approved_contextlist($this->student, 'quiz_aigrader', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertEquals(0, $DB->count_records('quiz_aigrader_attempt_ctx'));
        $this->assertEquals(1, $DB->count_records('quiz_aigrader_grading_logs'));
    }

    /**
     * Deleting an approved user list removes only the listed users.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $context = context_module::instance($this->cm->id);
        $userlist = new approved_userlist($context, 'quiz_aigrader', [$this->teacher->id]);
        provider::delete_data_for_users($userlist);

        $this->assertEquals(1, $DB->count_records('quiz_aigrader_attempt_ctx'));
        $this->assertEquals(0, $DB->count_records('quiz_aigrader_grading_logs'));
    }

    /**
     * Deleting a user in the system context removes their schedules.
     *
     * @return void
     */
    public function test_delete_schedules_for_user(): void {
        global $DB;

        $contextlist = new approved_contextlist(
            $this->teacher,
            'quiz_aigrader',
            [context_system::instance()->id]
        );
        provider::delete_data_for_user($contextlist);

        $this->assertEquals(0, $DB->count_records('quiz_aigrader_schedules'));
    }
}
