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
 * Settings for AI Grader quiz report plugin.
 *
 * v3.7.7 BULLETPROOF settings registration:
 * Moodle's base::load_settings() behaviour varies across versions:
 *   - Older Moodle 4.0-4.2: $settings = null before include, no post-include add.
 *   - Newer Moodle 4.3+: $settings = pre-created admin_settingpage, post-include
 *     $adminroot->add($parentnodename, $settings) — but $parentnodename for quiz
 *     report subplugins may reference a node that doesn't exist, failing silently.
 *
 * Fix: Always create the page ourselves, always add to tree with robust fallback,
 * then null $settings so Moodle's post-include add is skipped (if ($settings) → false).
 * The admin tree holds its own reference to the page object, so nulling the local
 * variable does not remove the page or its settings from the tree.
 *
 * Section ID MUST be 'quiz_aigrader' to match get_settings_section_name()
 * (format: type_name). The URL /admin/settings.php?section=quiz_aigrader calls
 * $adminroot->locate('quiz_aigrader') to find this page.
 *
 * @package    quiz_aigrader
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('reports', new admin_externalpage(
        'quiz_aigrader_activity_report',
        get_string('grader_report', 'quiz_aigrader'),
        new moodle_url('/mod/quiz/report/aigrader/grader_report.php'),
        'moodle/site:config'
    ));
}

$aigrader_page = new admin_settingpage(
    'quiz_aigrader',
    get_string('pluginname', 'quiz_aigrader')
);

if ($ADMIN->locate('modsettingsquizcat')) {
    $ADMIN->add('modsettingsquizcat', $aigrader_page);
} else if ($ADMIN->locate('modsettings')) {
    $ADMIN->add('modsettings', $aigrader_page);
} else {
    $ADMIN->add('root', $aigrader_page);
}

if ($ADMIN->fulltree) {
    $centralconfigurl = new moodle_url('/admin/settings.php', ['section' => 'local_aiconfig']);
    $centralconfiginstalled = file_exists($CFG->dirroot . '/local/aiconfig/version.php');
    
    if ($centralconfiginstalled) {
        $aigrader_page->add(new admin_setting_heading(
            'quiz_aigrader/centralconfig_notice',
            get_string('pluginname', 'quiz_aigrader'),
            '<div style="padding: 12px; background: #ecfdf5; border: 1px solid #10b981; border-radius: 8px; margin-bottom: 16px;">' .
            '<strong style="color: #047857;">AI Grader Central Config is installed.</strong><br>' .
            'Site ID and API Key are managed centrally. ' .
            '<a href="' . $centralconfigurl->out() . '">Configure Central Settings</a>' .
            '</div>'
        ));
    } else {
        $aigrader_page->add(new admin_setting_heading(
            'quiz_aigrader/centralconfig_notice',
            get_string('pluginname', 'quiz_aigrader'),
            '<div style="padding: 12px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; margin-bottom: 16px;">' .
            '<strong style="color: #b45309;">Recommended: Install AI Grader Central Config</strong><br>' .
            'Configure Site ID and API Key once for all AI Grader plugins.' .
            '</div>'
        ));
    }

    $reporturl = new moodle_url('/mod/quiz/report/aigrader/grader_report.php');
    $aigrader_page->add(new admin_setting_heading(
        'quiz_aigrader/reportlink',
        get_string('grader_report', 'quiz_aigrader'),
        html_writer::link($reporturl, get_string('view_grader_report', 'quiz_aigrader'), ['class' => 'btn btn-primary'])
    ));

    $aigrader_page->add(new admin_setting_configtext(
        'quiz_aigrader/siteid',
        get_string('siteid', 'quiz_aigrader'),
        get_string('siteid_desc', 'quiz_aigrader') . ($centralconfiginstalled ? ' (Fallback - Central Config takes priority)' : ''),
        '',
        PARAM_TEXT
    ));

    $aigrader_page->add(new admin_setting_configpasswordunmask(
        'quiz_aigrader/apikey',
        get_string('apikey', 'quiz_aigrader'),
        get_string('apikey_desc', 'quiz_aigrader') . ($centralconfiginstalled ? ' (Fallback - Central Config takes priority)' : ''),
        '',
        PARAM_RAW,
        255
    ));

    $aigrader_page->add(new admin_setting_configcheckbox(
        'quiz_aigrader/enable_student_notifications',
        get_string('enable_student_notifications', 'quiz_aigrader'),
        get_string('enable_student_notifications_desc', 'quiz_aigrader'),
        1
    ));

    // v3.9.7: hides ungraded work from suspended / expired / unenrolled students.
    // Read by both this plugin and block_aigrader_dashboard so one switch governs
    // the whole suite. Default 1 — historical data is hidden out of the box.
    $aigrader_page->add(new admin_setting_configcheckbox(
        'quiz_aigrader/hide_inactive_students',
        get_string('hide_inactive_students', 'quiz_aigrader'),
        get_string('hide_inactive_students_desc', 'quiz_aigrader'),
        1
    ));

    $aigrader_page->add(new admin_setting_configtext(
        'quiz_aigrader/min_review_time',
        get_string('min_review_time', 'quiz_aigrader'),
        get_string('min_review_time_desc', 'quiz_aigrader'),
        '0',
        PARAM_INT
    ));
}

$settings = null;
