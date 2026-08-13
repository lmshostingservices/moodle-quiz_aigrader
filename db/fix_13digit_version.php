<?php
/**
 * CLI recovery script: resets a 13-digit quiz_aigrader stored version to the
 * last valid 10-digit checkpoint so the standard Moodle upgrade can proceed.
 *
 * Background
 * ----------
 * Moodle compares $plugin->version (version.php) against the value stored in
 * mdl_config_plugins (plugin='quiz_aigrader', name='version') before it calls
 * xmldb_quiz_aigrader_upgrade().  If the stored value is numerically GREATER
 * than the code version, Moodle treats this as a downgrade and aborts.
 *
 * The 13-digit promoted build (2026080300210) is larger than all 10-digit
 * versions, including the current release (2026081300).  This script resets
 * the stored version to 2026072300 (the last 10-digit savepoint before
 * 2026081300) so the next normal Moodle upgrade runs a single clean step:
 *
 *   xmldb_quiz_aigrader_upgrade(2026072300)
 *     → if ($oldversion < 2026081300) { savepoint(2026081300); }
 *     → return true;
 *
 * Usage
 * -----
 *   php db/fix_13digit_version.php --moodle-root=/path/to/moodle [--dry-run]
 *
 *   --moodle-root   Path to your Moodle installation (must contain config.php).
 *   --dry-run       Print what would happen without modifying the database.
 *
 * Run this script BEFORE installing the new quiz_aigrader ZIP.  After it
 * completes, install the ZIP through Site admin → Plugins → Install plugin, or
 * copy the files manually and run:
 *
 *   php admin/cli/upgrade.php --non-interactive
 *
 * @package    quiz_aigrader
 * @copyright  2026 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// ── Parse arguments ──────────────────────────────────────────────────────────
$opts = getopt('', ['moodle-root:', 'dry-run']);

if (empty($opts['moodle-root'])) {
    fwrite(STDERR, "Usage: php db/fix_13digit_version.php --moodle-root=/path/to/moodle [--dry-run]\n");
    exit(1);
}

$moodleRoot = rtrim($opts['moodle-root'], '/');
$dryRun     = array_key_exists('dry-run', $opts);

if (!file_exists("$moodleRoot/config.php")) {
    fwrite(STDERR, "Error: config.php not found at $moodleRoot/config.php\n");
    exit(1);
}

// ── Bootstrap Moodle (provides $DB) ──────────────────────────────────────────
require_once "$moodleRoot/config.php";
require_once "$moodleRoot/lib/clilib.php";

// ── Check current stored version ─────────────────────────────────────────────
const PLUGIN          = 'quiz_aigrader';
const TARGET_RESET_TO = 2026072300;   // last 10-digit savepoint before 2026081300
const TARGET_NEW      = 2026081300;   // version.php version we are upgrading to
const THRESHOLD       = 9999999999;   // any value > this is a 13-digit version

$stored = (int) $DB->get_field('config_plugins', 'value',
    ['plugin' => PLUGIN, 'name' => 'version']);

if ($stored === 0) {
    cli_writeln("quiz_aigrader is not installed on this site — nothing to do.");
    exit(0);
}

cli_writeln("Current stored version : $stored");
cli_writeln("Code version (target)  : " . TARGET_NEW);

if ($stored <= THRESHOLD) {
    cli_writeln("Stored version is already a valid 10-digit value. No fix needed.");
    if ($stored <= TARGET_NEW) {
        cli_writeln("Normal Moodle upgrade will proceed without this script.");
    } else {
        cli_writeln("WARNING: stored ($stored) > target (" . TARGET_NEW . ") — a different version mismatch exists.");
    }
    exit(0);
}

// 13-digit version detected
cli_writeln("13-digit version detected — this will block Moodle's upgrade dispatcher.");
cli_writeln("Resetting to  : " . TARGET_RESET_TO . " (last 10-digit checkpoint)");

if ($dryRun) {
    cli_writeln("[DRY RUN] Would execute:");
    cli_writeln("  UPDATE mdl_config_plugins");
    cli_writeln("  SET    value = '" . TARGET_RESET_TO . "'");
    cli_writeln("  WHERE  plugin = '" . PLUGIN . "' AND name = 'version';");
    cli_writeln("[DRY RUN] No changes made.");
    exit(0);
}

// ── Apply the fix ─────────────────────────────────────────────────────────────
$DB->set_field('config_plugins', 'value', (string) TARGET_RESET_TO,
    ['plugin' => PLUGIN, 'name' => 'version']);

// Verify the write
$verify = (int) $DB->get_field('config_plugins', 'value',
    ['plugin' => PLUGIN, 'name' => 'version']);

if ($verify === TARGET_RESET_TO) {
    cli_writeln("Version reset successfully: $stored → $verify");
    cli_writeln("");
    cli_writeln("Next steps:");
    cli_writeln("  1. Install the quiz_aigrader ZIP (Site admin → Plugins → Install plugin)");
    cli_writeln("     OR copy the plugin directory to mod/quiz/report/aigrader/");
    cli_writeln("  2. Run:  php admin/cli/upgrade.php --non-interactive");
    cli_writeln("  3. Verify version shows " . TARGET_NEW . " in Site admin → Plugins.");
    exit(0);
} else {
    fwrite(STDERR, "Error: DB write failed (verify returned $verify, expected " . TARGET_RESET_TO . ")\n");
    exit(1);
}
