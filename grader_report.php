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
 * AI Grader Activity Report - Shows grader approval statistics.
 *
 * @package    quiz_aigrader
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');

use quiz_aigrader\report\service;

require_login();
require_capability('moodle/site:config', context_system::instance());

$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

$startdate = optional_param('startdate', strtotime('-2 weeks'), PARAM_INT);
$enddate = optional_param('enddate', time(), PARAM_INT);
$graderid = optional_param('graderid', 0, PARAM_INT);

// Handle downloads BEFORE admin_externalpage_setup to prevent any output
if (!empty($download)) {
    // Clean any output buffers to ensure clean file download
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $data = service::get_grader_activity($startdate, $enddate, $graderid);
    $totals = service::get_grader_totals($startdate, $enddate, $graderid);
    
    // Enrich data with avg_per_question for downloads
    foreach ($data as &$drow) {
        $drow['avg_per_question'] = ($drow['approved_count'] > 0 && $drow['time_seconds'] > 0) ? round($drow['time_seconds'] / $drow['approved_count'], 1) . 's' : '-';
    }
    unset($drow);

    if ($download === 'csv') {
        $csv = service::generate_csv($data, $startdate, $enddate);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="aigrader_report_' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $csv;
        exit;
    }
    
    if ($download === 'excel') {
        require_once($CFG->libdir . '/excellib.class.php');
        
        $filename = 'aigrader_report_' . date('Y-m-d') . '.xlsx';
        $workbook = new MoodleExcelWorkbook("-");
        $workbook->send($filename);
        
        $sheet = $workbook->add_worksheet(get_string('grader_report', 'quiz_aigrader'));
        
        $sheet->write_string(0, 0, get_string('grader_report', 'quiz_aigrader'));
        $sheet->write_string(1, 0, 'Date Range: ' . userdate($startdate, '%d %B %Y') . ' - ' . userdate($enddate, '%d %B %Y'));
        
        $headers = [
            get_string('course'),
            get_string('quiz', 'quiz_aigrader'),
            get_string('grader', 'quiz_aigrader'),
            get_string('approved_count', 'quiz_aigrader'),
            'Avg Time / Question',
        ];
        
        $col = 0;
        foreach ($headers as $header) {
            $sheet->write_string(3, $col, $header);
            $col++;
        }
        
        $row = 4;
        foreach ($data as $record) {
            $sheet->write_string($row, 0, $record['course_name']);
            $sheet->write_string($row, 1, $record['quiz_name']);
            $sheet->write_string($row, 2, $record['grader_name']);
            $sheet->write_number($row, 3, $record['approved_count']);
            $sheet->write_string($row, 4, $record['avg_per_question'] ?? '-');
            $row++;
        }
        
        $row += 2;
        $sheet->write_string($row, 0, get_string('summary_by_grader', 'quiz_aigrader'));
        $row++;
        $sheet->write_string($row, 0, get_string('grader', 'quiz_aigrader'));
        $sheet->write_string($row, 1, get_string('total_approved', 'quiz_aigrader'));
        $row++;
        
        foreach ($totals as $total) {
            $sheet->write_string($row, 0, $total['grader_name']);
            $sheet->write_number($row, 1, $total['total_approved']);
            $row++;
        }
        
        $workbook->close();
        exit;
    }
    
    if ($download === 'pdf') {
        require_once($CFG->libdir . '/pdflib.php');
        
        $pdf = new pdf('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('AI Grader');
        $pdf->SetAuthor('AI Grader Report');
        $pdf->SetTitle(get_string('grader_report', 'quiz_aigrader'));
        
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        
        $pdf->AddPage();
        
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, get_string('grader_report', 'quiz_aigrader'), 0, 1);
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Date Range: ' . userdate($startdate, '%d %B %Y') . ' - ' . userdate($enddate, '%d %B %Y'), 0, 1);
        $pdf->Ln(5);
        
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(55, 8, 'Course', 1);
        $pdf->Cell(55, 8, 'Quiz', 1);
        $pdf->Cell(40, 8, 'Grader', 1);
        $pdf->Cell(25, 8, 'Approved', 1);
        $pdf->Cell(30, 8, 'Avg / Question', 1);
        $pdf->Ln();
        
        $pdf->SetFont('helvetica', '', 10);
        foreach ($data as $record) {
            $pdf->Cell(55, 7, substr($record['course_name'], 0, 26), 1);
            $pdf->Cell(55, 7, substr($record['quiz_name'], 0, 26), 1);
            $pdf->Cell(40, 7, substr($record['grader_name'], 0, 20), 1);
            $pdf->Cell(25, 7, $record['approved_count'], 1);
            $pdf->Cell(30, 7, $record['avg_per_question'] ?? '-', 1);
            $pdf->Ln();
        }
        
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, get_string('summary_by_grader', 'quiz_aigrader'), 0, 1);
        
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(90, 8, 'Grader', 1);
        $pdf->Cell(40, 8, 'Total Approved', 1);
        $pdf->Ln();
        
        $pdf->SetFont('helvetica', '', 10);
        foreach ($totals as $total) {
            $pdf->Cell(90, 7, $total['grader_name'], 1);
            $pdf->Cell(40, 7, $total['total_approved'], 1);
            $pdf->Ln();
        }
        
        $pdf->Output('aigrader_report_' . date('Y-m-d') . '.pdf', 'D');
        exit;
    }
}

// Set up page manually (quiz report plugins cannot use admin_externalpage_setup
// because their settings.php is only loaded when visiting quiz settings).
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/mod/quiz/report/aigrader/grader_report.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('grader_report', 'quiz_aigrader'));
$PAGE->set_heading(get_string('grader_report', 'quiz_aigrader'));

// Add navigation breadcrumb.
$PAGE->navbar->add(get_string('administration'));
$PAGE->navbar->add(get_string('reports'));
$PAGE->navbar->add(get_string('grader_report', 'quiz_aigrader'));

$baseurl = new moodle_url('/mod/quiz/report/aigrader/grader_report.php', [
    'startdate' => $startdate,
    'enddate' => $enddate,
    'graderid' => $graderid,
]);

if ($action === 'saveschedule') {
    require_sesskey();
    
    $frequency = required_param('frequency', PARAM_ALPHA);
    $rawrecipients = optional_param('recipients', '', PARAM_TEXT);
    $enabled = optional_param('enabled', 0, PARAM_INT);
    
    // Validate and sanitize email recipients to prevent header injection
    $recipients = '';
    if (!empty($rawrecipients)) {
        $emails = array_map('trim', explode(',', $rawrecipients));
        $validemails = [];
        foreach ($emails as $email) {
            // Use Moodle's validate_email function for proper email validation
            if (!empty($email) && validate_email($email)) {
                $validemails[] = clean_param($email, PARAM_EMAIL);
            }
        }
        $recipients = implode(', ', $validemails);
    }
    
    $now = time();
    
    $existing = $DB->get_record('quiz_aigrader_schedules', ['userid' => $USER->id]);
    
    if ($existing) {
        $existing->frequency = $frequency;
        $existing->recipients = $recipients;
        $existing->enabled = $enabled;
        $existing->timemodified = $now;
        $existing->nextrun = service::calculate_next_run($frequency, $now);
        $DB->update_record('quiz_aigrader_schedules', $existing);
    } else {
        $record = new stdClass();
        $record->userid = $USER->id;
        $record->frequency = $frequency;
        $record->recipients = $recipients;
        $record->enabled = $enabled;
        $record->format = 'excel';
        $record->nextrun = service::calculate_next_run($frequency, $now);
        $record->timecreated = $now;
        $record->timemodified = $now;
        $DB->insert_record('quiz_aigrader_schedules', $record);
    }
    
    redirect($baseurl, get_string('schedule_saved', 'quiz_aigrader'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Get data for page display (downloads handled above before admin_externalpage_setup)
$data = service::get_grader_activity($startdate, $enddate, $graderid);
$totals = service::get_grader_totals($startdate, $enddate, $graderid);

echo $OUTPUT->header();

echo html_writer::start_div('aigrader-report-container', ['style' => 'max-width: 1200px; margin: 0 auto; padding: 32px; background: #ffffff;']);

echo html_writer::tag('h2', get_string('grader_report', 'quiz_aigrader'), ['style' => 'margin-bottom: 8px; font-size: 28px; font-weight: 700; color: #111827;']);
echo html_writer::tag('p', get_string('grader_report_desc', 'quiz_aigrader'), ['style' => 'color: #6b7280; margin-bottom: 32px; font-size: 15px;']);

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out_omit_querystring(), 'class' => 'aigrader-filter-form']);

echo html_writer::start_div('', ['style' => 'display: flex; gap: 16px; flex-wrap: wrap; align-items: end; margin-bottom: 24px; padding: 24px; background: #ffffff; border-radius: 8px; border: 1px solid #e5e7eb;']);

echo html_writer::start_div('', ['style' => 'flex: 1; min-width: 200px;']);
echo html_writer::tag('label', get_string('startdate', 'quiz_aigrader'), ['for' => 'startdate', 'style' => 'display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px; color: #374151;']);
echo html_writer::empty_tag('input', [
    'type' => 'date',
    'id' => 'startdate',
    'name' => 'startdate_input',
    'value' => date('Y-m-d', $startdate),
    'style' => 'width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; color: #111827;',
    'onchange' => 'document.getElementById("startdate_hidden").value = Math.floor(new Date(this.value).getTime() / 1000);',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'startdate', 'id' => 'startdate_hidden', 'value' => $startdate]);
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'flex: 1; min-width: 200px;']);
echo html_writer::tag('label', get_string('enddate', 'quiz_aigrader'), ['for' => 'enddate', 'style' => 'display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px; color: #374151;']);
echo html_writer::empty_tag('input', [
    'type' => 'date',
    'id' => 'enddate',
    'name' => 'enddate_input',
    'value' => date('Y-m-d', $enddate),
    'style' => 'width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; color: #111827;',
    'onchange' => 'document.getElementById("enddate_hidden").value = Math.floor(new Date(this.value).getTime() / 1000);',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'enddate', 'id' => 'enddate_hidden', 'value' => $enddate]);
echo html_writer::end_div();

$graders = service::get_graders();
$graderoptions = [0 => get_string('all_graders', 'quiz_aigrader')];
foreach ($graders as $grader) {
    $graderoptions[$grader->id] = fullname($grader);
}

echo html_writer::start_div('', ['style' => 'flex: 1; min-width: 200px;']);
echo html_writer::tag('label', get_string('grader', 'quiz_aigrader'), ['for' => 'graderid', 'style' => 'display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px; color: #374151;']);
echo html_writer::select($graderoptions, 'graderid', $graderid, false, ['id' => 'graderid', 'style' => 'width: 100%; height: 42px; padding: 0 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; color: #111827; line-height: 42px; -webkit-appearance: none; -moz-appearance: none; appearance: none; background: #fff url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 12 12\'%3E%3Cpath fill=\'%236b7280\' d=\'M2 4l4 4 4-4\'/%3E%3C/svg%3E") no-repeat right 12px center;']);
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'flex: 0 0 auto;']);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('filter', 'quiz_aigrader'),
    'style' => 'padding: 10px 24px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 14px;',
]);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_tag('form');

echo html_writer::start_div('', ['style' => 'display: flex; gap: 12px; margin-bottom: 24px;']);

$csvurl = new moodle_url($baseurl, ['download' => 'csv']);
echo html_writer::link($csvurl, get_string('download_csv', 'quiz_aigrader'), [
    'class' => 'btn',
    'style' => 'padding: 10px 20px; background: #ffffff; color: #374151; border: 1px solid #e5e7eb; border-radius: 6px; text-decoration: none; font-weight: 500; transition: all 0.2s;',
]);

$pdfurl = new moodle_url($baseurl, ['download' => 'pdf']);
echo html_writer::link($pdfurl, get_string('download_pdf', 'quiz_aigrader'), [
    'class' => 'btn',
    'style' => 'padding: 10px 20px; background: #3b82f6; color: #ffffff; border: 1px solid #3b82f6; border-radius: 6px; text-decoration: none; font-weight: 500; transition: all 0.2s;',
]);

echo html_writer::end_div();

if (!empty($totals)) {
    echo html_writer::start_div('', ['style' => 'display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;']);
    
    $grandtotal = array_sum(array_column($totals, 'total_approved'));
    
    echo html_writer::start_div('', ['style' => 'background: #ffffff; border: 1px solid #e5e7eb; padding: 24px; border-radius: 8px;']);
    echo html_writer::tag('div', get_string('total_approved', 'quiz_aigrader'), ['style' => 'font-size: 13px; color: #6b7280; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;']);
    echo html_writer::tag('div', $grandtotal, ['style' => 'font-size: 36px; font-weight: 700; color: #3b82f6;']);
    echo html_writer::end_div();
    
    echo html_writer::start_div('', ['style' => 'background: #ffffff; border: 1px solid #e5e7eb; padding: 24px; border-radius: 8px;']);
    echo html_writer::tag('div', get_string('active_graders', 'quiz_aigrader'), ['style' => 'font-size: 13px; color: #6b7280; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;']);
    echo html_writer::tag('div', count($totals), ['style' => 'font-size: 36px; font-weight: 700; color: #111827;']);
    echo html_writer::end_div();
    
    echo html_writer::start_div('', ['style' => 'background: #ffffff; border: 1px solid #e5e7eb; padding: 24px; border-radius: 8px;']);
    echo html_writer::tag('div', get_string('avg_per_grader', 'quiz_aigrader'), ['style' => 'font-size: 13px; color: #6b7280; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;']);
    echo html_writer::tag('div', round($grandtotal / count($totals), 1), ['style' => 'font-size: 36px; font-weight: 700; color: #111827;']);
    echo html_writer::end_div();
    
    // Add total students graded stat card
    $totalstudents = array_sum(array_column($data, 'students_graded'));
    echo html_writer::start_div('', ['style' => 'background: #ffffff; border: 1px solid #e5e7eb; padding: 24px; border-radius: 8px;']);
    echo html_writer::tag('div', get_string('total_students', 'quiz_aigrader'), ['style' => 'font-size: 13px; color: #6b7280; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;']);
    echo html_writer::tag('div', $totalstudents, ['style' => 'font-size: 36px; font-weight: 700; color: #10b981;']);
    echo html_writer::end_div();
    
    // Avg time per question — use approved count from $data (same filter as time data)
    $totaltimeseconds = array_sum(array_column($data, 'time_seconds'));
    $filteredapproved = array_sum(array_column($data, 'approved_count'));
    $overallavg = ($filteredapproved > 0 && $totaltimeseconds > 0) ? round($totaltimeseconds / $filteredapproved, 1) : 0;
    $overallavgformatted = $overallavg > 0 ? $overallavg . 's' : '-';
    echo html_writer::start_div('', ['style' => 'background: #ffffff; border: 1px solid #e5e7eb; padding: 24px; border-radius: 8px;']);
    echo html_writer::tag('div', 'AVG TIME / QUESTION', ['style' => 'font-size: 13px; color: #6b7280; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;']);
    echo html_writer::tag('div', $overallavgformatted, ['style' => 'font-size: 36px; font-weight: 700; color: #f59e0b;']);
    echo html_writer::end_div();
    
    echo html_writer::end_div();
}

echo html_writer::tag('h3', get_string('detailed_report', 'quiz_aigrader'), ['style' => 'margin-bottom: 16px; font-size: 18px; font-weight: 600; color: #111827;']);

if (empty($data)) {
    echo html_writer::div(
        get_string('no_data_for_period', 'quiz_aigrader'),
        '',
        ['style' => 'padding: 24px; border-radius: 8px; background: #f9fafb; border: 1px solid #e5e7eb; color: #6b7280; text-align: center;']
    );
} else {
    echo html_writer::start_div('', ['style' => 'background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;']);
    echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'width: 100%; border-collapse: collapse;']);
    
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr', ['style' => 'background: #f9fafb;']);
    echo html_writer::tag('th', get_string('course'), ['style' => 'padding: 14px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; font-weight: 600; font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;']);
    echo html_writer::tag('th', get_string('quiz', 'quiz_aigrader'), ['style' => 'padding: 14px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; font-weight: 600; font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;']);
    echo html_writer::tag('th', get_string('grader', 'quiz_aigrader'), ['style' => 'padding: 14px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; font-weight: 600; font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;']);
    echo html_writer::tag('th', get_string('students_graded', 'quiz_aigrader'), ['style' => 'padding: 14px 16px; text-align: right; border-bottom: 1px solid #e5e7eb; font-weight: 600; font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;']);
    echo html_writer::tag('th', get_string('approved_count', 'quiz_aigrader'), ['style' => 'padding: 14px 16px; text-align: right; border-bottom: 1px solid #e5e7eb; font-weight: 600; font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;']);
    echo html_writer::tag('th', get_string('time_spent', 'quiz_aigrader'), ['style' => 'padding: 14px 16px; text-align: right; border-bottom: 1px solid #e5e7eb; font-weight: 600; font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;']);
    echo html_writer::tag('th', 'AVG TIME / QUESTION', ['style' => 'padding: 14px 16px; text-align: right; border-bottom: 1px solid #e5e7eb; font-weight: 600; font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    
    echo html_writer::start_tag('tbody');
    foreach ($data as $row) {
        echo html_writer::start_tag('tr', ['style' => 'transition: background 0.2s;']);
        echo html_writer::tag('td', $row['course_name'], ['style' => 'padding: 14px 16px; border-bottom: 1px solid #f3f4f6; color: #111827; font-size: 14px;']);
        echo html_writer::tag('td', $row['quiz_name'], ['style' => 'padding: 14px 16px; border-bottom: 1px solid #f3f4f6; color: #111827; font-size: 14px;']);
        echo html_writer::tag('td', $row['grader_name'], ['style' => 'padding: 14px 16px; border-bottom: 1px solid #f3f4f6; color: #111827; font-size: 14px;']);
        echo html_writer::tag('td', $row['students_graded'], ['style' => 'padding: 14px 16px; text-align: right; border-bottom: 1px solid #f3f4f6; font-weight: 600; color: #10b981; font-size: 14px;']);
        echo html_writer::tag('td', $row['approved_count'], ['style' => 'padding: 14px 16px; text-align: right; border-bottom: 1px solid #f3f4f6; font-weight: 600; color: #3b82f6; font-size: 14px;']);
        echo html_writer::tag('td', $row['time_formatted'], ['style' => 'padding: 14px 16px; text-align: right; border-bottom: 1px solid #f3f4f6; font-weight: 600; color: #8b5cf6; font-size: 14px;']);
        $avgperq = ($row['approved_count'] > 0 && $row['time_seconds'] > 0) ? round($row['time_seconds'] / $row['approved_count'], 1) : 0;
        $avgperqformatted = $avgperq > 0 ? $avgperq . 's' : '-';
        echo html_writer::tag('td', $avgperqformatted, ['style' => 'padding: 14px 16px; text-align: right; border-bottom: 1px solid #f3f4f6; font-weight: 600; color: #f59e0b; font-size: 14px;']);
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('tbody');
    
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
}

echo html_writer::tag('h3', get_string('email_scheduler', 'quiz_aigrader'), ['style' => 'margin-top: 40px; margin-bottom: 16px; font-size: 18px; font-weight: 600; color: #111827;']);

$schedule = $DB->get_record('quiz_aigrader_schedules', ['userid' => $USER->id]);

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'saveschedule']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('', ['style' => 'padding: 24px; background: #ffffff; border-radius: 8px; border: 1px solid #e5e7eb;']);

echo html_writer::start_div('', ['style' => 'display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;']);

echo html_writer::start_div('');
echo html_writer::tag('label', get_string('frequency', 'quiz_aigrader'), ['for' => 'frequency', 'style' => 'display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px; color: #374151;']);
$freqoptions = [
    'daily' => get_string('daily', 'quiz_aigrader'),
    'weekly' => get_string('weekly', 'quiz_aigrader'),
    'monthly' => get_string('monthly', 'quiz_aigrader'),
];
echo html_writer::select($freqoptions, 'frequency', $schedule ? $schedule->frequency : 'weekly', false, [
    'id' => 'frequency',
    'style' => 'width: 100%; padding: 12px 16px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; color: #111827; height: auto; min-height: 44px; line-height: 1.4;',
]);
echo html_writer::end_div();

echo html_writer::start_div('');
echo html_writer::tag('label', get_string('cc_recipients', 'quiz_aigrader'), ['for' => 'recipients', 'style' => 'display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px; color: #374151;']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'recipients',
    'name' => 'recipients',
    'value' => $schedule ? $schedule->recipients : '',
    'placeholder' => 'finance@example.com, manager@example.com',
    'style' => 'width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; color: #111827;',
]);
echo html_writer::tag('small', get_string('cc_recipients_help', 'quiz_aigrader'), ['style' => 'color: #6b7280; display: block; margin-top: 6px; font-size: 13px;']);
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'display: flex; align-items: center; padding-top: 24px;']);
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'enabled', 'id' => 'enabled', 'value' => '1', 'style' => 'margin-right: 10px; width: 18px; height: 18px; cursor: pointer;'] + ($schedule && $schedule->enabled ? ['checked' => 'checked'] : []));
echo html_writer::tag('label', get_string('enable_schedule', 'quiz_aigrader'), ['for' => 'enabled', 'style' => 'font-size: 14px; color: #374151; cursor: pointer;']);
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'margin-top: 20px; display: flex; align-items: center; gap: 16px;']);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('save_schedule', 'quiz_aigrader'),
    'style' => 'padding: 10px 24px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 14px;',
]);

if ($schedule && $schedule->enabled && $schedule->nextrun) {
    echo html_writer::tag('span', 
        get_string('next_report', 'quiz_aigrader') . ': ' . userdate($schedule->nextrun, '%d %B %Y %H:%M'),
        ['style' => 'color: #6b7280; font-size: 14px;']
    );
}

echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_tag('form');

echo html_writer::end_div();

echo $OUTPUT->footer();
