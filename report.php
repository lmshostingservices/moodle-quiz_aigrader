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

/**
 * AI Grader quiz report display class.
 *
 * @package    quiz_aigrader
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

// Moodle 4.2+ / Moodle 5 moved quiz_default_report to mod_quiz\local\reports\report_base
// We create a class alias so the plugin works on both old and new Moodle versions
if (class_exists('\mod_quiz\local\reports\report_base')) {
    // Moodle 4.2+ / Moodle 5: Alias the new namespaced class
    class_alias('\mod_quiz\local\reports\report_base', 'quiz_aigrader_report_base');
} else {
    // Moodle 4.0 - 4.1: Load and alias the legacy class
    require_once($CFG->dirroot . '/mod/quiz/report/default.php');
    class_alias('quiz_default_report', 'quiz_aigrader_report_base');
}

/**
 * Main AI Grader quiz report class.
 */
class quiz_aigrader_report extends quiz_aigrader_report_base {
    /**
     * Display the AI Grader report.
     */
    public function display($quiz, $cm, $course) {
        global $PAGE, $OUTPUT, $CFG;

        $context = context_module::instance($cm->id);
        require_capability('mod/quiz:viewreports', $context);

        // Group filter: read selected group from GET param (0 = all groups).
        $groupid = optional_param('groupid', 0, PARAM_INT);

        /* Load CSS */
        $PAGE->requires->css('/mod/quiz/report/aigrader/styles.css');

        /* Load JS - note: config must be wrapped in array to pass as single object */
        // Get user's current Moodle language for multilingual AI feedback
        $userlang = current_language();
        $userlang = str_replace('_', '-', $userlang); // Convert 'en_au' -> 'en-AU'
        
        $minreviewtime = (int) get_config('quiz_aigrader', 'min_review_time');

        $PAGE->requires->js_call_amd(
            'quiz_aigrader/aigrader',
            'init',
            [[
                'cmid'    => $cm->id,
                'quizid'  => $quiz->id,
                'sesskey' => sesskey(),
                'ajaxurl' => $CFG->wwwroot . '/mod/quiz/report/aigrader/ajax.php',
                'buyurl'  => 'https://lms-labs.com/pricing',
                'language' => $userlang,
                'minReviewTime' => $minreviewtime
            ]]
        );

        /* Use Moodle's standard quiz report header (includes tabs/navigation) */
        $this->print_header_and_tabs($cm, $course, $quiz, 'aigrader');

        $this->render_container_start();
        $this->render_header($quiz);
        $this->render_grading_stats();
        $this->render_document_section($quiz);
        $this->render_action_buttons();
        $this->render_group_filter($cm, $course, $groupid);
        $this->render_essay_table($quiz, $cm, $groupid, $course);
        $this->render_footer();
        $this->render_container_end();
        $this->render_loading_overlay();

        /* DO NOT call $OUTPUT->footer() - the quiz report framework handles this automatically */
        return true;
    }

    /* Container wrappers */
    private function render_container_start() {
        global $CFG;
        $ajaxurl = $CFG->wwwroot . '/mod/quiz/report/aigrader/ajax.php';
        echo html_writer::start_div('aigrader-container', [
            'id' => 'aigrader-root',
            'data-ajaxurl' => $ajaxurl
        ]);
    }
    private function render_container_end() {
        echo html_writer::end_div();
    }

    /**
     * Header with credits badge.
     */
    private function render_header($quiz) {
        echo html_writer::start_div('aigrader-header ag-flex-between ag-flex-wrap ag-gap-lg');

        echo html_writer::start_div('aigrader-brand ag-flex-center ag-gap-lg');
        echo html_writer::start_div('aigrader-logo ag-flex-center');
        echo '<svg class="ag-icon-lg ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="none" stroke="white" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>';
        echo html_writer::end_div();
        echo html_writer::tag('h1', get_string('pluginname', 'quiz_aigrader'), ['class' => 'aigrader-title']);
        echo html_writer::tag('p', format_string($quiz->name), ['class' => 'aigrader-subtitle']);
        echo html_writer::end_div();

        echo html_writer::start_div('aigrader-credits ag-card ag-flex-center ag-gap-sm', ['id' => 'aigrader-credits-badge']);
        echo html_writer::start_div('aigrader-credits-icon ag-flex-center');
        echo '<svg class="ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" stroke="white" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v12"/><path d="M8 10h8a2 2 0 1 1 0 4H10a2 2 0 0 0 0 4h6"/></svg>';
        echo html_writer::end_div();
        echo html_writer::tag('span', get_string('credits', 'quiz_aigrader') . ': ', ['class' => 'aigrader-credits-label']);
        echo html_writer::tag('span', '…', [
            'class' => 'aigrader-credits-value',
            'id' => 'aigrader-credits-count'
        ]);
        echo html_writer::end_div();

        echo html_writer::end_div();
    }

    /**
     * Reference documents section for AI grading context.
     */
    private function render_document_section($quiz) {
        echo html_writer::start_div('aigrader-documents', ['id' => 'aigrader-documents']);
        
        echo html_writer::start_div('aigrader-documents-header');
        echo html_writer::tag('h3', 
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> ' .
            get_string('reference_documents', 'quiz_aigrader'), 
            ['class' => 'aigrader-documents-title']
        );
        echo html_writer::tag('button', 
            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>',
            ['class' => 'aigrader-documents-toggle', 'id' => 'aigrader-documents-toggle', 'type' => 'button']
        );
        echo html_writer::end_div();
        
        echo html_writer::start_div('aigrader-documents-content', ['id' => 'aigrader-documents-content']);
        
        echo html_writer::tag('p', get_string('reference_documents_help', 'quiz_aigrader'), ['class' => 'aigrader-documents-help']);
        
        // Upload form
        echo html_writer::start_div('aigrader-upload-form');
        echo html_writer::empty_tag('input', [
            'type' => 'file',
            'id' => 'aigrader-doc-input',
            'accept' => '.pdf,.docx,.txt,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain',
            'class' => 'aigrader-file-input'
        ]);
        echo html_writer::tag('label', 
            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> ' .
            get_string('upload_document', 'quiz_aigrader'),
            ['for' => 'aigrader-doc-input', 'class' => 'aigrader-upload-btn']
        );
        echo html_writer::tag('span', get_string('upload_formats', 'quiz_aigrader'), ['class' => 'aigrader-upload-hint']);
        echo html_writer::end_div();
        
        // Document list
        echo html_writer::start_div('aigrader-documents-list', ['id' => 'aigrader-documents-list']);
        echo html_writer::tag('div', get_string('loading', 'quiz_aigrader'), ['class' => 'aigrader-documents-loading']);
        echo html_writer::end_div();
        
        echo html_writer::end_div();
        
        echo html_writer::end_div();
        
        // Extra Instructions collapsible section
        echo html_writer::start_div('aigrader-section aigrader-instructions-section');
        
        // Section header with toggle
        echo html_writer::start_div('aigrader-section-header', ['id' => 'aigrader-instructions-toggle']);
        echo html_writer::tag('span', 
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> ' .
            get_string('extra_instructions', 'quiz_aigrader'),
            ['class' => 'aigrader-section-title']
        );
        echo html_writer::tag('span', '', ['class' => 'aigrader-collapse-icon']);
        echo html_writer::end_div();
        
        // Section content (collapsible)
        echo html_writer::start_div('aigrader-section-content', ['id' => 'aigrader-instructions-content']);
        echo html_writer::tag('p', get_string('extra_instructions_help', 'quiz_aigrader'), ['class' => 'aigrader-section-help']);
        
        // Feedback Language dropdown with country flags
        echo html_writer::start_div('aigrader-language-form');
        echo html_writer::tag('label', get_string('feedback_language', 'quiz_aigrader'), ['for' => 'aigrader-feedback-language', 'class' => 'aigrader-language-label']);
        $languages = [
            'en' => ['flag' => "\xF0\x9F\x87\xAC\xF0\x9F\x87\xA7", 'name' => 'English'],
            'en-AU' => ['flag' => "\xF0\x9F\x87\xA6\xF0\x9F\x87\xBA", 'name' => 'English (Australian)'],
            'en-GB' => ['flag' => "\xF0\x9F\x87\xAC\xF0\x9F\x87\xA7", 'name' => 'English (British)'],
            'en-US' => ['flag' => "\xF0\x9F\x87\xBA\xF0\x9F\x87\xB8", 'name' => 'English (American)'],
            'en-NZ' => ['flag' => "\xF0\x9F\x87\xB3\xF0\x9F\x87\xBF", 'name' => 'English (New Zealand)'],
            'en-CA' => ['flag' => "\xF0\x9F\x87\xA8\xF0\x9F\x87\xA6", 'name' => 'English (Canadian)'],
            'en-IE' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xAA", 'name' => 'English (Irish)'],
            'en-ZA' => ['flag' => "\xF0\x9F\x87\xBF\xF0\x9F\x87\xA6", 'name' => 'English (South African)'],
            'es' => ['flag' => "\xF0\x9F\x87\xAA\xF0\x9F\x87\xB8", 'name' => 'Spanish (Español)'],
            'fr' => ['flag' => "\xF0\x9F\x87\xAB\xF0\x9F\x87\xB7", 'name' => 'French (Français)'],
            'de' => ['flag' => "\xF0\x9F\x87\xA9\xF0\x9F\x87\xAA", 'name' => 'German (Deutsch)'],
            'it' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB9", 'name' => 'Italian (Italiano)'],
            'pt' => ['flag' => "\xF0\x9F\x87\xB5\xF0\x9F\x87\xB9", 'name' => 'Portuguese (Português)'],
            'nl' => ['flag' => "\xF0\x9F\x87\xB3\xF0\x9F\x87\xB1", 'name' => 'Dutch (Nederlands)'],
            'ru' => ['flag' => "\xF0\x9F\x87\xB7\xF0\x9F\x87\xBA", 'name' => 'Russian (Русский)'],
            'zh' => ['flag' => "\xF0\x9F\x87\xA8\xF0\x9F\x87\xB3", 'name' => 'Chinese (中文)'],
            'ja' => ['flag' => "\xF0\x9F\x87\xAF\xF0\x9F\x87\xB5", 'name' => 'Japanese (日本語)'],
            'ko' => ['flag' => "\xF0\x9F\x87\xB0\xF0\x9F\x87\xB7", 'name' => 'Korean (한국어)'],
            'ar' => ['flag' => "\xF0\x9F\x87\xB8\xF0\x9F\x87\xA6", 'name' => 'Arabic (العربية)'],
            'hi' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Hindi (हिन्दी)'],
            'bn' => ['flag' => "\xF0\x9F\x87\xA7\xF0\x9F\x87\xA9", 'name' => 'Bengali (বাংলা)'],
            'pa' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Punjabi (ਪੰਜਾਬੀ)'],
            'te' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Telugu (తెలుగు)'],
            'ta' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Tamil (தமிழ்)'],
            'mr' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Marathi (मराठी)'],
            'gu' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Gujarati (ગુજરાતી)'],
            'kn' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Kannada (ಕನ್ನಡ)'],
            'ml' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB3", 'name' => 'Malayalam (മലയാളം)'],
            'th' => ['flag' => "\xF0\x9F\x87\xB9\xF0\x9F\x87\xAD", 'name' => 'Thai (ไทย)'],
            'vi' => ['flag' => "\xF0\x9F\x87\xBB\xF0\x9F\x87\xB3", 'name' => 'Vietnamese (Tiếng Việt)'],
            'id' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xA9", 'name' => 'Indonesian (Bahasa Indonesia)'],
            'ms' => ['flag' => "\xF0\x9F\x87\xB2\xF0\x9F\x87\xBE", 'name' => 'Malay (Bahasa Melayu)'],
            'fil' => ['flag' => "\xF0\x9F\x87\xB5\xF0\x9F\x87\xAD", 'name' => 'Filipino (Tagalog)'],
            'tr' => ['flag' => "\xF0\x9F\x87\xB9\xF0\x9F\x87\xB7", 'name' => 'Turkish (Türkçe)'],
            'pl' => ['flag' => "\xF0\x9F\x87\xB5\xF0\x9F\x87\xB1", 'name' => 'Polish (Polski)'],
            'uk' => ['flag' => "\xF0\x9F\x87\xBA\xF0\x9F\x87\xA6", 'name' => 'Ukrainian (Українська)'],
            'cs' => ['flag' => "\xF0\x9F\x87\xA8\xF0\x9F\x87\xBF", 'name' => 'Czech (Čeština)'],
            'ro' => ['flag' => "\xF0\x9F\x87\xB7\xF0\x9F\x87\xB4", 'name' => 'Romanian (Română)'],
            'hu' => ['flag' => "\xF0\x9F\x87\xAD\xF0\x9F\x87\xBA", 'name' => 'Hungarian (Magyar)'],
            'el' => ['flag' => "\xF0\x9F\x87\xAC\xF0\x9F\x87\xB7", 'name' => 'Greek (Ελληνικά)'],
            'sv' => ['flag' => "\xF0\x9F\x87\xB8\xF0\x9F\x87\xAA", 'name' => 'Swedish (Svenska)'],
            'da' => ['flag' => "\xF0\x9F\x87\xA9\xF0\x9F\x87\xB0", 'name' => 'Danish (Dansk)'],
            'fi' => ['flag' => "\xF0\x9F\x87\xAB\xF0\x9F\x87\xAE", 'name' => 'Finnish (Suomi)'],
            'no' => ['flag' => "\xF0\x9F\x87\xB3\xF0\x9F\x87\xB4", 'name' => 'Norwegian (Norsk)'],
            'he' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB1", 'name' => 'Hebrew (עברית)'],
            'fa' => ['flag' => "\xF0\x9F\x87\xAE\xF0\x9F\x87\xB7", 'name' => 'Persian (فارسی)'],
            'ur' => ['flag' => "\xF0\x9F\x87\xB5\xF0\x9F\x87\xB0", 'name' => 'Urdu (اردو)'],
            'sw' => ['flag' => "\xF0\x9F\x87\xB0\xF0\x9F\x87\xAA", 'name' => 'Swahili (Kiswahili)'],
            'zu' => ['flag' => "\xF0\x9F\x87\xBF\xF0\x9F\x87\xA6", 'name' => 'Zulu (isiZulu)'],
            'af' => ['flag' => "\xF0\x9F\x87\xBF\xF0\x9F\x87\xA6", 'name' => 'Afrikaans'],
            'am' => ['flag' => "\xF0\x9F\x87\xAA\xF0\x9F\x87\xB9", 'name' => 'Amharic (አማርኛ)'],
            'bg' => ['flag' => "\xF0\x9F\x87\xA7\xF0\x9F\x87\xAC", 'name' => 'Bulgarian (Български)'],
            'hr' => ['flag' => "\xF0\x9F\x87\xAD\xF0\x9F\x87\xB7", 'name' => 'Croatian (Hrvatski)'],
            'sk' => ['flag' => "\xF0\x9F\x87\xB8\xF0\x9F\x87\xB0", 'name' => 'Slovak (Slovenčina)'],
            'sl' => ['flag' => "\xF0\x9F\x87\xB8\xF0\x9F\x87\xAE", 'name' => 'Slovenian (Slovenščina)'],
            'lt' => ['flag' => "\xF0\x9F\x87\xB1\xF0\x9F\x87\xB9", 'name' => 'Lithuanian (Lietuvių)'],
            'lv' => ['flag' => "\xF0\x9F\x87\xB1\xF0\x9F\x87\xBB", 'name' => 'Latvian (Latviešu)'],
            'et' => ['flag' => "\xF0\x9F\x87\xAA\xF0\x9F\x87\xAA", 'name' => 'Estonian (Eesti)']
        ];
        echo html_writer::start_tag('select', ['id' => 'aigrader-feedback-language', 'class' => 'aigrader-language-select']);
        foreach ($languages as $code => $lang) {
            echo html_writer::tag('option', $lang['flag'] . '  ' . $lang['name'], ['value' => $code]);
        }
        echo html_writer::end_tag('select');
        echo html_writer::end_div();
        
        // Instructions textarea
        echo html_writer::start_div('aigrader-instructions-form');
        echo html_writer::tag('textarea', '', [
            'id' => 'aigrader-extra-instructions',
            'class' => 'aigrader-instructions-textarea',
            'placeholder' => get_string('extra_instructions_placeholder', 'quiz_aigrader'),
            'rows' => 4
        ]);
        echo html_writer::start_div('aigrader-instructions-actions');
        echo html_writer::tag('button',
            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> ' .
            get_string('save_instructions', 'quiz_aigrader'),
            ['id' => 'aigrader-save-instructions', 'type' => 'button', 'class' => 'aigrader-btn aigrader-btn-secondary aigrader-btn-sm']
        );
        echo html_writer::tag('span', '', ['id' => 'aigrader-instructions-status', 'class' => 'aigrader-instructions-status']);
        echo html_writer::end_div();
        echo html_writer::end_div();
        
        echo html_writer::end_div();
        
        echo html_writer::end_div();
    }

    /**
     * Grading Time Statistics box with filters.
     */
    private function render_grading_stats() {
        echo html_writer::start_div('aigrader-stats-box', ['id' => 'aigrader-stats-box']);
        
        // Header with toggle
        echo html_writer::start_div('aigrader-stats-header', ['id' => 'aigrader-stats-toggle']);
        echo html_writer::tag('span',
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ' .
            get_string('grading_time_stats', 'quiz_aigrader'),
            ['class' => 'aigrader-stats-title']
        );
        echo html_writer::tag('span', '', ['class' => 'aigrader-collapse-icon']);
        echo html_writer::end_div();
        
        // Content (collapsible)
        echo html_writer::start_div('aigrader-stats-content', ['id' => 'aigrader-stats-content']);
        
        // Filters row
        echo html_writer::start_div('aigrader-stats-filters');
        
        // Course filter
        echo html_writer::start_div('aigrader-filter-group');
        echo html_writer::tag('label', get_string('filter_course', 'quiz_aigrader'), ['for' => 'aigrader-filter-course']);
        echo html_writer::tag('select', '<option value="0">' . get_string('all_courses', 'quiz_aigrader') . '</option>', 
            ['id' => 'aigrader-filter-course', 'class' => 'aigrader-filter-select']);
        echo html_writer::end_div();
        
        // Grader filter
        echo html_writer::start_div('aigrader-filter-group');
        echo html_writer::tag('label', get_string('filter_grader', 'quiz_aigrader'), ['for' => 'aigrader-filter-grader']);
        echo html_writer::tag('select', '<option value="0">' . get_string('all_graders', 'quiz_aigrader') . '</option>', 
            ['id' => 'aigrader-filter-grader', 'class' => 'aigrader-filter-select']);
        echo html_writer::end_div();
        
        // Date from filter
        echo html_writer::start_div('aigrader-filter-group');
        echo html_writer::tag('label', get_string('filter_date_from', 'quiz_aigrader'), ['for' => 'aigrader-filter-datefrom']);
        echo html_writer::empty_tag('input', ['type' => 'date', 'id' => 'aigrader-filter-datefrom', 'class' => 'aigrader-filter-input']);
        echo html_writer::end_div();
        
        // Date to filter
        echo html_writer::start_div('aigrader-filter-group');
        echo html_writer::tag('label', get_string('filter_date_to', 'quiz_aigrader'), ['for' => 'aigrader-filter-dateto']);
        echo html_writer::empty_tag('input', ['type' => 'date', 'id' => 'aigrader-filter-dateto', 'class' => 'aigrader-filter-input']);
        echo html_writer::end_div();
        
        // Apply button
        echo html_writer::tag('button', get_string('apply_filters', 'quiz_aigrader'),
            ['id' => 'aigrader-stats-apply', 'type' => 'button', 'class' => 'aigrader-btn aigrader-btn-sm aigrader-btn-primary']);
        
        echo html_writer::end_div();
        
        // Stats summary cards
        echo html_writer::start_div('aigrader-stats-summary');
        
        echo html_writer::start_div('aigrader-stat-card');
        echo html_writer::tag('div', get_string('total_essays_graded', 'quiz_aigrader'), ['class' => 'aigrader-stat-label']);
        echo html_writer::tag('div', '—', ['id' => 'aigrader-stat-essays', 'class' => 'aigrader-stat-value']);
        echo html_writer::end_div();
        
        echo html_writer::start_div('aigrader-stat-card');
        echo html_writer::tag('div', get_string('total_grading_time', 'quiz_aigrader'), ['class' => 'aigrader-stat-label']);
        echo html_writer::tag('div', '—', ['id' => 'aigrader-stat-time', 'class' => 'aigrader-stat-value']);
        echo html_writer::end_div();
        
        echo html_writer::start_div('aigrader-stat-card');
        echo html_writer::tag('div', get_string('avg_time_per_essay', 'quiz_aigrader'), ['class' => 'aigrader-stat-label']);
        echo html_writer::tag('div', '—', ['id' => 'aigrader-stat-avg', 'class' => 'aigrader-stat-value']);
        echo html_writer::end_div();

        echo html_writer::start_div('aigrader-stat-card');
        echo html_writer::tag('div', get_string('total_student_time', 'quiz_aigrader'), ['class' => 'aigrader-stat-label']);
        echo html_writer::tag('div', '—', ['id' => 'aigrader-stat-student-time', 'class' => 'aigrader-stat-value']);
        echo html_writer::end_div();
        
        echo html_writer::end_div();
        
        // Grader breakdown table
        echo html_writer::start_div('aigrader-stats-table-wrapper');
        echo html_writer::tag('table', 
            '<thead><tr><th>' . get_string('grader', 'quiz_aigrader') . '</th><th>' . get_string('essays', 'quiz_aigrader') . '</th><th>' . get_string('time_spent', 'quiz_aigrader') . '</th><th>' . get_string('avg_per_essay', 'quiz_aigrader') . '</th></tr></thead><tbody id="aigrader-stats-tbody"></tbody>',
            ['class' => 'aigrader-stats-table', 'id' => 'aigrader-stats-table']);
        echo html_writer::end_div();
        
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    /**
     * Group filter bar — shows a dropdown of course groups so trainers can restrict
     * the essay list to only their assigned group. Renders nothing when the course
     * has no groups. Auto-submits on change; also has an explicit Apply button.
     */
    private function render_group_filter($cm, $course, $groupid) {
        global $PAGE;

        // Fetch all groups defined in this course (respects the activity's grouping if set).
        $groups = groups_get_all_groups($course->id, 0, $cm->groupingid);
        if (empty($groups)) {
            return; // No groups in this course — nothing to show.
        }

        // Build the base URL (strip groupid so the form re-adds it cleanly).
        $formaction = $PAGE->url->out_omit_querystring();

        echo html_writer::start_div('aigrader-group-filter-bar');

        // Group filter icon + label
        echo '<div class="aigrader-group-filter-inner ag-flex-center ag-flex-wrap ag-gap-md">';
        echo '<svg class="ag-icon-sm ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="16" height="16" '
           . 'fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">'
           . '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>'
           . '<circle cx="9" cy="7" r="4"/>'
           . '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/>'
           . '<path d="M16 3.13a4 4 0 0 1 0 7.75"/>'
           . '</svg>';
        echo html_writer::tag('span', get_string('filter_group_label', 'quiz_aigrader'),
            ['class' => 'aigrader-group-filter-label']);

        echo '<form method="get" action="' . s($formaction) . '" class="aigrader-group-form ag-flex-center ag-gap-sm">';

        // Re-emit every current GET param except groupid so page context is preserved.
        // Iterate over GET params without using the superglobal directly.
        foreach ((filter_input_array(INPUT_GET, FILTER_UNSAFE_RAW) ?: []) as $k => $v) {
            if ($k === 'groupid') {
                continue;
            }
            echo html_writer::empty_tag('input', [
                'type'  => 'hidden',
                'name'  => s($k),
                'value' => s($v),
            ]);
        }

        // Build group <select>.
        $opts = html_writer::tag('option', get_string('filter_all_groups', 'quiz_aigrader'),
            ['value' => '0'] + ($groupid == 0 ? ['selected' => 'selected'] : []));
        foreach ($groups as $g) {
            $attrs = ['value' => $g->id];
            if ($groupid == $g->id) {
                $attrs['selected'] = 'selected';
            }
            $opts .= html_writer::tag('option', s($g->name), $attrs);
        }
        echo html_writer::tag('select', $opts, [
            'name'     => 'groupid',
            'id'       => 'aigrader-groupid',
            'class'    => 'aigrader-group-select',
            'onchange' => 'this.form.submit()',
        ]);

        echo html_writer::tag('button', get_string('filter_apply', 'quiz_aigrader'), [
            'type'  => 'submit',
            'class' => 'aigrader-btn aigrader-btn-secondary aigrader-btn-sm ag-btn-base',
        ]);

        echo '</form>';

        // If a group is active, show a clear link.
        if ($groupid > 0 && isset($groups[$groupid])) {
            $clearurl = new moodle_url($PAGE->url);
            $clearurl->param('groupid', 0);
            echo html_writer::link($clearurl->out(false),
                get_string('filter_clear_group', 'quiz_aigrader'),
                ['class' => 'aigrader-group-clear ag-text-muted']);
        }

        echo '</div>'; // inner
        echo html_writer::end_div(); // bar
    }

    /**
     * Action buttons: Refresh + Grade All + Search (filter removed for performance).
     */
    private function render_action_buttons() {
        echo html_writer::start_div('aigrader-actions ag-flex-center ag-flex-wrap ag-gap-md');

        echo html_writer::tag('button',
            '<svg class="ag-icon-sm ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.5 9A9 9 0 0 1 18.4 5L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15"/></svg> ' .
            get_string('refresh_credits', 'quiz_aigrader'),
            ['id' => 'aigrader-refresh-btn', 'type' => 'button', 'class' => 'aigrader-btn aigrader-btn-secondary ag-btn-base']
        );

        echo html_writer::tag('button',
            '<svg class="ag-icon-sm ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10"/></svg> ' .
            get_string('grade_all', 'quiz_aigrader') .
            ' <span id="aigrader-essay-count" class="aigrader-count-badge">0</span>',
            ['id' => 'aigrader-gradeall-btn', 'type' => 'button', 'class' => 'aigrader-btn aigrader-btn-primary ag-btn-base ag-btn-primary']
        );

        // Search box
        echo html_writer::start_div('aigrader-search-wrapper ag-flex-center');
        echo '<svg class="aigrader-search-icon ag-icon-sm ag-icon-fixed" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'id' => 'aigrader-search',
            'class' => 'aigrader-search-input',
            'placeholder' => get_string('search_student', 'quiz_aigrader')
        ]);
        echo html_writer::end_div();

        echo html_writer::end_div();
    }

    /**
     * Build & render the table of essay responses.
     * Only loads essays that need grading (using Moodle's question_attempt_steps state).
     * When $groupid > 0, restricts results to members of that group only.
     */
    private function render_essay_table($quiz, $cm, $groupid = 0, $course = null) {
        global $DB, $PAGE;

        // RC6 fix: replace correlated subquery with LEFT JOIN anti-pattern.
        // RC4 fix: pull questiontext and maxmark directly from the joined tables so we
        //          never need to call question_engine::load_questions_usage_by_activity().
        //
        // GROUP FILTER: when groupid > 0, add a JOIN to {groups_members} so only
        // students in the selected group are returned.
        $groupjoin  = '';
        $sqlparams  = ['quizid' => $quiz->id];
        if ($groupid > 0) {
            $groupjoin = 'JOIN {groups_members} agm ON agm.userid = qza.userid AND agm.groupid = :groupid';
            $sqlparams['groupid'] = $groupid;
        }

        $sql = "SELECT
                    CONCAT(qza.uniqueid, '-', qat.slot) as rowkey,
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
                JOIN {question_attempt_steps} qas ON qas.questionattemptid = qat.id
                LEFT JOIN {question_attempt_steps} qas_later
                    ON qas_later.questionattemptid = qat.id
                   AND qas_later.sequencenumber > qas.sequencenumber
                $groupjoin
                WHERE qza.quiz = :quizid
                  AND qza.state IN ('finished','complete','gradedright','gradedwrong','gradedpartial')
                  AND q.qtype = 'essay'
                  AND qas.state = 'needsgrading'
                  AND qas_later.id IS NULL
                ORDER BY qza.userid, qat.slot";

        $needsgrading = $DB->get_records_sql($sql, $sqlparams);

        if (empty($needsgrading)) {
            $this->render_all_graded_state();
            return;
        }

        // RC5 fix: bulk-load users in one query instead of one get_user() call per student.
        $userids = array_unique(array_column(array_values($needsgrading), 'userid'));
        $users   = $DB->get_records_list(
            'user', 'id', $userids,
            '', 'id,email,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename'
        );

        // Pre-load Essay Guard risk scores for all students in this activity.
        // Only runs if Essay Guard (plagiarism_essayguard) is installed.
        // One query for all students — de-duplicated to most recent record per user.
        $essayguard_scores = [];
        if (!empty($userids) && $DB->get_manager()->table_exists('plagiarism_essayguard_sc')) {
            [$in_sql, $eg_params] = $DB->get_in_or_equal(array_values($userids), SQL_PARAMS_NAMED);
            $eg_params['cmid'] = $cm->id;
            $eg_records = $DB->get_records_sql(
                "SELECT * FROM {plagiarism_essayguard_sc}
                  WHERE userid $in_sql AND cmid = :cmid
               ORDER BY timemodified DESC",
                $eg_params
            );
            foreach ($eg_records as $sc) {
                if (!isset($essayguard_scores[$sc->userid])) {
                    $essayguard_scores[$sc->userid] = $sc;
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
        $answers_by_qatid = [];
        if (!empty($qatids)) {
            [$qat_in_sql, $qat_params] = $DB->get_in_or_equal($qatids, SQL_PARAMS_NAMED);
            $answer_rows = $DB->get_records_sql(
                "SELECT qasd.id, qas.questionattemptid, qasd.value AS rawanswer, qas.sequencenumber
                   FROM {question_attempt_steps} qas
                   JOIN {question_attempt_step_data} qasd
                     ON qasd.attemptstepid = qas.id AND qasd.name = 'answer'
                  WHERE qas.questionattemptid $qat_in_sql
                  ORDER BY qas.sequencenumber DESC",
                $qat_params
            );
            foreach ($answer_rows as $ar) {
                if (!isset($answers_by_qatid[$ar->questionattemptid])) {
                    $answers_by_qatid[$ar->questionattemptid] = $ar->rawanswer;
                }
            }
        }

        $rows = [];

        foreach ($needsgrading as $record) {
            $rawanswer = $answers_by_qatid[$record->questionattemptid] ?? '';
            if (trim(strip_tags($rawanswer)) === '') {
                continue;
            }

            $user = $users[$record->userid] ?? null;
            if (!$user) {
                continue;
            }

            $cleananswer  = trim(strip_tags($rawanswer));
            $questiontext = $record->questiontext;

            $rows[] = [
                'rowid'        => $record->qubaid . '-' . $record->slot,
                'userid'       => $record->userid,
                'fullname'     => fullname($user),
                'email'        => $user->email,
                'initials'     => strtoupper(substr($user->firstname, 0, 1) . substr($user->lastname, 0, 1)),
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
                'gradelabel'   => '—',
                'feedback'     => '',
            ];
        }

        if (empty($rows)) {
            $this->render_all_graded_state();
            return;
        }

        // Sort by student name
        usort($rows, function ($a, $b) {
            return strcasecmp($a['fullname'], $b['fullname']);
        });

        echo html_writer::start_div('aigrader-table-wrapper');
        
        // Card-based layout - each essay gets its own card
        echo '<div class="aigrader-cards">';

        foreach ($rows as $r) {
            // Look up Essay Guard score for this student (null if not installed or no data).
            $eg = $essayguard_scores[$r['userid']] ?? null;
            // FIX-EG-LEVEL-COMPUTE (v1.2.103): derive level from the stored score, not the
            // stored risklevel column. The stored column may have been written under old
            // thresholds (e.g. HIGH at 41), causing confusing labels like "HIGH 44/100".
            // analyser::risk_level() is the single source of truth — consistent with
            // student.php and lib.php which already use this approach.
            $eg_score  = $eg ? (int)round((float)($eg->riskscore ?? 0) * 100) : 0;
            $eg_level  = $eg ? \plagiarism_essayguard\local\service\analyser::risk_level($eg_score) : null;
            $eg_pastes = $eg ? (int)($eg->paste_events ?? 0) : 0;

            echo '<div class="aigrader-card" data-rowid="' . s($r['rowid']) . '" data-qubaid="' . s($r['qubaid']) . '" data-slot="' . s($r['slot']) . '" data-studentname="' . s(strtolower($r['fullname'])) . '" data-questiontext="' . s(strtolower($r['questionfull'])) . '" data-answer="' . s(strtolower($r['full'])) . '">';
            
            // Student header
            echo '<div class="aigrader-card-header ag-flex-between ag-flex-wrap ag-gap-sm">';
            echo '<div class="aigrader-student-inline ag-flex-center ag-gap-sm">';
            echo '<div class="aigrader-avatar-sm ag-flex-center">' . s($r['initials']) . '</div>';
            echo '<span class="aigrader-student-name">' . s($r['fullname']) . '</span>';
            echo '<span class="aigrader-student-email ag-text-muted">' . s($r['email']) . '</span>';
            // Essay Guard inline badge — only if data exists
            if ($eg_level !== null) {
                $detailurl = new moodle_url('/plagiarism/essayguard/student.php', [
                    'cmid'   => $r['cmid'],
                    'userid' => $r['userid'],
                ]);
                echo '<a href="' . $detailurl->out(false) . '" target="_blank"'
                    . ' class="ag-eg-badge ag-eg-badge-' . s($eg_level) . '"'
                    . ' title="Essay Guard: ' . s(ucfirst($eg_level)) . ' (' . $eg_score . '/100)">'
                    . '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                    . ' stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:3px;">'
                    . '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>'
                    . s(ucfirst($eg_level))
                    . '</a>';
            }
            echo '</div>';
            echo '<span class="aigrader-qnum-badge">' . s($r['questionnum']) . '</span>';
            echo '</div>';

            // Essay Guard advisory panel — medium and high get a visible warning.
            if ($eg_level === 'high') {
                $detailurl = new moodle_url('/plagiarism/essayguard/student.php', [
                    'cmid'   => $r['cmid'],
                    'userid' => $r['userid'],
                ]);
                $paste_note = $eg_pastes > 0
                    ? ' ' . $eg_pastes . ' paste event' . ($eg_pastes === 1 ? '' : 's') . ' detected.'
                    : '';
                echo '<div class="ag-eg-advisory ag-eg-advisory-high">'
                    . '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                    . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
                    . ' style="flex-shrink:0;margin-top:1px;">'
                    . '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>'
                    . '<line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
                    . '<span><strong>Writing integrity alert (High ' . $eg_score . '/100).</strong>'
                    . $paste_note
                    . ' This student\'s answer shows high authenticity risk. If you have concerns,'
                    . ' consider overriding the AI grade and leaving a comment asking the student'
                    . ' to rephrase the answer in their own words. &nbsp;'
                    . '<a href="' . $detailurl->out(false) . '" target="_blank"'
                    . ' style="color:inherit;font-weight:600;">View Essay Guard detail &rarr;</a>'
                    . '</span>'
                    . '</div>';
            } elseif ($eg_level === 'medium') {
                $detailurl = new moodle_url('/plagiarism/essayguard/student.php', [
                    'cmid'   => $r['cmid'],
                    'userid' => $r['userid'],
                ]);
                echo '<div class="ag-eg-advisory ag-eg-advisory-medium">'
                    . '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                    . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
                    . ' style="flex-shrink:0;margin-top:1px;">'
                    . '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
                    . '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
                    . '<span><strong>Writing integrity note (Medium ' . $eg_score . '/100).</strong>'
                    . ' Elevated authenticity risk indicators detected. Review before accepting the AI grade. &nbsp;'
                    . '<a href="' . $detailurl->out(false) . '" target="_blank"'
                    . ' style="color:inherit;font-weight:600;">View Essay Guard detail &rarr;</a>'
                    . '</span>'
                    . '</div>';
            }
            
            // Question section
            echo '<div class="aigrader-card-section aigrader-question-section">';
            echo '<div class="aigrader-section-label">' . get_string('question', 'quiz_aigrader') . '</div>';
            $formattedQuestion = $this->format_question_text($r['questionfull']);
            echo '<div class="aigrader-question-text">' . $formattedQuestion . '</div>';
            echo '</div>';
            
            // Answer section
            echo '<div class="aigrader-card-section aigrader-answer-section">';
            echo '<div class="aigrader-section-label">' . get_string('answer', 'quiz_aigrader') . '</div>';
            $formattedAnswer = $this->format_student_answer($r['rawanswer']);
            echo '<div class="aigrader-answer-text" data-raw="' . s($r['rawanswer']) . '">' . $formattedAnswer . '</div>';
            echo '</div>';
            
            // Feedback and Actions section - all essays are ungraded
            echo '<div class="aigrader-card-section aigrader-feedback-section">';
            echo '<div class="aigrader-initial-actions ag-flex-between ag-flex-wrap ag-gap-md" id="initial-actions-' . $r['rowid'] . '">';
            echo '<span class="ag-text-muted">' . get_string('not_yet_graded', 'quiz_aigrader') . '</span>';
            echo '<div class="aigrader-card-actions ag-flex-center ag-gap-md">';
            echo '<span class="aigrader-grade aigrader-grade-pending" id="grade-' . $r['rowid'] . '">—</span>';
            echo '<button class="aigrader-btn aigrader-btn-sm aigrader-btn-success aigrader-grade-btn ag-btn-base ag-btn-success"
                    data-qubaid="' . $r['qubaid'] . '"
                    data-slot="' . $r['slot'] . '"
                    data-cmid="' . $r['cmid'] . '"
                    data-maxmark="' . $r['maxmark'] . '"
                    data-questiontext="' . s($r['questionfull']) . '">' .
                    get_string('ai_grade', 'quiz_aigrader') . '</button>';
            echo '</div>';
            echo '</div>';
            echo '<div class="aigrader-feedback" id="feedback-' . $r['rowid'] . '" style="display: none;"></div>';
            echo '</div>';
            
            echo '</div>'; // End card
        }

        echo '</div>'; // End cards container
        echo html_writer::end_div();
    }
    
    /**
     * Format question text - just use Moodle's format_text, no other processing.
     */
    private function format_question_text($questionhtml) {
        return format_text($questionhtml, FORMAT_HTML);
    }
    
    /**
     * Format student answer - just use Moodle's format_text, no other processing.
     */
    private function format_student_answer($rawanswer) {
        return format_text($rawanswer, FORMAT_HTML);
    }
    
    /**
     * Show message when all essays have been graded.
     */
    private function render_all_graded_state() {
        echo html_writer::start_div('aigrader-empty aigrader-all-graded');
        echo '<svg class="aigrader-empty-icon" xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="none"
                stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
        echo html_writer::tag('h3', get_string('all_graded', 'quiz_aigrader'), ['class' => 'aigrader-empty-title']);
        echo html_writer::tag('p', get_string('all_graded_message', 'quiz_aigrader'), ['class' => 'aigrader-empty-message']);
        echo html_writer::end_div();
    }

    private function truncate($text) {
        $clean = trim(strip_tags($text));
        return (strlen($clean) > 200) ? substr($clean, 0, 200) . '…' : $clean;
    }

    private function is_essay_like($qtype) {
        return (
            $qtype === 'essay' ||
            $qtype === 'essayautograde' ||
            strpos($qtype, 'essay') !== false ||
            $qtype === 'pmatch' ||
            $qtype === 'recordrtc'
        );
    }

    private function render_empty_state() {
        echo html_writer::start_div('aigrader-empty');
        echo '<svg class="aigrader-empty-icon" xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="none"
                stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 
                0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>';
        echo html_writer::tag('h3', get_string('no_essays', 'quiz_aigrader'), ['class' => 'aigrader-empty-title']);
        echo html_writer::tag('p', get_string('no_essays_message', 'quiz_aigrader'), ['class' => 'aigrader-empty-message']);
        echo html_writer::end_div();
    }

    private function render_footer() {
        echo html_writer::start_div('aigrader-footer');
        echo html_writer::tag('span',
            get_string('powered_by', 'quiz_aigrader') . ' — ' .
            html_writer::link('https://lms-labs.com', 'lms-labs.com', ['target' => '_blank']),
            ['class' => 'aigrader-powered']
        );
        echo html_writer::end_div();
    }

    private function render_loading_overlay() {
        echo html_writer::start_div('aigrader-loading-overlay', ['id' => 'aigrader-loading']);
        echo html_writer::start_div('aigrader-loading-content');
        echo html_writer::tag('div', '', ['class' => 'aigrader-loading-spinner']);
        echo html_writer::tag('div', get_string('processing', 'quiz_aigrader'), ['class' => 'aigrader-loading-text']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}
