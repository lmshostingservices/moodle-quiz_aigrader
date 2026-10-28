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
 * Capability definitions for the AI Essay Grader quiz report.
 *
 * @package   quiz_aigrader
 * @copyright 2026 LMS Labs
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Approve an AI suggested mark and write it to the attempt and the gradebook.
    //
    // Viewing the report only needs mod/quiz:viewreports, which is a read capability held by
    // mentors, auditors and other observers. Writing a grade is a separate decision, so it has
    // its own capability rather than reusing the view one. Both teacher archetypes are allowed
    // by default, and for custom roles the permission is cloned from mod/quiz:grade, which is
    // the core capability for marking a quiz question by hand.
    'quiz/aigrader:approve' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quiz:grade',
    ],

    // Confirm the marking criteria a quiz's questions are marked against (5.0.4).
    //
    // Confirming criteria decides the breakdown, and so the mark, for every learner's response
    // to a question, in every group. That is setting the marking scheme rather than marking,
    // so by default it belongs to those who can change the quiz itself: editing teachers and
    // managers. For custom roles the permission is cloned from mod/quiz:manage.
    'quiz/aigrader:confirmcriteria' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quiz:manage',
    ],
];
