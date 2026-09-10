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
 * AI Grader quiz report display class.
 *
 * @package    quiz_aigrader
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

// Moodle 4.2+ / Moodle 5 moved quiz_default_report to mod_quiz\local\reports\report_base.
// We create a class alias so the plugin works on both old and new Moodle versions.
if (class_exists('\mod_quiz\local\reports\report_base')) {
    // Moodle 4.2+ / Moodle 5: alias the new namespaced class.
    class_alias('\mod_quiz\local\reports\report_base', 'quiz_aigrader_report_base');
} else {
    // Moodle 4.0 - 4.1: load and alias the legacy class.
    require_once($CFG->dirroot . '/mod/quiz/report/default.php');
    class_alias('quiz_default_report', 'quiz_aigrader_report_base');
}

/**
 * Main AI Grader quiz report class.
 */
class quiz_aigrader_report extends quiz_aigrader_report_base {
    /**
     * Display the AI Grader report.
     *
     * @param stdClass $quiz The quiz record.
     * @param stdClass $cm The course module record.
     * @param stdClass $course The course record.
     * @return bool Always true.
     */
    public function display($quiz, $cm, $course) {
        global $DB, $PAGE, $CFG;

        $context = context_module::instance($cm->id);
        require_capability('mod/quiz:viewreports', $context);

        // Work out which group, if any, the essay list must be restricted to.
        $groupmode = groups_get_activity_groupmode($cm, $course);
        $groupid = 0;
        $forcegroupjoin = false;

        if ($groupmode) {
            // The groups_get_activity_group() call reads the 'group' parameter, remembers the choice
            // and already limits the value to the groups this user is allowed to see.
            $groupid = (int) groups_get_activity_group($cm, true);

            // Never trust the resolved id: make sure it really is a group of this course.
            if ($groupid > 0 && !$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $course->id])) {
                $groupid = 0;
            }

            if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
                // The user may only ever see one of their own groups, so the group join is mandatory.
                $forcegroupjoin = true;
                $allowedgroups = groups_get_activity_allowed_groups($cm);
                if ($groupid <= 0 || !isset($allowedgroups[$groupid])) {
                    $firstgroup = !empty($allowedgroups) ? reset($allowedgroups) : null;
                    $groupid = $firstgroup ? (int) $firstgroup->id : 0;
                }
            }
        }

        // Work out the submission date range the essay list must be restricted to.
        [$datefrom, $dateto] = $this->resolve_date_filter($cm);

        // The plugin's root styles.css is aggregated into the page by Moodle automatically,
        // so it must not be requested here as well.

        // Load JS. Note: config must be wrapped in an array to pass it as a single object.
        // Get the user's current Moodle language for multilingual AI feedback.
        $userlang = current_language();
        // Convert 'en_au' to 'en-AU'.
        $userlang = str_replace('_', '-', $userlang);

        $minreviewtime = (int) get_config('quiz_aigrader', 'min_review_time');

        $PAGE->requires->js_call_amd(
            'quiz_aigrader/aigrader',
            'init',
            [[
                'cmid'    => $cm->id,
                'quizid'  => $quiz->id,
                'sesskey' => sesskey(),
                'ajaxurl' => $CFG->wwwroot . '/mod/quiz/report/aigrader/ajax.php',
                'language' => $userlang,
                'minReviewTime' => $minreviewtime,
            ]]
        );

        // Use Moodle's standard quiz report header (includes tabs/navigation).
        $this->print_header_and_tabs($cm, $course, $quiz, 'aigrader');

        $this->render_container_start();
        $this->render_header($quiz, $context);
        $this->render_grading_stats();
        $this->render_document_section($quiz);
        $this->render_action_buttons();
        $this->render_filter_bar($cm, $groupmode, $datefrom, $dateto);
        $this->render_essay_table($quiz, $cm, $context, $groupid, $forcegroupjoin, $datefrom, $dateto);
        $this->render_footer();
        $this->render_container_end();
        $this->render_loading_overlay();

        // Do not call $OUTPUT->footer() - the quiz report framework handles this automatically.
        return true;
    }

    /**
     * Open the plugin's outer container element.
     *
     * @return void
     */
    private function render_container_start() {
        global $CFG;
        $ajaxurl = $CFG->wwwroot . '/mod/quiz/report/aigrader/ajax.php';
        echo html_writer::start_div('aigrader-container', [
            'id' => 'aigrader-root',
            'data-ajaxurl' => $ajaxurl,
        ]);
    }

    /**
     * Close the plugin's outer container element.
     *
     * @return void
     */
    private function render_container_end() {
        echo html_writer::end_div();
    }

    /**
     * Header with credits badge.
     *
     * @param stdClass $quiz The quiz record.
     * @param context $context The module context used to format the quiz name.
     * @return void
     */
    private function render_header($quiz, $context) {
        echo html_writer::start_div('aigrader-header ag-flex-between ag-flex-wrap ag-gap-lg');

        echo html_writer::start_div('aigrader-brand ag-flex-center ag-gap-lg');
        echo html_writer::start_div('aigrader-logo ag-flex-center');
        echo '<svg class="ag-icon-lg ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="28" height="28"'
            . ' fill="none" stroke="white" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9"/>'
            . '<path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>';
        echo html_writer::end_div();
        echo html_writer::tag('h1', get_string('pluginname', 'quiz_aigrader'), ['class' => 'aigrader-title']);
        echo html_writer::tag(
            'p',
            format_string($quiz->name, true, ['context' => $context]),
            ['class' => 'aigrader-subtitle']
        );
        echo html_writer::end_div();

        echo html_writer::start_div(
            'aigrader-credits ag-card ag-flex-center ag-gap-sm',
            ['id' => 'aigrader-credits-badge']
        );
        echo html_writer::start_div('aigrader-credits-icon ag-flex-center');
        echo '<svg class="ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none"'
            . ' stroke="white" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/>'
            . '<path d="M12 6v12"/><path d="M8 10h8a2 2 0 1 1 0 4H10a2 2 0 0 0 0 4h6"/></svg>';
        echo html_writer::end_div();
        echo html_writer::tag(
            'span',
            get_string('credits', 'quiz_aigrader') . ': ',
            ['class' => 'aigrader-credits-label']
        );
        echo html_writer::tag('span', '&hellip;', [
            'class' => 'aigrader-credits-value',
            'id' => 'aigrader-credits-count',
        ]);
        echo html_writer::end_div();

        echo html_writer::end_div();
    }

    /**
     * Reference documents section for AI grading context.
     *
     * @param stdClass $quiz The quiz record.
     * @return void
     */
    private function render_document_section($quiz) {
        echo html_writer::start_div('aigrader-documents', ['id' => 'aigrader-documents']);

        echo html_writer::start_div('aigrader-documents-header');
        echo html_writer::tag(
            'h3',
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor"'
            . ' stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
            . '<polyline points="14 2 14 8 20 8"/></svg> '
            . get_string('reference_documents', 'quiz_aigrader'),
            ['class' => 'aigrader-documents-title']
        );
        echo html_writer::tag(
            'button',
            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" stroke="currentColor"'
            . ' stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>',
            ['class' => 'aigrader-documents-toggle', 'id' => 'aigrader-documents-toggle', 'type' => 'button']
        );
        echo html_writer::end_div();

        echo html_writer::start_div('aigrader-documents-content', ['id' => 'aigrader-documents-content']);

        echo html_writer::tag(
            'p',
            get_string('reference_documents_help', 'quiz_aigrader'),
            ['class' => 'aigrader-documents-help']
        );

        // Upload form.
        echo html_writer::start_div('aigrader-upload-form');
        echo html_writer::empty_tag('input', [
            'type' => 'file',
            'id' => 'aigrader-doc-input',
            'accept' => '.pdf,.docx,.txt,application/pdf,'
                . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain',
            'class' => 'aigrader-file-input',
        ]);
        echo html_writer::tag(
            'label',
            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor"'
            . ' stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
            . '<polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> '
            . get_string('upload_document', 'quiz_aigrader'),
            ['for' => 'aigrader-doc-input', 'class' => 'aigrader-upload-btn']
        );
        echo html_writer::tag(
            'span',
            get_string('upload_formats', 'quiz_aigrader'),
            ['class' => 'aigrader-upload-hint']
        );
        echo html_writer::end_div();

        // Document list.
        echo html_writer::start_div('aigrader-documents-list', ['id' => 'aigrader-documents-list']);
        echo html_writer::tag(
            'div',
            get_string('loading', 'quiz_aigrader'),
            ['class' => 'aigrader-documents-loading']
        );
        echo html_writer::end_div();

        echo html_writer::end_div();

        echo html_writer::end_div();

        // Extra Instructions collapsible section.
        echo html_writer::start_div('aigrader-section aigrader-instructions-section');

        // Section header with toggle.
        echo html_writer::start_div('aigrader-section-header', ['id' => 'aigrader-instructions-toggle']);
        echo html_writer::tag(
            'span',
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor"'
            . ' stroke-width="2" viewBox="0 0 24 24">'
            . '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>'
            . '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> '
            . get_string('extra_instructions', 'quiz_aigrader'),
            ['class' => 'aigrader-section-title']
        );
        echo html_writer::tag('span', '', ['class' => 'aigrader-collapse-icon']);
        echo html_writer::end_div();

        // Section content (collapsible).
        echo html_writer::start_div('aigrader-section-content', ['id' => 'aigrader-instructions-content']);
        echo html_writer::tag(
            'p',
            get_string('extra_instructions_help', 'quiz_aigrader'),
            ['class' => 'aigrader-section-help']
        );

        // Feedback language dropdown with country flags.
        echo html_writer::start_div('aigrader-language-form');
        echo html_writer::tag(
            'label',
            get_string('feedback_language', 'quiz_aigrader'),
            ['for' => 'aigrader-feedback-language', 'class' => 'aigrader-language-label']
        );
        $languages = [
            'en' => ['flag' => "\xF0\x9F\x87\xAC\xF0\x9F\x87\xA7", 'name' => 'English'],
            'en-AU' => ['flag' => "\xF0\x9F\x87\xA6\xF0\x9F\x87\xBA", 'name' => 'English (Australian)'],
            'en-GB' => ['flag' => "\xF0\x9F\x87\xAC\xF0\x9F\x87\xA7", 'name' => 'English (British)'],
            'en-US' => ['flag' => "\xF0\x9F\x87\xBA\xF0\x9F\x87\xB8", 'name' => 'English (American)'],
            'en-NZ' => ['flag' => "\xF0\x9F\x87\xB3\xF0\x9F\x87\xBF", 'name' => 'English (New Zealand)'],
            'en-CA' => ['flag' => "\xF0\x9F\x87\xA8\xF0\x9F\x87\xA6", 'name' => 'English (Canadian)'],
            'en-IE' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xAA", 'name' => 'English (Irish)'],
            'en-ZA' => ['flag' => "\xF0\x9F\x87\xBF\xF0\x9F\x87\xA6", 'name' => 'English (South African)'],
            'es' => ['flag' => "\xF0\x9F\x87\xAA\xF0\x9F\x87\xB8", 'name' => 'Spanish (Espanol)'],
            'fr' => ['flag' => "\xF0\x9F\x87\xAB\xF0\x9F\x87\xB7", 'name' => 'French (Francais)'],
            'de' => ['flag' => "\xF0\x9F\x87\xA9\xF0\x9F\x87\xAA", 'name' => 'German (Deutsch)'],
            'it' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB9", 'name' => 'Italian (Italiano)'],
            'pt' => ['flag' => "\xF0\x9F\x87\xB5\xF0\x9F\x87\xB9", 'name' => 'Portuguese (Portugues)'],
            'nl' => ['flag' => "\xF0\x9F\x87\xB3\xF0\x9F\x87\xB1", 'name' => 'Dutch (Nederlands)'],
            'ru' => ['flag' => "\xF0\x9F\x87\xB7\xF0\x9F\x87\xBA", 'name' => 'Russian'],
            'zh' => ['flag' => "\xF0\x9F\x87\xA8\xF0\x9F\x87\xB3", 'name' => 'Chinese'],
            'ja' => ['flag' => "\xF0\x9F\x87\xAF\xF0\x9F\x87\xB5", 'name' => 'Japanese'],
            'ko' => ['flag' => "\xF0\x9F\x87\xB0\xF0\x9F\x87\xB7", 'name' => 'Korean'],
            'ar' => ['flag' => "\xF0\x9F\x87\xB8\xF0\x9F\x87\xA6", 'name' => 'Arabic'],
            'hi' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Hindi'],
            'bn' => ['flag' => "\xF0\x9F\x87\xA7\xF0\x9F\x87\xA9", 'name' => 'Bengali'],
            'pa' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Punjabi'],
            'te' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Telugu'],
            'ta' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Tamil'],
            'mr' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Marathi'],
            'gu' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Gujarati'],
            'kn' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Kannada'],
            'ml' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Malayalam'],
            'th' => ['flag' => "\xF0\x9F\x87\xB9\xF0\x9F\x87\xAD", 'name' => 'Thai'],
            'vi' => ['flag' => "\xF0\x9F\x87\xBB\xF0\x9F\x87\xB3", 'name' => 'Vietnamese'],
            'id' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xA9", 'name' => 'Indonesian (Bahasa Indonesia)'],
            'ms' => ['flag' => "\xF0\x9F\x87\xB2\xF0\x9F\x87\xBE", 'name' => 'Malay (Bahasa Melayu)'],
            'fil' => ['flag' => "\xF0\x9F\x87\xB5\xF0\x9F\x87\xAD", 'name' => 'Filipino (Tagalog)'],
            'tr' => ['flag' => "\xF0\x9F\x87\xB9\xF0\x9F\x87\xB7", 'name' => 'Turkish (Turkce)'],
            'pl' => ['flag' => "\xF0\x9F\x87\xB5\xF0\x9F\x87\xB1", 'name' => 'Polish (Polski)'],
            'uk' => ['flag' => "\xF0\x9F\x87\xBA\xF0\x9F\x87\xA6", 'name' => 'Ukrainian'],
            'cs' => ['flag' => "\xF0\x9F\x87\xA8\xF0\x9F\x87\xBF", 'name' => 'Czech (Cestina)'],
            'ro' => ['flag' => "\xF0\x9F\x87\xB7\xF0\x9F\x87\xB4", 'name' => 'Romanian (Romana)'],
            'hu' => ['flag' => "\xF0\x9F\x87\xAD\xF0\x9F\x87\xBA", 'name' => 'Hungarian (Magyar)'],
            'el' => ['flag' => "\xF0\x9F\x87\xAC\xF0\x9F\x87\xB7", 'name' => 'Greek'],
            'sv' => ['flag' => "\xF0\x9F\x87\xB8\xF0\x9F\x87\xAA", 'name' => 'Swedish (Svenska)'],
            'da' => ['flag' => "\xF0\x9F\x87\xA9\xF0\x9F\x87\xB0", 'name' => 'Danish (Dansk)'],
            'fi' => ['flag' => "\xF0\x9F\x87\xAB\xF0\x9F\x87\xAE", 'name' => 'Finnish (Suomi)'],
            'no' => ['flag' => "\xF0\x9F\x87\xB3\xF0\x9F\x87\xB4", 'name' => 'Norwegian (Norsk)'],
            'he' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB1", 'name' => 'Hebrew'],
            'fa' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB7", 'name' => 'Persian'],
            'ur' => ['flag' => "\xF0\x9F\x87\xB5\xF0\x9F\x87\xB0", 'name' => 'Urdu'],
            'sw' => ['flag' => "\xF0\x9F\x87\xB0\xF0\x9F\x87\xAA", 'name' => 'Swahili (Kiswahili)'],
            'zu' => ['flag' => "\xF0\x9F\x87\xBF\xF0\x9F\x87\xA6", 'name' => 'Zulu (isiZulu)'],
            'af' => ['flag' => "\xF0\x9F\x87\xBF\xF0\x9F\x87\xA6", 'name' => 'Afrikaans'],
            'am' => ['flag' => "\xF0\x9F\x87\xAA\xF0\x9F\x87\xB9", 'name' => 'Amharic'],
            'bg' => ['flag' => "\xF0\x9F\x87\xA7\xF0\x9F\x87\xAC", 'name' => 'Bulgarian'],
            'hr' => ['flag' => "\xF0\x9F\x87\xAD\xF0\x9F\x87\xB7", 'name' => 'Croatian (Hrvatski)'],
            'sk' => ['flag' => "\xF0\x9F\x87\xB8\xF0\x9F\x87\xB0", 'name' => 'Slovak (Slovencina)'],
            'sl' => ['flag' => "\xF0\x9F\x87\xB8\xF0\x9F\x87\xAE", 'name' => 'Slovenian (Slovenscina)'],
            'lt' => ['flag' => "\xF0\x9F\x87\xB1\xF0\x9F\x87\xB9", 'name' => 'Lithuanian (Lietuviu)'],
            'lv' => ['flag' => "\xF0\x9F\x87\xB1\xF0\x9F\x87\xBB", 'name' => 'Latvian (Latviesu)'],
            'et' => ['flag' => "\xF0\x9F\x87\xAA\xF0\x9F\x87\xAA", 'name' => 'Estonian (Eesti)'],
        ];
        echo html_writer::start_tag(
            'select',
            ['id' => 'aigrader-feedback-language', 'class' => 'aigrader-language-select']
        );
        foreach ($languages as $code => $lang) {
            echo html_writer::tag('option', $lang['flag'] . '  ' . $lang['name'], ['value' => $code]);
        }
        echo html_writer::end_tag('select');
        echo html_writer::end_div();

        // Instructions textarea.
        echo html_writer::start_div('aigrader-instructions-form');
        echo html_writer::tag('textarea', '', [
            'id' => 'aigrader-extra-instructions',
            'class' => 'aigrader-instructions-textarea',
            'placeholder' => get_string('extra_instructions_placeholder', 'quiz_aigrader'),
            'rows' => 4,
        ]);
        echo html_writer::start_div('aigrader-instructions-actions');
        echo html_writer::tag(
            'button',
            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" stroke="currentColor"'
            . ' stroke-width="2" viewBox="0 0 24 24">'
            . '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>'
            . '<polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> '
            . get_string('save_instructions', 'quiz_aigrader'),
            [
                'id' => 'aigrader-save-instructions',
                'type' => 'button',
                'class' => 'aigrader-btn aigrader-btn-secondary aigrader-btn-sm',
            ]
        );
        echo html_writer::tag(
            'span',
            '',
            ['id' => 'aigrader-instructions-status', 'class' => 'aigrader-instructions-status']
        );
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::end_div();

        echo html_writer::end_div();
    }

    /**
     * Grading time statistics box with filters.
     *
     * @return void
     */
    private function render_grading_stats() {
        echo html_writer::start_div('aigrader-stats-box', ['id' => 'aigrader-stats-box']);

        // Header with toggle.
        echo html_writer::start_div('aigrader-stats-header', ['id' => 'aigrader-stats-toggle']);
        echo html_writer::tag(
            'span',
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor"'
            . ' stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/>'
            . '<polyline points="12 6 12 12 16 14"/></svg> '
            . get_string('grading_time_stats', 'quiz_aigrader'),
            ['class' => 'aigrader-stats-title']
        );
        echo html_writer::tag('span', '', ['class' => 'aigrader-collapse-icon']);
        echo html_writer::end_div();

        // Content (collapsible).
        echo html_writer::start_div('aigrader-stats-content', ['id' => 'aigrader-stats-content']);

        // Filters row.
        echo html_writer::start_div('aigrader-stats-filters');

        // Course filter.
        echo html_writer::start_div('aigrader-filter-group');
        echo html_writer::tag(
            'label',
            get_string('filter_course', 'quiz_aigrader'),
            ['for' => 'aigrader-filter-course']
        );
        echo html_writer::tag(
            'select',
            '<option value="0">' . get_string('all_courses', 'quiz_aigrader') . '</option>',
            ['id' => 'aigrader-filter-course', 'class' => 'aigrader-filter-select']
        );
        echo html_writer::end_div();

        // Grader filter.
        echo html_writer::start_div('aigrader-filter-group');
        echo html_writer::tag(
            'label',
            get_string('filter_grader', 'quiz_aigrader'),
            ['for' => 'aigrader-filter-grader']
        );
        echo html_writer::tag(
            'select',
            '<option value="0">' . get_string('all_graders', 'quiz_aigrader') . '</option>',
            ['id' => 'aigrader-filter-grader', 'class' => 'aigrader-filter-select']
        );
        echo html_writer::end_div();

        // Date from filter.
        echo html_writer::start_div('aigrader-filter-group');
        echo html_writer::tag(
            'label',
            get_string('filter_date_from', 'quiz_aigrader'),
            ['for' => 'aigrader-filter-datefrom']
        );
        echo html_writer::empty_tag(
            'input',
            ['type' => 'date', 'id' => 'aigrader-filter-datefrom', 'class' => 'aigrader-filter-input']
        );
        echo html_writer::end_div();

        // Date to filter.
        echo html_writer::start_div('aigrader-filter-group');
        echo html_writer::tag(
            'label',
            get_string('filter_date_to', 'quiz_aigrader'),
            ['for' => 'aigrader-filter-dateto']
        );
        echo html_writer::empty_tag(
            'input',
            ['type' => 'date', 'id' => 'aigrader-filter-dateto', 'class' => 'aigrader-filter-input']
        );
        echo html_writer::end_div();

        // Apply button.
        echo html_writer::tag('button', get_string('apply_filters', 'quiz_aigrader'), [
            'id' => 'aigrader-stats-apply',
            'type' => 'button',
            'class' => 'aigrader-btn aigrader-btn-sm aigrader-btn-primary',
        ]);

        echo html_writer::end_div();

        // Stats summary cards.
        echo html_writer::start_div('aigrader-stats-summary');

        echo html_writer::start_div('aigrader-stat-card');
        echo html_writer::tag(
            'div',
            get_string('total_essays_graded', 'quiz_aigrader'),
            ['class' => 'aigrader-stat-label']
        );
        echo html_writer::tag('div', '&mdash;', ['id' => 'aigrader-stat-essays', 'class' => 'aigrader-stat-value']);
        echo html_writer::end_div();

        echo html_writer::start_div('aigrader-stat-card');
        echo html_writer::tag(
            'div',
            get_string('total_grading_time', 'quiz_aigrader'),
            ['class' => 'aigrader-stat-label']
        );
        echo html_writer::tag('div', '&mdash;', ['id' => 'aigrader-stat-time', 'class' => 'aigrader-stat-value']);
        echo html_writer::end_div();

        echo html_writer::start_div('aigrader-stat-card');
        echo html_writer::tag(
            'div',
            get_string('avg_time_per_essay', 'quiz_aigrader'),
            ['class' => 'aigrader-stat-label']
        );
        echo html_writer::tag('div', '&mdash;', ['id' => 'aigrader-stat-avg', 'class' => 'aigrader-stat-value']);
        echo html_writer::end_div();

        echo html_writer::start_div('aigrader-stat-card');
        echo html_writer::tag(
            'div',
            get_string('total_student_time', 'quiz_aigrader'),
            ['class' => 'aigrader-stat-label']
        );
        echo html_writer::tag(
            'div',
            '&mdash;',
            ['id' => 'aigrader-stat-student-time', 'class' => 'aigrader-stat-value']
        );
        echo html_writer::end_div();

        echo html_writer::end_div();

        // Grader breakdown table.
        echo html_writer::start_div('aigrader-stats-table-wrapper');
        echo html_writer::tag(
            'table',
            '<thead><tr><th>' . get_string('grader', 'quiz_aigrader') . '</th>'
            . '<th>' . get_string('essays', 'quiz_aigrader') . '</th>'
            . '<th>' . get_string('time_spent', 'quiz_aigrader') . '</th>'
            . '<th>' . get_string('avg_per_essay', 'quiz_aigrader') . '</th></tr></thead>'
            . '<tbody id="aigrader-stats-tbody"></tbody>',
            ['class' => 'aigrader-stats-table', 'id' => 'aigrader-stats-table']
        );
        echo html_writer::end_div();

        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    /**
     * Read the date filter values out of the current request.
     *
     * Kept separate from resolve_date_filter() so that the filter logic can be exercised
     * with plain values rather than request state.
     *
     * @return array The submitted filter values, keyed by parameter name.
     */
    private function read_filter_request(): array {
        return [
            'aigfilter' => optional_param('aigfilter', 0, PARAM_INT),
            'aigclearfilter' => optional_param('aigclearfilter', 0, PARAM_INT),
            'datefrom' => optional_param('datefrom', '', PARAM_TEXT),
            'dateto' => optional_param('dateto', '', PARAM_TEXT),
            // phpcs:ignore moodle.Commenting.InlineComment.NotCapital
            'sesskey' => optional_param('sesskey', '', PARAM_RAW), // pipeline-ignore: PARAM_RAW - sesskey token.
        ];
    }

    /**
     * Resolve the submission date range the essay list should be restricted to.
     *
     * The range is taken from the request when the filter form has been submitted, and
     * from the user's saved preference otherwise, so a marker's chosen view survives
     * navigating away and coming back. Submitting the form stores the new range; the
     * clear button removes it. Dates are entered as YYYY-MM-DD in the user's own
     * timezone and stored as timestamps covering whole days.
     *
     * The preference is held per course module, so a range chosen while marking one quiz
     * is never silently applied to a different one. Storing or clearing it changes user
     * state, so both paths require a valid sesskey; without one the request is treated as
     * read-only and the saved range is returned unchanged.
     *
     * @param stdClass $cm The course module record, used to scope the saved preference.
     * @param array|null $request The submitted filter values, read from the request when null.
     *      Supplying them explicitly keeps the logic testable without request state.
     * @return array Two elements: the from timestamp and the to timestamp, 0 when unset.
     */
    private function resolve_date_filter($cm, ?array $request = null) {
        if ($request === null) {
            $request = $this->read_filter_request();
        }

        $applied = (int) ($request['aigfilter'] ?? 0);
        $clear = (int) ($request['aigclearfilter'] ?? 0);
        $submittedkey = (string) ($request['sesskey'] ?? '');

        $frompref = 'quiz_aigrader_datefrom_' . $cm->id;
        $topref = 'quiz_aigrader_dateto_' . $cm->id;

        $saved = [
            (int) get_user_preferences($frompref, 0),
            (int) get_user_preferences($topref, 0),
        ];

        // Writing a preference is a state change, so never act on a request that could
        // have been made on the user's behalf by a third-party page. A missing key means
        // the request is treated as read-only: confirm_sesskey() is deliberately not asked
        // to validate a missing key, because it raises an exception rather than returning
        // false, and a forged link should quietly do nothing rather than show an error.
        if (($applied || $clear) && ($submittedkey === '' || !confirm_sesskey($submittedkey))) {
            return $saved;
        }

        if ($clear) {
            unset_user_preference($frompref);
            unset_user_preference($topref);
            return [0, 0];
        }

        if ($applied) {
            $fromtext = trim((string) ($request['datefrom'] ?? ''));
            $totext = trim((string) ($request['dateto'] ?? ''));

            // Discard anything malformed before comparing, so an invalid value in one field
            // can never displace the valid value in the other when the pair is swapped.
            if (!$this->is_filter_date($fromtext)) {
                $fromtext = '';
            }
            if (!$this->is_filter_date($totext)) {
                $totext = '';
            }

            // An inverted range is almost always a typo. Swap the dates before converting
            // them, so each still gets the right end of its day rather than a range that is
            // off by one day at both ends.
            if ($fromtext !== '' && $totext !== '' && strcmp($fromtext, $totext) > 0) {
                [$fromtext, $totext] = [$totext, $fromtext];
            }

            $datefrom = $this->parse_filter_date($fromtext, false);
            $dateto = $this->parse_filter_date($totext, true);

            set_user_preference($frompref, $datefrom);
            set_user_preference($topref, $dateto);

            return [$datefrom, $dateto];
        }

        return $saved;
    }

    /**
     * Whether a submitted value is a well formed YYYY-MM-DD calendar date.
     *
     * @param string $value The submitted value.
     * @return bool True when the value names a real date.
     */
    private function is_filter_date($value) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $matches)) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    /**
     * Convert a YYYY-MM-DD value from the filter form into a timestamp.
     *
     * @param string $value The submitted date, empty when the field was left blank.
     * @param bool $endofday Whether to return the last second of the day rather than the first.
     * @return int The timestamp, or 0 when the value was blank or not a valid date.
     */
    private function parse_filter_date($value, $endofday) {
        $value = trim($value);
        if (!$this->is_filter_date($value)) {
            return 0;
        }

        preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches);
        [, $year, $month, $day] = $matches;

        if ($endofday) {
            return make_timestamp((int) $year, (int) $month, (int) $day, 23, 59, 59);
        }

        return make_timestamp((int) $year, (int) $month, (int) $day, 0, 0, 0);
    }

    /**
     * Format a timestamp as a strict YYYY-MM-DD date in the viewing user's timezone.
     *
     * userdate() is not usable here: its '%Y-%m-%d' output drops the leading zero from
     * single-digit days, producing values such as "2026-01-1". A date input rejects that
     * as invalid and renders itself empty, which would show a marker a blank filter while
     * the filter was still being applied.
     *
     * @param int $timestamp The timestamp to format.
     * @return string The date as YYYY-MM-DD, or an empty string when the timestamp is unset.
     */
    private function format_filter_date($timestamp) {
        if (empty($timestamp)) {
            return '';
        }

        $date = new DateTime('now', core_date::get_user_timezone_object());
        $date->setTimestamp((int) $timestamp);

        return $date->format('Y-m-d');
    }

    /**
     * Filter bar holding the group menu and the submission date range.
     *
     * The group menu is Moodle's standard one, which only ever offers the groups the
     * current user is allowed to see and handles submitting the change itself. It is
     * omitted when the activity is not in a group mode.
     *
     * @param stdClass $cm The course module record.
     * @param int $groupmode One of NOGROUPS, SEPARATEGROUPS or VISIBLEGROUPS.
     * @param int $datefrom Start of the active date range, 0 when unset.
     * @param int $dateto End of the active date range, 0 when unset.
     * @return void
     */
    private function render_filter_bar($cm, $groupmode, $datefrom, $dateto) {
        global $PAGE;

        echo html_writer::start_div('aigrader-group-filter-bar');
        echo html_writer::start_div('aigrader-group-filter-inner ag-flex-center ag-flex-wrap ag-gap-md');

        if ($groupmode) {
            $groupurl = new moodle_url($PAGE->url);
            $groupurl->remove_params('group', 'aigfilter', 'aigclearfilter', 'datefrom', 'dateto');
            groups_print_activity_menu($cm, $groupurl->out(false));
        }

        $this->render_date_filter($datefrom, $dateto);

        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    /**
     * The submission date range form.
     *
     * @param int $datefrom Start of the active date range, 0 when unset.
     * @param int $dateto End of the active date range, 0 when unset.
     * @return void
     */
    private function render_date_filter($datefrom, $dateto) {
        global $PAGE;

        $formurl = new moodle_url($PAGE->url);
        $formurl->remove_params('aigfilter', 'aigclearfilter', 'datefrom', 'dateto');

        echo html_writer::start_tag('form', [
            'method' => 'get',
            'action' => $formurl->out_omit_querystring(),
            'class' => 'aigrader-date-filter',
            'aria-label' => get_string('filter_form_label', 'quiz_aigrader'),
        ]);

        $params = $formurl->params();

        // The report only resolves when it knows which report to show. $PAGE->url normally
        // carries it, but do not depend on that: emit it explicitly if it is missing.
        if (!isset($params['mode'])) {
            $params['mode'] = 'aigrader';
        }

        $params['aigfilter'] = 1;
        $params['sesskey'] = sesskey();

        foreach ($params as $name => $value) {
            echo html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => $name,
                'value' => $value,
            ]);
        }

        echo html_writer::tag('label', get_string('filter_submitted_from', 'quiz_aigrader'), [
            'for' => 'aigrader-datefrom',
            'class' => 'aigrader-date-filter-label',
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'date',
            'id' => 'aigrader-datefrom',
            'name' => 'datefrom',
            'class' => 'aigrader-date-filter-input',
            'value' => $this->format_filter_date($datefrom),
        ]);

        echo html_writer::tag('label', get_string('filter_submitted_to', 'quiz_aigrader'), [
            'for' => 'aigrader-dateto',
            'class' => 'aigrader-date-filter-label',
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'date',
            'id' => 'aigrader-dateto',
            'name' => 'dateto',
            'class' => 'aigrader-date-filter-input',
            'value' => $this->format_filter_date($dateto),
        ]);

        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'aigrader-date-filter-apply',
            'value' => get_string('filter_apply', 'quiz_aigrader'),
        ]);

        if ($datefrom || $dateto) {
            $clearurl = new moodle_url($formurl, ['aigclearfilter' => 1, 'sesskey' => sesskey()]);
            echo html_writer::link($clearurl, get_string('filter_clear', 'quiz_aigrader'), [
                'class' => 'aigrader-date-filter-clear',
            ]);
        }

        echo html_writer::end_tag('form');

        if ($datefrom || $dateto) {
            echo html_writer::div(
                get_string('filter_saved_notice', 'quiz_aigrader', $this->describe_date_filter($datefrom, $dateto)),
                'aigrader-date-filter-notice'
            );
        }
    }

    /**
     * Human readable description of the active date range.
     *
     * @param int $datefrom Start of the range, 0 when unset.
     * @param int $dateto End of the range, 0 when unset.
     * @return string The description, already formatted in the user's timezone.
     */
    private function describe_date_filter($datefrom, $dateto) {
        $format = get_string('strftimedaydate', 'langconfig');

        if ($datefrom && $dateto) {
            return get_string('filter_range_between', 'quiz_aigrader', (object) [
                'from' => userdate($datefrom, $format),
                'to' => userdate($dateto, $format),
            ]);
        }

        if ($datefrom) {
            return get_string('filter_range_from', 'quiz_aigrader', userdate($datefrom, $format));
        }

        return get_string('filter_range_to', 'quiz_aigrader', userdate($dateto, $format));
    }

    /**
     * Action buttons: refresh, grade all and search.
     *
     * @return void
     */
    private function render_action_buttons() {
        echo html_writer::start_div('aigrader-actions ag-flex-center ag-flex-wrap ag-gap-md');

        echo html_writer::tag(
            'button',
            '<svg class="ag-icon-sm ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="16" height="16"'
            . ' stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24">'
            . '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>'
            . '<path d="M3.5 9A9 9 0 0 1 18.4 5L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15"/></svg> '
            . get_string('refresh_credits', 'quiz_aigrader'),
            [
                'id' => 'aigrader-refresh-btn',
                'type' => 'button',
                'class' => 'aigrader-btn aigrader-btn-secondary ag-btn-base',
            ]
        );

        echo html_writer::tag(
            'button',
            '<svg class="ag-icon-sm ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="16" height="16"'
            . ' stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24">'
            . '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10"/></svg> '
            . get_string('grade_all', 'quiz_aigrader')
            . ' <span id="aigrader-essay-count" class="aigrader-count-badge">0</span>',
            [
                'id' => 'aigrader-gradeall-btn',
                'type' => 'button',
                'class' => 'aigrader-btn aigrader-btn-primary ag-btn-base ag-btn-primary',
            ]
        );

        // Search box.
        echo html_writer::start_div('aigrader-search-wrapper ag-flex-center');
        echo '<svg class="aigrader-search-icon ag-icon-sm ag-icon-fixed" xmlns="http://www.w3.org/2000/svg"'
            . ' width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">'
            . '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'id' => 'aigrader-search',
            'class' => 'aigrader-search-input',
            'placeholder' => get_string('search_student', 'quiz_aigrader'),
        ]);
        echo html_writer::end_div();

        echo html_writer::end_div();
    }

    /**
     * Check whether the Essay Guard plagiarism plugin is installed and usable.
     *
     * @return bool True when the Essay Guard analyser API is available.
     */
    private function essayguard_available() {
        global $DB;

        $analyser = '\plagiarism_essayguard\local\service\analyser';

        return class_exists($analyser)
            && method_exists($analyser, 'risk_level')
            && $DB->get_manager()->table_exists('plagiarism_essayguard_sc');
    }

    /**
     * Build and render the table of essay responses.
     *
     * Only loads essays that need grading (using Moodle's question_attempt_steps state).
     * When $groupid > 0 the results are restricted to members of that group only, and when
     * $forcegroupjoin is true the group restriction is always applied so that essays from
     * other groups can never be returned.
     *
     * @param stdClass $quiz The quiz record.
     * @param stdClass $cm The course module record.
     * @param context $context The module context, used for formatting names.
     * @param int $groupid Group to restrict to, 0 for all groups.
     * @param bool $forcegroupjoin Whether the group restriction is mandatory.
     * @param int $datefrom Only include attempts submitted on or after this timestamp, 0 for no limit.
     * @param int $dateto Only include attempts submitted on or before this timestamp, 0 for no limit.
     * @return void
     */
    private function render_essay_table(
        $quiz,
        $cm,
        $context,
        $groupid = 0,
        $forcegroupjoin = false,
        $datefrom = 0,
        $dateto = 0
    ) {
        global $DB;

        // Separate groups without accessallgroups and no group membership means nothing is visible.
        if ($forcegroupjoin && $groupid <= 0) {
            $this->render_empty_state($datefrom, $dateto);
            return;
        }

        // RC6 fix: replace correlated subquery with LEFT JOIN anti-pattern.
        // RC4 fix: pull questiontext and maxmark directly from the joined tables so we
        // never need to call question_engine::load_questions_usage_by_activity().
        //
        // Group filter: when groupid > 0, add a JOIN to {groups_members} so only
        // students in the selected group are returned.
        $groupjoin = '';
        $sqlparams = ['quizid' => $quiz->id];
        if ($groupid > 0) {
            $groupjoin = 'JOIN {groups_members} agm ON agm.userid = qza.userid AND agm.groupid = :groupid';
            $sqlparams['groupid'] = $groupid;
        }

        // Date filter: restrict to attempts submitted inside the chosen range.
        $datewhere = '';
        if ($datefrom > 0) {
            $datewhere .= ' AND qza.timefinish >= :datefrom';
            $sqlparams['datefrom'] = $datefrom;
        }
        if ($dateto > 0) {
            $datewhere .= ' AND qza.timefinish <= :dateto';
            $sqlparams['dateto'] = $dateto;
        }

        $rowkey = $DB->sql_concat("qza.uniqueid", "'-'", "qat.slot");

        $sql = "SELECT
                    $rowkey as rowkey,
                    qza.uniqueid AS qubaid,
                    qat.slot,
                    qza.userid,
                    qat.id as questionattemptid,
                    q.questiontext,
                    qat.maxmark
                FROM {quiz_attempts} qza
                JOIN {question_usages} qu ON qu.id = qza.uniqueid
                JOIN {question_attempts} qat ON qat.questionusageid = qu.id
                JOIN {question} q ON q.id = qat.questionid
                JOIN {question_attempt_steps} qas
                    ON qas.questionattemptid = qat.id
                   AND qas.state = 'needsgrading'
                $groupjoin
                WHERE qza.quiz = :quizid
                  AND qza.state IN ('finished','complete','gradedright','gradedwrong','gradedpartial')
                  AND q.qtype = 'essay'
                  AND NOT EXISTS (
                          SELECT 1
                            FROM {question_attempt_steps} qas_later
                           WHERE qas_later.questionattemptid = qat.id
                             AND qas_later.sequencenumber > qas.sequencenumber
                      )
                  $datewhere
                ORDER BY qza.userid, qat.slot";

        $needsgrading = $DB->get_records_sql($sql, $sqlparams);

        if (empty($needsgrading)) {
            $this->render_empty_state($datefrom, $dateto);
            return;
        }

        // RC5 fix: bulk-load users in one query instead of one get_user() call per student.
        $userids = array_unique(array_column(array_values($needsgrading), 'userid'));
        $users = $DB->get_records_list(
            'user',
            'id',
            $userids,
            '',
            'id,email,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename'
        );

        // Pre-load Essay Guard risk scores for all students in this activity.
        // Only runs if the Essay Guard plugin (plagiarism_essayguard) is installed and
        // exposes the analyser API we rely on. One query for all students, de-duplicated
        // to the most recent record per user.
        $essayguardscores = [];
        $essayguardavailable = $this->essayguard_available();
        if (!empty($userids) && $essayguardavailable) {
            [$insql, $egparams] = $DB->get_in_or_equal(array_values($userids), SQL_PARAMS_NAMED);
            $egparams['cmid'] = $cm->id;
            $egrecords = $DB->get_records_sql(
                "SELECT * FROM {plagiarism_essayguard_sc}
                  WHERE userid $insql AND cmid = :cmid
               ORDER BY timemodified DESC",
                $egparams
            );
            foreach ($egrecords as $sc) {
                if (!isset($essayguardscores[$sc->userid])) {
                    $essayguardscores[$sc->userid] = $sc;
                }
            }
        }

        // RC4 fix: bulk-load answer text for every question attempt in ONE query.
        // This completely replaces the previous N+1 loop that called
        // question_engine::load_questions_usage_by_activity() once per student attempt.
        // For essay questions the student answer lives in question_attempt_step_data
        // with name='answer'. We fetch all rows for our attempts, ordered DESC so the
        // most recent submission is first, then keep only the first per questionattemptid.
        $qatids = array_column(array_values($needsgrading), 'questionattemptid');
        $answersbyqatid = [];
        if (!empty($qatids)) {
            [$qatinsql, $qatparams] = $DB->get_in_or_equal($qatids, SQL_PARAMS_NAMED);
            $answerrows = $DB->get_records_sql(
                "SELECT qasd.id, qas.questionattemptid, qasd.value AS rawanswer, qas.sequencenumber
                   FROM {question_attempt_steps} qas
                   JOIN {question_attempt_step_data} qasd
                     ON qasd.attemptstepid = qas.id AND qasd.name = 'answer'
                  WHERE qas.questionattemptid $qatinsql
                  ORDER BY qas.sequencenumber DESC",
                $qatparams
            );
            foreach ($answerrows as $ar) {
                if (!isset($answersbyqatid[$ar->questionattemptid])) {
                    $answersbyqatid[$ar->questionattemptid] = $ar->rawanswer;
                }
            }
        }

        $rows = [];

        foreach ($needsgrading as $record) {
            $rawanswer = $answersbyqatid[$record->questionattemptid] ?? '';
            if (trim(strip_tags($rawanswer)) === '') {
                continue;
            }

            $user = $users[$record->userid] ?? null;
            if (!$user) {
                continue;
            }

            $cleananswer = trim(strip_tags($rawanswer));
            $questiontext = $record->questiontext;
            $initials = core_text::substr($user->firstname, 0, 1) . core_text::substr($user->lastname, 0, 1);

            $rows[] = [
                'rowid'        => $record->qubaid . '-' . $record->slot,
                'userid'       => $record->userid,
                'fullname'     => format_string(fullname($user), true, ['context' => $context]),
                'email'        => $user->email,
                'initials'     => core_text::strtoupper($initials),
                'questionnum'  => 'Q' . $record->slot,
                'questiontext' => $this->truncate($questiontext),
                'questionfull' => strip_tags($questiontext),
                'truncated'    => $this->truncate($rawanswer),
                'full'         => $cleananswer,
                'rawanswer'    => $rawanswer,
                'qubaid'       => $record->qubaid,
                'slot'         => $record->slot,
                'cmid'         => $cm->id,
                'maxmark'      => $record->maxmark,
                'isgraded'     => false,
                'gradelabel'   => '&mdash;',
                'feedback'     => '',
            ];
        }

        if (empty($rows)) {
            $this->render_empty_state($datefrom, $dateto);
            return;
        }

        // Sort by student name.
        usort($rows, function ($a, $b) {
            return strcasecmp($a['fullname'], $b['fullname']);
        });

        echo html_writer::start_div('aigrader-table-wrapper');

        // Card-based layout - each essay gets its own card.
        echo '<div class="aigrader-cards">';

        foreach ($rows as $r) {
            // Look up the Essay Guard score for this student (null if not installed or no data).
            $eg = $essayguardscores[$r['userid']] ?? null;
            // FIX-EG-LEVEL-COMPUTE (v1.2.103): derive the level from the stored score, not the
            // stored risklevel column. The stored column may have been written under old
            // thresholds (e.g. HIGH at 41), causing confusing labels like "HIGH 44/100".
            // analyser::risk_level() is the single source of truth - consistent with
            // student.php and lib.php which already use this approach.
            $egscore = $eg ? (int) round((float) ($eg->riskscore ?? 0) * 100) : 0;
            $egpastes = $eg ? (int) ($eg->paste_events ?? 0) : 0;
            $eglevel = null;
            if ($eg && $essayguardavailable) {
                $eglevel = \plagiarism_essayguard\local\service\analyser::risk_level($egscore);
            }

            echo '<div class="aigrader-card" data-rowid="' . s($r['rowid']) . '"'
                . ' data-qubaid="' . s($r['qubaid']) . '" data-slot="' . s($r['slot']) . '"'
                . ' data-studentname="' . s(core_text::strtolower($r['fullname'])) . '"'
                . ' data-questiontext="' . s(core_text::strtolower($r['questionfull'])) . '"'
                . ' data-answer="' . s(core_text::strtolower($r['full'])) . '">';

            // Student header.
            echo '<div class="aigrader-card-header ag-flex-between ag-flex-wrap ag-gap-sm">';
            echo '<div class="aigrader-student-inline ag-flex-center ag-gap-sm">';
            echo '<div class="aigrader-avatar-sm ag-flex-center">' . s($r['initials']) . '</div>';
            echo '<span class="aigrader-student-name">' . s($r['fullname']) . '</span>';
            echo '<span class="aigrader-student-email ag-text-muted">' . s($r['email']) . '</span>';

            // Essay Guard inline badge - only if data exists.
            if ($eglevel !== null) {
                $detailurl = new moodle_url('/plagiarism/essayguard/student.php', [
                    'cmid'   => $r['cmid'],
                    'userid' => $r['userid'],
                ]);
                echo '<a href="' . $detailurl->out(false) . '" target="_blank"'
                    . ' class="ag-eg-badge ag-eg-badge-' . s($eglevel) . '"'
                    . ' title="Essay Guard: ' . s(ucfirst($eglevel)) . ' (' . $egscore . '/100)">'
                    . '<svg class="aigrader-eg-badge-icon" width="11" height="11" viewBox="0 0 24 24" fill="none"'
                    . ' stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">'
                    . '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>'
                    . s(ucfirst($eglevel))
                    . '</a>';
            }
            echo '</div>';
            echo '<span class="aigrader-qnum-badge">' . s($r['questionnum']) . '</span>';
            echo '</div>';

            // Essay Guard advisory panel - medium and high get a visible warning.
            if ($eglevel === 'high') {
                $detailurl = new moodle_url('/plagiarism/essayguard/student.php', [
                    'cmid'   => $r['cmid'],
                    'userid' => $r['userid'],
                ]);
                $pastenote = $egpastes > 0
                    ? ' ' . $egpastes . ' paste event' . ($egpastes === 1 ? '' : 's') . ' detected.'
                    : '';
                echo '<div class="ag-eg-advisory ag-eg-advisory-high">'
                    . '<svg class="aigrader-eg-advisory-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"'
                    . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
                    . '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>'
                    . '<line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
                    . '<span><strong>Writing integrity alert (High ' . $egscore . '/100).</strong>'
                    . $pastenote
                    . ' This student\'s answer shows high authenticity risk. If you have concerns,'
                    . ' consider overriding the AI grade and leaving a comment asking the student'
                    . ' to rephrase the answer in their own words. &nbsp;'
                    . '<a href="' . $detailurl->out(false) . '" target="_blank" class="aigrader-eg-link">'
                    . 'View Essay Guard detail &rarr;</a>'
                    . '</span>'
                    . '</div>';
            } else if ($eglevel === 'medium') {
                $detailurl = new moodle_url('/plagiarism/essayguard/student.php', [
                    'cmid'   => $r['cmid'],
                    'userid' => $r['userid'],
                ]);
                echo '<div class="ag-eg-advisory ag-eg-advisory-medium">'
                    . '<svg class="aigrader-eg-advisory-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"'
                    . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
                    . '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
                    . '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
                    . '<span><strong>Writing integrity note (Medium ' . $egscore . '/100).</strong>'
                    . ' Elevated authenticity risk indicators detected. Review before accepting the AI grade. &nbsp;'
                    . '<a href="' . $detailurl->out(false) . '" target="_blank" class="aigrader-eg-link">'
                    . 'View Essay Guard detail &rarr;</a>'
                    . '</span>'
                    . '</div>';
            }

            // Question section.
            echo '<div class="aigrader-card-section aigrader-question-section">';
            echo '<div class="aigrader-section-label">' . get_string('question', 'quiz_aigrader') . '</div>';
            $formattedquestion = $this->format_question_text($r['questionfull']);
            echo '<div class="aigrader-question-text">' . $formattedquestion . '</div>';
            echo '</div>';

            // Answer section.
            echo '<div class="aigrader-card-section aigrader-answer-section">';
            echo '<div class="aigrader-section-label">' . get_string('answer', 'quiz_aigrader') . '</div>';
            $formattedanswer = $this->format_student_answer($r['rawanswer']);
            echo '<div class="aigrader-answer-text" data-raw="' . s($r['rawanswer']) . '">'
                . $formattedanswer . '</div>';
            echo '</div>';

            // Feedback and actions section - all essays are ungraded.
            echo '<div class="aigrader-card-section aigrader-feedback-section">';
            echo '<div class="aigrader-initial-actions ag-flex-between ag-flex-wrap ag-gap-md"'
                . ' id="initial-actions-' . $r['rowid'] . '">';
            echo '<span class="ag-text-muted">' . get_string('not_yet_graded', 'quiz_aigrader') . '</span>';
            echo '<div class="aigrader-card-actions ag-flex-center ag-gap-md">';
            echo '<span class="aigrader-grade aigrader-grade-pending" id="grade-' . $r['rowid'] . '">&mdash;</span>';
            echo '<button class="aigrader-btn aigrader-btn-sm aigrader-btn-success aigrader-grade-btn'
                . ' ag-btn-base ag-btn-success"
                    data-qubaid="' . $r['qubaid'] . '"
                    data-slot="' . $r['slot'] . '"
                    data-cmid="' . $r['cmid'] . '"
                    data-maxmark="' . $r['maxmark'] . '"
                    data-questiontext="' . s($r['questionfull']) . '">'
                . get_string('ai_grade', 'quiz_aigrader') . '</button>';
            echo '</div>';
            echo '</div>';
            echo '<div class="aigrader-feedback aigrader-feedback-hidden" id="feedback-' . $r['rowid'] . '"></div>';
            echo '</div>';

            // End card.
            echo '</div>';
        }

        // End cards container.
        echo '</div>';
        echo html_writer::end_div();
    }

    /**
     * Format question text - just use Moodle's format_text, no other processing.
     *
     * @param string $questionhtml The raw question HTML.
     * @return string The formatted HTML.
     */
    private function format_question_text($questionhtml) {
        return format_text($questionhtml, FORMAT_HTML);
    }

    /**
     * Format student answer - just use Moodle's format_text, no other processing.
     *
     * @param string $rawanswer The raw answer HTML.
     * @return string The formatted HTML.
     */
    private function format_student_answer($rawanswer) {
        return format_text($rawanswer, FORMAT_HTML);
    }

    /**
     * Show a message when all essays have been graded.
     *
     * @return void
     */
    private function render_all_graded_state() {
        echo html_writer::start_div('aigrader-empty aigrader-all-graded');
        echo '<svg class="aigrader-empty-icon" xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="none"'
            . ' stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">'
            . '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
        echo html_writer::tag('h3', get_string('all_graded', 'quiz_aigrader'), ['class' => 'aigrader-empty-title']);
        echo html_writer::tag(
            'p',
            get_string('all_graded_message', 'quiz_aigrader'),
            ['class' => 'aigrader-empty-message']
        );
        echo html_writer::end_div();
    }

    /**
     * Render whichever empty state is truthful for the current filter.
     *
     * Telling a marker that everything is graded while a date filter is hiding outstanding
     * work is the one failure this feature must never produce, so every path that finds no
     * rows goes through here rather than calling the all-graded state directly.
     *
     * @param int $datefrom Start of the active date range, 0 when unset.
     * @param int $dateto End of the active date range, 0 when unset.
     * @return void
     */
    private function render_empty_state($datefrom, $dateto) {
        if ($datefrom > 0 || $dateto > 0) {
            $this->render_no_results_in_range();
        } else {
            $this->render_all_graded_state();
        }
    }

    /**
     * Empty state shown when a date filter is active and nothing falls inside it.
     *
     * Kept separate from the all-graded state so a marker is never told everything is
     * marked when the filter is simply hiding the outstanding work.
     *
     * @return void
     */
    private function render_no_results_in_range() {
        global $PAGE;

        $clearurl = new moodle_url($PAGE->url);
        $clearurl->remove_params('aigfilter', 'datefrom', 'dateto');
        $clearurl->param('aigclearfilter', 1);
        $clearurl->param('sesskey', sesskey());

        echo html_writer::start_div('aigrader-empty aigrader-no-results');
        echo html_writer::tag(
            'h3',
            get_string('filter_no_results', 'quiz_aigrader'),
            ['class' => 'aigrader-empty-title']
        );
        echo html_writer::tag(
            'p',
            get_string('filter_no_results_message', 'quiz_aigrader'),
            ['class' => 'aigrader-empty-message']
        );
        echo html_writer::link(
            $clearurl,
            get_string('filter_clear', 'quiz_aigrader'),
            ['class' => 'aigrader-date-filter-clear']
        );
        echo html_writer::end_div();
    }

    /**
     * Strip tags from a chunk of text and shorten it for preview display.
     *
     * @param string $text The text to shorten.
     * @return string The plain-text, shortened version.
     */
    private function truncate($text) {
        $clean = trim(strip_tags($text));
        return shorten_text($clean, 200);
    }

    /**
     * Render the plugin footer.
     *
     * @return void
     */
    private function render_footer() {
        echo html_writer::start_div('aigrader-footer');
        echo html_writer::tag(
            'span',
            get_string('powered_by', 'quiz_aigrader'),
            ['class' => 'aigrader-powered']
        );
        echo html_writer::end_div();
    }

    /**
     * Render the full-page loading overlay used while AI grading runs.
     *
     * @return void
     */
    private function render_loading_overlay() {
        echo html_writer::start_div('aigrader-loading-overlay', ['id' => 'aigrader-loading']);
        echo html_writer::start_div('aigrader-loading-content');
        echo html_writer::tag('div', '', ['class' => 'aigrader-loading-spinner']);
        echo html_writer::tag(
            'div',
            get_string('processing', 'quiz_aigrader'),
            ['class' => 'aigrader-loading-text']
        );
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}
