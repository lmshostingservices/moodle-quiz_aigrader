# Changelog - AI Essay Grader Quiz Report Plugin

All notable changes to this plugin will be documented in this file.

## [3.9.8] - 2026-08-17

### Fixed
- **CRITICAL: AI-approved grades never reached the gradebook.** After a teacher clicked
  "Approve & Save to Gradebook", the quiz attempt displayed the correct mark (e.g.
  `28.00/28.00 (100%)`) but the course gradebook showed `-` for that student — permanently,
  on every quiz using AI grading, across all courses. Marking the same question through
  Moodle's own Manual grading screen worked correctly, as did a quiz regrade, which is
  what masked the defect in testing.

  **Root cause.** `ajax.php` (approve action) called:

  ```php
  \mod_quiz\grade_calculator::create($quizobj)->recompute_final_grade();
  ```

  with no argument. The Moodle signature is:

  ```php
  public function recompute_final_grade(?int $userid = null, array $attempts = []): void
  ```

  and its first action is:

  ```php
  if (empty($userid)) { $userid = $USER->id; }
  ```

  `recompute_final_grade()` does **not** inherit the userid from the `quiz_settings`
  object passed to `create()`, even though that object was constructed with the
  student's id via `quiz_settings::create($quiz->id, $attempt->userid)`. That userid
  is used for access and context resolution only.

  Every approve therefore recomputed the grade of the **logged-in teacher**, who has no
  attempts at the quiz. `compute_final_grade_from_attempts([])` returned null, which sent
  execution down the `is_null($bestgrade)` branch and issued a `DELETE` against the
  teacher's `quiz_grades` row. The student's `quiz_grades` and `grade_grades` rows were
  never written at all.

  The attempt's `sumgrades` was updated correctly a few lines earlier by the plugin's own
  code, which is why the quiz UI showed the right mark and only the gradebook was wrong —
  the two are written by separate code paths.

  **Regression origin.** The legacy `quiz_save_best_grade($quiz, $attempt->userid)`
  fallback always passed the userid correctly. The defect was introduced in v3.8.3 when
  the Moodle 4.2+ `grade_calculator` branch was added and the userid argument was not
  carried across. Sites running Moodle < 4.2 took the legacy branch and were unaffected.

  **Fix.** Pass `$attempt->userid` explicitly to `recompute_final_grade()`.

### Added
- **Gradebook write verification.** After the grade push, the plugin now reads the grade
  back from `grade_grades` and returns the outcome in the approve JSON response as
  `gradebookverified` (bool) and `gradebookwarning` (string|null). It also checks for a
  missing grade item and for `grade_items.needsupdate` being set. A failed gradebook write
  can no longer present to the teacher as a success.

  Deliberately non-fatal: the question mark and feedback are already committed to the
  question engine at that point, so a verification failure is reported as a warning rather
  than aborting the response and losing the teacher's work. Failures are also written to
  the Moodle debug log at `DEBUG_DEVELOPER`.

### Notes
- No DB schema changes. No AMD rebuild required. `version.php` → `2026081700`.
- **Remediation required for existing data.** This fix applies to grades approved from
  now on. Attempts approved under v3.8.3–v3.9.7 have correct `sumgrades` but no gradebook
  row, and must be repaired by regrading the affected quizzes
  (Quiz → Results → select all → Regrade selected attempts). Cohorts finalised from
  gradebook figures during that window may have understated results.

## [3.8.7] - 2026-04-23

### Fixed
- **AMD invalid regex causing RequireJS crash and AI credits not loading**: The section-detection
  regexes in `aigrader.js` / `aigrader.min.js` used unescaped bracket notation for icon names
  (`[chart-down]`, `[thumbs-up]`, `[tip]`). Inside a JavaScript regex character class `[...]`,
  the hyphen in `[chart-down]` was parsed as a backwards character range (`t`=116 to `d`=100),
  producing an `Invalid regular expression: Range out of order in character class` SyntaxError.
  This error was thrown while RequireJS loaded `first.js`, causing the entire AMD module chain to
  abort with "No define call for core/first" — hiding Moodle navigation and preventing
  `block_aiplugin_nav/credits` from initialising (AI credits showed as 0 / not visible).
  Fix: Escaped all bracket-icon references to `\[chart-down\]`, `\[thumbs-up\]`, `\[tip\]` in
  both detection regexes and strip regexes. Strip patterns updated from the invalid
  `/^[[icon]\s]+/` form to the correct `/^(\[icon\]|\s)+/` alternation form.
  No PHP, DB schema, or functional changes. AMD build files (aigrader.js + aigrader.min.js)
  updated. version.php → 2026042300387.

## [3.6.7] - 2026-03-11

### Performance
- Eliminated N+1 `question_engine::load_questions_usage_by_activity()` calls in the essay table renderer. Previously one heavy multi-query Moodle API call was made per student attempt; answer text, question text, and max mark are now fetched across all students in two bulk SQL queries. A 100-student quiz that previously took 30+ seconds now loads in under 2 seconds.
- Replaced correlated subquery `SELECT MAX(sequencenumber) WHERE questionattemptid = qa.id` on `question_attempt_steps` with a LEFT JOIN anti-pattern, eliminating per-row nested scans.
- Replaced per-student `core_user::get_user()` loop with a single `get_records_list()` bulk query.

## [3.6.6] - 2026-03-07

### Added
- Essay Guard risk badges now appear on every student card in the grader. Low, Mild, Medium, and High colour-coded pill badges link to the Essay Guard student detail page. Medium and High risk students also show a contextual advisory panel with guidance for the assessor.
- Graceful degradation: badge code is silently skipped when Essay Guard is not installed.

## [3.58.6] - 2026-02-04

### Added
- **Student Notifications**: Students now receive automatic notifications when their essay has been graded
  - Moodle popup notifications enabled by default
  - Email notifications enabled by default
  - Includes score, quiz name, course name, and direct link to view feedback
  - Configurable via Site Admin → Plugins → Quiz reports → AI Essay Grader
- **New Setting**: "Notify students when graded" toggle in plugin settings (enabled by default)

## [3.58.5] - 2026-02-04

### Changed
- **Streamlined Prompt v4.0**: Reduced grading prompt from ~220 to ~60 lines for faster, more deterministic grading
- **5 Core Rules Engine**: All grading decisions now follow 5 explicit rules:
  1. Binary Scoring: MET or NOT MET, no partial marks
  2. Mismatch Rule: Holistic grading when GRADER_INFO criteria exceed MAX_MARKS
  3. Forbidden Deductions: Cannot deduct for "could be expanded" style feedback
  4. Industry-Specific Gate: ≥1 workplace term = automatic pass
  5. Missing-Proof Rule: Must quote missing content before deducting
- **Fixed "2/3 Grading Bug"**: When rubric mentions more points than actual marks (e.g., 3 criteria for 1 mark), AI now grades holistically—if majority of criteria addressed → full marks

## [3.56.2] - 2026-01-02

### Fixed
- **Bullet Consistency**: Comprehensive bullet stripping regex covers 30+ Unicode bullet types (bullets, triangles, squares, dashes, arrows, middle dots)
- **Double Bullet Handling**: Repeated stripping loop handles cases like "• • text" or "- - item"
- **Consistent Rendering**: All feedback items now display with uniform filled bullet character (•)

## [3.56.1] - 2025-12-28

### Changed
- **UI Polish**: Neutral dark gray bullets instead of colored circles (feedback boxes already provide color distinction)

## [3.56.0] - 2025-12-28

### Added
- **Audit Logging**: Rejected criteria IDs logged via Moodle debugging for AI drift diagnosis
- **Question-Specific Criteria**: Support for `allowedCriteria` from API to freeze criteria per question
- **Mandatory Human Review**: Attempt ≥5 now triggers mandatory human review (ASQA-friendly)

## [3.55.0] - 2025-12-28

### Added
- **ChatGPT Defensive Fixes**: 4 safeguards to prevent AI misbehavior
  - Validate `criteriaMet` is always an array (malformed data protection)
  - Clamp `feedbackSummary` to 300 chars (prevent prompt bloat)
  - Trust criteria over score (if all criteria met → 100%)
  - Validate criteria IDs against 15 known RTO criteria (prevent hallucinations)

### Changed
- Criteria validation now filters against: explain_process, example_provided, site_specific, hazard_identified, control_measure, procedure_followed, legislation_referenced, workplace_context, practical_application, communication_clear, documentation_complete, safety_awareness, risk_assessment, compliance_demonstrated, knowledge_applied

## [3.54.0] - 2025-12-28

### Added
- **Attempt Consistency System**: Stores criteria_met and feedback_summary per question attempt to prevent contradictory feedback between attempts
- **Auto-Lock Rule**: After 3 attempts with all criteria met, automatically awards full marks (competency-based assessment)
- **Human Review Flag**: After 4 attempts without improvement, flags for assessor review to ensure fairness
- **Attempt Context API**: Passes previouslyMetCriteria and previousFeedbackSummary to grading API for consistent feedback
- New database table `quiz_aigrader_attempt_ctx` for tracking attempt history

### Changed
- API payload now includes `attemptContext` object with attempt number and previous grading history
- Enhanced response handling to track and persist criteria met across attempts
- Improved fairness messaging for competency-based assessments

## [3.53.2] - 2025-12-22

### Changed
- Added official Moodle 5.x compatibility declaration (`$plugin->supported = [400, 500]`)



## [3.53.0] - 2025-12-20

### Changed
- Migrated to centralized download architecture
- Updated versioned ZIP filename

## [3.50.0] - 2025-12-01

### Added
- Enhanced grading interface
- Bulk grading operations
- Improved feedback generation

## [1.0.0] - 2025-01-01

### Added
- Initial release
- AI-powered essay grading
- Rubric-based assessment
- Moodle 4.0+ compatibility
