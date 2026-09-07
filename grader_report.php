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

$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

/**
 * Format a timestamp as a strict YYYY-MM-DD date in the viewing user's timezone.
 *
 * userdate() is not usable here: its '%Y-%m-%d' output drops the leading zero from
 * single-digit days, producing values such as "2026-01-1", which a date input rejects as
 * invalid and renders empty.
 *
 * @param int $timestamp The timestamp to format.
 * @return string The date as YYYY-MM-DD, or an empty string when the timestamp is unset.
 */
function quiz_aigrader_format_report_date($timestamp) {
    if (empty($timestamp)) {
        return '';
    }

    $date = new DateTime('now', core_date::get_user_timezone_object());
    $date->setTimestamp((int) $timestamp);

    return $date->format('Y-m-d');
}

/**
 * Convert a YYYY-MM-DD value from the filter form into a timestamp.
 *
 * Parsed in the viewing user's timezone rather than the server's, so the date shown in
 * the report header always matches the date typed into the form.
 *
 * @param string $value The submitted date, empty when the field was left blank.
 * @param bool $endofday Whether to return the last second of the day rather than the first.
 * @return int The timestamp, or 0 when the value was blank or not a valid date.
 */
function quiz_aigrader_parse_report_date($value, $endofday) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $matches)) {
        return 0;
    }

    [, $year, $month, $day] = $matches;
    if (!checkdate((int) $month, (int) $day, (int) $year)) {
        return 0;
    }

    if ($endofday) {
        return make_timestamp((int) $year, (int) $month, (int) $day, 23, 59, 59);
    }

    return make_timestamp((int) $year, (int) $month, (int) $day, 0, 0, 0);
}

// The filter form posts plain yyyy-mm-dd dates; convert them to the timestamps used
// everywhere else. This replaces the inline JavaScript that used to do the conversion.
$startdatetext = optional_param('startdatetext', '', PARAM_TEXT);
$enddatetext = optional_param('enddatetext', '', PARAM_TEXT);

// Track whether this request actually carried a choice. Only a real choice is saved:
// persisting a computed default would freeze the report on the range it happened to show
// the first time it was ever opened.
$chosenstart = quiz_aigrader_parse_report_date($startdatetext, false);
$chosenend = quiz_aigrader_parse_report_date($enddatetext, true);
if (!$chosenstart) {
    $chosenstart = optional_param('startdate', 0, PARAM_INT);
}
if (!$chosenend) {
    $chosenend = optional_param('enddate', 0, PARAM_INT);
}
$chosengrader = optional_param('graderid', -1, PARAM_INT);

// An inverted range is almost always a typo, so swap rather than return nothing.
if ($chosenstart > 0 && $chosenend > 0 && $chosenstart > $chosenend) {
    [$chosenstart, $chosenend] = [$chosenend, $chosenstart];
}

// The last range this user chose is remembered, so the report opens where they left it.
$savedstart = (int) get_user_preferences('quiz_aigrader_report_startdate', 0);
$savedend = (int) get_user_preferences('quiz_aigrader_report_enddate', 0);
$savedgrader = (int) get_user_preferences('quiz_aigrader_report_graderid', 0);

$startdate = $chosenstart ?: ($savedstart ?: strtotime('-2 weeks'));
$enddate = $chosenend ?: ($savedend ?: time());
$graderid = $chosengrader >= 0 ? $chosengrader : $savedgrader;

// The download branch runs before admin_externalpage_setup() so that nothing is sent to
// the browser before the file headers, so it must do its own access checks first.
require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);

// Remember the choice for next time, but only when the user actually made one, and never
// from a download URL: fetching an export should not rewrite the saved view. Saving is a
// state change, so it also requires a valid sesskey.
// confirm_sesskey() raises an exception on a missing key rather than returning false, so
// the key is read first and only validated when it is actually present. A first visit with
// no key is normal and must simply not save anything.
// phpcs:ignore moodle.Commenting.InlineComment.NotCapital
$submittedkey = optional_param('sesskey', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW - sesskey token.
$validsesskey = $submittedkey !== '' && confirm_sesskey($submittedkey);

if (empty($download) && $validsesskey) {
    if ($chosenstart) {
        set_user_preference('quiz_aigrader_report_startdate', $chosenstart);
    }
    if ($chosenend) {
        set_user_preference('quiz_aigrader_report_enddate', $chosenend);
    }
    if ($chosengrader >= 0) {
        set_user_preference('quiz_aigrader_report_graderid', $chosengrader);
    }
}

/**
 * Apply Moodle filters to a name and return it as plain text, for file exports.
 *
 * @param string $name Raw name from the database.
 * @param context $context Context to format in.
 * @return string Plain text name.
 */
function quiz_aigrader_plain_name($name, $context) {
    return html_entity_decode(format_string($name, true, ['context' => $context]), ENT_QUOTES, 'UTF-8');
}

/**
 * Make a value safe to place in a spreadsheet cell.
 *
 * Prefixes anything that a spreadsheet would treat as a formula with an apostrophe and
 * flattens line breaks so a single cell can never break the row structure.
 *
 * @param string $value The cell value.
 * @return string The neutralised value.
 */
function quiz_aigrader_cell_safe($value) {
    $value = (string) $value;
    if (preg_match('/^[=+\-@]/', $value)) {
        $value = "'" . $value;
    }
    return str_replace(["\r\n", "\r", "\n"], ' ', $value);
}

if (!empty($download)) {
    // Clean any output buffers to ensure a clean file download.
    while (ob_get_level()) {
        ob_end_clean();
    }

    $data = service::get_grader_activity($startdate, $enddate, $graderid);
    $totals = service::get_grader_totals($startdate, $enddate, $graderid);

    $secondssuffix = function ($seconds) {
        return get_string('seconds_suffix', 'quiz_aigrader', $seconds);
    };

    // Enrich data with avg_per_question for downloads.
    foreach ($data as &$drow) {
        $drow['avg_per_question'] = ($drow['approved_count'] > 0 && $drow['time_seconds'] > 0)
            ? $secondssuffix(round($drow['time_seconds'] / $drow['approved_count'], 1))
            : '-';
    }
    unset($drow);

    $daterange = get_string('daterange', 'quiz_aigrader', (object) [
        'from' => userdate($startdate, '%d %B %Y'),
        'to' => userdate($enddate, '%d %B %Y'),
    ]);

    $exportcolumns = [
        get_string('course'),
        get_string('quiz', 'quiz_aigrader'),
        get_string('grader', 'quiz_aigrader'),
        get_string('approved_count', 'quiz_aigrader'),
        get_string('avg_time_per_question', 'quiz_aigrader'),
    ];

    if ($download === 'csv') {
        $exportrows = [];
        foreach ($data as $record) {
            $exportrows[] = [
                quiz_aigrader_cell_safe(quiz_aigrader_plain_name($record['course_name'], $systemcontext)),
                quiz_aigrader_cell_safe(quiz_aigrader_plain_name($record['quiz_name'], $systemcontext)),
                quiz_aigrader_cell_safe(quiz_aigrader_plain_name($record['grader_name'], $systemcontext)),
                quiz_aigrader_cell_safe($record['approved_count']),
                quiz_aigrader_cell_safe($record['avg_per_question'] ?? '-'),
            ];
        }

        \core\dataformat::download_data(
            'aigrader_report_' . date('Y-m-d'),
            'csv',
            $exportcolumns,
            new ArrayIterator($exportrows)
        );
        exit;
    }

    if ($download === 'excel') {
        require_once($CFG->libdir . '/excellib.class.php');

        $filename = 'aigrader_report_' . date('Y-m-d') . '.xlsx';
        $workbook = new MoodleExcelWorkbook("-");
        $workbook->send($filename);

        $sheet = $workbook->add_worksheet(get_string('grader_report', 'quiz_aigrader'));

        $sheet->write_string(0, 0, get_string('grader_report', 'quiz_aigrader'));
        $sheet->write_string(1, 0, $daterange);

        $col = 0;
        foreach ($exportcolumns as $header) {
            $sheet->write_string(3, $col, $header);
            $col++;
        }

        $row = 4;
        foreach ($data as $record) {
            $sheet->write_string(
                $row,
                0,
                quiz_aigrader_cell_safe(quiz_aigrader_plain_name($record['course_name'], $systemcontext))
            );
            $sheet->write_string(
                $row,
                1,
                quiz_aigrader_cell_safe(quiz_aigrader_plain_name($record['quiz_name'], $systemcontext))
            );
            $sheet->write_string(
                $row,
                2,
                quiz_aigrader_cell_safe(quiz_aigrader_plain_name($record['grader_name'], $systemcontext))
            );
            $sheet->write_number($row, 3, $record['approved_count']);
            $sheet->write_string($row, 4, quiz_aigrader_cell_safe($record['avg_per_question'] ?? '-'));
            $row++;
        }

        $row += 2;
        $sheet->write_string($row, 0, get_string('summary_by_grader', 'quiz_aigrader'));
        $row++;
        $sheet->write_string($row, 0, get_string('grader', 'quiz_aigrader'));
        $sheet->write_string($row, 1, get_string('total_approved', 'quiz_aigrader'));
        $row++;

        foreach ($totals as $total) {
            $sheet->write_string(
                $row,
                0,
                quiz_aigrader_cell_safe(quiz_aigrader_plain_name($total['grader_name'], $systemcontext))
            );
            $sheet->write_number($row, 1, $total['total_approved']);
            $row++;
        }

        $workbook->close();
        exit;
    }

    if ($download === 'pdf') {
        require_once($CFG->libdir . '/pdflib.php');

        $pdf = new pdf('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator(get_string('pluginname', 'quiz_aigrader'));
        $pdf->SetAuthor(get_string('pluginname', 'quiz_aigrader'));
        $pdf->SetTitle(get_string('grader_report', 'quiz_aigrader'));

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, get_string('grader_report', 'quiz_aigrader'), 0, 1);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $daterange, 0, 1);
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(55, 8, get_string('course'), 1);
        $pdf->Cell(55, 8, get_string('quiz', 'quiz_aigrader'), 1);
        $pdf->Cell(40, 8, get_string('grader', 'quiz_aigrader'), 1);
        $pdf->Cell(25, 8, get_string('approved_count', 'quiz_aigrader'), 1);
        $pdf->Cell(30, 8, get_string('avg_time_per_question', 'quiz_aigrader'), 1);
        $pdf->Ln();

        $pdf->SetFont('helvetica', '', 10);
        foreach ($data as $record) {
            $coursename = quiz_aigrader_plain_name($record['course_name'], $systemcontext);
            $quizname = quiz_aigrader_plain_name($record['quiz_name'], $systemcontext);
            $gradername = quiz_aigrader_plain_name($record['grader_name'], $systemcontext);
            $pdf->Cell(55, 7, core_text::substr($coursename, 0, 26), 1);
            $pdf->Cell(55, 7, core_text::substr($quizname, 0, 26), 1);
            $pdf->Cell(40, 7, core_text::substr($gradername, 0, 20), 1);
            $pdf->Cell(25, 7, $record['approved_count'], 1);
            $pdf->Cell(30, 7, $record['avg_per_question'] ?? '-', 1);
            $pdf->Ln();
        }

        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, get_string('summary_by_grader', 'quiz_aigrader'), 0, 1);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(90, 8, get_string('grader', 'quiz_aigrader'), 1);
        $pdf->Cell(40, 8, get_string('total_approved', 'quiz_aigrader'), 1);
        $pdf->Ln();

        $pdf->SetFont('helvetica', '', 10);
        foreach ($totals as $total) {
            $gradername = quiz_aigrader_plain_name($total['grader_name'], $systemcontext);
            $pdf->Cell(90, 7, core_text::substr($gradername, 0, 40), 1);
            $pdf->Cell(40, 7, $total['total_approved'], 1);
            $pdf->Ln();
        }

        $pdf->Output('aigrader_report_' . date('Y-m-d') . '.pdf', 'D');
        exit;
    }
}

// Standard admin external page setup for the HTML view. This registers the page against
// the admin tree entry declared in settings.php and handles login, capability, layout,
// title, heading and navigation for us.
admin_externalpage_setup('quiz_aigrader_activity_report', '', [
    'startdate' => $startdate,
    'enddate' => $enddate,
    'graderid' => $graderid,
]);

$baseurl = new moodle_url('/mod/quiz/report/aigrader/grader_report.php', [
    'startdate' => $startdate,
    'enddate' => $enddate,
    'graderid' => $graderid,
]);

if ($action === 'saveschedule') {
    require_sesskey();

    $frequency = required_param('frequency', PARAM_ALPHA);
    if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
        throw new moodle_exception('invalidfrequency', 'quiz_aigrader');
    }

    $rawrecipients = optional_param('recipients', '', PARAM_TEXT);
    $enabled = optional_param('enabled', 0, PARAM_INT);

    // Validate and sanitize email recipients to prevent header injection.
    $recipients = '';
    if (!empty($rawrecipients)) {
        $emails = array_map('trim', explode(',', $rawrecipients));
        $validemails = [];
        foreach ($emails as $email) {
            // Use Moodle's validate_email function for proper email validation.
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

    redirect(
        $baseurl,
        get_string('schedule_saved', 'quiz_aigrader'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Get data for page display (downloads are handled above, before any output).
$data = service::get_grader_activity($startdate, $enddate, $graderid);
$totals = service::get_grader_totals($startdate, $enddate, $graderid);

echo $OUTPUT->header();

echo html_writer::start_div('aigrader-report-container');

echo html_writer::tag('h2', get_string('grader_report', 'quiz_aigrader'), ['class' => 'aigrader-report-title']);
echo html_writer::tag('p', get_string('grader_report_desc', 'quiz_aigrader'), ['class' => 'aigrader-report-desc']);

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $baseurl->out_omit_querystring(),
    'class' => 'aigrader-filter-form',
]);

// The chosen range is saved as a user preference, which is a state change, so the form
// must carry a sesskey for that save to be accepted.
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('aigrader-filter-row');

echo html_writer::start_div('aigrader-filter-field');
echo html_writer::tag(
    'label',
    get_string('startdate', 'quiz_aigrader'),
    ['for' => 'startdate', 'class' => 'aigrader-filter-label']
);
echo html_writer::empty_tag('input', [
    'type' => 'date',
    'id' => 'startdate',
    'name' => 'startdatetext',
    'value' => quiz_aigrader_format_report_date($startdate),
    'class' => 'aigrader-filter-control',
]);
echo html_writer::end_div();

echo html_writer::start_div('aigrader-filter-field');
echo html_writer::tag(
    'label',
    get_string('enddate', 'quiz_aigrader'),
    ['for' => 'enddate', 'class' => 'aigrader-filter-label']
);
echo html_writer::empty_tag('input', [
    'type' => 'date',
    'id' => 'enddate',
    'name' => 'enddatetext',
    'value' => quiz_aigrader_format_report_date($enddate),
    'class' => 'aigrader-filter-control',
]);
echo html_writer::end_div();

$graders = service::get_graders();
$graderoptions = [0 => get_string('all_graders', 'quiz_aigrader')];
foreach ($graders as $grader) {
    $graderoptions[$grader->id] = format_string(fullname($grader), true, ['context' => $systemcontext]);
}

echo html_writer::start_div('aigrader-filter-field');
echo html_writer::tag(
    'label',
    get_string('grader', 'quiz_aigrader'),
    ['for' => 'graderid', 'class' => 'aigrader-filter-label']
);
echo html_writer::select($graderoptions, 'graderid', $graderid, false, [
    'id' => 'graderid',
    'class' => 'aigrader-filter-control aigrader-filter-select',
]);
echo html_writer::end_div();

echo html_writer::start_div('aigrader-filter-action');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('filter', 'quiz_aigrader'),
    'class' => 'aigrader-btn-submit',
]);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_tag('form');

echo html_writer::start_div('aigrader-download-row');

$csvurl = new moodle_url($baseurl, ['download' => 'csv']);
echo html_writer::link(
    $csvurl,
    get_string('download_csv', 'quiz_aigrader'),
    ['class' => 'btn aigrader-btn-download']
);

$pdfurl = new moodle_url($baseurl, ['download' => 'pdf']);
echo html_writer::link(
    $pdfurl,
    get_string('download_pdf', 'quiz_aigrader'),
    ['class' => 'btn aigrader-btn-download aigrader-btn-download-primary']
);

echo html_writer::end_div();

if (!empty($totals)) {
    echo html_writer::start_div('aigrader-stat-grid');

    $grandtotal = array_sum(array_column($totals, 'total_approved'));

    echo html_writer::start_div('aigrader-stat-tile');
    echo html_writer::tag(
        'div',
        get_string('total_approved', 'quiz_aigrader'),
        ['class' => 'aigrader-stat-tile-label']
    );
    echo html_writer::tag('div', $grandtotal, ['class' => 'aigrader-stat-tile-value aigrader-num-blue']);
    echo html_writer::end_div();

    echo html_writer::start_div('aigrader-stat-tile');
    echo html_writer::tag(
        'div',
        get_string('active_graders', 'quiz_aigrader'),
        ['class' => 'aigrader-stat-tile-label']
    );
    echo html_writer::tag('div', count($totals), ['class' => 'aigrader-stat-tile-value aigrader-num-dark']);
    echo html_writer::end_div();

    echo html_writer::start_div('aigrader-stat-tile');
    echo html_writer::tag(
        'div',
        get_string('avg_per_grader', 'quiz_aigrader'),
        ['class' => 'aigrader-stat-tile-label']
    );
    echo html_writer::tag(
        'div',
        round($grandtotal / count($totals), 1),
        ['class' => 'aigrader-stat-tile-value aigrader-num-dark']
    );
    echo html_writer::end_div();

    // Total students graded stat card.
    $totalstudents = array_sum(array_column($data, 'students_graded'));
    echo html_writer::start_div('aigrader-stat-tile');
    echo html_writer::tag(
        'div',
        get_string('total_students', 'quiz_aigrader'),
        ['class' => 'aigrader-stat-tile-label']
    );
    echo html_writer::tag('div', $totalstudents, ['class' => 'aigrader-stat-tile-value aigrader-num-green']);
    echo html_writer::end_div();

    // Average time per question - uses the approved count from $data (same filter as the time data).
    $totaltimeseconds = array_sum(array_column($data, 'time_seconds'));
    $filteredapproved = array_sum(array_column($data, 'approved_count'));
    $overallavg = ($filteredapproved > 0 && $totaltimeseconds > 0)
        ? round($totaltimeseconds / $filteredapproved, 1)
        : 0;
    $overallavgformatted = $overallavg > 0
        ? get_string('seconds_suffix', 'quiz_aigrader', $overallavg)
        : '-';
    echo html_writer::start_div('aigrader-stat-tile');
    echo html_writer::tag(
        'div',
        get_string('avg_time_per_question', 'quiz_aigrader'),
        ['class' => 'aigrader-stat-tile-label']
    );
    echo html_writer::tag(
        'div',
        $overallavgformatted,
        ['class' => 'aigrader-stat-tile-value aigrader-num-amber']
    );
    echo html_writer::end_div();

    echo html_writer::end_div();
}

echo html_writer::tag(
    'h3',
    get_string('detailed_report', 'quiz_aigrader'),
    ['class' => 'aigrader-section-heading']
);

if (empty($data)) {
    echo html_writer::div(get_string('no_data_for_period', 'quiz_aigrader'), 'aigrader-empty-notice');
} else {
    echo html_writer::start_div('aigrader-table-card');
    echo html_writer::start_tag('table', ['class' => 'generaltable aigrader-report-table']);

    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr', ['class' => 'aigrader-report-headrow']);
    echo html_writer::tag('th', get_string('course'), ['class' => 'aigrader-report-th']);
    echo html_writer::tag('th', get_string('quiz', 'quiz_aigrader'), ['class' => 'aigrader-report-th']);
    echo html_writer::tag('th', get_string('grader', 'quiz_aigrader'), ['class' => 'aigrader-report-th']);
    echo html_writer::tag(
        'th',
        get_string('students_graded', 'quiz_aigrader'),
        ['class' => 'aigrader-report-th aigrader-report-th-right']
    );
    echo html_writer::tag(
        'th',
        get_string('approved_count', 'quiz_aigrader'),
        ['class' => 'aigrader-report-th aigrader-report-th-right']
    );
    echo html_writer::tag(
        'th',
        get_string('time_spent', 'quiz_aigrader'),
        ['class' => 'aigrader-report-th aigrader-report-th-right']
    );
    echo html_writer::tag(
        'th',
        get_string('avg_time_per_question', 'quiz_aigrader'),
        ['class' => 'aigrader-report-th aigrader-report-th-right']
    );
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');

    echo html_writer::start_tag('tbody');
    foreach ($data as $row) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag(
            'td',
            format_string($row['course_name'], true, ['context' => $systemcontext]),
            ['class' => 'aigrader-report-td']
        );
        echo html_writer::tag(
            'td',
            format_string($row['quiz_name'], true, ['context' => $systemcontext]),
            ['class' => 'aigrader-report-td']
        );
        echo html_writer::tag(
            'td',
            format_string($row['grader_name'], true, ['context' => $systemcontext]),
            ['class' => 'aigrader-report-td']
        );
        echo html_writer::tag(
            'td',
            $row['students_graded'],
            ['class' => 'aigrader-report-td aigrader-report-td-right aigrader-num-green']
        );
        echo html_writer::tag(
            'td',
            $row['approved_count'],
            ['class' => 'aigrader-report-td aigrader-report-td-right aigrader-num-blue']
        );
        echo html_writer::tag(
            'td',
            $row['time_formatted'],
            ['class' => 'aigrader-report-td aigrader-report-td-right aigrader-num-purple']
        );
        $avgperq = ($row['approved_count'] > 0 && $row['time_seconds'] > 0)
            ? round($row['time_seconds'] / $row['approved_count'], 1)
            : 0;
        $avgperqformatted = $avgperq > 0
            ? get_string('seconds_suffix', 'quiz_aigrader', $avgperq)
            : '-';
        echo html_writer::tag(
            'td',
            $avgperqformatted,
            ['class' => 'aigrader-report-td aigrader-report-td-right aigrader-num-amber']
        );
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('tbody');

    echo html_writer::end_tag('table');
    echo html_writer::end_div();
}

echo html_writer::tag(
    'h3',
    get_string('email_scheduler', 'quiz_aigrader'),
    ['class' => 'aigrader-section-heading aigrader-schedule-heading']
);

$schedule = $DB->get_record('quiz_aigrader_schedules', ['userid' => $USER->id]);

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'saveschedule']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('aigrader-panel');

echo html_writer::start_div('aigrader-form-grid');

echo html_writer::start_div('aigrader-form-field');
echo html_writer::tag(
    'label',
    get_string('frequency', 'quiz_aigrader'),
    ['for' => 'frequency', 'class' => 'aigrader-filter-label']
);
$freqoptions = [
    'daily' => get_string('daily', 'quiz_aigrader'),
    'weekly' => get_string('weekly', 'quiz_aigrader'),
    'monthly' => get_string('monthly', 'quiz_aigrader'),
];
echo html_writer::select($freqoptions, 'frequency', $schedule ? $schedule->frequency : 'weekly', false, [
    'id' => 'frequency',
    'class' => 'aigrader-filter-control aigrader-frequency-select',
]);
echo html_writer::end_div();

echo html_writer::start_div('aigrader-form-field');
echo html_writer::tag(
    'label',
    get_string('cc_recipients', 'quiz_aigrader'),
    ['for' => 'recipients', 'class' => 'aigrader-filter-label']
);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'recipients',
    'name' => 'recipients',
    'value' => $schedule ? $schedule->recipients : '',
    'placeholder' => get_string('cc_recipients_placeholder', 'quiz_aigrader'),
    'class' => 'aigrader-filter-control',
]);
echo html_writer::tag(
    'small',
    get_string('cc_recipients_help', 'quiz_aigrader'),
    ['class' => 'aigrader-help-text']
);
echo html_writer::end_div();

echo html_writer::start_div('aigrader-checkbox-field');
echo html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'name' => 'enabled',
    'id' => 'enabled',
    'value' => '1',
    'class' => 'aigrader-checkbox',
] + ($schedule && $schedule->enabled ? ['checked' => 'checked'] : []));
echo html_writer::tag(
    'label',
    get_string('enable_schedule', 'quiz_aigrader'),
    ['for' => 'enabled', 'class' => 'aigrader-checkbox-label']
);
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('aigrader-submit-row');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('save_schedule', 'quiz_aigrader'),
    'class' => 'aigrader-btn-submit',
]);

if ($schedule && $schedule->enabled && $schedule->nextrun) {
    echo html_writer::tag(
        'span',
        get_string('next_report', 'quiz_aigrader') . ': ' . userdate($schedule->nextrun, '%d %B %Y %H:%M'),
        ['class' => 'aigrader-next-run']
    );
}

echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_tag('form');

echo html_writer::end_div();

echo $OUTPUT->footer();
