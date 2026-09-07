# Changelog

All notable changes to this plugin are documented here, newest first. This project
follows [Semantic Versioning](https://semver.org/).

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
