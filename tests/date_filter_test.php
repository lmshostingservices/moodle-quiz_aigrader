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
 * Unit tests for the saved submission date filter on the grading screen.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_aigrader;

use ReflectionMethod;

/**
 * Unit tests for the saved submission date filter on the grading screen.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader_report
 */
final class date_filter_test extends \advanced_testcase {
    /** @var \quiz_aigrader_report The report under test. */
    protected $report;

    /**
     * Load the report class and create an instance to exercise.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->dirroot . '/mod/quiz/report/aigrader/report.php');
        $this->report = new \quiz_aigrader_report();
    }

    /**
     * Call a private method on the report.
     *
     * @param string $name The method name.
     * @param array $args Arguments to pass.
     * @return mixed Whatever the method returns.
     */
    protected function call(string $name, array $args = []) {
        $method = new ReflectionMethod($this->report, $name);
        $method->setAccessible(true);
        return $method->invokeArgs($this->report, $args);
    }

    /**
     * Values that should and should not be accepted as filter dates.
     *
     * @return array[] Each case is a value and whether it is a valid date.
     */
    public static function filter_date_provider(): array {
        return [
            'valid date' => ['2026-01-01', true],
            'valid leap day' => ['2024-02-29', true],
            'non-leap 29 February' => ['2026-02-29', false],
            'month out of range' => ['2026-13-01', false],
            'day out of range' => ['2026-01-45', false],
            'unpadded' => ['2026-1-1', false],
            'empty' => ['', false],
            'relative expression' => ['next monday', false],
            'offset expression' => ['+10 years', false],
            'sql fragment' => ["2026-01-01'; DROP TABLE", false],
        ];
    }

    /**
     * Only well formed calendar dates are accepted.
     *
     * @dataProvider filter_date_provider
     * @param string $value The submitted value.
     * @param bool $expected Whether it should be accepted.
     * @return void
     */
    public function test_is_filter_date(string $value, bool $expected): void {
        $this->assertSame($expected, $this->call('is_filter_date', [$value]));
    }

    /**
     * Timezones to check the date round trip in.
     *
     * @return array[] Each case is a single timezone name.
     */
    public static function timezone_provider(): array {
        return [
            'Perth' => ['Australia/Perth'],
            'Bangkok' => ['Asia/Bangkok'],
            'Los Angeles' => ['America/Los_Angeles'],
            'Auckland' => ['Pacific/Auckland'],
            'UTC' => ['UTC'],
        ];
    }

    /**
     * A date survives the trip to a timestamp and back, in any timezone.
     *
     * The formatted value is fed straight back into an HTML date input, which rejects
     * anything that is not strictly YYYY-MM-DD. An unpadded day would render the field
     * empty while the filter was still applied.
     *
     * @dataProvider timezone_provider
     * @param string $timezone The timezone to test in.
     * @return void
     */
    public function test_date_round_trip(string $timezone): void {
        global $USER;

        $this->setAdminUser();
        $USER->timezone = $timezone;

        // Includes single-digit days, and days on which DST starts or ends somewhere.
        $dates = ['2026-01-01', '2026-03-29', '2026-06-15', '2026-10-04', '2026-11-01', '2024-02-29'];

        foreach ($dates as $date) {
            $start = $this->call('parse_filter_date', [$date, false]);
            $end = $this->call('parse_filter_date', [$date, true]);

            $this->assertSame(
                $date,
                $this->call('format_filter_date', [$start]),
                "start of {$date} did not round trip in {$timezone}"
            );
            $this->assertSame(
                $date,
                $this->call('format_filter_date', [$end]),
                "end of {$date} did not round trip in {$timezone}"
            );
            $this->assertGreaterThan($start, $end, "end of {$date} is not after its start");
        }
    }

    /**
     * An unset timestamp formats as an empty string rather than the epoch.
     *
     * @return void
     */
    public function test_format_filter_date_handles_zero(): void {
        $this->assertSame('', $this->call('format_filter_date', [0]));
    }

    /**
     * A saved range belongs to one quiz and does not leak into another.
     *
     * @return void
     */
    public function test_preference_is_scoped_per_course_module(): void {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $quizone = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $quiztwo = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cmone = get_coursemodule_from_instance('quiz', $quizone->id);
        $cmtwo = get_coursemodule_from_instance('quiz', $quiztwo->id);

        set_user_preference('quiz_aigrader_datefrom_' . $cmone->id, 1767225600);
        set_user_preference('quiz_aigrader_dateto_' . $cmone->id, 1769817599);

        $this->assertSame([1767225600, 1769817599], $this->call('resolve_date_filter', [$cmone]));
        $this->assertSame([0, 0], $this->call('resolve_date_filter', [$cmtwo]));
    }

    /**
     * A request without a valid sesskey never changes the saved range.
     *
     * @return void
     */
    public function test_filter_changes_require_a_sesskey(): void {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);

        set_user_preference('quiz_aigrader_datefrom_' . $cm->id, 1767225600);
        set_user_preference('quiz_aigrader_dateto_' . $cm->id, 1769817599);

        // A forged link that tries to widen the range, carrying no sesskey.
        $forgedapply = [
            'aigfilter' => 1,
            'datefrom' => '2099-01-01',
            'dateto' => '2099-12-31',
        ];

        $this->assertSame([1767225600, 1769817599], $this->call('resolve_date_filter', [$cm, $forgedapply]));
        $this->assertEquals(1767225600, get_user_preferences('quiz_aigrader_datefrom_' . $cm->id));

        // A wrong key is no better than a missing one.
        $forgedapply['sesskey'] = 'not-the-real-key';

        $this->assertSame([1767225600, 1769817599], $this->call('resolve_date_filter', [$cm, $forgedapply]));
        $this->assertEquals(1767225600, get_user_preferences('quiz_aigrader_datefrom_' . $cm->id));

        // The same is true of a forged clear.
        $forgedclear = ['aigclearfilter' => 1];

        $this->assertSame([1767225600, 1769817599], $this->call('resolve_date_filter', [$cm, $forgedclear]));
        $this->assertEquals(1767225600, get_user_preferences('quiz_aigrader_datefrom_' . $cm->id));
    }

    /**
     * With a sesskey, a submitted range is applied and saved.
     *
     * @return void
     */
    public function test_filter_is_saved_with_a_sesskey(): void {
        global $USER;

        $this->setAdminUser();
        $USER->timezone = 'UTC';

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);

        $apply = [
            'sesskey' => sesskey(),
            'aigfilter' => 1,
            'datefrom' => '2026-05-01',
            'dateto' => '2026-05-31',
        ];

        [$from, $to] = $this->call('resolve_date_filter', [$cm, $apply]);

        $this->assertSame('2026-05-01', $this->call('format_filter_date', [$from]));
        $this->assertSame('2026-05-31', $this->call('format_filter_date', [$to]));
        $this->assertEquals($from, get_user_preferences('quiz_aigrader_datefrom_' . $cm->id));

        // Clearing removes it.
        $clear = ['sesskey' => sesskey(), 'aigclearfilter' => 1];

        $this->assertSame([0, 0], $this->call('resolve_date_filter', [$cm, $clear]));
        $this->assertNull(get_user_preferences('quiz_aigrader_datefrom_' . $cm->id));
    }

    /**
     * A range entered back to front is corrected rather than rejected.
     *
     * @return void
     */
    public function test_inverted_range_is_swapped(): void {
        global $USER;

        $this->setAdminUser();
        $USER->timezone = 'UTC';

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);

        $inverted = [
            'sesskey' => sesskey(),
            'aigfilter' => 1,
            'datefrom' => '2026-03-31',
            'dateto' => '2026-03-01',
        ];

        [$from, $to] = $this->call('resolve_date_filter', [$cm, $inverted]);

        $this->assertSame('2026-03-01', $this->call('format_filter_date', [$from]));
        $this->assertSame('2026-03-31', $this->call('format_filter_date', [$to]));
    }

    /**
     * An invalid value in one field never displaces the valid value in the other.
     *
     * @return void
     */
    public function test_invalid_date_does_not_displace_the_other(): void {
        global $USER;

        $this->setAdminUser();
        $USER->timezone = 'UTC';

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);

        $malformed = [
            'sesskey' => sesskey(),
            'aigfilter' => 1,
            'datefrom' => '2026-13-45',
            'dateto' => '2026-05-10',
        ];

        [$from, $to] = $this->call('resolve_date_filter', [$cm, $malformed]);

        $this->assertSame(0, $from);
        $this->assertSame('2026-05-10', $this->call('format_filter_date', [$to]));
    }
}
