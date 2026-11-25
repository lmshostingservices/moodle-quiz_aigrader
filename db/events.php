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
 * Event observers: remove the plugin's records when what they describe is deleted.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\quiz_aigrader\local\cleanup::course_module_deleted',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_deleted',
        'callback' => '\quiz_aigrader\local\cleanup::attempt_deleted',
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => '\quiz_aigrader\local\cleanup::user_deleted',
    ],
];
