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

/**
 * Rules for the grading service address.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_url {
    /**
     * Whether the site key and learners' answers may be sent to this address.
     *
     * https always; plain http only to this machine, for a local test service.
     *
     * @param string $url The service base URL.
     * @return bool
     */
    public static function is_safe(string $url): bool {
        $parts = parse_url(trim($url));
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }
        return $scheme === 'http' && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    /**
     * The site's API key from the plugin settings, decrypted.
     *
     * Saved encrypted since 5.3.0. A key saved by an earlier release is still plain text
     * until the upgrade encrypts it, so plain text is returned as it is.
     *
     * @return string The key, or '' when none is set or it cannot be read.
     */
    public static function api_key(): string {
        $stored = trim((string)get_config('quiz_aigrader', 'apikey'));
        if ($stored === '' || !self::is_encrypted($stored)) {
            return $stored;
        }
        try {
            return trim(\core\encryption::decrypt($stored));
        } catch (\moodle_exception $e) {
            debugging('AI Essay Grader: the API key could not be decrypted: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }

    /**
     * Whether a stored value is in Moodle's encrypted format.
     *
     * @param string $value The stored value.
     * @return bool
     */
    public static function is_encrypted(string $value): bool {
        return (bool)preg_match('~^(sodium|openssl-aes-256-ctr):[A-Za-z0-9+/=]+$~', $value);
    }
}
