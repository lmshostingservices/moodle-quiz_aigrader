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
 * Settings for AI Essay Grader quiz report plugin.
 *
 * The settings page is created and added to the admin tree here, then $settings is set to null
 * so that Moodle's post-include add is skipped and the page is not added twice. The admin tree
 * holds its own reference to the page object, so clearing the local variable is safe.
 *
 * The section id must be 'quiz_aigrader' to match get_settings_section_name(), because
 * /admin/settings.php?section=quiz_aigrader locates the page by that name.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
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

    $settingspage->add(new \quiz_aigrader\admin\setting_serviceurl(
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

    // Stored encrypted with the site's key, and never written back into the page.
    $settingspage->add(new admin_setting_encryptedpassword(
        'quiz_aigrader/apikey',
        get_string('apikey', 'quiz_aigrader'),
        get_string('apikey_desc', 'quiz_aigrader') . $fallbacknotice
    ));

    $settingspage->add(new admin_setting_configcheckbox(
        'quiz_aigrader/enable_student_notifications',
        get_string('enable_student_notifications', 'quiz_aigrader'),
        get_string('enable_student_notifications_desc', 'quiz_aigrader'),
        1
    ));

    // What a learner who scored full marks is shown. In competency-based assessment the
    // judgement is binary: a learner who met every criterion is competent, and telling
    // them what to improve or what to try next time contradicts that. Sites that prefer
    // the older behaviour, or that want everything delivered regardless, can say so here.
    $settingspage->add(new admin_setting_configselect(
        'quiz_aigrader/fullmarks_feedback',
        get_string('fullmarks_feedback', 'quiz_aigrader'),
        get_string('fullmarks_feedback_desc', 'quiz_aigrader'),
        'strengths',
        [
            'strengths' => get_string('fullmarks_strengths', 'quiz_aigrader'),
            'nosuggestions' => get_string('fullmarks_nosuggestions', 'quiz_aigrader'),
            'all' => get_string('fullmarks_all', 'quiz_aigrader'),
        ]
    ));

    // A picker, not a free-text field. The value gates every approval on the site, and a
    // mistyped 3600 would lock an assessor out of their own marking for an hour.
    $settingspage->add(new admin_setting_configselect(
        'quiz_aigrader/min_review_time',
        get_string('min_review_time', 'quiz_aigrader'),
        get_string('min_review_time_desc', 'quiz_aigrader'),
        '0',
        [
            '0'  => get_string('min_review_off', 'quiz_aigrader'),
            '5'  => get_string('min_review_seconds', 'quiz_aigrader', 5),
            '10' => get_string('min_review_seconds', 'quiz_aigrader', 10),
            '30' => get_string('min_review_seconds', 'quiz_aigrader', 30),
            '60' => get_string('min_review_seconds', 'quiz_aigrader', 60),
        ]
    ));

    // Site default for the language feedback is written in. Set per quiz as before; this
    // is the value a quiz starts from.
    $settingspage->add(new admin_setting_configselect(
        'quiz_aigrader/default_feedback_language',
        get_string('default_feedback_language', 'quiz_aigrader'),
        get_string('default_feedback_language_desc', 'quiz_aigrader'),
        'learner',
        ['learner' => get_string('language_learner', 'quiz_aigrader')] + \quiz_aigrader\local\formatter::feedback_languages()
    ));

    // Whether assessors may upload marking guides at all. Some RTOs keep rubrics inside
    // the question and do not want documents leaving the site.
    $settingspage->add(new admin_setting_configcheckbox(
        'quiz_aigrader/allow_reference_documents',
        get_string('allow_reference_documents', 'quiz_aigrader'),
        get_string('allow_reference_documents_desc', 'quiz_aigrader'),
        1
    ));

    // Responsible AI.
    //
    // Transparency to learners and a site-wide pause, for the EU AI Act and good practice
    // anywhere. See \quiz_aigrader\local\transparency and docs/eu-ai-act.md.
    $settingspage->add(new admin_setting_heading(
        'quiz_aigrader/responsibleai_heading',
        get_string('responsibleai_heading', 'quiz_aigrader'),
        get_string('responsibleai_heading_desc', 'quiz_aigrader')
    ));

    $settingspage->add(new admin_setting_configcheckbox(
        'quiz_aigrader/learner_ai_notice',
        get_string('learner_ai_notice_setting', 'quiz_aigrader'),
        get_string('learner_ai_notice_setting_desc', 'quiz_aigrader'),
        1
    ));

    $settingspage->add(new admin_setting_configcheckbox(
        'quiz_aigrader/ai_paused',
        get_string('ai_paused', 'quiz_aigrader'),
        get_string('ai_paused_desc', 'quiz_aigrader'),
        0
    ));

    // Marking criteria.
    //
    // How many criteria a question should have, and what happens to a question that has
    // none. The range drives the advice shown to assessors and bounds any criteria the
    // grading service derives; it never trims or merges criteria an assessor wrote.
    $settingspage->add(new admin_setting_heading(
        'quiz_aigrader/criteria_heading_settings',
        get_string('criteria_settings_heading', 'quiz_aigrader'),
        get_string('criteria_settings_heading_desc', 'quiz_aigrader')
    ));

    // Only criteria a person confirmed drive the per-criterion breakdown. On by default:
    // reading criteria out of free text can never be certain for every way they are written.
    $settingspage->add(new admin_setting_configcheckbox(
        'quiz_aigrader/criteria_confirmation',
        get_string('criteria_confirmation', 'quiz_aigrader'),
        get_string('criteria_confirmation_desc', 'quiz_aigrader'),
        1
    ));

    $settingspage->add(new admin_setting_configselect(
        'quiz_aigrader/nocriteria_policy',
        get_string('nocriteria_policy', 'quiz_aigrader'),
        get_string('nocriteria_policy_desc', 'quiz_aigrader'),
        'infer',
        [
            'infer' => get_string('nocriteria_infer', 'quiz_aigrader'),
            'holistic' => get_string('nocriteria_holistic', 'quiz_aigrader'),
            'manual' => get_string('nocriteria_manual', 'quiz_aigrader'),
        ]
    ));

    $criteriacounts = array_combine(range(1, 10), range(1, 10));
    $settingspage->add(new admin_setting_configselect(
        'quiz_aigrader/criteria_min',
        get_string('criteria_min', 'quiz_aigrader'),
        get_string('criteria_min_desc', 'quiz_aigrader'),
        3,
        $criteriacounts
    ));
    $settingspage->add(new admin_setting_configselect(
        'quiz_aigrader/criteria_max',
        get_string('criteria_max', 'quiz_aigrader'),
        get_string('criteria_max_desc', 'quiz_aigrader'),
        5,
        $criteriacounts
    ));

    // Competency signals across repeated attempts.
    //
    // Both default to 0, meaning off. Releases up to 4.3.0 hard-coded these at three, four
    // and five attempts, numbers chosen by the plugin rather than by the RTO's assessment
    // strategy, and applied them whatever the quiz's own attempt limit was. An RTO that
    // wants them sets them to match the strategy it can show an auditor.
    $settingspage->add(new admin_setting_heading(
        'quiz_aigrader/competency_heading',
        get_string('competency_heading', 'quiz_aigrader'),
        get_string('competency_heading_desc', 'quiz_aigrader')
    ));

    $settingspage->add(new admin_setting_configtext(
        'quiz_aigrader/competency_attempt_threshold',
        get_string('competency_attempt_threshold', 'quiz_aigrader'),
        get_string('competency_attempt_threshold_desc', 'quiz_aigrader'),
        '0',
        PARAM_INT
    ));

    $settingspage->add(new admin_setting_configtext(
        'quiz_aigrader/humanreview_attempt_threshold',
        get_string('humanreview_attempt_threshold', 'quiz_aigrader'),
        get_string('humanreview_attempt_threshold_desc', 'quiz_aigrader'),
        '0',
        PARAM_INT
    ));
}

$settings = null;
