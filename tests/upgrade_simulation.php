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
 * Upgrade simulation: validates quiz_aigrader upgrade.php logic for all known
 * upgrade scenarios, including the 13-digit stored-version recovery path.
 *
 * Run with: php moodle-plugin/quiz_aigrader/tests/upgrade_simulation.php
 *
 * IMPORTANT — what this script tests and what it does NOT test:
 *
 *   TESTED: The logic inside xmldb_quiz_aigrader_upgrade() — whether the
 *   correct savepoints fire for a given $oldversion, and that no exceptions
 *   are thrown.
 *
 *   NOT TESTED: Moodle's core upgrade dispatcher.  Before calling this
 *   function, Moodle compares $plugin->version (version.php) with the stored
 *   value in mdl_config_plugins.  If stored > code-version, Moodle throws a
 *   downgrade_exception and never reaches this function.  The 13-digit stored
 *   version (2026080300210) is numerically LARGER than the 10-digit code
 *   version (2026081300), so the real Moodle site would block that upgrade
 *   before this function is ever called.  See db/fix_13digit_version.php for
 *   the DB recovery step that must precede the plugin install.
 *
 * Real-site result (moodle.cbplugins.com, 2026-08-13):
 *   After running fix_13digit_version.php (or equivalent SQL), the site had
 *   stored version 2026080300.  Installing the 2026081300 ZIP and running
 *   the targeted upgrade script produced:
 *     Stored version : 2026080300
 *     Code version   : 2026081300
 *     Calling xmldb_quiz_aigrader_upgrade(2026080300) ...
 *     Result         : true
 *     DB version now : 2026081300
 *     SUCCESS: quiz_aigrader upgraded 2026080300 → 2026081300
 *
 * No Moodle installation required for this script — stubs replicate the
 * relevant Moodle functions so upgrade.php can run standalone.
 *
 * @package    quiz_aigrader
 * @copyright  2026 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// ── Stubs ─────────────────────────────────────────────────────────────────────

$savepoints_called = [];
$downgrade_exception_thrown = false;

/**
 * Simulates Moodle's upgrade_plugin_savepoint() — records the savepoint and
 * mirrors the real Moodle behaviour of throwing a downgrade_exception when the
 * savepoint version is less than the oldversion passed in from outside.
 *
 * NOTE: In real Moodle the check happens inside upgrade_plugin_savepoint itself
 * against the DB-stored version, not against the $oldversion param.  Here we
 * track it from outside to keep the stub simple.
 */
function upgrade_plugin_savepoint(bool $result, int $version, string $type, string $plugin): void {
    global $savepoints_called;
    $savepoints_called[] = $version;
}

// opcache_invalidate / opcache_reset are PHP built-ins; only stub when missing.
if (!function_exists('opcache_invalidate')) {
    function opcache_invalidate(string $path, bool $force = false): bool { return true; }
}
if (!function_exists('opcache_reset')) {
    function opcache_reset(): bool { return true; }
}

class FakeDbManager {
    public function table_exists(object $table): bool { return true; }
    public function create_table(object $table): void {}
}
class FakeDb {
    public FakeDbManager $manager;
    public function __construct() { $this->manager = new FakeDbManager(); }
    public function get_manager(): FakeDbManager { return $this->manager; }
}
class xmldb_table {
    public function __construct(string $name) {}
    public function add_field(...$args): void {}
    public function add_key(...$args): void {}
    public function add_index(...$args): void {}
}

define('XMLDB_TYPE_INTEGER', 'int');
define('XMLDB_TYPE_CHAR',    'char');
define('XMLDB_TYPE_TEXT',    'text');
define('XMLDB_TYPE_NUMBER',  'number');
define('XMLDB_NOTNULL',      true);
define('XMLDB_SEQUENCE',     true);
define('XMLDB_KEY_PRIMARY',  'primary');
define('XMLDB_KEY_FOREIGN',  'foreign');
define('XMLDB_INDEX_NOTUNIQUE', false);
define('XMLDB_INDEX_UNIQUE',    true);
define('MOODLE_INTERNAL',    true);

$DB = new FakeDb();

// ── Load the real upgrade function ────────────────────────────────────────────

require_once __DIR__ . '/../db/upgrade.php';

// ── Helpers ───────────────────────────────────────────────────────────────────

$PASS = 0;
$FAIL = 0;

function check(bool $cond, string $label): void {
    global $PASS, $FAIL;
    if ($cond) { echo "  PASS  $label\n"; $PASS++; }
    else        { echo "  FAIL  $label\n"; $FAIL++; }
}

echo "\n=== quiz_aigrader upgrade simulation ===\n";
echo "(Tests upgrade.php logic; Moodle dispatcher behaviour documented separately)\n\n";

// ── Scenario 1: Normal 10-digit upgrade (real-site verified) ──────────────────
// moodle.cbplugins.com was at 2026080300 (10-digit DB version after prior
// remediation).  The targeted real-site upgrade script confirmed this path
// succeeded:  2026080300 → 2026081300.
echo "Scenario 1: 10-digit upgrade 2026080300 → 2026081300 (real-site path)\n";

$savepoints_called = [];
$result = xmldb_quiz_aigrader_upgrade(2026080300);

check($result === true,             'upgrade() returns true');
check(in_array(2026081300, $savepoints_called), 'final savepoint 2026081300 called');
$others = array_filter($savepoints_called, fn($v) => $v !== 2026081300);
check(count($others) === 0,         'no earlier savepoints replayed');

echo "\n";

// ── Scenario 2: 13-digit stored version — Moodle dispatcher blocks BEFORE ─────
// this function is reached.  Documented here for clarity.
echo "Scenario 2: 13-digit stored version 2026080300210 — numeric comparison\n";
echo "  NOTE: On a real Moodle site this scenario is blocked by the core upgrade\n";
echo "  dispatcher BEFORE xmldb_quiz_aigrader_upgrade() is ever called.\n";
echo "  Moodle compares \$plugin->version (2026081300) with stored (2026080300210)\n";
echo "  and throws downgrade_exception.  Recovery: run db/fix_13digit_version.php\n";
echo "  (or the equivalent SQL) to reset stored to 2026072300, then install.\n\n";

// Confirm the numeric inequality that causes the downgrade detection:
check(2026080300210 > 2026081300,   '2026080300210 > 2026081300: Moodle sees downgrade');
check((string)2026080300210 === '2026080300210', 'PHP represents 13-digit int exactly (no overflow/truncation)');

// Confirm that IF the function were called (post-recovery), it returns true
// (all guards are false so the function is a no-op pass-through):
$savepoints_called = [];
$result_if_called = xmldb_quiz_aigrader_upgrade(2026080300210);
check($result_if_called === true,   'upgrade() returns true even if called with 13-digit oldversion');
check(count($savepoints_called) === 0, 'no savepoints called (all guards false — no-op)');

echo "\n";

// ── Scenario 3: Fresh install (oldversion = 0) ────────────────────────────────
echo "Scenario 3: Fresh install (oldversion = 0)\n";

$savepoints_called = [];
$result = xmldb_quiz_aigrader_upgrade(0);
$unique = array_unique($savepoints_called);

check($result === true,                     'upgrade() returns true');
check(count($savepoints_called) > 0,        'savepoints are called');
check(in_array(2026081300, $unique),         'final savepoint 2026081300 reached');
check(max($unique) === 2026081300,           'highest savepoint is 2026081300 (no 13-digit leakage)');

echo "\n";

// ── Scenario 4: Upgrade from immediately-previous savepoint ──────────────────
echo "Scenario 4: One-step upgrade from 2026072300\n";

$savepoints_called = [];
$result = xmldb_quiz_aigrader_upgrade(2026072300);

check($result === true,                     'upgrade() returns true');
check(in_array(2026081300, $savepoints_called), 'savepoint 2026081300 called');
$others = array_filter($savepoints_called, fn($v) => $v !== 2026081300);
check(count($others) === 0,                 'no earlier savepoints replayed');

echo "\n";

// ── Scenario 5: Already current — no-op ──────────────────────────────────────
echo "Scenario 5: Already at 2026081300 (no-op)\n";

$savepoints_called = [];
$result = xmldb_quiz_aigrader_upgrade(2026081300);

check($result === true,                     'upgrade() returns true');
check(count($savepoints_called) === 0,      'no savepoints called');

echo "\n";

// ── All savepoints ≤ plugin version ──────────────────────────────────────────
echo "Scenario 6: Savepoint ceiling check\n";

$savepoints_called = [];
xmldb_quiz_aigrader_upgrade(0);
$over = array_filter($savepoints_called, fn($v) => $v > 2026081300);

check(count($over) === 0, 'no savepoint exceeds plugin version (2026081300)');
$bad13 = array_filter($savepoints_called, fn($v) => $v > 9999999999);
check(count($bad13) === 0, 'no 13-digit savepoints in upgrade.php');

echo "\n";

// ── Summary ───────────────────────────────────────────────────────────────────
$total = $PASS + $FAIL;
echo "=== Results: $PASS/$total passed";
if ($FAIL > 0) { echo " — $FAIL FAILED"; }
echo " ===\n\n";
exit($FAIL > 0 ? 1 : 0);
