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

namespace quiz_aigrader\report;

defined('MOODLE_INTERNAL') || die();

/**
 * Report service for generating grader activity reports.
 *
 * @package    quiz_aigrader
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service {
    /**
     * Get grader activity data for the report.
     *
     * @param int $startdate Unix timestamp for start of date range
     * @param int $enddate Unix timestamp for end of date range
     * @param int|null $graderid Filter by specific grader (optional)
     * @return array Array of report rows
     */
    public static function get_grader_activity($startdate, $enddate, $graderid = null) {
        global $DB;

        $params = [
            'startdate' => $startdate,
            'enddate' => $enddate,
        ];

        $graderwhere = '';
        if ($graderid) {
            $graderwhere = 'AND qas.userid = :graderid';
            $params['graderid'] = $graderid;
        }

        // Use CONCAT to create unique key per course/quiz/grader combination
        // This prevents get_records_sql from overwriting rows with same graderid
        // Include all name fields required by fullname() to prevent debugging output that breaks PDF
        // Also count distinct students graded
        $sql = "SELECT 
                    CONCAT(c.id, '_', q.id, '_', qas.userid) AS uniquekey,
                    qas.userid AS graderid,
                    u.firstname,
                    u.lastname,
                    u.firstnamephonetic,
                    u.lastnamephonetic,
                    u.middlename,
                    u.alternatename,
                    c.id AS courseid,
                    c.fullname AS coursename,
                    q.id AS quizid,
                    q.name AS quizname,
                    COUNT(qas.id) AS approved_count,
                    COUNT(DISTINCT qza.userid) AS students_graded
                FROM {question_attempt_steps} qas
                JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
                JOIN {quiz_attempts} qza ON qza.uniqueid = qa.questionusageid
                JOIN {quiz} q ON q.id = qza.quiz
                JOIN {course} c ON c.id = q.course
                JOIN {user} u ON u.id = qas.userid
                WHERE qas.state IN ('mangrright', 'mangrpartial', 'mangrwrong')
                  AND qas.timecreated >= :startdate
                  AND qas.timecreated <= :enddate
                  AND qas.userid != 0
                  {$graderwhere}
                GROUP BY c.id, q.id, qas.userid, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, c.fullname, q.name
                ORDER BY c.fullname, q.name, u.lastname, u.firstname";

        $records = $DB->get_records_sql($sql, $params);

        // Get grading time data from grading logs
        $timeparams = [
            'startdate' => $startdate,
            'enddate' => $enddate,
        ];
        $timegraderwhere = '';
        if ($graderid) {
            $timegraderwhere = 'AND gl.graderid = :graderid';
            $timeparams['graderid'] = $graderid;
        }
        
        $timesql = "SELECT gl.id, gl.graderid, gl.courseid, gl.quizid, gl.timegraded
                    FROM {quiz_aigrader_grading_logs} gl
                    WHERE gl.timegraded >= :startdate
                      AND gl.timegraded <= :enddate
                      {$timegraderwhere}
                    ORDER BY gl.graderid, gl.courseid, gl.quizid, gl.timegraded ASC";
        
        $timelogs = $DB->get_records_sql($timesql, $timeparams);
        
        // Calculate time per course/quiz/grader combination
        $timedata = [];
        $groupedlogs = [];
        foreach ($timelogs as $log) {
            $key = $log->courseid . '_' . $log->quizid . '_' . $log->graderid;
            if (!isset($groupedlogs[$key])) {
                $groupedlogs[$key] = [];
            }
            $groupedlogs[$key][] = $log->timegraded;
        }
        
        foreach ($groupedlogs as $key => $times) {
            $sessiontime = 0;
            for ($i = 1; $i < count($times); $i++) {
                $gap = $times[$i] - $times[$i - 1];
                // Cap gap at 2 minutes (120s) - longer gaps indicate breaks
                $sessiontime += min($gap, 120);
            }
            $timedata[$key] = $sessiontime;
        }

        $result = [];
        foreach ($records as $record) {
            $key = $record->courseid . '_' . $record->quizid . '_' . $record->graderid;
            $timeseconds = isset($timedata[$key]) ? $timedata[$key] : 0;
            
            $result[] = [
                'grader_id' => $record->graderid,
                'grader_name' => fullname($record),
                'course_id' => $record->courseid,
                'course_name' => $record->coursename,
                'quiz_id' => $record->quizid,
                'quiz_name' => $record->quizname,
                'approved_count' => $record->approved_count,
                'students_graded' => $record->students_graded,
                'time_seconds' => $timeseconds,
                'time_formatted' => $timeseconds > 0 ? gmdate('H:i:s', $timeseconds) : '-',
            ];
        }

        return $result;
    }

    /**
     * Get summary totals by grader.
     *
     * @param int $startdate Unix timestamp for start of date range
     * @param int $enddate Unix timestamp for end of date range
     * @param int|null $graderid Filter by specific grader (optional)
     * @return array Array of grader totals
     */
    public static function get_grader_totals($startdate, $enddate, $graderid = null) {
        global $DB;

        $params = [
            'startdate' => $startdate,
            'enddate' => $enddate,
        ];

        $graderwhere = '';
        if ($graderid) {
            $graderwhere = 'AND qas.userid = :graderid';
            $params['graderid'] = $graderid;
        }

        $sql = "SELECT 
                    qas.userid AS graderid,
                    u.firstname,
                    u.lastname,
                    u.firstnamephonetic,
                    u.lastnamephonetic,
                    u.middlename,
                    u.alternatename,
                    u.email,
                    COUNT(qas.id) AS total_approved
                FROM {question_attempt_steps} qas
                JOIN {user} u ON u.id = qas.userid
                WHERE qas.state IN ('mangrright', 'mangrpartial', 'mangrwrong')
                  AND qas.timecreated >= :startdate
                  AND qas.timecreated <= :enddate
                  AND qas.userid != 0
                  {$graderwhere}
                GROUP BY qas.userid, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email
                ORDER BY total_approved DESC";

        $records = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($records as $record) {
            $result[] = [
                'grader_id' => $record->graderid,
                'grader_name' => fullname($record),
                'grader_email' => $record->email,
                'total_approved' => $record->total_approved,
            ];
        }

        return $result;
    }

    /**
     * Get students graded per course (distinct students who had essays graded).
     *
     * @param int $startdate Unix timestamp for start of date range
     * @param int $enddate Unix timestamp for end of date range
     * @param int|null $graderid Filter by specific grader (optional)
     * @return array Array of course totals with student counts
     */
    public static function get_students_graded_per_course($startdate, $enddate, $graderid = null) {
        global $DB;

        $params = [
            'startdate' => $startdate,
            'enddate' => $enddate,
        ];

        $graderwhere = '';
        if ($graderid) {
            $graderwhere = 'AND qas.userid = :graderid';
            $params['graderid'] = $graderid;
        }

        $sql = "SELECT 
                    c.id AS courseid,
                    c.fullname AS coursename,
                    COUNT(DISTINCT qza.userid) AS students_graded,
                    COUNT(qas.id) AS questions_graded
                FROM {question_attempt_steps} qas
                JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
                JOIN {quiz_attempts} qza ON qza.uniqueid = qa.questionusageid
                JOIN {quiz} q ON q.id = qza.quiz
                JOIN {course} c ON c.id = q.course
                WHERE qas.state IN ('mangrright', 'mangrpartial', 'mangrwrong')
                  AND qas.timecreated >= :startdate
                  AND qas.timecreated <= :enddate
                  AND qas.userid != 0
                  {$graderwhere}
                GROUP BY c.id, c.fullname
                ORDER BY c.fullname";

        $records = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($records as $record) {
            $result[] = [
                'course_id' => $record->courseid,
                'course_name' => $record->coursename,
                'students_graded' => $record->students_graded,
                'questions_graded' => $record->questions_graded,
            ];
        }

        return $result;
    }

    /**
     * Get list of all users who have graded essays.
     *
     * @return array Array of user objects
     */
    public static function get_graders() {
        global $DB;

        // Include all name fields required by fullname() to prevent debugging output that breaks PDF
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email
                FROM {question_attempt_steps} qas
                JOIN {user} u ON u.id = qas.userid
                WHERE qas.state IN ('mangrright', 'mangrpartial', 'mangrwrong')
                  AND qas.userid != 0
                ORDER BY u.lastname, u.firstname";

        return $DB->get_records_sql($sql);
    }

    /**
     * Generate CSV content for the report.
     *
     * @param array $data Report data
     * @param int $startdate
     * @param int $enddate
     * @return string CSV content
     */
    public static function generate_csv($data, $startdate, $enddate) {
        $output = "AI Essay Grader Activity Report\n";
        $output .= "Date Range: " . userdate($startdate, '%d %B %Y') . " - " . userdate($enddate, '%d %B %Y') . "\n\n";
        $output .= "Course,Quiz,Grader,Questions Approved,Avg Time / Question\n";

        foreach ($data as $row) {
            $output .= '"' . str_replace('"', '""', $row['course_name']) . '",';
            $output .= '"' . str_replace('"', '""', $row['quiz_name']) . '",';
            $output .= '"' . str_replace('"', '""', $row['grader_name']) . '",';
            $output .= $row['approved_count'] . ',';
            $output .= '"' . ($row['avg_per_question'] ?? '-') . '"' . "\n";
        }

        return $output;
    }

    /**
     * Calculate next run time based on frequency.
     *
     * @param string $frequency daily, weekly, or monthly
     * @param int|null $lastrun Last run timestamp
     * @return int Next run timestamp
     */
    public static function calculate_next_run($frequency, $lastrun = null) {
        $now = time();
        $base = $lastrun ?: $now;

        switch ($frequency) {
            case 'daily':
                return strtotime('+1 day', $base);
            case 'weekly':
                return strtotime('+1 week', $base);
            case 'monthly':
                return strtotime('+1 month', $base);
            default:
                return strtotime('+1 week', $base);
        }
    }
}
