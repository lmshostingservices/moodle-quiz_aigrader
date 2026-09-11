# Changelog

All notable changes to this plugin are documented here, newest first. This project
follows [Semantic Versioning](https://semver.org/).

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
