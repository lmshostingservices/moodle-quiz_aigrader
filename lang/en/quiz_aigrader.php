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
 * English language strings for the AI Essay Grader quiz report.
 *
 * @package    quiz_aigrader
 * @category   string
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// General.
$string['pluginname'] = 'AI Essay Grader';
$string['pluginname_help'] = 'AI Essay Grader automatically grades open-ended essay and short-answer quiz questions using AI, providing instant detailed feedback to students and saving teachers significant marking time.

The report screen shows all students who have submitted essay-type answers in a quiz, with a table of student name, question text, submitted answer, AI feedback, and grade. Teachers can grade all essays in one click, or grade individual responses. Re-grading is free — no credits are consumed for essays that have already been graded once.

AI feedback is competency-aware: for RTO/VET assessments, students who demonstrate the required criteria across attempts receive full marks with an Autolock notification. Responses close to passing are flagged for human assessor review to ensure fairness and consistency. A Competency notice makes clear to students that meeting criteria equals a pass, and suggestions are provided to support learning rather than reduce marks.

Students receive a Moodle notification (email and/or app message) when their essay has been graded, with a direct link to their attempt and feedback. Notification emails can be enabled or disabled site-wide. Analytics panels show total essays graded, grading time, average time per essay, and students graded per course — all filterable by date range and course. Credit cost: 1 credit per essay graded (re-grading is free).';
$string['aigrader'] = 'AI Essay Grader';
$string['aigraderreport'] = 'AI Essay Grader';

// Settings.
$string['siteid'] = 'Site ID';
$string['siteid_desc'] = 'Enter your Moodle site identifier (usually the full site URL) registered with Essay Grader AI.';
$string['apikey'] = 'API key';
$string['apikey_desc'] = 'Enter your API key from Essay Grader AI. You can find it in your account dashboard.';
$string['credits'] = 'Credits';

// Report header / footer.
$string['powered_by'] = 'AI Essay Grader';

// Not configured.
$string['not_configured'] = 'AI Essay Grader is not configured';
$string['not_configured_message'] = 'To use AI Essay Grader, go to <strong>Site administration &gt; Plugins &gt; Quiz reports &gt; AI Essay Grader</strong> and enter your Site ID and API key.';

// Table headings.
$string['student'] = 'Student';
$string['question'] = 'Question';
$string['answer'] = 'Answer';
$string['feedback'] = 'Feedback';
$string['grade'] = 'Grade';
$string['actions'] = 'Actions';
$string['quiz'] = 'Quiz';

// Buttons.
$string['refresh_credits'] = 'Refresh credits';
$string['grade_all'] = 'Grade all essays';
$string['regrade_all'] = 'Re-grade all';
$string['ai_grade'] = 'AI Grade';
$string['ai_regrade'] = 'Re-grade';
$string['show_all_responses'] = 'Show all responses';
$string['show_ungraded_only'] = 'Show ungraded only';
$string['search_student'] = 'Search student...';

// Empty / status messages.
$string['no_essays'] = 'No essay responses found';
$string['no_essays_message'] = 'There are currently no completed attempts with essay-style questions for this quiz.';
$string['not_yet_graded'] = 'Not yet graded';
$string['graded'] = 'Graded';
$string['grading_complete'] = 'AI grading complete';
$string['regrading_complete'] = 'Re-grading complete';
$string['regrading'] = 'Re-grading...';
$string['no_graded_essays'] = 'No graded essays to re-grade';
$string['no_graded_essays_message'] = 'There are no essays with existing AI feedback to re-grade. Use "Grade all essays" to grade new essays.';
$string['confirm_regrade'] = 'This will replace existing AI feedback for {$a} essays. Continue?';
$string['regrade_free'] = 'Free';
$string['regrade_free_hint'] = 'Re-grading is free - no credits are used since these essays were already graded';
$string['no_credits'] = 'No credits remaining';

// AJAX / error messages (used in JS + PHP).
$string['processing'] = 'Processing…';
$string['grading'] = 'Grading…';
$string['success'] = 'Done';

$string['error'] = 'Error';
$string['error_connection'] = 'Unable to connect to the Essay Grader AI service. Please check your server internet connection, firewall and DNS.';
$string['error_fetching_credits'] = 'Unable to fetch credit balance from the Essay Grader AI service.';
$string['error_grading'] = 'An error occurred while requesting an AI grade. Please try again.';

$string['credits_error'] = 'Credit lookup error';
$string['insufficient_credits'] = 'You do not have enough credits to grade this essay.';

// Backend-only error strings (ajax.php).
$string['notconfigured'] = 'AI Essay Grader is not configured. Please enter your Site ID and API key in the plugin settings.';
$string['connectionfailed'] = 'Connection to the Essay Grader AI service failed. Please check firewall and DNS settings.';
$string['invalidresponse'] = 'Received an invalid response from the Essay Grader AI service.';
$string['noanswer'] = 'No answer was found for this question attempt.';

// Rubric strings.
$string['rubric_hazard'] = 'Hazard identified';
$string['rubric_example'] = 'Workplace example';
$string['rubric_control'] = 'Control measure';

// UX text for expanding/collapsing answers.
$string['show_more'] = 'Show more';
$string['show_less'] = 'Show less';

// Privacy metadata for external API.
$string['privacy:metadata:essaygraderai_api'] = 'The Essay Grader AI service receives question text and student answers for grading.';
$string['privacy:metadata:essaygraderai_api:questiontext'] = 'The text of the essay question being graded.';
$string['privacy:metadata:essaygraderai_api:answer'] = 'The student\'s answer to the essay question.';

// Reference documents.
$string['reference_documents'] = 'Reference Documents';
$string['reference_documents_help'] = 'Upload industry documents (codes of practice, regulations, learning materials) to give AI grading more specific context. The AI will prioritize information from these documents in its feedback.';
$string['upload_document'] = 'Upload Document';
$string['upload_formats'] = 'PDF, Word (.docx), or Text files (max 10MB)';
$string['loading'] = 'Loading...';
$string['extracting_text'] = 'Extracting text from document...';
$string['no_documents'] = 'No reference documents uploaded for this quiz.';
$string['document_ready'] = 'Ready';
$string['document_processing'] = 'Processing...';
$string['document_failed'] = 'Failed';
$string['document_delete'] = 'Delete';
$string['document_delete_confirm'] = 'Are you sure you want to delete this document?';
$string['document_uploaded'] = 'Document uploaded and text extracted';
$string['document_upload_error'] = 'Failed to upload document';
$string['document_deleted'] = 'Document deleted';

// Grader approval workflow.
$string['pending_approval'] = 'Pending Approval';
$string['approve_save'] = 'Approve & Save to Gradebook';
$string['saving'] = 'Saving...';
$string['saved_to_gradebook'] = 'Saved';
$string['grade_saved'] = 'Grade and feedback saved to Moodle gradebook';
$string['grade_label'] = 'Grade';
$string['grade_0'] = 'No criteria met';
$string['grade_1'] = 'Basic understanding';
$string['grade_2'] = 'Good understanding';
$string['grade_3'] = 'Excellent understanding';

// Feedback language.
$string['feedback_language'] = 'Feedback Language';
$string['feedback_language_help'] = 'Select the language for AI-generated feedback. The AI will write all feedback in your chosen language.';

// Extra instructions.
$string['extra_instructions'] = 'Extra AI Instructions';
$string['extra_instructions_help'] = 'Add custom instructions to modify how the AI generates feedback. For example, you can specify language style, focus areas, or additional context.';
$string['extra_instructions_placeholder'] = 'e.g., "Use simpler language for beginner students" or "Focus on safety compliance with AS/NZS 4801"';
$string['save_instructions'] = 'Save Settings';
$string['instructions_saved'] = 'Saved';

// All graded state.
$string['all_graded'] = 'All essays have been graded';
$string['all_graded_message'] = 'There are no ungraded essay responses for this quiz. All feedback has been saved to the gradebook.';

// Grader activity report.
$string['grader_report'] = 'AI Essay Grader Activity Report';
$string['grader_report_desc'] = 'View grader approval statistics filtered by date range. Track how many essay questions each assessor has approved.';
$string['view_grader_report'] = 'View Grader Activity Report';
$string['grader'] = 'Grader';
$string['approved_count'] = 'Questions Approved';
$string['total_approved'] = 'Total Approved';
$string['active_graders'] = 'Active Graders';
$string['avg_per_grader'] = 'Avg per Grader';
$string['summary_by_grader'] = 'Summary by Grader';
$string['detailed_report'] = 'Detailed Report';
$string['all_graders'] = 'All Graders';
$string['startdate'] = 'Start Date';
$string['enddate'] = 'End Date';
$string['filter'] = 'Filter';
$string['download_csv'] = 'Download CSV';
$string['download_excel'] = 'Download Excel';
$string['download_pdf'] = 'Download PDF';
$string['no_data_for_period'] = 'No grading activity found for the selected date range.';

// Email scheduler.
$string['email_scheduler'] = 'Email Scheduler';
$string['frequency'] = 'Frequency';
$string['daily'] = 'Daily';
$string['weekly'] = 'Weekly';
$string['monthly'] = 'Monthly';
$string['cc_recipients'] = 'CC Recipients';
$string['cc_recipients_help'] = 'Comma-separated email addresses to CC on scheduled reports (e.g., finance@example.com)';
$string['enable_schedule'] = 'Enable scheduled email reports';
$string['save_schedule'] = 'Save Schedule';
$string['schedule_saved'] = 'Report schedule saved successfully';
$string['next_report'] = 'Next report';
$string['task_send_report'] = 'Send AI Essay Grader scheduled reports';

// Grading time statistics.
$string['grading_time_stats'] = 'Grading Time Statistics';
$string['filter_course'] = 'Course';
$string['filter_grader'] = 'Grader';
$string['filter_date_from'] = 'From';
$string['filter_date_to'] = 'To';
$string['all_courses'] = 'All Courses';
$string['apply_filters'] = 'Apply';
$string['total_essays_graded'] = 'Essays Graded';
$string['total_grading_time'] = 'Total Time';
$string['avg_time_per_essay'] = 'Avg per Essay';
$string['total_student_time'] = 'Total Time Spent';
$string['essays'] = 'Essays';
$string['time_spent'] = 'Time Spent';
$string['avg_per_essay'] = 'Avg/Essay';
$string['no_grading_data'] = 'No grading data available for the selected filters.';

// Students graded per course.
$string['students_graded_per_course'] = 'Students Graded per Course';
$string['students_graded'] = 'Students Graded';
$string['questions_graded'] = 'Questions Graded';
$string['total_students'] = 'Total Students';

// Attempt consistency and fairness (v3.54.0).
$string['autolock_feedback'] = 'This assessment is competency-based. You have demonstrated that you meet all the required criteria across your attempts. Full marks have been awarded.';
$string['humanreview_feedback'] = 'Your response meets the assessment criteria. This attempt has been flagged for assessor review to ensure fairness and consistency.';
$string['humanreview_notice'] = 'Note: This response has been flagged for human assessor review to ensure fairness.';
$string['competency_notice'] = 'This assessment is competency-based. If you meet the criteria, you pass. Suggestions are provided to help learning, not to reduce marks.';

// Student notifications (v3.58.6).
$string['messageprovider:grading_complete'] = 'Notification when essay has been graded';
$string['notification_subject'] = 'Your essay in "{$a->quizname}" has been graded';
$string['notification_body'] = 'Hi {$a->firstname},

Your essay response in "{$a->quizname}" has been graded.

Course: {$a->coursename}
Quiz: {$a->quizname}
Score: {$a->score}

You can view your feedback by clicking the link below:
{$a->attempturl}

Best regards,
{$a->sitename}';
$string['notification_body_html'] = '<p>Hi {$a->firstname},</p>

<p>Your essay response in <strong>{$a->quizname}</strong> has been graded.</p>

<p><strong>Course:</strong> {$a->coursename}<br>
<strong>Quiz:</strong> {$a->quizname}<br>
<strong>Score:</strong> {$a->score}</p>

<p><a href="{$a->attempturl}">View your feedback</a></p>

<p>Best regards,<br>{$a->sitename}</p>';
$string['enable_student_notifications'] = 'Notify students when graded';
$string['enable_student_notifications_desc'] = 'Send a notification to students when their essay has been graded and approved.';
$string['min_review_time'] = 'Minimum review time (seconds)';
$string['min_review_time_desc'] = 'Require graders to wait this many seconds before they can approve each essay. Set to 0 to disable. The approve button will show a countdown timer reminding the grader to carefully consider the student response and AI feedback.';


// Error messages returned by the AJAX endpoint.
$string['error_sessionexpired'] = 'Your session has expired. Please reload the page and try again.';
$string['error_missingparams'] = 'Required parameters are missing from the request.';
$string['error_invalidcoursemodule'] = 'The quiz could not be found.';
$string['error_notloggedin'] = 'You are not logged in.';
$string['error_nopermission'] = 'You do not have permission to perform this action.';
$string['error_invalidslot'] = 'The requested question is not part of this attempt.';
$string['error_loadfailed'] = 'The requested data could not be loaded.';
$string['error_savefailed'] = 'The changes could not be saved.';
$string['error_nofile'] = 'No file was received.';
$string['error_uploadfailed'] = 'The file could not be uploaded.';
$string['error_filetoolarge'] = 'The file is larger than the maximum allowed size.';
$string['error_invalidfiletype'] = 'That file type is not accepted. Allowed types are PDF, DOC, DOCX, TXT and MD.';
$string['error_missingdocid'] = 'No document was specified.';
$string['error_unknownaction'] = 'The requested action is not recognised.';
$string['error_servererror'] = 'The grading service could not be reached. Please try again shortly.';

// Gradebook verification warnings.
$string['gradebookwarning_noitem'] = 'The grade was saved, but no matching gradebook item was found for this quiz.';
$string['gradebookwarning_needsupdate'] = 'The grade was saved, but the gradebook has not finished updating.';
$string['gradebookwarning_nograde'] = 'The grade was saved, but no gradebook grade was recorded for this student.';
$string['gradebookwarning_verifyfailed'] = 'The grade was saved, but the gradebook entry could not be verified.';

// Settings.
$string['apiurl'] = 'API URL';
$string['apiurl_desc'] = 'Base URL of the Essay Grader AI service. Only change this if you have been given a different endpoint.';
$string['centralconfig'] = 'Central configuration';
$string['centralconfig_detected'] = 'AI Grader Central Config is installed on this site. The site ID and API key can be managed there: {$a}';
$string['centralconfig_notdetected'] = 'AI Grader Central Config is not installed. Enter the site ID and API key below.';
$string['centralconfig_fallback'] = 'If AI Grader Central Config is installed, its value is used instead of this one.';

// Reports and exports.
$string['avg_time_per_question'] = 'Avg time / question';
$string['daterange'] = 'Date range: {$a->from} - {$a->to}';
$string['seconds_suffix'] = '{$a}s';
$string['invalidfrequency'] = 'Invalid report frequency.';
$string['cc_recipients_placeholder'] = 'finance@example.com, manager@example.com';
$string['csv_daterange'] = 'Date range: {$a->from} - {$a->to}';
$string['csv_course'] = 'Course';
$string['csv_quiz'] = 'Quiz';
$string['csv_grader'] = 'Grader';
$string['csv_approved'] = 'Questions approved';
$string['csv_avgtime'] = 'Average time per question';
$string['report_email_subject'] = '{$a->period} AI Essay Grader activity report - {$a->date}';
$string['report_email_body'] = 'Please find attached your {$a->period} AI Essay Grader activity report.

Period: {$a->from} - {$a->to}
Total records: {$a->count}

This is an automated message from AI Essay Grader.';
$string['report_email_summary_line'] = '- {$a->name}: {$a->count} questions approved';

// Privacy API.
$string['privacy:path:attemptcontext'] = 'AI grading context';
$string['privacy:path:gradinglogs'] = 'AI grading activity';
$string['privacy:path:schedules'] = 'Scheduled activity reports';
$string['privacy:metadata:quiz_aigrader_attempt_ctx'] = 'Grading context retained for each student and question so that repeated attempts are graded consistently.';
$string['privacy:metadata:quiz_aigrader_attempt_ctx:quizid'] = 'The quiz the graded question belongs to.';
$string['privacy:metadata:quiz_aigrader_attempt_ctx:userid'] = 'The student whose answer was graded.';
$string['privacy:metadata:quiz_aigrader_attempt_ctx:questionid'] = 'The question that was graded.';
$string['privacy:metadata:quiz_aigrader_attempt_ctx:slot'] = 'The slot the question occupies in the attempt.';
$string['privacy:metadata:quiz_aigrader_attempt_ctx:attemptnum'] = 'The attempt number this context relates to.';
$string['privacy:metadata:quiz_aigrader_attempt_ctx:criteriamet'] = 'The rubric criteria the answer satisfied.';
$string['privacy:metadata:quiz_aigrader_attempt_ctx:feedbacksummary'] = 'A summary of the feedback previously given to the student.';
$string['privacy:metadata:quiz_aigrader_attempt_ctx:lastgrade'] = 'The most recent grade awarded, as a percentage.';
$string['privacy:metadata:quiz_aigrader_attempt_ctx:humanreview'] = 'Whether the answer was flagged for human review.';
$string['privacy:metadata:quiz_aigrader_attempt_ctx:autolocked'] = 'Whether the answer was automatically locked to full marks.';
$string['privacy:metadata:quiz_aigrader_attempt_ctx:timecreated'] = 'The time the grading context was created.';
$string['privacy:metadata:quiz_aigrader_attempt_ctx:timemodified'] = 'The time the grading context was last changed.';
$string['privacy:metadata:quiz_aigrader_grading_logs'] = 'A record of each grade approved by a marker, used for grading activity reporting.';
$string['privacy:metadata:quiz_aigrader_grading_logs:quizid'] = 'The quiz containing the graded question.';
$string['privacy:metadata:quiz_aigrader_grading_logs:courseid'] = 'The course containing the quiz.';
$string['privacy:metadata:quiz_aigrader_grading_logs:graderid'] = 'The user who approved the grade.';
$string['privacy:metadata:quiz_aigrader_grading_logs:qubaid'] = 'The question usage the graded question belongs to.';
$string['privacy:metadata:quiz_aigrader_grading_logs:slot'] = 'The slot the graded question occupies.';
$string['privacy:metadata:quiz_aigrader_grading_logs:timegraded'] = 'The time the grade was approved.';
$string['privacy:metadata:quiz_aigrader_schedules'] = 'Scheduled grading activity reports configured by a user.';
$string['privacy:metadata:quiz_aigrader_schedules:userid'] = 'The user who created the schedule.';
$string['privacy:metadata:quiz_aigrader_schedules:frequency'] = 'How often the report is sent.';
$string['privacy:metadata:quiz_aigrader_schedules:recipients'] = 'Additional email addresses the report is copied to.';
$string['privacy:metadata:quiz_aigrader_schedules:lastrun'] = 'The time the report was last sent.';
$string['privacy:metadata:quiz_aigrader_schedules:nextrun'] = 'The time the report is next due.';
$string['privacy:metadata:quiz_aigrader_schedules:enabled'] = 'Whether the schedule is active.';
$string['privacy:metadata:quiz_aigrader_schedules:format'] = 'The file format the report is sent in.';
$string['privacy:metadata:quiz_aigrader_schedules:timecreated'] = 'The time the schedule was created.';
$string['privacy:metadata:quiz_aigrader_schedules:timemodified'] = 'The time the schedule was last changed.';

// Date filter on the grading screen.
$string['filter_submitted_from'] = 'Submitted from';
$string['filter_submitted_to'] = 'Submitted to';
$string['filter_form_label'] = 'Filter outstanding essays by submission date';
$string['filter_apply'] = 'Apply';
$string['filter_clear'] = 'Clear filter';
$string['filter_saved_notice'] = 'Showing {$a}. This filter is saved and will be applied next time you open this page.';
$string['filter_range_between'] = 'submissions between {$a->from} and {$a->to}';
$string['filter_range_from'] = 'submissions from {$a} onwards';
$string['filter_range_to'] = 'submissions up to {$a}';
$string['filter_no_results'] = 'Nothing to mark in this date range';
$string['filter_no_results_message'] = 'There are no ungraded essay responses submitted in the selected date range. There may be outstanding work outside it.';

// Privacy API: user preferences.
$string['privacy:metadata:preference:datefrom'] = 'The start of the submission date range a marker last chose on the grading screen for a quiz.';
$string['privacy:metadata:preference:dateto'] = 'The end of the submission date range a marker last chose on the grading screen for a quiz.';
$string['privacy:metadata:preference:reportstartdate'] = 'The start of the date range last chosen on the grading activity report.';
$string['privacy:metadata:preference:reportenddate'] = 'The end of the date range last chosen on the grading activity report.';
$string['privacy:metadata:preference:reportgraderid'] = 'The marker last selected as a filter on the grading activity report.';
$string['privacy:preference:datefilter'] = 'The saved submission date filter for one quiz grading screen.';
$string['privacy:preference:reportstartdate'] = 'The saved start date for the grading activity report.';
$string['privacy:preference:reportenddate'] = 'The saved end date for the grading activity report.';
$string['privacy:preference:reportgraderid'] = 'The saved marker filter for the grading activity report.';

// Capabilities.
$string['aigrader:approve'] = 'Approve an AI suggested mark and save it to the gradebook';
$string['error_noapprovepermission'] = 'You do not have permission to approve grades in this quiz. An administrator can grant the "Approve an AI suggested mark and save it to the gradebook" capability (quiz/aigrader:approve) to your role.';

// Feedback card strings used by the AMD module.
$string['feedback_strengths'] = 'What you did well';
$string['feedback_improvements'] = 'What needs improvement';
$string['feedback_suggestions'] = 'How to improve your answer';
$string['feedback_other'] = 'Additional feedback';
$string['feedback_none'] = 'No feedback available';
$string['feedback_notrecorded'] = 'No feedback recorded';
$string['feedback_suppressed_notice'] = 'At full marks the "{$a}" section is not shown to the student. Everything else you write here is shown.';
$string['notgraded'] = 'Not graded';
$string['previousattempt'] = 'Previous attempt';
$string['attemptnumber'] = 'Attempt {$a}';
$string['attemptnumberofmax'] = 'Attempt {$a->num} of {$a->total}';
$string['edit_feedback'] = 'Edit feedback';
$string['update_preview'] = 'Update preview';
$string['review_countdown'] = 'Carefully consider student response and AI feedback';
