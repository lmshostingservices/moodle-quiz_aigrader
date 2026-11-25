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
 * AI Essay Grader quiz report display class.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
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
 * Main AI Essay Grader quiz report class.
 */
class quiz_aigrader_report extends quiz_aigrader_report_base {
    /**
     * Display the AI Essay Grader report.
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

        // Which feedback sections a learner on full marks receives. Applied in the browser
        // when the card is built, so the editor shows exactly what will be delivered.
        $fullmarksfeedback = get_config('quiz_aigrader', 'fullmarks_feedback');
        if (!in_array($fullmarksfeedback, ['strengths', 'nosuggestions', 'all'], true)) {
            $fullmarksfeedback = 'strengths';
        }

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
                'fullMarksFeedback' => $fullmarksfeedback,
                // Diagnostic logging is off unless the site is in developer debug mode.
                // The module's log lines carry the graded response and the feedback with
                // it, which is a learner's work and must not reach the browser console of
                // every assessor on a production site.
                'debug' => (bool)debugging('', DEBUG_DEVELOPER),
            ]]
        );

        // The editor in the Marking criteria check, where an assessor confirms each question's
        // criteria before they drive the breakdown.
        if (\quiz_aigrader\local\confirmed::required() && has_capability('quiz/aigrader:confirmcriteria', $context)) {
            $limits = \quiz_aigrader\local\criteria::limits();
            $PAGE->requires->js_call_amd('quiz_aigrader/criteria_editor', 'init', [[
                'cmid' => $cm->id,
                'sesskey' => sesskey(),
                'ajaxurl' => $CFG->wwwroot . '/mod/quiz/report/aigrader/ajax.php',
                'min' => $limits['min'],
                'max' => $limits['max'],
            ]]);
        }

        // Use Moodle's standard quiz report header (includes tabs/navigation).
        $this->print_header_and_tabs($cm, $course, $quiz, 'aigrader');

        $this->render_container_start();
        $this->render_header($quiz, $context);
        $this->render_paused_notice();
        // Setup and statistics fold away under one line, so the marking queue is the first thing
        // an assessor sees. Until 5.2.0 three open panels sat above the first essay.
        echo html_writer::start_tag('details', ['class' => 'aigrader-setup', 'id' => 'aigrader-setup']);
        echo html_writer::tag(
            'summary',
            html_writer::span(get_string('setup_heading', 'quiz_aigrader'), 'aigrader-setup-title')
                . html_writer::span(get_string('setup_hint', 'quiz_aigrader'), 'aigrader-setup-hint'),
            ['class' => 'aigrader-setup-summary']
        );
        echo html_writer::start_div('aigrader-setup-body');
        $this->render_grading_stats();
        $this->render_document_section($quiz);
        $this->render_responsible_use();
        echo html_writer::end_div();
        echo html_writer::end_tag('details');
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
        $classes = 'aigrader-container' . (\quiz_aigrader\local\transparency::paused() ? ' aigrader-paused' : '');
        echo html_writer::start_div(\quiz_aigrader\local\design::root_classes($classes), [
            'id' => 'aigrader-root',
            'data-ajaxurl' => $ajaxurl,
        ]);
    }

    /**
     * Say so, at the top of the report, when the site has paused AI suggestions.
     *
     * An MDS warning alert: graders need to know why the AI Grade buttons are gone and that
     * marking by hand still works.
     *
     * @return void
     */
    private function render_paused_notice() {
        if (!\quiz_aigrader\local\transparency::paused()) {
            return;
        }
        echo html_writer::div(
            '<span class="mds-alert-icon-wrap" aria-hidden="true"><span class="mds-alert-icon">'
                . '<i class="fa-solid fa-circle-pause"></i></span></span>'
                . '<div class="mds-alert-content"><div class="mds-alert-text" role="status">'
                . html_writer::tag('p', get_string('aipaused_title', 'quiz_aigrader'), ['class' => 'mds-alert-title'])
                . html_writer::tag('p', get_string('aipaused_message', 'quiz_aigrader'), ['class' => 'mds-alert-message'])
                . '</div></div>',
            'aigrader-paused-notice mds-alert alert mds-alert--warning alert-warning'
        );
    }

    /**
     * Guidance on using AI suggestions well, in the setup panel.
     *
     * Supports the AI literacy of the people who mark with the plugin (EU AI Act, Article 4):
     * what the suggestion is, what it is not, and what the grader stays responsible for.
     *
     * @return void
     */
    private function render_responsible_use() {
        $items = '';
        foreach (['check', 'criteria', 'limits', 'bias', 'learners', 'report'] as $key) {
            $items .= html_writer::tag('li', get_string('responsible_' . $key, 'quiz_aigrader'));
        }
        echo html_writer::start_div('aigrader-responsible ag-card');
        echo html_writer::tag(
            'h2',
            '<i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> ' . get_string('responsible_heading', 'quiz_aigrader'),
            ['class' => 'aigrader-section-title']
        );
        echo html_writer::tag('ul', $items, ['class' => 'aigrader-responsible-list']);
        echo html_writer::end_div();
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
        echo '<i class="fa-solid fa-pen-nib ag-icon-lg" aria-hidden="true"></i>';
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
        echo '<i class="fa-solid fa-coins ag-icon-xs" aria-hidden="true"></i>';
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
            'h2',
            '<i class="fa-regular fa-file-lines" aria-hidden="true"></i> '
            . get_string('reference_documents', 'quiz_aigrader'),
            ['class' => 'aigrader-documents-title']
        );
        echo html_writer::tag(
            'button',
            '<i class="fa-solid fa-chevron-down" aria-hidden="true"></i>',
            [
                'class' => 'aigrader-documents-toggle',
                'id' => 'aigrader-documents-toggle',
                'type' => 'button',
                'aria-label' => get_string('reference_documents', 'quiz_aigrader'),
                'aria-controls' => 'aigrader-documents-content',
                'aria-expanded' => 'true',
            ]
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
            '<i class="fa-solid fa-upload" aria-hidden="true"></i> '
            . get_string('upload_document', 'quiz_aigrader'),
            ['for' => 'aigrader-doc-input', 'class' => 'aigrader-upload-btn mds-btn btn btn-secondary mds-btn--size-md']
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
            '<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> '
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

        // Feedback language dropdown.
        echo html_writer::start_div('aigrader-language-form');
        echo html_writer::tag(
            'label',
            get_string('feedback_language', 'quiz_aigrader'),
            ['for' => 'aigrader-feedback-language', 'class' => 'aigrader-language-label']
        );
        // One list for the site default and every quiz, named in the viewer's own language.
        $languages = \quiz_aigrader\local\formatter::feedback_languages();
        echo html_writer::start_tag(
            'select',
            ['id' => 'aigrader-feedback-language', 'class' => 'aigrader-language-select custom-select form-select']
        );
        foreach ($languages as $code => $name) {
            echo html_writer::tag('option', s($name), ['value' => $code]);
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
            '<i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> '
            . get_string('save_instructions', 'quiz_aigrader'),
            [
                'id' => 'aigrader-save-instructions',
                'type' => 'button',
                'class' => 'aigrader-btn mds-btn btn btn-secondary mds-btn--size-md',
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
        // A keyboard control as well as a click target: role, focus and the expanded state.
        echo html_writer::start_div('aigrader-stats-header', [
            'id' => 'aigrader-stats-toggle',
            'role' => 'button',
            'tabindex' => '0',
            'aria-expanded' => 'true',
            'aria-controls' => 'aigrader-stats-content',
        ]);
        echo html_writer::tag(
            'span',
            '<i class="fa-regular fa-clock" aria-hidden="true"></i> '
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
            ['id' => 'aigrader-filter-course', 'class' => 'aigrader-filter-select custom-select form-select']
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
            ['id' => 'aigrader-filter-grader', 'class' => 'aigrader-filter-select custom-select form-select']
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
            'class' => 'aigrader-btn mds-btn btn btn-secondary mds-btn--size-lg',
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
            'class' => 'aigrader-date-filter-apply mds-btn btn btn-secondary mds-btn--size-lg',
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
            // An MDS info alert without a title: it says what the queue shows, nothing to act on.
            echo html_writer::div(
                '<span class="mds-alert-icon-wrap" aria-hidden="true"><span class="mds-alert-icon">'
                . '<i class="fa-solid fa-circle-info"></i></span></span>'
                . '<div class="mds-alert-content"><div class="mds-alert-text" role="status">'
                . html_writer::tag(
                    'p',
                    get_string('filter_saved_notice', 'quiz_aigrader', $this->describe_date_filter($datefrom, $dateto)),
                    ['class' => 'mds-alert-message']
                )
                . '</div></div>',
                'aigrader-date-filter-notice mds-alert alert mds-alert--info alert-info mds-alert--no-title'
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
            '<i class="fa-solid fa-rotate ag-icon-sm" aria-hidden="true"></i> '
            . get_string('refresh_credits', 'quiz_aigrader'),
            [
                'id' => 'aigrader-refresh-btn',
                'type' => 'button',
                'class' => 'aigrader-btn mds-btn btn btn-secondary mds-btn--size-lg',
            ]
        );

        echo html_writer::tag(
            'button',
            '<i class="fa-solid fa-bolt ag-icon-sm" aria-hidden="true"></i> '
            . get_string('grade_all', 'quiz_aigrader')
            . ' <span id="aigrader-essay-count"'
            . ' class="aigrader-count-badge mds-badge badge mds-badge--primary mds-badge--pill">0</span>',
            [
                'id' => 'aigrader-gradeall-btn',
                'type' => 'button',
                'title' => get_string('grade_all_hint', 'quiz_aigrader'),
                'class' => 'aigrader-btn mds-btn btn btn-primary mds-btn--size-lg',
            ]
        );

        // Search box.
        echo html_writer::start_div('aigrader-search-wrapper ag-flex-center');
        echo '<i class="fa-solid fa-magnifying-glass aigrader-search-icon ag-icon-sm" aria-hidden="true"></i>';
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'id' => 'aigrader-search',
            'class' => 'aigrader-search-input',
            'placeholder' => get_string('search_student', 'quiz_aigrader'),
            'value' => optional_param('search', '', PARAM_TEXT),
            'title' => get_string('search_hint', 'quiz_aigrader'),
            'aria-label' => get_string('search_student', 'quiz_aigrader'),
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
                    q.id AS questionid,
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
                  AND qza.state = 'finished'
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

        // Email addresses appear only where the site's identity fields include them and the
        // assessor may see identity fields in this context.
        $showemail = in_array('email', \core_user\fields::get_identity_fields($context), true);

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

        // Attachment-bearing responses.
        //
        // An essay set to "attachments only", or one where the learner wrote nothing and
        // uploaded a document, has no 'answer' step data row at all. Until 4.4.0 those rows
        // were dropped by the empty-answer test below, so the assessor was shown "All essays
        // have been graded" while that learner's work sat unmarked and unnoticed - the exact
        // failure the empty state was written to prevent. VET assessments routinely require
        // an uploaded workplace document, so this was not an edge case.
        $attachmentsbyqatid = [];
        if (!empty($qatids)) {
            [$attinsql, $attparams] = $DB->get_in_or_equal($qatids, SQL_PARAMS_NAMED);
            $attachmentrows = $DB->get_records_sql(
                "SELECT qasd.id, qas.questionattemptid, qasd.value AS attachmentvalue
                   FROM {question_attempt_steps} qas
                   JOIN {question_attempt_step_data} qasd
                     ON qasd.attemptstepid = qas.id AND qasd.name = 'attachments'
                  WHERE qas.questionattemptid $attinsql
                  ORDER BY qas.sequencenumber DESC",
                $attparams
            );
            foreach ($attachmentrows as $at) {
                if (
                    !isset($attachmentsbyqatid[$at->questionattemptid])
                        && trim((string)$at->attachmentvalue) !== ''
                ) {
                    $attachmentsbyqatid[$at->questionattemptid] = true;
                }
            }
        }

        $rows = [];
        // One entry per question in the queue, for the marking criteria check.
        $criteriaquestions = [];

        foreach ($needsgrading as $record) {
            $rawanswer = $answersbyqatid[$record->questionattemptid] ?? '';
            $hasattachments = !empty($attachmentsbyqatid[$record->questionattemptid]);
            // Skip only a response that is genuinely empty. One with attachments is listed
            // so the assessor can see and mark it, even though the AI cannot read it.
            if (trim(strip_tags($rawanswer)) === '' && !$hasattachments) {
                continue;
            }

            $user = $users[$record->userid] ?? null;
            if (!$user) {
                continue;
            }

            $cleananswer = trim(strip_tags($rawanswer));
            $questiontext = $record->questiontext;

            // One entry per question and mark; the question's fields are fetched once below.
            $checkkey = $record->questionid . '-' . format_float((float)$record->maxmark, 2);
            if (!isset($criteriaquestions[$checkkey])) {
                $criteriaquestions[$checkkey] = [
                    'questionid' => (int)$record->questionid,
                    'slot' => (int)$record->slot,
                    'maxmark' => (float)$record->maxmark,
                ];
            }

            $rows[] = [
                'rowid'        => $record->qubaid . '-' . $record->slot,
                'userid'       => $record->userid,
                // Plain text here and escaped once with s() where it is printed. Passing it
                // through format_string() as well escaped it twice, so "Smith & Jones"
                // showed as "Smith &amp; Jones".
                'fullname'     => fullname($user),
                // Only where the site shows email addresses to this assessor: the user
                // identity setting and moodle/site:viewuseridentity, as on core reports.
                'email'        => $showemail ? $user->email : '',
                'questionnum'  => 'Q' . $record->slot,
                'questiontext' => $this->truncate($questiontext),
                'questionfull' => strip_tags($questiontext),
                // The question with its line breaks, for the marking card. Criteria are often
                // written into the question text, and with the tags stripped they ran
                // together: "safety.Marking Criteria (2 marks total)1 mark: ...".
                'questiondisplay' => format_text(
                    strip_tags($questiontext, '<p><br><div><ul><ol><li><strong><b><em><i><u><sup><sub><span>'
                        . '<table><thead><tbody><tr><td><th>'),
                    FORMAT_HTML,
                    ['context' => $context]
                ),
                'truncated'    => $this->truncate($rawanswer),
                'full'         => $cleananswer,
                'rawanswer'    => $rawanswer,
                'qubaid'       => $record->qubaid,
                'slot'         => $record->slot,
                'cmid'         => $cm->id,
                'maxmark'      => $record->maxmark,
                // Marked so the row can say plainly that AI grading is unavailable and the
                // response must be read and marked by hand.
                'hasattachments'  => $hasattachments,
                'attachmentonly'  => $hasattachments && $cleananswer === '',
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

        // Search across every page, not only the one on screen: name, question and answer.
        $search = trim(optional_param('search', '', PARAM_TEXT));
        if ($search !== '') {
            $needle = core_text::strtolower($search);
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                return strpos(core_text::strtolower($r['fullname']), $needle) !== false
                    || strpos(core_text::strtolower($r['questionfull']), $needle) !== false
                    || strpos(core_text::strtolower($r['full']), $needle) !== false;
            }));
        }

        // One page of responses at a time. A large cohort sent every response and question
        // at once: 1,000 learners made a 16 MB page that took seconds to arrive and to draw.
        $perpageoptions = [25, 50, 100, 250];
        $perpage = optional_param('perpage', 50, PARAM_INT);
        if (!in_array($perpage, $perpageoptions, true)) {
            $perpage = 50;
        }
        $total = count($rows);
        $page = max(0, optional_param('page', 0, PARAM_INT));
        if ($page * $perpage >= $total) {
            $page = 0;
        }
        $rows = array_slice($rows, $page * $perpage, $perpage);
        $pageurl = new moodle_url(qualified_me());
        $pageurl->remove_params('page');

        // Queue layout: a compact table, one row per response, each expanding in place to
        // the full marking card. The previous layout rendered every response as an open
        // card, so a quiz with 150 attempts was 150 expanded cards deep and there was no
        // way to scan the cohort. The expanded card is unchanged - it is simply behind a
        // row now - so nothing about how an essay is marked has changed.
        $egcolumn = $essayguardavailable;
        $colcount = $egcolumn ? 6 : 5;

        // The fields the criteria are read from, once per question rather than once per response.
        if ($criteriaquestions) {
            $qids = array_unique(array_column($criteriaquestions, 'questionid'));
            [$qinsql, $qparams] = $DB->get_in_or_equal($qids, SQL_PARAMS_NAMED);
            $fields = $DB->get_records_sql(
                "SELECT q.id, q.questiontext, q.generalfeedback, q.defaultmark, eo.graderinfo,
                        qv.version, qv.questionbankentryid
                   FROM {question} q
              LEFT JOIN {qtype_essay_options} eo ON eo.questionid = q.id
              LEFT JOIN {question_versions} qv ON qv.questionid = q.id
                  WHERE q.id $qinsql",
                $qparams
            );
            foreach ($criteriaquestions as $k => $q) {
                $f = $fields[$q['questionid']] ?? null;
                $criteriaquestions[$k] += [
                    'questiontext' => (string)($f->questiontext ?? ''),
                    'generalfeedback' => (string)($f->generalfeedback ?? ''),
                    'graderinfo' => (string)($f->graderinfo ?? ''),
                    'defaultmark' => (float)($f->defaultmark ?? 0),
                    'version' => (int)($f->version ?? 0),
                    'entryid' => (int)($f->questionbankentryid ?? 0),
                ];
            }
        }
        $this->render_criteria_check($criteriaquestions, $cm, $context);

        $this->render_queue_paging_summary($pageurl, $search, $page, $perpage, $perpageoptions, $total);
        if ($total === 0) {
            echo html_writer::div(
                s(get_string('search_nomatch', 'quiz_aigrader', $search)) . ' '
                    . html_writer::link(new moodle_url($pageurl, ['search' => '']), get_string('search_clear', 'quiz_aigrader')),
                'aigrader-search-nomatch alert alert-info mds-alert'
            );
            return;
        }

        global $OUTPUT;

        $templaterows = [];
        foreach ($rows as $r) {
            // The Essay Guard score for this learner, null if the plugin is absent or has no data.
            // The level is derived from the stored score by the analyser, not read from the stored
            // risklevel column, which may have been written under older thresholds.
            $eg = $essayguardscores[$r['userid']] ?? null;
            $egcontext = null;
            if ($eg && $essayguardavailable) {
                $egscore = (int) round((float) ($eg->riskscore ?? 0) * 100);
                $egpastes = (int) ($eg->paste_events ?? 0);
                $eglevel = (string) \plagiarism_essayguard\local\service\analyser::risk_level($egscore);
                $eglabel = get_string_manager()->string_exists('essayguard_level_' . $eglevel, 'quiz_aigrader')
                    ? get_string('essayguard_level_' . $eglevel, 'quiz_aigrader')
                    : ucfirst($eglevel);
                $egurl = (new moodle_url('/plagiarism/essayguard/student.php', [
                    'cmid' => $r['cmid'],
                    'userid' => $r['userid'],
                ]))->out(false);
                $advisory = null;
                if ($eglevel === 'high' || $eglevel === 'medium') {
                    $advice = get_string('essayguard_' . $eglevel . '_advice', 'quiz_aigrader');
                    if ($eglevel === 'high' && $egpastes === 1) {
                        $advice = get_string('essayguard_paste', 'quiz_aigrader') . ' ' . $advice;
                    } else if ($eglevel === 'high' && $egpastes > 1) {
                        $advice = get_string('essayguard_pastes', 'quiz_aigrader', $egpastes) . ' ' . $advice;
                    }
                    $advisory = [
                        'level' => $eglevel,
                        'heading' => get_string('essayguard_' . $eglevel, 'quiz_aigrader', $egscore),
                        'advice' => $advice,
                        'url' => $egurl,
                    ];
                }
                $egcontext = [
                    'level' => $eglevel,
                    'label' => $eglabel,
                    'score' => $egscore,
                    'url' => $egurl,
                    'title' => get_string('essayguard_badge_title', 'quiz_aigrader', (object) [
                        'level' => $eglabel,
                        'score' => $egscore,
                    ]),
                    'advisory' => $advisory,
                ];
            }

            $templaterows[] = [
                'rowid' => $r['rowid'],
                'qubaid' => (int) $r['qubaid'],
                'slot' => (int) $r['slot'],
                'cmid' => (int) $r['cmid'],
                'maxmark' => (string) $r['maxmark'],
                'fullname' => $r['fullname'],
                'email' => $r['email'],
                'studentname_lc' => core_text::strtolower($r['fullname']),
                'questiontext_lc' => core_text::strtolower($r['questionfull']),
                'answer_lc' => core_text::strtolower($r['full']),
                'questionnum' => $r['questionnum'],
                'questionfull' => $r['questionfull'],
                'truncated' => $r['truncated'],
                'slotlabel' => get_string('essay_question_n', 'quiz_aigrader', $r['slot']),
                // Both already passed through format_text(), which cleans them.
                'questionhtml' => $r['questiondisplay'],
                'answerhtml' => $this->format_student_answer(
                    $this->answer_with_file_urls($r['rawanswer'], (int) $r['qubaid'], (int) $r['slot'], $context),
                    $context
                ),
                'attachmentonly' => !empty($r['attachmentonly']),
                'eg' => $egcontext,
            ];
        }

        // Rendered from a template so a theme can override the queue (5.2.0).
        echo $OUTPUT->render_from_template('quiz_aigrader/queue', [
            'essayguard' => $egcolumn,
            'colcount' => $colcount,
            'rows' => $templaterows,
        ]);
        echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
    }

    /**
     * The line above the queue: how many responses are shown, the page size, and the pages.
     *
     * @param moodle_url $pageurl This page's address, without the page number.
     * @param string $search The active search, '' for none.
     * @param int $page The page shown, from 0.
     * @param int $perpage Responses per page.
     * @param int[] $perpageoptions The page sizes offered.
     * @param int $total Responses matching the filters.
     * @return void
     */
    private function render_queue_paging_summary(
        moodle_url $pageurl,
        string $search,
        int $page,
        int $perpage,
        array $perpageoptions,
        int $total
    ) {
        global $OUTPUT;
        echo html_writer::start_div('aigrader-queue-summary');
        if ($total > 0) {
            echo html_writer::div(get_string('queue_showing', 'quiz_aigrader', (object)[
                'from' => $page * $perpage + 1,
                'to' => min($total, ($page + 1) * $perpage),
                'total' => $total,
            ]), 'aigrader-queue-count');
        }
        if ($search !== '') {
            echo html_writer::div(
                s(get_string('search_active', 'quiz_aigrader', $search)) . ' '
                    . html_writer::link(new moodle_url($pageurl, ['search' => '']), get_string('search_clear', 'quiz_aigrader')),
                'aigrader-queue-search'
            );
        }
        $options = [];
        foreach ($perpageoptions as $n) {
            $options[$n] = $n;
        }
        $selecturl = new moodle_url($pageurl);
        $selecturl->remove_params('perpage');
        $select = new single_select(
            $selecturl,
            'perpage',
            $options,
            $perpage,
            null,
            'aigrader-perpage'
        );
        $select->set_label(get_string('queue_perpage', 'quiz_aigrader'));
        $select->class = 'aigrader-perpage';
        echo $OUTPUT->render($select);
        echo html_writer::end_div();
        echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
    }

    /**
     * Turn the stored @@PLUGINFILE@@ placeholders in a learner's answer into real file URLs.
     *
     * An image or file the learner embedded in their essay is stored against the response step,
     * and the stored text holds only a placeholder. Until 5.2.0 the marking card printed the
     * placeholder, so the image showed as broken. The question usage is loaded only for an
     * answer that actually holds one, so the queue stays one query for every other response.
     *
     * @param string $rawanswer The answer as stored.
     * @param int $qubaid The question usage id.
     * @param int $slot The slot in that usage.
     * @param context $context The quiz context the files belong to.
     * @return string The answer with working file URLs.
     */
    private function answer_with_file_urls(string $rawanswer, int $qubaid, int $slot, $context): string {
        if (strpos($rawanswer, '@@PLUGINFILE@@') === false) {
            return $rawanswer;
        }
        try {
            $qa = question_engine::load_questions_usage_by_activity($qubaid)->get_question_attempt($slot);
            $step = $qa->get_last_step_with_qt_var('answer');
            return $qa->rewrite_response_pluginfile_urls($rawanswer, $context->id, 'answer', $step);
        } catch (\Throwable $e) {
            debugging('AI Essay Grader: response file URLs not rewritten: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $rawanswer;
        }
    }

    /**
     * Format student answer - just use Moodle's format_text, no other processing.
     *
     * @param string $rawanswer The raw answer HTML.
     * @param context $context The quiz context, so filters run as they do on the review page.
     * @return string The formatted HTML.
     */
    private function format_student_answer($rawanswer, $context) {
        return format_text($rawanswer, FORMAT_HTML, ['context' => $context]);
    }

    /**
     * Show a message when all essays have been graded.
     *
     * @return void
     */
    private function render_all_graded_state() {
        echo html_writer::start_div('aigrader-empty aigrader-all-graded');
        echo '<i class="fa-regular fa-circle-check aigrader-empty-icon" aria-hidden="true"></i>';
        echo html_writer::tag('h2', get_string('all_graded', 'quiz_aigrader'), ['class' => 'aigrader-empty-title']);
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
            'h2',
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
     * Show which questions in the queue have marking criteria, before any marking starts.
     *
     * Grading reads each question's criteria from Information for graders, the question text
     * or General feedback. This shows the result of that for every question in the queue, so
     * a question with no criteria, too many, or criteria that do not add up to its mark is
     * found and fixed before any learner is marked against it, not discovered card by card.
     * Collapsed when every question is in order; open when any needs attention.
     *
     * @param array $questions One entry per question: questionid, slot, questiontext,
     *     generalfeedback, graderinfo, maxmark.
     * @param \stdClass $cm The quiz course module.
     * @param \context $context The quiz context.
     * @return void
     */
    private function render_criteria_check(array $questions, $cm, $context) {
        global $PAGE;

        if (empty($questions)) {
            return;
        }
        usort($questions, function ($a, $b) {
            return $a['slot'] <=> $b['slot'];
        });

        $policy = \quiz_aigrader\local\criteria::policy();
        $attention = ['too_many', 'too_few', 'total_mismatch', 'declared_mismatch'];
        $confirming = \quiz_aigrader\local\confirmed::required();
        $canconfirm = $confirming && has_capability('quiz/aigrader:confirmcriteria', $context);

        // Responses can span versions of a question (each edit is a new version, and attempts
        // keep the version they were started on), and a question in random slots can carry
        // different marks. Each is its own row and its own confirmation, labelled so the
        // assessor can tell them apart.
        $versions = [];
        $marks = [];
        foreach ($questions as $q) {
            $entry = $q['entryid'] ?? 0;
            $versions[$entry][$q['version'] ?? 0] = true;
            $marks[$q['questionid']][\quiz_aigrader\local\criteria::fmt($q['maxmark'])] = true;
        }

        $rowshtml = '';
        $issues = 0;
        $shown = 0;
        $exact = 0;
        foreach ($questions as $q) {
            try {
                $resolved = \quiz_aigrader\local\criteria::for_fields(
                    $q['graderinfo'],
                    $q['questiontext'],
                    $q['generalfeedback'],
                    $q['maxmark'],
                    $q['defaultmark']
                );
                $state = \quiz_aigrader\local\confirmed::state(
                    (int)$cm->instance,
                    (int)$q['questionid'],
                    \quiz_aigrader\local\confirmed::fields_hash(
                        $q['graderinfo'],
                        $q['questiontext'],
                        $q['generalfeedback'],
                        $q['defaultmark']
                    ),
                    $q['maxmark']
                );
            } catch (\Throwable $e) {
                debugging('AI Essay Grader: criteria check failed for question ' . $q['questionid'] . ': '
                    . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }

            $none = $resolved['confidence'] === \quiz_aigrader\local\criteria::NONE;
            $uncertain = $resolved['confidence'] === \quiz_aigrader\local\criteria::UNCERTAIN;
            $isconfirmed = $state['state'] === \quiz_aigrader\local\confirmed::STATE_CONFIRMED;
            $isstale = $state['state'] === \quiz_aigrader\local\confirmed::STATE_STALE;
            $notes = [];
            if (!$isconfirmed) {
                foreach (\quiz_aigrader\local\criteria::warning_messages($resolved['warnings']) as $w) {
                    // With confirmation on, why the guidance cannot be used as written does not
                    // matter: nothing is used until the assessor confirms it.
                    if ($w['code'] !== 'none_found' && !($confirming && $w['code'] === 'uncertain')) {
                        $notes[] = $w['message'];
                    }
                }
            }

            if ($confirming) {
                $flagged = !$isconfirmed;
                if ($isstale) {
                    $notes[] = get_string('criteriacheck_stale_note', 'quiz_aigrader');
                } else if (!$isconfirmed && $none) {
                    $notes[] = get_string('criteriacheck_none_confirm', 'quiz_aigrader');
                } else if (!$isconfirmed && $uncertain) {
                    $notes[] = \quiz_aigrader\local\confirmed::proposal($resolved, null)
                        ? get_string('criteriacheck_uncertain_confirm_note', 'quiz_aigrader')
                        : get_string('criteriacheck_noproposal', 'quiz_aigrader');
                }
            } else {
                if ($none) {
                    $notes[] = get_string('criteriacheck_policy_' . $policy, 'quiz_aigrader');
                }
                if ($uncertain) {
                    $notes[] = get_string('criteriacheck_uncertain_note', 'quiz_aigrader');
                }
                $flagged = $none || $uncertain || array_intersect(array_column($resolved['warnings'], 'code'), $attention);
            }
            if ($flagged) {
                $issues++;
            }

            $shown++;
            // Lines joined with spaces and list markers dropped: one readable sentence.
            $plain = trim(preg_replace(
                '/\s*\n\s*(?:[*\-\x{2022}]\s+)?/u',
                ' ',
                \quiz_aigrader\local\formatter::rubric_text($q['questiontext'])
            ));
            $label = html_writer::span('Q' . $q['slot'], 'aigrader-critcheck-q')
                . ' ' . html_writer::span(s(shorten_text($plain, 90)), 'aigrader-critcheck-qtext');
            $tags = [];
            if (!empty($q['entryid']) && count($versions[$q['entryid']] ?? []) > 1) {
                $tags[] = get_string('criteriacheck_version', 'quiz_aigrader', (int)$q['version']);
            }
            if (count($marks[$q['questionid']] ?? []) > 1) {
                $tags[] = get_string('criteriacheck_atmark', 'quiz_aigrader', \quiz_aigrader\local\criteria::fmt($q['maxmark']));
            }
            if ($tags) {
                $label .= ' ' . html_writer::span(s(implode(' · ', $tags)), 'aigrader-critcheck-tag');
            }

            // Checked per question: in Moodle 5 a question can live in a shared question bank,
            // where the quiz's own permissions say nothing about who may edit it.
            $canedit = false;
            try {
                $canedit = question_has_capability_on($q['questionid'], 'edit');
            } catch (\Throwable $e) {
                $canedit = false;
            }
            if ($canedit) {
                $editurl = new moodle_url('/question/bank/editquestion/question.php', [
                    'id' => $q['questionid'],
                    'cmid' => $cm->id,
                    'returnurl' => $PAGE->url->out_as_local_url(false),
                ]);
                $label .= ' ' . html_writer::link(
                    $editurl,
                    get_string('criteriacheck_edit', 'quiz_aigrader'),
                    ['class' => 'aigrader-critcheck-edit']
                );
            }

            $source = '&mdash;';
            if (!$none) {
                $source = s(get_string('criteriasource_' . $resolved['source'], 'quiz_aigrader'));
                $source = core_text::strtoupper(core_text::substr($source, 0, 1)) . core_text::substr($source, 1);
            }

            // What the breakdown would use, or what is proposed for confirmation.
            $listed = [];
            if ($confirming) {
                $listed = $isconfirmed
                    ? $state['criteria']
                    : array_map(function ($c) {
                        return ['label' => $c['label'], 'max' => $c['max']];
                    }, \quiz_aigrader\local\confirmed::proposal($resolved, null));
            } else if (!$none && !$uncertain) {
                $listed = $resolved['criteria'];
            }

            if ($confirming) {
                if ($isconfirmed) {
                    $count = html_writer::span(
                        get_string('criteriacheck_confirmed', 'quiz_aigrader', count($state['criteria'])),
                        'aigrader-critcheck-pill mds-badge badge mds-badge--subtle mds-badge--success'
                    );
                } else {
                    $count = html_writer::span(
                        get_string($isstale ? 'criteriacheck_reconfirm' : 'criteriacheck_toconfirm', 'quiz_aigrader'),
                        'aigrader-critcheck-pill aigrader-critcheck-pill-attention'
                            . ' mds-badge badge mds-badge--subtle mds-badge--warning'
                    );
                }
            } else if ($none) {
                $count = html_writer::span(
                    get_string('criteriacheck_none', 'quiz_aigrader'),
                    'aigrader-critcheck-pill aigrader-critcheck-pill-attention'
                        . ' mds-badge badge mds-badge--subtle mds-badge--warning'
                );
            } else if ($uncertain) {
                $count = html_writer::span(
                    get_string('criteriacheck_uncertain', 'quiz_aigrader'),
                    'aigrader-critcheck-pill aigrader-critcheck-pill-attention'
                        . ' mds-badge badge mds-badge--subtle mds-badge--warning'
                );
            } else {
                $count = html_writer::span(
                    (string)count($resolved['criteria']),
                    'aigrader-critcheck-pill mds-badge badge mds-badge--subtle'
                        . ($flagged ? ' aigrader-critcheck-pill-attention mds-badge--warning' : ' mds-badge--success')
                );
            }

            $noteshtml = '';
            if ($listed) {
                $labels = array_map(function ($c) {
                    $weight = isset($c['max']) && $c['max'] !== null
                        ? ' <span class="aigrader-critcheck-weight">'
                            . s(\quiz_aigrader\local\criteria::fmt((float)$c['max'])) . '</span>'
                        : '';
                    return s($c['label']) . $weight;
                }, $listed);
                $lead = $confirming && !$isconfirmed
                    ? '<span class="aigrader-critcheck-lead">' . get_string('criteriacheck_proposed', 'quiz_aigrader')
                        . '</span> '
                    : '';
                $noteshtml .= '<span class="aigrader-critcheck-labels">' . $lead . implode(' &middot; ', $labels) . '</span>';
            }
            if ($isconfirmed && $state['record']) {
                $confirmer = core_user::get_user((int)$state['record']->confirmedby);
                $confirmedby = get_string(
                    ($state['record']->method ?? '') === 'bulk' ? 'criteriacheck_bulkby' : 'criteriacheck_confirmedby',
                    'quiz_aigrader',
                    (object)[
                    'name' => $confirmer ? fullname($confirmer) : get_string('unknownuser'),
                    'date' => userdate((int)$state['record']->timeconfirmed, get_string('strftimedatefullshort', 'langconfig')),
                    ]
                );
                $noteshtml .= '<span class="aigrader-critcheck-by">' . s($confirmedby) . '</span>';
            }
            if ($notes) {
                $noteshtml .= '<ul class="aigrader-critcheck-notes">';
                foreach ($notes as $note) {
                    $noteshtml .= '<li>' . s($note) . '</li>';
                }
                $noteshtml .= '</ul>';
            }

            $attrs = ['class' => 'aigrader-critcheck-row' . ($flagged ? ' aigrader-critcheck-flagged' : '')];
            if ($canconfirm) {
                $readexact = !$isconfirmed && $resolved['confidence'] === \quiz_aigrader\local\criteria::CERTAIN;
                if ($readexact) {
                    $exact++;
                }
                $editorrows = \quiz_aigrader\local\confirmed::proposal($resolved, $isconfirmed ? $state['record'] : null);
                $attrs += [
                    'data-questionid' => (int)$q['questionid'],
                    'data-slot' => (int)$q['slot'],
                    'data-maxmark' => \quiz_aigrader\local\criteria::fmt($q['maxmark']),
                    'data-state' => $state['state'],
                    'data-since' => $state['record'] ? (int)$state['record']->timeconfirmed : 0,
                    'data-exact' => $readexact ? '1' : '0',
                    'data-proposal' => json_encode($editorrows, JSON_UNESCAPED_UNICODE),
                ];
                $noteshtml .= '<div class="aigrader-critcheck-actions">'
                    . html_writer::tag(
                        'button',
                        get_string($isconfirmed ? 'criteriacheck_editconfirmed' : 'criteriacheck_confirm', 'quiz_aigrader'),
                        ['type' => 'button', 'class' => 'aigrader-critcheck-review mds-btn btn mds-btn--size-md'
                            . ($isconfirmed ? ' btn-secondary' : ' btn-outline-primary aigrader-critcheck-review-primary')]
                    )
                    . '</div>';
            }

            $rowshtml .= html_writer::start_tag('tr', $attrs)
                . '<td>' . $label . '</td>'
                . '<td class="aigrader-critcheck-statecell">' . $count . '</td>'
                . '<td>' . $source . '</td>'
                . '<td class="aigrader-critcheck-notescell">' . $noteshtml . '</td>'
                . '</tr>';
        }

        if ($rowshtml === '') {
            return;
        }

        $total = $shown;
        if ($confirming) {
            $summary = $issues
                ? get_string('criteriacheck_issues_confirm', 'quiz_aigrader', (object)['issues' => $issues, 'total' => $total])
                : get_string('criteriacheck_ok_confirm', 'quiz_aigrader', $total);
        } else {
            $summary = $issues
                ? get_string('criteriacheck_issues', 'quiz_aigrader', (object)['issues' => $issues, 'total' => $total])
                : get_string('criteriacheck_ok', 'quiz_aigrader', $total);
        }

        // The editor's wording, read by the criteria_editor module from the panel itself.
        $stringsattr = '';
        if ($canconfirm) {
            $editorstrings = [];
            foreach (
                [
                'critedit_add', 'critedit_cancel', 'critedit_criterion', 'critedit_error_duplicate',
                'critedit_error_label', 'critedit_error_mark', 'critedit_error_total', 'critedit_failed',
                'critedit_hint', 'critedit_intro', 'critedit_marks', 'critedit_remove', 'critedit_reset', 'critedit_scale',
                'critedit_save', 'critedit_saving', 'critedit_total', 'critedit_notconfirmed', 'criteriacheck_confirm',
                'criteriacheck_confirmed', 'criteriacheck_editconfirmed', 'criteriacheck_toconfirm',
                'criteriacheck_ok_confirm', 'criteriacheck_issues_confirm', 'criteriacheck_proposed',
                ] as $id
            ) {
                $editorstrings[$id] = get_string($id, 'quiz_aigrader');
            }
            $stringsattr = ' data-strings="' . s(json_encode($editorstrings, JSON_UNESCAPED_UNICODE)) . '"';
        }
        echo '<details class="aigrader-critcheck' . ($issues ? ' aigrader-critcheck-has-issues' : '') . '"'
            . ' id="aigrader-critcheck"' . $stringsattr . ($issues ? ' open' : '') . '>';
        echo '<summary class="aigrader-critcheck-summary">'
            . '<span class="aigrader-critcheck-title">' . get_string('criteriacheck_heading', 'quiz_aigrader') . '</span>'
            . '<span class="aigrader-critcheck-status">' . s($summary) . '</span>'
            . '</summary>';
        echo '<div class="aigrader-critcheck-body">';
        if ($confirming) {
            echo '<p class="aigrader-critcheck-intro">' . get_string('criteriacheck_intro_confirm', 'quiz_aigrader') . '</p>';
        }
        if ($canconfirm && $exact > 0) {
            echo '<div class="aigrader-critcheck-bulk">'
                . html_writer::tag(
                    'button',
                    get_string('criteriacheck_confirmall', 'quiz_aigrader', $exact),
                    ['type' => 'button', 'class' => 'aigrader-critcheck-confirmall mds-btn btn btn-primary mds-btn--size-md']
                )
                . '<span class="aigrader-critcheck-bulkhint">' . get_string('criteriacheck_confirmall_hint', 'quiz_aigrader')
                . '</span></div>';
        }
        echo '<table class="aigrader-critcheck-table">';
        echo '<thead><tr>'
            . '<th scope="col">' . get_string('criteriacheck_question', 'quiz_aigrader') . '</th>'
            . '<th scope="col">' . get_string('criteriacheck_criteria', 'quiz_aigrader') . '</th>'
            . '<th scope="col">' . get_string('criteriacheck_source', 'quiz_aigrader') . '</th>'
            . '<th scope="col">' . get_string('criteriacheck_notes', 'quiz_aigrader') . '</th>'
            . '</tr></thead><tbody>' . $rowshtml . '</tbody></table></div>';
        echo '</details>';
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
        echo html_writer::start_div(\quiz_aigrader\local\design::root_classes('aigrader-loading-overlay'), [
            'id' => 'aigrader-loading',
            'role' => 'status',
            'aria-live' => 'polite',
        ]);
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
