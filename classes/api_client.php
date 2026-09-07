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
 * Client for the external Essay Grader AI service.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_aigrader;

/**
 * Client for the external Essay Grader AI service.
 *
 * Resolves the site credentials, preferring the local_aiconfig plugin when it is
 * installed and falling back to this plugin's own settings.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_client {
    /** @var string Default base URL of the grading service. */
    const DEFAULT_BASE_URL = 'https://lms-labs.com';

    /** @var string The site identifier issued for this Moodle site. */
    protected $siteid;

    /** @var string The API key issued for this Moodle site. */
    protected $apikey;

    /** @var string Base URL of the grading service. */
    protected $baseurl;

    /**
     * Resolve the credentials and base URL for this site.
     */
    public function __construct() {
        global $CFG;

        $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
        if (file_exists($aiconfiglib)) {
            require_once($aiconfiglib);
        }

        $this->siteid = '';
        $this->apikey = '';

        // Central configuration takes priority when the local_aiconfig plugin is present.
        if (function_exists('local_aiconfig_get_siteid')) {
            $this->siteid = (string) local_aiconfig_get_siteid();
        }
        if (function_exists('local_aiconfig_get_apikey')) {
            $this->apikey = (string) local_aiconfig_get_apikey();
        }

        if ($this->siteid === '') {
            $this->siteid = (string) get_config('quiz_aigrader', 'siteid');
        }
        if ($this->apikey === '') {
            $this->apikey = (string) get_config('quiz_aigrader', 'apikey');
        }

        $baseurl = trim((string) get_config('quiz_aigrader', 'apiurl'));
        $this->baseurl = rtrim($baseurl === '' ? self::DEFAULT_BASE_URL : $baseurl, '/');
    }

    /**
     * Whether the plugin has the credentials it needs to call the service.
     *
     * @return bool True when both a site ID and an API key are configured.
     */
    public function is_configured(): bool {
        return $this->siteid !== '' && $this->apikey !== '';
    }

    /**
     * Fetch the remaining grading credit balance for this site.
     *
     * @return \stdClass Object with an ok flag, and either a credits value or a message.
     */
    public function fetch_credits() {
        if (!$this->is_configured()) {
            return (object) [
                'ok' => false,
                'message' => get_string('notconfigured', 'quiz_aigrader'),
            ];
        }

        $curl = new \curl();
        $curl->setHeader(['Authorization: Bearer ' . $this->apikey]);
        $response = $curl->get($this->baseurl . '/api/credits', ['siteId' => $this->siteid], [
            'CURLOPT_TIMEOUT' => 15,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);

        if ($response === false || $curl->get_errno()) {
            return (object) [
                'ok' => false,
                'message' => get_string('error_connection', 'quiz_aigrader'),
            ];
        }

        $json = json_decode($response);
        if ($json === null) {
            return (object) [
                'ok' => false,
                'message' => get_string('error_servererror', 'quiz_aigrader'),
            ];
        }

        $credits = $json->credits ??
            $json->balance ??
            $json->creditsRemaining ??
            ($json->data->credits ?? null);

        if ($credits === null) {
            return (object) [
                'ok' => false,
                'message' => get_string('error_servererror', 'quiz_aigrader'),
            ];
        }

        return (object) [
            'ok' => true,
            'credits' => $credits,
        ];
    }
}
