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

use html_writer;
use stdClass;

/**
 * Transparency and human control over the AI, for the EU AI Act and good practice anywhere.
 *
 * Two site settings live here. The learner notice tells every learner that their feedback was
 * drafted with AI and approved by a named person (EU AI Act, Article 26(11) for deployers of
 * high-risk systems, and the transparency aims of Article 50). It is added on the server when
 * a mark is approved, so no browser can leave it out, and it carries a fixed class so the
 * delivered text is detectable as AI-assisted by software as well as by people. The pause
 * switch lets a site stop all AI suggestions at once (Article 26(5): suspend use when a risk
 * appears) while marking by hand carries on.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transparency {
    /** @var string Class on the learner notice: the machine-readable AI-assisted marker. */
    public const NOTICE_CLASS = 'quiz-aigrader-ai-notice';

    /**
     * Whether learners are told that AI drafted their feedback. On unless a site turns it off.
     *
     * @return bool
     */
    public static function learner_notice_enabled(): bool {
        return get_config('quiz_aigrader', 'learner_ai_notice') !== '0';
    }

    /**
     * Whether the site has paused AI suggestions.
     *
     * @return bool
     */
    public static function paused(): bool {
        return (bool)get_config('quiz_aigrader', 'ai_paused');
    }

    /**
     * Add the learner notice to approved feedback, in the learner's own language.
     *
     * @param string $html The feedback the grader approved.
     * @param stdClass $grader The person who approved it.
     * @param string $learnerlang The learner's language code, or '' for the current language.
     * @return string The feedback the learner receives.
     */
    public static function add_learner_notice(string $html, stdClass $grader, string $learnerlang = ''): string {
        if (!self::learner_notice_enabled() || strpos($html, 'class="' . self::NOTICE_CLASS . '"') !== false) {
            return $html;
        }
        $text = get_string_manager()->get_string(
            'learner_ai_notice',
            'quiz_aigrader',
            fullname($grader),
            $learnerlang !== '' ? $learnerlang : null
        );
        // Inline styles, like the rest of the feedback: it is shown on the quiz review page,
        // where the plugin's stylesheet is not loaded. No text colour, so it takes the page's
        // own and stays readable in light and dark mode; the rule is a grey that suits both.
        return $html . html_writer::tag('p', s($text), [
            'class' => self::NOTICE_CLASS,
            'style' => 'margin:14px 0 0;padding-top:10px;border-top:1px solid #8f959e;font-size:13px;',
        ]);
    }
}
