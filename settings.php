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
 * The settings page is created and added to the admin tree here, then $settings is set to null
 * so that Moodle's post-include add is skipped and the page is not added twice. The admin tree
 * holds its own reference to the page object, so clearing the local variable is safe.
 *
 * The section id must be 'quiz_aigrader' to match get_settings_section_name(), because
 * /admin/settings.php?section=quiz_aigrader locates the page by that name.
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

$settingspage = new admin_settingpage(
    'quiz_aigrader',
    get_string('pluginname', 'quiz_aigrader')
);

if ($ADMIN->locate('modsettingsquizcat')) {
    $ADMIN->add('modsettingsquizcat', $settingspage);
} else if ($ADMIN->locate('modsettings')) {
    $ADMIN->add('modsettings', $settingspage);
} else {
    $ADMIN->add('root', $settingspage);
}

if ($ADMIN->fulltree) {
    $centralconfiginstalled = file_exists($CFG->dirroot . '/local/aiconfig/version.php');

    if ($centralconfiginstalled) {
        $centralconfigurl = new moodle_url('/admin/settings.php', ['section' => 'local_aiconfig']);
        $noticedescription = get_string('centralconfig_detected', 'quiz_aigrader', $centralconfigurl->out());
    } else {
        $noticedescription = get_string('centralconfig_notdetected', 'quiz_aigrader');
    }

    $settingspage->add(new admin_setting_heading(
        'quiz_aigrader/centralconfig_notice',
        get_string('centralconfig', 'quiz_aigrader'),
        $noticedescription
    ));

    $reporturl = new moodle_url('/mod/quiz/report/aigrader/grader_report.php');
    $settingspage->add(new admin_setting_heading(
        'quiz_aigrader/reportlink',
        get_string('grader_report', 'quiz_aigrader'),
        html_writer::link($reporturl, get_string('view_grader_report', 'quiz_aigrader'))
    ));

    $fallbacknotice = $centralconfiginstalled ? ' ' . get_string('centralconfig_fallback', 'quiz_aigrader') : '';

    $settingspage->add(new admin_setting_configtext(
        'quiz_aigrader/apiurl',
        get_string('apiurl', 'quiz_aigrader'),
        get_string('apiurl_desc', 'quiz_aigrader'),
        'https://lms-labs.com',
        PARAM_URL
    ));

    $settingspage->add(new admin_setting_configtext(
        'quiz_aigrader/siteid',
        get_string('siteid', 'quiz_aigrader'),
        get_string('siteid_desc', 'quiz_aigrader') . $fallbacknotice,
        '',
        PARAM_TEXT
    ));

    $settingspage->add(new admin_setting_configpasswordunmask(
        'quiz_aigrader/apikey',
        get_string('apikey', 'quiz_aigrader'),
        get_string('apikey_desc', 'quiz_aigrader') . $fallbacknotice,
        '',
        // An API key is an opaque credential. Any param cleaning could silently alter or
        // truncate it, so it is stored exactly as issued.
        // phpcs:ignore moodle.Commenting.InlineComment.NotCapital
        PARAM_RAW, // pipeline-ignore: PARAM_RAW - opaque API credential.
        255
    ));

    $settingspage->add(new admin_setting_configcheckbox(
        'quiz_aigrader/enable_student_notifications',
        get_string('enable_student_notifications', 'quiz_aigrader'),
        get_string('enable_student_notifications_desc', 'quiz_aigrader'),
        1
    ));

    $settingspage->add(new admin_setting_configtext(
        'quiz_aigrader/min_review_time',
        get_string('min_review_time', 'quiz_aigrader'),
        get_string('min_review_time_desc', 'quiz_aigrader'),
        '0',
        PARAM_INT
    ));
}

$settings = null;
