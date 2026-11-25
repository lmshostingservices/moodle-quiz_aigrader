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

namespace quiz_aigrader\local;

#[\PHPUnit\Framework\Attributes\CoversClass(service_url::class)]
/**
 * Tests for the service address rule and the stored API key.
 *
 * @package    quiz_aigrader
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\local\service_url
 */
final class service_url_test extends \advanced_testcase {
    /**
     * Only https, or http to this machine.
     */
    public function test_is_safe(): void {
        $this->assertTrue(service_url::is_safe('https://lms-labs.com'));
        $this->assertTrue(service_url::is_safe('http://127.0.0.1:8099'));
        $this->assertTrue(service_url::is_safe('http://localhost'));
        $this->assertFalse(service_url::is_safe('http://lms-labs.com'));
        $this->assertFalse(service_url::is_safe('ftp://lms-labs.com'));
        $this->assertFalse(service_url::is_safe('lms-labs.com'));
        $this->assertFalse(service_url::is_safe(''));
    }

    /**
     * The key is read back whether it was saved encrypted or by an earlier release in plain text.
     */
    public function test_api_key(): void {
        $this->resetAfterTest();
        set_config('apikey', 'plain-key-123', 'quiz_aigrader');
        $this->assertSame('plain-key-123', service_url::api_key());

        $encrypted = \core\encryption::encrypt('secret-key-456');
        $this->assertTrue(service_url::is_encrypted($encrypted));
        set_config('apikey', $encrypted, 'quiz_aigrader');
        $this->assertSame('secret-key-456', service_url::api_key());

        set_config('apikey', '', 'quiz_aigrader');
        $this->assertSame('', service_url::api_key());
    }
}
