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
 * A stand-in for the grading service, for Behat acceptance tests only.
 *
 * Behat sites cannot reach the real service, and Moodle's test web server handles one request
 * at a time, so a test endpoint on the same server would never answer. Instead, on a running
 * Behat site whose service address is the reserved {@see self::URL}, the plugin answers from
 * here without touching the network. On any other site this class is never used.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_stub {
    /** @var string The service address that selects the stand-in. The .invalid domain never resolves. */
    const URL = 'https://behat.invalid';

    /** @var string The only key the stand-in accepts. */
    const KEY = 'behat-key';

    /**
     * Whether this request should be answered here.
     *
     * @param string $url The request address.
     * @return bool
     */
    public static function applies(string $url): bool {
        return defined('BEHAT_SITE_RUNNING') && strpos($url, self::URL . '/') === 0;
    }

    /**
     * Answer a request the way the grading service would.
     *
     * @param string $url The request address.
     * @param string $apikey The site key sent.
     * @return array The same shape as quiz_aigrader_fetch() returns.
     */
    public static function handle(string $url, string $apikey): array {
        $path = (string)parse_url($url, PHP_URL_PATH);
        if ($apikey !== self::KEY) {
            return self::reply(['error' => 'Invalid credentials'], 401);
        }
        switch ($path) {
            case '/api/credits':
                return self::reply(['credits' => 250, 'creditsRaw' => 250]);
            case '/api/reference-docs':
                return self::reply(['ok' => true, 'documents' => []]);
            case '/api/quiz-settings':
                return self::reply(['ok' => true, 'settings' => ['extraInstructions' => '', 'feedbackLanguage' => 'en']]);
            case '/api/grade-essay':
                return self::reply([
                    'ok' => true,
                    'score' => '3/4',
                    'grade' => 'Satisfactory',
                    'feedback' => [
                        'what_you_did_well' => ['You explained what consultation means for workers.'],
                        'areas_for_improvement' => ['Give a second example of when consultation is required.'],
                    ],
                    'criteria' => [],
                    'criteriaMet' => [],
                    'feedbackSummary' => 'Give a second example of when consultation is required.',
                    'credits' => 249,
                    'modelVersion' => 'behat',
                    'requestId' => 'behat1',
                ]);
        }
        return self::reply(['ok' => false, 'error' => 'API_NOT_FOUND'], 404);
    }

    /**
     * A reply in the shape quiz_aigrader_fetch() returns.
     *
     * @param array $body The reply body.
     * @param int $httpcode The HTTP status.
     * @return array
     */
    private static function reply(array $body, int $httpcode = 200): array {
        return ['success' => true, 'body' => json_encode($body), 'error' => null, 'httpcode' => $httpcode, 'timedout' => false];
    }
}
