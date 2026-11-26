# Changelog

## 5.3.0 - 2026-11-24

Version: `2026112600`. The screens are rebuilt on the Moodle Design System (MDS), finished in the
LMS Labs premium style. No database changes. Tested on Moodle 4.5, 5.1 and 5.3, light and dark.

## Added: bring your own API key

- **Bring your own API key (BYOK).** With an LMS Labs BYOK subscription (USD $500 plus GST a year
  for this plugin, or the All plugins plan) and AI Grader Central Config (local_aiconfig 1.1.0 or later) set so
  Text runs on Moodle AI or the site's own key, essays are graded on the site itself with the same
  prompt, rubric rules and feedback format as LMS Labs credits. The learner's answer goes from the
  site straight to the site's own AI provider. No credits are used, the credit balance shows as
  unlimited, and no Client Portal usage is reported for those grades. If the provider fails, the
  grader sees a clear message and no credits are spent as a fallback.
- Privacy metadata for the question and answer sent to the site's own AI provider.
- A **Bring your own API key** panel in the plugin settings shows where the site stands (not
  subscribed, subscribed but on credits, using your own AI) and links to subscribe for this plugin
  alone at US$500 plus GST a year. The grading screen shows *AI: Your own key* instead of a credit
  count.
- Every decision records which AI made the suggestion (`own-ai:<provider>`), and Suggestion
  accuracy is split by the AI used, so a site can compare its own AI with LMS Labs (EU AI Act,
  Articles 12 and 15).
- Known limitation: reference documents uploaded to LMS Labs are only used when grading on credits.

## Fixed: grading service failures

- A refused key, a server error or a reply with no readable score was shown as a suggested mark
  of zero with an Approve button. It is now an error that says what went wrong, and the row stays
  ready to grade.
- "Grade all essays" stops at the first failure that would repeat for every essay (key refused,
  no credits, suggestions paused, service down) and says why. When only some fail, it reports how
  many were graded and how many were not, instead of reporting all as graded.
- Running out of credits now shows the credits message.

## Fixed: activity report dates

- **Activity report stopped at the time a filter was applied.** Choosing an end date of today saved
  that exact time, so approvals made later the same day were left out of the report until the
  date was changed. Today now means "up to now", and the report keeps rolling forward.

## Added: suggestion accuracy

- The activity report shows how the AI's suggested marks compared with the marks graders approved,
  by learner language: approved suggestions, average difference (as a share of the full mark), and
  how often the AI was higher or lower. This is the accuracy evidence for the instructions for use
  (EU AI Act, Articles 13 and 15), and shows whether second-language writers are marked differently.

## Large cohorts, accessibility

- The grading queue is paged: 50 responses by default (25, 50, 100 or 250), with Moodle's paging
  bar. With 1,000 learners the page went from 16.6 MB and 3.7 s to 0.5 MB and 0.6 s; database
  queries stay flat as the cohort grows. Press Enter in the search box to search every page.
- Timeouts say the service took too long, instead of pointing at firewall settings.
- Accessibility: no axe-core WCAG 2.2 AA violations on any screen, light or dark. Fixed: a
  missing button name, a skipped heading level, an unlabelled feedback box, dark-mode button
  contrast (white on blue now 4.9:1) and blue text on dark surfaces. The statistics panel opens
  from the keyboard, and every control shows a visible focus ring.

## Wording and learner card

- Labels are in sentence case, as Moodle asks ("Approve and save to gradebook", "Grading time
  statistics"), and the learner's feedback sections read "What you did well", "What needs
  improvement" and "Next steps".
- The learner feedback heading no longer says "your grading criteria" (it read as the learner's own
  criteria); it now says "marked against the criteria for this question". Older feedback with the
  previous wording is still recognised.
- The learner feedback card keeps its look in every theme: its layout table carries its own border
  reset, so Boost's table borders no longer show inside it.

## Security and privacy

- Approved feedback is cleaned on the server before it is saved, because Moodle shows question
  comments without cleaning them again. The learner feedback card was rebuilt from markup that
  survives cleaning (no SVG or flex layout), so it looks the same.
- The learner notice cannot be suppressed by feedback that already contains an element of its
  class, and a suggestion made for an earlier attempt is no longer treated as AI help on a later one.
- The API key is stored encrypted (existing keys are encrypted on upgrade) and is no longer written
  into the settings page. The service address must use https (plain http only to this machine),
  and redirects are never followed, so the key cannot be forwarded to another host.
- Records are removed when the quiz, attempt or user they describe is deleted, with a daily task
  for what Moodle's events do not cover (a quiz deleted with its course, a course reset). When a
  grader is deleted, the learner's decision record stays, without the grader.
- Privacy metadata now lists every stored field and everything sent to the grading service.
- Write actions are accepted only as POST; the unused legacy approve action was removed; grading
  statistics, which cover the whole course, need a course-level capability; service replies are
  reduced to known, cleaned fields before they reach the browser; unused code removed.

## Added: responsible AI (EU AI Act)

- Learners are told when AI drafted their feedback (only when the service made a suggestion for that response): one line, in the learner's language, naming the grader who approved it. Added on the server at approval, marked with the class `quiz-aigrader-ai-notice`. On by default (setting *Tell learners when AI drafted their feedback*).
- New setting *Pause AI suggestions* stops every suggestion on the site at once; graders keep marking by hand and the AI Grade buttons are hidden while paused.
- "Using AI suggestions well" panel on the grading screen: responsibility, limits, bias, criteria and how to report problems.
- Activity report: new "Marks changed by graders" tile for human oversight.
- Wording: the plugin now says "grader" throughout, where it mixed "assessor" and "grader".
- New `docs/eu-ai-act.md`: classification, dates, provider and deployer duties, feature matrix and instructions for use.

## Changed
- **Moodle Design System throughout.** Every button is an MDS Button (primary, secondary, ghost,
  outline-primary, outline-danger; md by default, lg beside 42px fields), every status an MDS Badge
  and every message an MDS Alert. Colours, radii, spacing and type come from MDS tokens; the
  plugin's own `--ag-*` tokens are now aliases of the MDS semantic tokens.
- **Moodle blue and Font Awesome.** The Google-blue accent is replaced by Moodle blue (#0f6cbf),
  and the inline SVG icons on the grading screens by Font Awesome 6, the icon set Moodle ships.
  The feedback written to the learner keeps its inline styles, as before.
- **Row status is a badge**: Pending, Grading, Graded, Approved and Mark by hand each have their
  own MDS Badge variant. Question numbers, attempt numbers, document status, criteria counts and
  the feedback language are badges too.
- **Fields** follow the MDS Input: 42px, 8px radius, a darker border on hover and a soft focus halo.
- **Dropdowns** carry Moodle's own select classes, so the theme draws the same chevron as core on
  every supported version (4.4 to 5.3).
- **Confirmations are Moodle toasts** (`core/toast`) and disappear by themselves; problems are MDS
  danger or warning alerts and stay until dismissed, so an error is never gone before it is read.
- The submitted-date notice is an MDS info alert.

### Added
- **Dark mode on Moodle 5.3.** From 5.3 core ships the MDS tokens and a dark palette, and the plugin
  uses them, so it follows the site's light or dark colour mode. On earlier releases the plugin
  supplies the tokens itself (`\quiz_aigrader\local\design`, class `aigrader-mds-fallback`).
- The MDS Button, Badge, Alert and Close button styles (@moodlehq/design-system 5.5.0, GPL-3.0),
  scoped to the plugin's containers so they never restyle the rest of the site. Moodle does not
  allow `data:` URLs in CSS, so their icons are Font Awesome glyphs in the markup.
- PHPUnit tests for the design system support. 159 tests in total.

## 5.2.0 - 2026-11-24

Version: `2026112400`. Every recommendation from the 5.1.0 production readiness audit, built and
tested on Moodle 5.1 and 5.3. No database changes. **Needs the LMS Labs grading service from
23 November 2026 or later**, which accepts the API key as a Bearer header.

### Security
- **The API key never travels in a URL or a request body.** It is sent only as an
  `Authorization: Bearer` header, so proxy and server access logs cannot keep it.
- **Approving a mark is a Moodle web service** (`quiz_aigrader_approve_mark`), called through
  `core/ajax`. Moodle checks the session, the login and every parameter's type before the
  plugin's own capability, group and attempt checks run. The approval logic moved out of
  `ajax.php` into `\quiz_aigrader\local\approval`, shared by both paths.

### Added
- Behat features: the marking queue for an editing teacher, separate groups for an assessor,
  and the folded setup panel.
- End-to-end PHPUnit tests for approval: gradebook, decision record, out-of-range marks, other
  groups, unfinished attempts and learners. 157 tests in total.
- CI runs on Moodle 5.3 (PostgreSQL 17 and MariaDB 11.4) as well as 4.4 to 5.2 and main.

### Changed
- **The marking queue comes first.** Grading statistics, reference documents and extra AI
  instructions fold under one "Grading setup and statistics" line, remembered per browser.
- The marking queue is rendered from a Mustache template (`quiz_aigrader/queue`), so themes can
  override it. The hidden copy of every raw answer was dropped, halving the page for large cohorts.
- **One feedback language list** for the site default and every quiz (58 languages), named in the
  viewer's own language from Moodle's language and country lists. The language pill on a
  suggestion now names the quiz's feedback language, not the assessor's interface language.
- One product name (AI Essay Grader) and one copyright holder (LMS Hosting Services) throughout.
- CI workflow no longer carries jobs for another plugin. The unused `quiz_aigrader.php` stub is gone.

### Fixed
- Images and files a learner embedded in an essay now show on the marking card instead of a
  broken placeholder.
- A second element with the same id on each card (`grade-<row>`) was removed.

## 5.1.0 - 2026-11-23

Version: `2026112300`. Production readiness release for Moodle partner review: a line-by-line
security, privacy and standards audit, with every finding fixed or documented. No database changes.

### Security
- **The grading endpoint now enforces the same access rules as the report page.** Suggesting or
  approving a mark is refused for an attempt that is not finished, for a learner outside every
  group the assessor can access under separate groups, and for any question that is not an essay.
  Before, a direct request could mark an in-progress attempt or a learner in another group.
- **A reference document can only be deleted from the quiz it belongs to.** The service identifies
  documents by site, so an assessor could delete another course's marking guide by its id.
- A grading service reply outside the question's scale (for example "7/5") is clamped before it is
  offered to the assessor. Redirects on service calls are capped at three.
- The learner's name is escaped in the HTML body of the "marking complete" notification.
- The document list escapes the status the service returns before it reaches a class attribute.

### Privacy
- **Assessment decisions are now found, exported and erased for both the learner and the
  assessor.** The decisions table was declared and deleted, but never used to find a user's
  contexts nor exported, so a learner marked by hand (no AI suggestion) could not be found.
- The attempt context export now includes the AI's suggested mark, feedback and criteria.
- Email addresses on the marking queue respect the site's identity fields and
  `moodle/site:viewuseridentity`, as core reports do.

### Fixed
- The "Processing..." loading overlay had no style that reached it and showed permanently under
  the report. It is now a proper full-page overlay, hidden until grading runs, and announced to
  screen readers.
- Names containing "&" were escaped twice on the marking queue.
- Essay Guard alerts were hard-coded English; every string is now in the language pack.
- Learner responses are formatted in the quiz context, so filters run as on the review page.
- Reference document deletion uses Moodle's confirmation dialogue instead of the browser's.

### Changed
- Each queue row shows which question it is (Q1, Q2...), so three rows for one learner can be
  told apart. The submission date filter is aligned on one line with a styled Apply button.
  Long criterion labels wrap beside a score box that stays pinned to the right.
- CI is green again: phpcs, PHPDoc and ESLint warnings fixed (84 ESLint warnings to 0), and a
  corrupted character class in the feedback bullet pattern rewritten with explicit escapes.
- New `\quiz_aigrader\local\access` class with unit tests; 150 tests in total.

## 5.0.6 - 2026-11-17

### Fixed
- **Marking criteria check proposed nothing for guidance written as "A satisfactory response
  addresses the following:" over sub-numbered parts (1a., 1b., 2a. ...).** Two causes: list
  numbers with a letter ("1a.", "2b)") were not recognised as list items, so every part was read
  as loose prose; and the "satisfactory response" lead was read as a sample answer, whose unmarked
  lines are skipped. Sub-numbered items are now list items, and in Information for graders a lead
  saying what the answer must address or cover ("addresses the following", "must address:",
  "covers all of") opens the criteria. Such guidance is still unmarked, so it is proposed for
  confirmation, never used as written.

## 5.0.5 - 2026-11-13

### Added
- Works with Client Portal 2.0.1 and later: each LMS Labs AI call made in a client's course, or by a client's people, is reported to the portal with the credits it used, so the site owner can see and invoice each client's usage. The service's own figure is used when its reply gives one; otherwise the plugin's published price. Calls that fail and polled jobs are never counted twice. Nothing changes on sites without Client Portal.

## 5.0.4 - 2026-10-27

Version: `2026102705`. Marking criteria are found wherever the question author put them, an
assessor confirms them once per question, and only confirmed criteria drive the breakdown. The
card turns criteria scores into the mark, so a wrong criterion or a wrong weight is a wrong
mark; from this version no criterion reaches a mark without a person confirming it. Database
changes: new table `quiz_aigrader_criteria`, and new fields
`quiz_aigrader_decisions.criteriasource` and `quiz_aigrader_attempt_ctx.aicriteria`. New
capability: `quiz/aigrader:confirmcriteria`.

**After upgrading:** until a question's criteria are confirmed, its responses get one overall
mark, and on sites set to **Mark by hand** they get no AI suggestion. Open the Marking criteria
check on each quiz in progress and confirm its questions, or switch confirmation off in the
site settings to keep the previous behaviour.

- **Assessors confirm each question's criteria.** The Marking criteria check above the queue
  proposes each question's criteria, with their marks, from its marking guidance. The assessor
  opens **Review and confirm**, edits the wording and marks if needed, and confirms. The marks
  must add up to the question's mark in the quiz; when guidance written for 2 marks sits on a
  question worth 1, one click shares the mark in the same proportions. The editor recommends
  3 to 5 criteria and allows up to 10. Questions whose criteria were read exactly can be
  confirmed together, and are recorded as confirmed that way. Who confirmed and when is shown
  in the panel and on every card.
- **Confirming is setting the marking scheme,** so it has its own capability,
  `quiz/aigrader:confirmcriteria`, given to editing teachers and managers. Non-editing teachers
  see the panel and mark as before.
- **Each version and each mark is confirmed separately.** Attempts keep the question version
  they started on, and a question drawn into random slots can carry different marks; each is
  its own row, labelled with its version or mark. Two assessors cannot overwrite each other's
  confirmation: a save made from an out-of-date view is refused.
- **The decision record keeps the assessor's per-criterion scores only when they are scores on
  the criteria the suggestion was measured against.** Nothing confirmed by nobody is sent to the
  grading service as criteria.
- **Only confirmed criteria drive the breakdown.** A question whose criteria are not confirmed
  gets one overall mark, and the card says why. Under **Mark by hand** it gets no suggestion
  and uses no credit. Editing the question, its default mark or its mark in the quiz asks for
  the criteria to be confirmed again. Every check is repeated on the server: the question must
  have been attempted in the quiz, and the confirming user needs the approve capability.
- **A site setting turns confirmation off** (Assessors confirm each question's criteria, on
  by default). Off, criteria read exactly are used as written and other guidance is condensed
  by the AI and checked, as described below.
- **Marks follow the criteria.** The grade list steps as finely as the confirmed criteria need:
  two criteria of 0.5 on a 1-mark question offer 0.25, 0.5 and 0.75, where before a 0.75
  breakdown was rounded to 1.
- **Proposals are read more carefully.** Criteria split across lines, alternatives ("Option 2",
  "(or)", "Learners who chose Scenario B"), superseded versions, deductions ("-1 mark"),
  instructions written as criteria ("Where the learner only names a control, give 1 mark"),
  near-duplicate and two-language copies of a rubric, cumulative levels ("Names / Names and
  describes / Names, describes and explains"), notes that change what criteria are worth, and
  marked lines under a closing heading are no longer read as exact.
- **Criteria are read from all three question fields:** Information for graders, then the
  question text, then General feedback. Until 5.0.3 only Information for graders was read, so
  a question with its criteria in the question text was marked with no criteria at all.
- **One rule for using a rubric as written.** Every criterion has its marks beside it, nothing
  in it is ambiguous, and the marks add up to the question's mark (in the quiz, or as the
  question was written). Many ways of writing marks are read: "1 mark: ...", "(2 marks)",
  "- 2 marks", "Knowledge (3 marks): ...", "(up to 2 marks)", "[2]", half marks and decimal
  commas, "mks", Thai, Vietnamese and Chinese marks, marking-guide tables with a marks column
  in any position, "Part A (3 marks)" headings over their criteria, "marked out of 5 each".
  Lists may be numbered, lettered, roman, bulleted, ticked or pasted from Word.
- **Everything else the assessor wrote is condensed by the AI, and checked.** Unmarked lists,
  mark bands, pick-lists, percentages, "/2", "1 mark each", marks on their own line, nested
  marked lists, marks that do not add up, benchmark answers in prose. The grading service is
  asked to restate the guidance as 3 to 5 criteria, and the set is used only if every criterion
  is found in the assessor's own text, the weights match the assessor's where every criterion
  had one, and the breakdown agrees with the service's overall mark. One failure refuses the
  whole set, and the response gets one overall mark.
- **Never read as criteria:** headings that carry a total, totals in any wording, sample and
  model answers, indicative content, examples, notes to the assessor, "do not accept" and
  "also accept" lists, bonus and optional marks, alternatives ("OR ..."), submission
  requirements, struck-out and hidden text, data tables and figures in a question's text.
- **A setting for questions with no marking guidance:** derive criteria from the question,
  labelled as AI-derived and checked the same way (default); one overall mark; or mark by
  hand, with no AI suggestion and no credit used.
- **3 to 5 criteria per question.** New site settings set the recommended range. Assessors are
  told when a question has more, or fewer than its marks call for. The assessor's own criteria
  are never trimmed or merged.
- **The assessor's weights stand.** A score the service gives out of a different maximum is
  scaled to the criterion's weight, and two criteria with the same name each get their own
  score.
- **The fifteen generic criteria are gone.** A question without criteria used to show a fixed
  vendor list ("Hazard identified" and so on), most at zero.
- **The card says where the criteria came from** - the field, condensed by the AI, derived by
  the AI, or none - with any warning beneath it, including when the breakdown and the suggested
  mark disagree.
- **Marking criteria check** above the queue: every question, its criteria with their marks,
  where they were found, what to fix to have them used as written, and a link to edit the
  question.
- **The decision record says what the mark was measured against:** `criteriasource` and a
  fingerprint of the criteria shown (in `graderinfohash`, which was always empty), taken from
  the server's record of that exact suggestion.
- The question on the marking card keeps its line breaks and runs through Moodle's filters.
  Feedback headed "Improvements" is recognised, so the full-marks policy withholds it.
- The grading request carries `criteriaSource`, `criteriaMode` (`rubric`, `extract`, `infer`
  or `holistic`), `criteriaCandidates`, `rubricTotal`, `criteriaLimits` and, for `extract` and
  `infer`, `criteriaInstruction`. **The grading service must follow `criteriaInstruction` for
  condensed and derived criteria to appear; until it does, those questions get one overall
  mark.**
- **Proposals are cleaner.** Acceptable points in an "any 2 of" list and levels of answer are
  not proposed as criteria; percentage weights are proposed as shares of the question's mark; a
  marked line under a closing heading such as "Requirements" is proposed rather than dropped;
  assessor notes no longer hide criteria written in the question text. The panel shows one
  short note per question, and a card for an unconfirmed question shows only why it is one
  overall mark.
- The feedback language list shows language names without flag emoji.
- `db/install.xml` is in the XMLDB editor's canonical form, as Moodle's own install-file check
  requires. The last `!important` in the stylesheet is gone.
- Tests: 345 real-world layouts (95 written blind from the specification, 250 written by three
  independent reviews to break the reader) and 102 more from a fourth review, a property test
  over generated rubrics, regression tests for every defect found in review, randomised input,
  and tests of confirmation, staleness, validation, permissions and privacy. 155 PHPUnit tests pass on
  Moodle 5.3 after a clean upgrade from 5.0.3, the confirm, grade and approve flow was run end
  to end in a browser, and the plugin was installed over 5.0.3 on Moodle 4.4.1 with MariaDB
  10.11 and PHP 8.2.

## 5.0.3 - 2026-10-27

Version: `2026102702`. A cleaner look for feedback, the previous attempt and the activity
report. No database changes.

- **Cleaner feedback.** The feedback box shows plain headings and bullets instead of the
  service's emoji and coloured circles, and each item in the learner's feedback has a small
  line icon in its section's colour: a tick for strengths, an arrow for improvements and
  next steps.
- **The Previous attempt panel shows every criterion.** The saved summary keeps each
  criterion's mark, with its comment where it fits whole, instead of the first 300
  characters of the feedback, which held two criteria and ended mid-word. The panel lays the
  criteria out as rows with the mark in an amber or green pill.
- **The activity report page is styled.** Its rules were scoped to the quiz report page,
  so on its own page the filters, totals and tables showed as plain stacked text, and the
  table header wrapped onto two lines. The page now has the same tiles, filter bar and
  table card as the quiz report.

## 5.0.2 - 2026-10-27

Version: `2026102701`. Interface fixes found while filming the tutorial on Moodle 5.3. No
database changes.

- **Grading Time Statistics shows its four figures side by side.** The summary had no grid
  rule, so Essays Graded, Total Time, Avg per Essay and Total Time Spent each took a full-width
  row.
- **The "Previous attempt" panel keeps its line breaks.** The approved feedback was flattened
  with Moodle's `html_to_text()`, which neither breaks after a closing `div` nor separates
  adjacent inline elements, so the criteria breakdown arrived as "Understanding of the
  concept0 / 4 This criterion...". It now goes through the plugin's own formatter, which does
  both, and the panel keeps the breaks.
- **Grade all no longer opens every card.** Each row turns to Graded as its result comes
  back and stays closed until the assessor opens it. Opening every card filled the page
  with the whole batch at once and pushed the progress out of view. Grading a single
  response still opens its card.
- **Feedback sections are recognised when the grading service writes its headings with
  an emoji**, as it does ("👍 What you did well", "📉 Areas for improvement", "💡 Suggestions
  for next time"). Heading detection only allowed list and quote markers before the words, so
  none of the three was recognised: the card showed the feedback as flat text instead of the
  green, amber and blue sections, and the full-marks feedback policy had no sections to act
  on, so a learner on full marks could be sent the improvement and next-time sections the
  site had chosen to withhold.
- **A previous mark below full is shown in amber, never red,** and as a whole percentage
  ("0%", not "0.00%").
- **The learner preview on the marking card is labelled "What the learner will see".** It
  carried the same heading as the assessor's scoring block above it, so the card appeared to
  show the criteria twice.
- **The Reference Documents collapse button no longer picks up the theme's grey button
  style.**
- JavaScript rebuilt with Moodle's grunt AMD pipeline from the 5.3 tree.

## 5.0.1 - 2026-10-27

Version: `2026102700`.

- Restore a missing attempt-context table from the complete `install.xml` definition
  before the older upgrade checks its fields.
- Repair the same missing table for sites already running version 5.0.0.
- Leave existing attempt-context tables and grading records untouched.

## 5.0.0 - 2026-10-26

**A security and correctness release.** Version: `2026102503`. No database changes; the
upgrade records the new version and does nothing else.

Every item below was found by running the Moodle coding standard, Moodle's eslint config and
the full PHPUnit suite against a real Moodle 4.5 install, rather than by reading the code.

### Security

- **The grading statistics panel no longer builds its markup by concatenating names into
  HTML.** The course filter, the grader filter and the statistics table each inserted course
  short names and grader names directly into an HTML string. The panel shows one assessor's
  own name to every other assessor, so a name containing markup would have run in their
  browsers. Those rows are now built through the DOM, which escapes for the position it
  writes into.
- **`escapeHtml()` is now safe inside an attribute.** It escaped the angle brackets and the
  ampersand but left both quote characters, and it was being used in attribute values as
  well as between tags, so a value carrying a quote could close the attribute it sat in.
- **Diagnostic logging no longer reaches a production browser console.** Thirty-one log
  lines, carrying the graded response and the feedback written about it, were written to the
  console on every site. They now go through Moodle's `core/log` and only when the site is in
  developer debug mode.

### Fixed

- **Grading a response now opens its marking card, as it was meant to.** The function that
  expands a row was defined inside the handler registration and called from outside it, so
  the call threw a `ReferenceError` — which also abandoned the rest of that success handler,
  leaving the status pill and the grade label behind.
- **The "grading complete" message appears once.** Every request still in flight scheduled
  the next pass, so the last few all reached the finished branch and the assessor saw the
  same alert two or three times.
- **The accessible label on the expand control no longer depends on a deprecated class.**
  It used Bootstrap's `sr-only`. That still hides the label on 4.4 through 5.3, so nothing
  was visibly wrong, but Moodle 5.1 moved it into a deprecated Bootstrap 4 compatibility
  layer where it is flagged "Deprecated style in use" to anyone viewing in theme-designer
  mode, and it will go when that layer does. The label now uses Moodle's own `accesshide`,
  which is current in every supported version, and the plugin's stylesheet defines the rule
  as well so it holds in a theme that does not.
- **The count in the "all graded" message is translatable.** It was an English sentence built
  in JavaScript; it is now the `grading_complete_message` string with a count placeholder.

### Changed

- **Single grading and Grade All are one implementation.** They were two near-identical
  208-line copies, which is why the expand-on-grade fix above had only ever reached one of
  the two screens. The only difference the callers need — whether a failure is announced, or
  left quiet so a bulk run keeps moving — is now a parameter. The JavaScript bundle is 46 KB,
  down from 53 KB.
- **The language file is in the order Moodle requires**: all 343 strings alphabetical, with
  the section comments removed, because the standard allows neither. No string was added,
  removed or altered.

### Changed

- **Declared support now reaches Moodle 5.3** (`supported = [404, 503]`, was `[404, 502]`),
  on the strength of the run below rather than on inspection.
- The JavaScript bundle is built with Moodle's own grunt AMD pipeline, so it is a named
  module and is left unmangled, exactly as core builds its own.

### Verified

Installed and tested from scratch on **five** Moodle versions - 4.4.12+, 4.5.15+, 5.1.8+,
5.2.4+ and 5.3 - each with its own database, its own PHPUnit and its own eslint config.

- 60 tests, 242 assertions, no skips, on every one of the five.
- No errors from the Moodle coding standard or from Moodle's eslint configuration on any of
  them.
- The grading flow driven end to end in a headless browser against the shipping bundle:
  grade a response, render the card, score a criterion, edit the feedback, expand and
  collapse a row, and Grade All. Hostile content in a grader name, a criterion label and the
  feedback rendered as text and never executed.
- 5.3 required PostgreSQL 17, so that run used 17.10.
- No errors from the Moodle coding standard or from Moodle's eslint configuration.
- The rendered marking queue parsed in Chromium: one `tbody` per response holding a summary
  row and a hidden detail row, no nesting, `colspan` matching the header, no JavaScript
  errors.

## 4.5.0

Marking is now built from the question's own grading criteria.

### Feedback is organised by criterion
- Each criterion from the question's *Information for graders* heads its own feedback,
  replacing the three generic headings. A learner reading "Clarity and structure 7 / 10"
  sees immediately what cost them the three marks.
- "How to improve" is shown only where there are marks still to win. At full marks on a
  criterion it is withheld, because it contradicts the score.
- However many criteria the question has, that many bars appear.

### The assessor scores each criterion
- Every criterion carries an editable score. The bar moves as it is typed, the total
  updates, and the question's mark follows as the criteria total scaled onto its own
  scale. The mark is now the sum of the criteria rather than a separate number beside them.
- Scores the grading service supplies arrive pre-filled and are confirmed rather than
  accepted. Where it supplies none, which is the case today, the assessor fills them in
  and the breakdown still works.
- A criteria total that lands exactly halfway between two awardable marks rounds down,
  never into the learner's favour by default. The assessor raises it if they judge it
  should be raised, and that judgement is recorded as theirs.
- The per-criterion breakdown is stored on the assessment decision record.

### Other
- The grading request now asks the service for a per-criterion `awarded`, `comment` and
  `improve`, so that when it answers, the numbers and the feedback arrive together.

## 4.4.0

Correctness, the assessment record, criteria-level marking, and the interface restyle.

### Interface
- The marking screen is rebuilt to the product reference: the learner's work and the
  assessment sit side by side, the criteria breakdown carries a bar per criterion, and the
  headline figure sits at the top right of the card.
- The feedback box is always visible and read-only until you press Edit, which gives you
  Save changes and Cancel. Previously the text was hidden behind a toggle.
- Approving keeps the card and turns the button green, instead of replacing the card and
  discarding the criteria breakdown at the moment the decision is recorded.
- While the minimum review time runs, the button shows a padlock and the remaining time and
  the card says "Read the answer first".
- The stylesheet is rewritten from scratch against the reference screens and no longer
  forces any rule past the site's theme. It previously carried 1,062 forced overrides,
  which was one of three issues holding the plugin out of the Moodle plugins directory.

### Marks are stored exactly as the assessor selects them
- Marks were routed through an integer percentage, which cannot represent 2/3 or 5/7.
  A selected 2/3 was stored as 2.01 and 5/7 as 4.97, so the gradebook did not hold the
  mark that was chosen. Marks are now sent and stored as absolute values.
- A question whose maximum is not a whole number could never be awarded full marks: a
  2.5-mark essay offered 0, 1 and 2, and a 0.5-mark question offered only 0. The grade
  dropdown is now built from the question's own mark scale and always reaches the maximum.
- An out-of-range mark is rejected rather than written. Nothing clamps silently.
- Score labels no longer truncate a 2.5-mark question to "2/2".

### Criteria-level marking
- The assessment is shown broken down by criterion, with a bar for each, on the assessor's
  card and in the feedback the learner receives.
- The assessor's rubric is sent as discrete criteria instead of one block of prose.
- Rubrics, questions and answers keep their paragraphs and lists on the way to the grading
  service. They were previously flattened into a single line, so a numbered list of
  performance criteria arrived as one sentence.
- An essay answered with an attachment and no text is listed for marking instead of being
  dropped, and is never sent for AI grading - the service would have received the file name
  and graded that.

### The assessment decision record
- Every approval writes an append-only record: what the AI suggested, what the assessor
  approved, whether the mark or the feedback was changed, the full AI feedback as issued,
  how long the suggestion was open, and which rules were in force.
- The minimum review time is enforced on the server. It was a browser countdown that
  disabled one button, and after a bulk grade every countdown ran at once.
- The attempt context now records the approved decision. It recorded the AI's suggestion,
  so an assessor's correction was discarded and the next attempt was graded as though the
  criteria had been met.
- Student notifications are no longer blocked for ever by a blank or non-essay slot.
- Concurrent approvals on one attempt no longer lose an essay's contribution to the total.

### Competency rules
- An attempt flagged for review no longer returns a mark derived from a different attempt,
  and an auto-locked attempt no longer returns full marks for a response nobody read.
  Every response is assessed on its own merits.
- The attempt thresholds are site settings and default to off. They were hard-coded at
  three, four and five.
- Approving a flagged attempt requires an assessor acknowledgement and a reason, stored on
  the decision record. The flags were previously written as zero and never read.
- The rule that forced a mark to 100% once three criteria had accumulated is removed.

### Feedback at full marks
- The policy is re-applied whenever the assessor changes the mark. Lowering a suggested
  full mark previously delivered a reduced mark with the gap analysis already deleted.
- Feedback the policy withholds stays visible to the assessor instead of being discarded.
- When no sections can be identified the assessor is told, instead of the policy silently
  doing nothing.
- A sentence such as "Your answer has real strengths." is no longer treated as a heading.
- Structured feedback from the grading service is used when available, so the card renders
  correctly on sites using a feedback language other than English.

### Other
- The activity report counts only approvals made through this plugin. It counted every
  manual grade on the site, so teachers who had never used it appeared as graders.
- Average time per essay is divided by the number of essays, not the gaps between them.
- Data from the grading service is escaped before it is rendered.
- The re-grade billing flag is decided by the server, not the browser.
- The privacy declaration covers the decision record and everything sent to the service.
- The preview follows the editor as the assessor types.


All notable changes to this plugin are documented here, newest first. This project
follows [Semantic Versioning](https://semver.org/).

## 4.3.0 - 2026-10-24

### What's new

- **A new look for student feedback.** The feedback students read under their answer has
  been redesigned: calm flat colour instead of gradients, lighter borders, a neat icon for
  each section and cleaner, more readable text.
- **Notices now stand out properly.** Competency and assessor-review notices used to appear
  as plain paragraphs above the feedback. They now sit in their own panel so students can
  see they matter.
- Suggestions are shown in blue rather than purple, so the colour matches the rest of the
  AI Essay Grader and AI Grader Dashboard screens.

### Feedback at full marks

- **New setting: Feedback at full marks.** Competency-based assessment is a binary
  judgement, so by default a learner who scored full marks now sees only what they did
  well. Telling a competent learner what to improve, or what to try next time, implies a
  gap their grade says is not there.
- Choose instead to include areas for improvement, or to deliver everything, in
  *Site administration → Plugins → Activity modules → Quiz → AI Essay Grader*.
- **The grading screen now shows exactly what the learner will receive.** Previously the
  editor displayed sections that were removed on the way to the learner, so a marker could
  edit — and approve — text that was never delivered. The sections are now removed before
  the feedback reaches the grading screen, so what a marker reads, edits and approves is
  what the learner reads.

### Notes

- Feedback already approved keeps the appearance it was saved with. The new styling applies
  to feedback approved from this release onwards.
- Wording, marks, and which sections a student sees are all unchanged.

## 4.2.2 - 2026-09-11

### What's new

- **Grading Time Statistics now display correctly.** Essays graded, total time, average per
  essay and the per-marker breakdown all populate, and the course and marker filters are
  filled in. The panel had been unable to load its figures on any previous release.

## 4.2.1 - 2026-09-11

### What's new

- **Grading Time Statistics now work.** The statistics panel could not load its figures, so
  essays graded, total time, average per essay and the per-marker table stayed empty and
  the course and marker filters had nothing to choose from. All of it now displays
  correctly.

## 4.2.0 - 2026-09-10

### What's new

- **Students see the whole of their feedback.** Any comments written above the first
  heading now appear in full, including the competency and assessor-review notices.
- **Markers can see what the student will get.** The feedback editor now says when a
  section is not shown to the student, so nothing is edited in vain.
- **Works in your site's language.** All on-screen text, including the feedback headings
  students read, now comes from the language pack and translates properly.
- **Faster on sites with a lot of quiz history.** The search for essays awaiting marking
  has been streamlined, and the guide now includes optional database tuning for very
  large sites.
- Improved packaging and code quality throughout, in line with Moodle plugin standards.

## 4.1.5 - 2026-09-08

A line-by-line review against the working 3.9.9 release, hunting for more changes of the
kind that broke approving. Six more were found and fixed. No new features.

### Fixed

- **Marking instructions were silently truncated.** `extraInstructions` had been changed to
  `PARAM_TEXT`, which ends in `strip_tags()`, so an instruction such as "award 0 if the word
  count is <200" lost everything from the `<` onwards. Saving still reported success. Back to
  `PARAM_RAW`; the value is sent only to the AI service and never rendered as HTML.
- **Deleting a reference document could target the wrong document, or none.** `docid` had been
  changed to `PARAM_ALPHANUMEXT`, which strips disallowed characters rather than rejecting
  them, mangling any identifier from the service containing a dot, slash or plus. Back to
  `PARAM_RAW`; it is only ever url-encoded into a request path.
- **The Grading Stats panel was broken by an SQL syntax error.** The user-name fields were
  interpolated without a separating comma, because `core_user\fields::get_sql()` does not
  return a leading one. Every report query is now executed against PostgreSQL as part of the
  checks.
- **AI Suggest, document upload, document delete and settings save answered "server error"
  for some roles.** Those four actions called `require_capability()`, which throws, and the
  file-level handler turned it into a generic message naming nothing. All five write actions
  now share one gate that returns the capability an administrator needs to grant.
- **Students could be locked out of AI grading after improving.** Approving recomputed the
  human-review flag with a broader rule than the one that set it, missing the "no improvement
  on the previous attempt" condition, so any attempt-4 grade below 100% flagged the student
  for review and skipped AI grading on their next attempt. The flag is now read from the
  stored attempt context, which is the only place that knows the previous grade.
- **Score labels changed shape and could break on non-English sites.** `format_float()` turned
  "3/3" into "3.00/3.00" and uses the site's decimal separator, while one of those strings is
  parsed back with `floatval()`. Restored to the original form.
- A missing quiz attempt now reports a clear error instead of raising an exception, and the
  AMD build files are regenerated from source so they cannot drift apart.

### Verified

Every class, method and function the plugin calls is checked to exist by tokenising all PHP
files and resolving each reference against a real Moodle install - the check that would have
caught the invented method behind the 4.1.3 approve failure. Approving is exercised end to
end as a non-editing teacher on Moodle 5.0.9 / PostgreSQL: the mark reaches the student's
gradebook, the event triggers, and the feedback icons survive. 31 PHPUnit tests, 140
assertions.

### Known behaviour changes kept deliberately

- The grading activity report is scoped to the current course unless the user holds
  `moodle/site:config`, and the essay list honours separate-groups mode. Both are security
  fixes from 4.0.0; they mean some users see fewer rows than they did in 3.9.9.

## 4.1.4 - 2026-09-08

Fixes approving, and fixes every call to the grading service. Both faults were introduced
during the 4.x compliance work.

### Fixed

- **Approving threw on every attempt.** 4.x added an event trigger calling
  `\mod_quiz\event\question_manually_graded::create_from_question_attempt()`. That method
  does not exist in Moodle. The call was guarded with `class_exists()`, which passes, so PHP
  raised `Error: Call to undefined method`, the surrounding `catch (\Throwable)` swallowed it,
  and the interface reported "The changes could not be saved." The event is now created with
  the parameters core itself uses in `mod/quiz/comment.php`, and is wrapped in its own
  try/catch so logging can never fail a grade that has already been saved.
- **Credits, settings and reference documents all failed.** 4.x moved the API key out of the
  query string into an `Authorization: Bearer` header. The service reads the key from the
  query string, so every GET was rejected. The `apiKey` parameter is restored on all four
  endpoints; the bearer header is still sent as well, so the service can move to it and the
  query parameter can then be dropped.
- **Feedback lost its icons.** 4.x passed the approved feedback through `clean_text()`, which
  strips the inline SVG the feedback cards are built from. The call is removed. The value is
  stored as `FORMAT_HTML` and Moodle sanitises it through `format_text()` on every render.

### Verified

On a live Moodle 5.0.9 / PostgreSQL install, as a **non-editing teacher**: the manual grading
event triggers, the mark reaches `quiz_grades` and `grade_grades` for the student, the
attempt's `sumgrades` updates, and the SVG icons survive into the stored feedback.

## 4.1.3 - 2026-09-08

Fixes the Approve button on sites where it stopped working after 4.1.x.

### Fixed

- **Reverted the AMD build to the file that shipped before 4.0.0.** 4.1.x replaced
  `amd/build/aigrader.min.js` with a genuinely minified build and added a source map.
  Moodle's `lib/requirejs.php` serves the plain `amd/src/` source when no `.map` file is
  present, and the minified build when one is. Adding the map therefore switched sites to
  executing minified code that had never run a real approve. The previous build files are
  restored and the map removed.
- **Approving is now gated on the plugin's own capability, `quiz/aigrader:approve`,**
  instead of `mod/quiz:grade`. Core allows `mod/quiz:grade` for both teacher archetypes, but
  a site with a custom assessor role that lacks it would have found approving refused after
  4.0.0. The new capability is allowed by default for non-editing teacher, editing teacher
  and manager, and is cloned from `mod/quiz:grade` for existing custom roles. Read-only roles
  such as mentors and auditors still cannot approve, which was the point of the original fix.
- A refused approval now returns a clear message naming the capability an administrator needs
  to grant, rather than throwing and leaving the button looking dead.

## 4.1.2 - 2026-09-07

Supersedes 4.1.1, which was promoted before the supported-version change below was
finalised. No functional change to grading or the date filter.

### Changed

- Declared Moodle support is 4.4 to 5.2. Earlier builds said 4.0 to 5.0, so Moodle flagged
  the plugin as unsupported on 5.1 and 5.2, the current releases. Verified against Moodle
  5.2.2, including the move of the webroot into `public/` in 5.1. The minimum PHP is 8.1,
  matching Moodle 4.4.
- The CI matrix covers 4.4, 4.5, 5.0, 5.1 and 5.2 across PostgreSQL and MariaDB.

## 4.1.1 - 2026-09-07

### Changed

- Declared Moodle support is now 4.4 to 5.2. It previously said 4.0 to 5.0, which meant
  Moodle flagged the plugin as unsupported on 5.1 and 5.2, the current releases. Verified
  against Moodle 5.2.2, including the move of the webroot into `public/` in 5.1: the plugin
  registers, creates its tables, both settings pages appear, and the report class alias
  resolves correctly now that `quiz_default_report` has been removed from core. The minimum
  PHP is now 8.1, matching Moodle 4.4.
- The CI matrix now covers 4.4, 4.5, 5.0, 5.1 and 5.2 rather than stopping at 5.0.

- The date filter logic no longer reads the request directly. `resolve_date_filter()` now
  takes the submitted values as an argument, and a separate `read_filter_request()` does the
  `optional_param()` reads. The unit tests pass values in rather than populating `$_GET`, so
  no superglobal appears anywhere in the plugin except the validated file upload. The logic
  is also now testable without request state, which is what it should have been.
- The sesskey tests additionally cover a wrong key, not just a missing one.

The plugin was also installed into a real Moodle 5.0.9 site on PostgreSQL 16 and its code
executed rather than only inspected. Four defects that static checking had missed were
found and fixed.

### Fixed

- Dates were formatted for the filter inputs with `userdate('%Y-%m-%d')`, which drops the
  leading zero from single-digit days and yields values such as "2026-01-1". An HTML date
  input rejects that as invalid and renders itself empty, so on the 1st to the 9th of any
  month a marker saw a blank filter while the filter was still being applied. Both screens
  now format through `DateTime` in the user's timezone.
- `confirm_sesskey()` was called as `confirm_sesskey(null, true)` in the belief that the
  second argument suppressed errors. It does not - it is the expected request method - so a
  request without a sesskey raised an exception instead of falling back to read-only. The
  key is now read first and only validated when present.
- `amd/build/aigrader.min.js` was a verbatim copy of the source rather than a build
  artefact, and `amd/build/aigrader.js` was a stray file that Moodle never loads. The build
  is now genuinely minified with a source map, and the stray file is gone.
- Two assertions in the privacy tests compared context ids strictly against integers, but
  `get_contextids()` returns strings, and one used `assertObjectHasProperty()`, which needs
  PHPUnit 10.1 and would have fataled on the Moodle 4.2 CI job.

### Added

- `tests/date_filter_test.php` covering date validation, the timezone round trip in five
  timezones including DST boundaries, per-course-module scoping, the sesskey requirement,
  the inverted-range swap, and the rule that an invalid date in one field never displaces
  the other.

### Verified

- 31 PHPUnit tests, 140 assertions, all passing on PHP 8.4 and PostgreSQL 16.
- The plugin installs cleanly: all three tables created, scheduled task and message
  provider registered, all 248 language strings load.
- Every report query executes on PostgreSQL, including the filtered essay-list query.
- The settings page registers at section `quiz_aigrader`, which is the failure mode that
  produced the "sectionerror" bug in 3.7.7.
- The AMD module loads and initialises in Chromium from both source and minified build,
  with an identical public API and no page errors.
- 0 errors and 0 warnings from moodle-cs on PHP_CodeSniffer 3.13.2.

## 4.1.0 - 2026-09-07

### Added

- Date filter on the grading screen. The outstanding-essay list can be limited to
  attempts submitted within a chosen range, and the range is saved per user, so the
  view a marker leaves is the view they come back to. When a filter is active and
  hides everything, the screen says so and offers to clear it rather than claiming
  all work is marked.
- The grading activity report now remembers its date range and grader selection
  between visits.

### Security and correctness of the above

- Saved filters are scoped per course module, so a range chosen on one quiz is never
  applied to a different one.
- Saving or clearing a filter requires a valid sesskey. Without it these were state
  changes reachable from a plain GET, so a third-party page could have silently set a
  marker's filter and made their marking queue look empty.
- Every path that finds no essays now reports the truth: an active filter yields the
  empty-range state, never the all-graded state. This covers the three separate empty
  paths, including the one reached after blank answers are dropped.
- Malformed dates are discarded before the inverted-range swap, so an invalid value in
  one field can no longer displace the valid value in the other.
- The grading activity report parses and renders dates in the viewing user's timezone
  rather than the server's, which could previously show a date one day off the one typed.
- The grading activity report no longer persists computed defaults, which would otherwise
  have frozen it on whatever two-week window it showed the first time it was opened, and
  no longer rewrites the saved view as a side effect of fetching a CSV, Excel or PDF
  export.
- All five saved preferences are declared in the Privacy API and exported through
  `export_user_preferences()`.

## 4.0.0 - 2026-09-04

Compliance and security release, prepared for submission to the Moodle plugins
directory. No new features.

### Security

- Fixed a cross-course privilege escalation: `qubaid` and `slot` submitted to the
  AJAX endpoint were never validated against the requested course module, which
  allowed a user with `mod/quiz:viewreports` on any single quiz to read essay
  answers from, and write marks and feedback to, attempts in any other course.
- Approving a mark now requires `mod/quiz:grade` rather than
  `mod/quiz:viewreports`.
- The grading activity report is now scoped to the current course unless the
  user holds `moodle/site:config`. It previously returned marker names and
  grading activity from every course on the site.
- The essay list now honours the quiz's group mode. Under separate groups a
  teacher no longer sees other groups' essay answers.
- The API key is sent in an `Authorization` header instead of the URL query
  string, where it was written to proxy and debug logs.
- Uploaded reference documents are validated for extension and MIME type and go
  through Moodle's cURL wrapper, so proxy and blocked-host settings apply.
- Exception messages, server file paths and raw upstream response bodies are no
  longer returned to the browser.
- Approved feedback is passed through `clean_text()` before storage.
- CSV exports are protected against formula injection.

### Privacy

- The Privacy API provider is fully implemented. All three plugin tables are
  declared in the metadata and are exported and deleted correctly, alongside the
  existing external-service declaration.

### Compatibility

- Replaced raw `CONCAT()` and `LEAST()` SQL with `$DB->sql_concat()` and a
  `CASE` expression, for PostgreSQL, MSSQL and Oracle compatibility.
- Removed `opcache_reset()` and `opcache_invalidate()` calls from upgrade steps.
- Duplicate upgrade savepoint blocks collapsed.

### Changed

- Removed all pricing and purchase links from the plugin interface and code.
- The service base URL is now an admin setting rather than hardcoded.
- Google Fonts is no longer loaded from an external host.
- Hardcoded English strings moved into the language pack.
- Scheduled report attachments are written to Moodle's temp directory, which
  fixes emailed reports arriving without their attachment.
- Removed the shipped CLI version-recovery script and the non-PHPUnit
  simulation script from `tests/`.
- Added PHPUnit coverage for the privacy provider and a GitHub Actions workflow
  running moodle-plugin-ci against MySQL and PostgreSQL on Moodle 4.2, 4.5 and
  5.0.

## Earlier releases

Release notes for versions before 4.0.0 are available in the repository history.
