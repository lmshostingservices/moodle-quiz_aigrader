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
 * quiz_aigrader file.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace quiz_aigrader;

defined('MOODLE_INTERNAL') || die();

class api_client {
    private $siteid;
    private $apikey;
    private $endpoint = 'https://lms-labs.com/api/credits';

    public function __construct() {
        global $CFG;
        
        // Explicitly include aiconfig lib.php if available
        $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
        if (file_exists($aiconfiglib)) {
            require_once($aiconfiglib);
        }
        
        // Priority 1: Central Config (recommended for multi-plugin setups)
        $this->siteid = '';
        $this->apikey = '';
        if (function_exists('local_aiconfig_get_siteid')) {
            $this->siteid = local_aiconfig_get_siteid();
        }
        if (function_exists('local_aiconfig_get_apikey')) {
            $this->apikey = local_aiconfig_get_apikey();
        }
        
        // Priority 2: Plugin settings as fallback
        if (empty($this->siteid)) {
            $this->siteid = get_config('quiz_aigrader', 'siteid');
        }
        if (empty($this->apikey)) {
            $this->apikey = get_config('quiz_aigrader', 'apikey');
        }
    }

    public function fetch_credits() {

        if (empty($this->siteid) || empty($this->apikey)) {
            return (object)[ 'ok'=>false, 'message'=>'Plugin not configured' ];
        }

        $curl = new \curl();
        $resp = $curl->get($this->endpoint, [
            'siteId'=>$this->siteid,
            'apiKey'=>$this->apikey
        ]);

        if ($resp === false) {
            return (object)[ 'ok'=>false, 'message'=>'Connection failed' ];
        }

        $json = json_decode($resp);

        if ($json === null) {
            return (object)[ 'ok'=>false, 'message'=>'Invalid API response' ];
        }

        // Flexible parsing
        $credits =
            $json->credits ??
            $json->balance ??
            $json->creditsRemaining ??
            ($json->data->credits ?? null);

        if ($credits === null) {
            return (object)[ 'ok'=>false, 'message'=>'Credits field missing' ];
        }

        return (object)[ 'ok'=>true, 'credits'=>$credits ];
    }
}
