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

namespace quiz_aigrader\task;

use quiz_aigrader\report\service;

/**
 * Scheduled task to send grader activity reports via email.
 *
 * @package    quiz_aigrader
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_report_task extends \core\task\scheduled_task {
    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_send_report', 'quiz_aigrader');
    }

    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute() {
        global $DB, $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $now = time();

        $schedules = $DB->get_records_select(
            'quiz_aigrader_schedules',
            'enabled = 1 AND (nextrun IS NULL OR nextrun <= :now)',
            ['now' => $now]
        );

        if (empty($schedules)) {
            mtrace('No scheduled reports due.');
            return;
        }

        foreach ($schedules as $schedule) {
            try {
                $this->process_schedule($schedule);
            } catch (\Exception $e) {
                mtrace('Error processing schedule ' . $schedule->id . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Process a single schedule.
     *
     * @param object $schedule Schedule record.
     * @return void
     */
    protected function process_schedule($schedule) {
        global $DB;

        $user = $DB->get_record('user', ['id' => $schedule->userid]);
        if (!$user) {
            mtrace('Schedule ' . $schedule->id . ': User not found');
            return;
        }

        // The report contains site wide grading activity, so re-check that the owner of the
        // schedule is still allowed to see it. Otherwise disable the schedule.
        if (!has_capability('moodle/site:config', \context_system::instance(), $user)) {
            $DB->set_field('quiz_aigrader_schedules', 'enabled', 0, ['id' => $schedule->id]);
            mtrace('Schedule ' . $schedule->id . ': User no longer has moodle/site:config, schedule disabled');
            return;
        }

        $now = time();

        switch ($schedule->frequency) {
            case 'daily':
                $startdate = strtotime('-1 day', $now);
                $periodkey = 'daily';
                break;
            case 'monthly':
                $startdate = strtotime('-1 month', $now);
                $periodkey = 'monthly';
                break;
            case 'weekly':
            default:
                $startdate = strtotime('-1 week', $now);
                $periodkey = 'weekly';
                break;
        }

        $data = service::get_grader_activity($startdate, $now);

        if (empty($data)) {
            mtrace('Schedule ' . $schedule->id . ': No data for period');
            $this->reschedule($schedule, $now);
            return;
        }

        $totals = service::get_grader_totals($startdate, $now);

        // Build the message in the recipient's language.
        $userlang = empty($user->lang) ? current_language() : $user->lang;
        $oldforcelang = force_current_language($userlang);
        try {
            $dateformat = get_string('strftimedaydate', 'langconfig');

            $subjectdata = new \stdClass();
            $subjectdata->period = get_string($periodkey, 'quiz_aigrader');
            $subjectdata->date = userdate($now, $dateformat);
            $subject = get_string('report_email_subject', 'quiz_aigrader', $subjectdata);

            $bodydata = new \stdClass();
            $bodydata->period = $subjectdata->period;
            $bodydata->from = userdate($startdate, $dateformat);
            $bodydata->to = userdate($now, $dateformat);
            $bodydata->count = count($data);
            $messagetext = get_string('report_email_body', 'quiz_aigrader', $bodydata);

            if (!empty($totals)) {
                $messagetext .= "\n\n" . get_string('summary_by_grader', 'quiz_aigrader') . "\n";
                foreach ($totals as $total) {
                    $linedata = new \stdClass();
                    $linedata->name = $total['grader_name'];
                    $linedata->count = $total['total_approved'];
                    $messagetext .= get_string('report_email_summary_line', 'quiz_aigrader', $linedata) . "\n";
                }
            }
        } finally {
            force_current_language($oldforcelang);
        }

        [$attachment, $attachname, $fullpath] = $this->create_attachment($schedule, $data, $totals, $startdate, $now);

        $noreplyuser = \core_user::get_noreply_user();

        email_to_user($user, $noreplyuser, $subject, $messagetext, '', $attachment, $attachname);

        if (!empty($schedule->recipients)) {
            $ccemails = array_map('trim', explode(',', $schedule->recipients));
            foreach ($ccemails as $email) {
                if ($email === '') {
                    continue;
                }
                if (!validate_email($email)) {
                    mtrace('Schedule ' . $schedule->id . ': Skipping invalid CC address');
                    continue;
                }

                $ccuser = $this->make_cc_user($email);
                email_to_user($ccuser, $noreplyuser, $subject, $messagetext, '', $attachment, $attachname);
            }
        }

        if ($fullpath && file_exists($fullpath)) {
            unlink($fullpath);
        }

        $this->reschedule($schedule, $now);

        mtrace('Schedule ' . $schedule->id . ': Report sent to ' . $user->email);
    }

    /**
     * Record this run and calculate the next one.
     *
     * @param object $schedule Schedule record.
     * @param int $now Timestamp of this run.
     * @return void
     */
    protected function reschedule($schedule, $now) {
        global $DB;

        $DB->set_field('quiz_aigrader_schedules', 'lastrun', $now, ['id' => $schedule->id]);
        $DB->set_field(
            'quiz_aigrader_schedules',
            'nextrun',
            service::calculate_next_run($schedule->frequency, $now),
            ['id' => $schedule->id]
        );
    }

    /**
     * Write the report attachment into the Moodle temp directory.
     *
     * email_to_user() only accepts attachment paths relative to $CFG->dataroot, so the file
     * must live inside the Moodle temp directory rather than the system temp directory.
     *
     * @param object $schedule Schedule record.
     * @param array $data Report rows.
     * @param array $totals Per grader totals.
     * @param int $startdate Start of the reporting period.
     * @param int $enddate End of the reporting period.
     * @return array Array of [relative attachment path, attachment name, absolute path].
     */
    protected function create_attachment($schedule, $data, $totals, $startdate, $enddate) {
        global $CFG;

        $tempdir = make_temp_directory('quiz_aigrader');

        $format = isset($schedule->format) ? $schedule->format : 'csv';
        if ($format === 'excel') {
            // MoodleExcelWorkbook can only stream to the browser, so it cannot be used from a
            // scheduled task. Fall back to CSV, which spreadsheet applications also open.
            debugging(
                'quiz_aigrader: excel scheduled reports are not supported, sending CSV instead.',
                DEBUG_DEVELOPER
            );
            $format = 'csv';
        }

        $base = 'aigrader_report_' . date('Y-m-d', $enddate) . '_' . (int) $schedule->id . '_' . random_string(6);

        if ($format === 'pdf') {
            $attachname = $base . '.pdf';
            $fullpath = $tempdir . '/' . $attachname;
            $this->write_pdf($fullpath, $data, $totals, $startdate, $enddate);
        } else {
            $attachname = $base . '.csv';
            $fullpath = $tempdir . '/' . $attachname;
            file_put_contents($fullpath, service::generate_csv($data, $startdate, $enddate));
        }

        // The email_to_user() function expects a path relative to $CFG->dataroot.
        $relative = ltrim(str_replace('\\', '/', substr($fullpath, strlen($CFG->dataroot))), '/');

        return [$relative, $attachname, $fullpath];
    }

    /**
     * Write the report to a PDF file.
     *
     * @param string $fullpath Absolute path of the file to create.
     * @param array $data Report rows.
     * @param array $totals Per grader totals.
     * @param int $startdate Start of the reporting period.
     * @param int $enddate End of the reporting period.
     * @return void
     */
    protected function write_pdf($fullpath, $data, $totals, $startdate, $enddate) {
        global $CFG;

        require_once($CFG->libdir . '/pdflib.php');

        $daterange = new \stdClass();
        $daterange->from = userdate($startdate, get_string('strftimedaydate', 'langconfig'));
        $daterange->to = userdate($enddate, get_string('strftimedaydate', 'langconfig'));

        $pdf = new \pdf('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetTitle(get_string('grader_report', 'quiz_aigrader'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, get_string('grader_report', 'quiz_aigrader'), 0, 1);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, get_string('csv_daterange', 'quiz_aigrader', $daterange), 0, 1);
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(55, 8, get_string('csv_course', 'quiz_aigrader'), 1);
        $pdf->Cell(55, 8, get_string('csv_quiz', 'quiz_aigrader'), 1);
        $pdf->Cell(40, 8, get_string('csv_grader', 'quiz_aigrader'), 1);
        $pdf->Cell(25, 8, get_string('csv_approved', 'quiz_aigrader'), 1);
        $pdf->Cell(30, 8, get_string('csv_avgtime', 'quiz_aigrader'), 1);
        $pdf->Ln();

        $pdf->SetFont('helvetica', '', 10);
        foreach ($data as $record) {
            $pdf->Cell(55, 7, \core_text::substr($record['course_name'], 0, 26), 1);
            $pdf->Cell(55, 7, \core_text::substr($record['quiz_name'], 0, 26), 1);
            $pdf->Cell(40, 7, \core_text::substr($record['grader_name'], 0, 20), 1);
            $pdf->Cell(25, 7, $record['approved_count'], 1);
            $pdf->Cell(30, 7, $record['avg_per_question'] ?? '-', 1);
            $pdf->Ln();
        }

        if (!empty($totals)) {
            $pdf->Ln(10);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 8, get_string('summary_by_grader', 'quiz_aigrader'), 0, 1);

            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(90, 8, get_string('csv_grader', 'quiz_aigrader'), 1);
            $pdf->Cell(40, 8, get_string('total_approved', 'quiz_aigrader'), 1);
            $pdf->Ln();

            $pdf->SetFont('helvetica', '', 10);
            foreach ($totals as $total) {
                $pdf->Cell(90, 7, $total['grader_name'], 1);
                $pdf->Cell(40, 7, $total['total_approved'], 1);
                $pdf->Ln();
            }
        }

        $pdf->Output($fullpath, 'F');
    }

    /**
     * Build a pseudo user object suitable for email_to_user() from a plain email address.
     *
     * @param string $email A validated email address.
     * @return \stdClass User like object.
     */
    protected function make_cc_user($email) {
        $ccuser = clone \core_user::get_noreply_user();

        $ccuser->id = \core_user::NOREPLY_USER;
        $ccuser->email = $email;
        $ccuser->username = 'quiz_aigrader_ccuser';
        $ccuser->firstname = get_string('pluginname', 'quiz_aigrader');
        $ccuser->lastname = '';
        $ccuser->firstnamephonetic = '';
        $ccuser->lastnamephonetic = '';
        $ccuser->middlename = '';
        $ccuser->alternatename = '';
        $ccuser->maildisplay = 1;
        $ccuser->mailformat = 1;
        $ccuser->emailstop = 0;
        $ccuser->deleted = 0;
        $ccuser->suspended = 0;
        $ccuser->auth = 'manual';
        $ccuser->mnethostid = 1;
        if (empty($ccuser->lang)) {
            $ccuser->lang = current_language();
        }

        return $ccuser;
    }
}
