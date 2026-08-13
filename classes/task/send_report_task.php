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

defined('MOODLE_INTERNAL') || die();

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
     * @param object $schedule Schedule record
     */
    protected function process_schedule($schedule) {
        global $DB;

        $user = $DB->get_record('user', ['id' => $schedule->userid]);
        if (!$user) {
            mtrace('Schedule ' . $schedule->id . ': User not found');
            return;
        }

        $now = time();

        switch ($schedule->frequency) {
            case 'daily':
                $startdate = strtotime('-1 day', $now);
                $period = 'Daily';
                break;
            case 'weekly':
                $startdate = strtotime('-1 week', $now);
                $period = 'Weekly';
                break;
            case 'monthly':
                $startdate = strtotime('-1 month', $now);
                $period = 'Monthly';
                break;
            default:
                $startdate = strtotime('-1 week', $now);
                $period = 'Weekly';
        }

        $data = service::get_grader_activity($startdate, $now);

        if (empty($data)) {
            mtrace('Schedule ' . $schedule->id . ': No data for period');
            $DB->set_field('quiz_aigrader_schedules', 'lastrun', $now, ['id' => $schedule->id]);
            $DB->set_field('quiz_aigrader_schedules', 'nextrun', 
                service::calculate_next_run($schedule->frequency, $now), ['id' => $schedule->id]);
            return;
        }

        $csv = service::generate_csv($data, $startdate, $now);

        $subject = $period . ' AI Essay Grader Activity Report - ' . userdate($now, '%d %B %Y');
        $messagetext = "Please find attached your {$period} AI Essay Grader activity report.\n\n";
        $messagetext .= "Period: " . userdate($startdate, '%d %B %Y') . " - " . userdate($now, '%d %B %Y') . "\n";
        $messagetext .= "Total records: " . count($data) . "\n\n";
        $messagetext .= "This is an automated message from AI Essay Grader.";

        $totals = service::get_grader_totals($startdate, $now);
        if (!empty($totals)) {
            $messagetext .= "\n\nSummary by Grader:\n";
            foreach ($totals as $total) {
                $messagetext .= "- {$total['grader_name']}: {$total['total_approved']} questions approved\n";
            }
        }

        $tempfile = tempnam(sys_get_temp_dir(), 'aigrader_report_');
        file_put_contents($tempfile, $csv);

        $filename = 'aigrader_report_' . date('Y-m-d', $now) . '.csv';

        $attachment = $tempfile;
        $attachname = $filename;

        $noreplyuser = \core_user::get_noreply_user();

        email_to_user($user, $noreplyuser, $subject, $messagetext, '', $attachment, $attachname);

        if (!empty($schedule->recipients)) {
            $ccemails = array_map('trim', explode(',', $schedule->recipients));
            foreach ($ccemails as $email) {
                if (validate_email($email)) {
                    $ccuser = new \stdClass();
                    $ccuser->email = $email;
                    $ccuser->firstname = '';
                    $ccuser->lastname = '';
                    $ccuser->maildisplay = true;
                    $ccuser->mailformat = 1;
                    $ccuser->id = -99;
                    $ccuser->firstnamephonetic = '';
                    $ccuser->lastnamephonetic = '';
                    $ccuser->middlename = '';
                    $ccuser->alternatename = '';

                    email_to_user($ccuser, $noreplyuser, $subject, $messagetext, '', $attachment, $attachname);
                }
            }
        }

        @unlink($tempfile);

        $DB->set_field('quiz_aigrader_schedules', 'lastrun', $now, ['id' => $schedule->id]);
        $DB->set_field('quiz_aigrader_schedules', 'nextrun', 
            service::calculate_next_run($schedule->frequency, $now), ['id' => $schedule->id]);

        mtrace('Schedule ' . $schedule->id . ': Report sent to ' . $user->email);
    }
}
