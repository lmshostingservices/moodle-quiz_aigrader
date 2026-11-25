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
 * Moodle Design System support.
 *
 * The plugin's screens are built from Moodle Design System (MDS) tokens and components.
 * Moodle 5.3 ships the MDS tokens in core, with a dark palette for its colour modes; older
 * releases do not. On those the plugin's root containers carry an extra class that switches
 * on its own copy of the tokens (styles.css), so the screens look the same everywhere and,
 * from 5.3, follow the site's light or dark mode.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class design {
    /** @var int The first Moodle branch whose core ships the MDS tokens. */
    public const CORE_MDS_BRANCH = 503;

    /** @var string The class that switches on the plugin's own copy of the tokens. */
    public const FALLBACK_CLASS = 'aigrader-mds-fallback';

    /**
     * Whether this site's core provides the MDS tokens.
     *
     * @return bool
     */
    public static function core_has_tokens(): bool {
        global $CFG;
        return (int)($CFG->branch ?? 0) >= self::CORE_MDS_BRANCH;
    }

    /** @var int First Moodle branch on Bootstrap 5, where selects are styled with form-select. */
    public const BOOTSTRAP5_BRANCH = 500;

    /**
     * Moodle's own class for a select, for this version.
     *
     * Bootstrap 4 (Moodle 4.4 and 4.5) styles selects with custom-select; Bootstrap 5 (Moodle 5.0
     * and later) with form-select, and marks custom-select as deprecated.
     *
     * @param bool $small The compact size.
     * @return string
     */
    public static function select_class(bool $small = false): string {
        global $CFG;
        if ((int)($CFG->branch ?? 0) >= self::BOOTSTRAP5_BRANCH) {
            return $small ? 'form-select form-select-sm' : 'form-select';
        }
        return $small ? 'custom-select custom-select-sm' : 'custom-select';
    }

    /**
     * Classes for one of the plugin's root containers.
     *
     * @param string $classes The container's own classes.
     * @return string The classes, plus the token fallback where core has no tokens.
     */
    public static function root_classes(string $classes): string {
        return self::core_has_tokens() ? $classes : $classes . ' ' . self::FALLBACK_CLASS;
    }
}
