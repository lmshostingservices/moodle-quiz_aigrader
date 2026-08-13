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

// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU GPL v3.

defined('MOODLE_INTERNAL') || die();

/**
 * English language strings for AI Grader quiz report.
 *
 * @package    quiz_aigrader
 * @category   string
 * @copyright  2025 Essay Grader AI
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// General.
$string['pluginname'] = 'AI Essay Grader';
$string['pluginname_help'] = 'AI Essay Grader automatically grades open-ended essay and short-answer quiz questions using AI, providing instant detailed feedback to students and saving teachers significant marking time.

The report screen shows all students who have submitted essay-type answers in a quiz, with a table of student name, question text, submitted answer, AI feedback, and grade. Teachers can grade all essays in one click, or grade individual responses. Re-grading is free — no credits are consumed for essays that have already been graded once.

AI feedback is competency-aware: for RTO/VET assessments, students who demonstrate the required criteria across attempts receive full marks with an Autolock notification. Responses close to passing are flagged for human assessor review to ensure fairness and consistency. A Competency notice makes clear to students that meeting criteria equals a pass, and suggestions are provided to support learning rather than reduce marks.

Students receive a Moodle notification (email and/or app message) when their essay has been graded, with a direct link to their attempt and feedback. Notification emails can be enabled or disabled site-wide. Analytics panels show total essays graded, grading time, average time per essay, and students graded per course — all filterable by date range and course. Credit cost: 1 credit per essay graded (re-grading is free).';
$string['aigrader'] = 'AI Essay Grader';
$string['aigraderreport'] = 'AI Essay Grader';
$string['privacy:metadata'] = 'The AI Essay Grader quiz report plugin does not store any personal data itself. It sends question text and student responses to the Essay Grader AI service for grading.';

// Settings.
$string['siteid'] = 'Site ID';
$string['siteid_desc'] = 'Enter your Moodle site identifier (usually the full site URL) registered with Essay Grader AI.';
$string['apikey'] = 'API key';
$string['apikey_desc'] = 'Enter your API key from Essay Grader AI. You can find it in your account dashboard.';
$string['credits'] = 'Credits';

// Report header / footer.
$string['powered_by'] = 'Powered by Essay Grader AI';

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
$string['buy_credits'] = 'Buy credits';

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

// Group filter.
$string['filter_group_label'] = 'Filter by group:';
$string['filter_all_groups'] = 'All groups';
$string['filter_apply'] = 'Apply';
$string['filter_clear_group'] = 'Clear group filter';
