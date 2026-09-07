# AI Essay Grader for Moodle

A Moodle quiz report plugin that adds AI-assisted marking of essay questions,
with a teacher approval step before any mark reaches the gradebook.

| | |
|---|---|
| Plugin type | Quiz report (`quiz_aigrader`) |
| Install path | `mod/quiz/report/aigrader/` |
| Moodle | 4.4 - 5.2 (`2024042200` or later) |
| PHP | 8.1 or later |
| Databases | MySQL, MariaDB, PostgreSQL |
| Licence | GNU GPL v3 or later |
| Issue tracker | https://github.com/lmshostingservices/moodle-aigrader/issues |

## What it does

Adds an **AI Grader** tab to the quiz reports for any quiz containing essay
questions. For each unmarked essay the teacher can request a suggested mark and
feedback, review and edit both, and then approve them. Only on approval is the
mark written to the question attempt and pushed to the gradebook.

- Suggested marks against a fixed three-criterion rubric, so results are
  comparable between students and between markers.
- The teacher always reviews before anything is saved. Nothing is graded
  automatically.
- Marks and feedback are written through Moodle's own manual grading APIs, so
  regrades, the gradebook and the question engine behave normally.
- A grading activity report shows questions approved and time spent, filterable
  by course, marker and date, with CSV, Excel and PDF export.
- Optional scheduled email of the activity report.

## External service

This plugin does not grade essays itself. Question text and the student's answer
are sent over HTTPS to the Essay Grader AI service to generate a suggested mark
and feedback. **The plugin will not produce suggestions without a site ID and
API key for that service.** All other features of the plugin, including the
grading activity report, work without it.

No other personal data is transmitted. The data sent, and the plugin's own
database tables, are declared through Moodle's Privacy API — see
`classes/privacy/provider.php`.

Service terms and privacy policy: https://lms-labs.com

## Installation

Install as any other Moodle plugin, either from the Moodle plugins directory
through *Site administration > Plugins > Install plugins*, or manually:

1. Copy the plugin directory to `mod/quiz/report/aigrader/`.
2. Visit *Site administration > Notifications* and complete the upgrade.
3. Configure at *Site administration > Plugins > Activity modules > Quiz >
   AI Essay Grader*.

## Settings

| Setting | Purpose |
|---|---|
| API URL | Base URL of the grading service. |
| Site ID | Identifier issued for this Moodle site. |
| API key | Key issued for this Moodle site. Stored write-only. |
| Student notifications | Notify the student when all their essays are marked. |
| Minimum review time | Seconds a marker must spend before approving. |

If the `local_aiconfig` plugin is installed, the site ID and API key are read
from there and the values above are used only as a fallback.

## Capabilities

The plugin defines no capabilities of its own. It uses:

- `mod/quiz:viewreports` - view the AI Grader tab and the essay list.
- `mod/quiz:grade` - request suggestions and approve marks.
- `moodle/site:config` - view the site-wide grading activity report.

## Privacy

The plugin stores three tables: per-student grading context, per-marker approval
logs, and scheduled report configurations. All are exported and deleted through
the Privacy API, and all data sent to the external service is declared in the
plugin's privacy metadata.

## Development

```
git clone https://github.com/lmshostingservices/moodle-aigrader.git aigrader
```

The repository root is the plugin root, so it clones straight into
`mod/quiz/report/aigrader/`. Continuous integration runs
[moodle-plugin-ci](https://moodlehq.github.io/moodle-plugin-ci/) against both
MySQL and PostgreSQL.

## Bugs and support

Please report bugs and feature requests on the
[issue tracker](https://github.com/lmshostingservices/moodle-aigrader/issues).

## Licence

GNU GPL v3 or later. See [LICENSE](LICENSE).
