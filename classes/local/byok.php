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
 * Bring your own AI: grade an essay on this site's own AI instead of LMS Labs credits.
 *
 * Runs the same grading prompt, checks and clean-up as the LMS Labs /api/grade-essay service, through
 * the shared engine in AI Grader Central Config (local_aiconfig 1.1.0+). The student's answer goes from
 * this site straight to the site's AI provider; nothing is sent to LMS Labs and no credits are spent.
 * Returns the same response shape as the service, so the rest of the grading flow is unchanged.
 *
 * Reference documents uploaded for a quiz live on LMS Labs and are only used when grading on credits.
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok {
    /** This plugin, as named in the licence's products list. */
    const COMPONENT = 'quiz_aigrader';

    /** Feedback languages, as the service names them. */
    const LANGUAGES = [
        'en' => 'English', 'en-AU' => 'Australian English', 'en-GB' => 'British English', 'en-US' => 'American English',
        'ja' => 'Japanese', 'zh' => 'Chinese (Simplified)', 'zh-TW' => 'Chinese (Traditional)', 'ko' => 'Korean',
        'vi' => 'Vietnamese', 'th' => 'Thai', 'id' => 'Indonesian', 'ms' => 'Malay', 'tl' => 'Filipino/Tagalog',
        'hi' => 'Hindi', 'bn' => 'Bengali', 'ta' => 'Tamil', 'te' => 'Telugu', 'mr' => 'Marathi', 'gu' => 'Gujarati',
        'kn' => 'Kannada', 'ml' => 'Malayalam', 'pa' => 'Punjabi', 'ur' => 'Urdu', 'ar' => 'Arabic', 'fa' => 'Persian',
        'he' => 'Hebrew', 'tr' => 'Turkish', 'ru' => 'Russian', 'uk' => 'Ukrainian', 'pl' => 'Polish', 'cs' => 'Czech',
        'sk' => 'Slovak', 'hu' => 'Hungarian', 'ro' => 'Romanian', 'bg' => 'Bulgarian', 'hr' => 'Croatian',
        'sr' => 'Serbian', 'sl' => 'Slovenian', 'el' => 'Greek', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish',
        'pt' => 'Portuguese', 'pt-BR' => 'Portuguese (Brazilian)', 'it' => 'Italian', 'nl' => 'Dutch',
        'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian', 'fi' => 'Finnish',
    ];

    /**
     * Should essays on this site be graded on the site's own AI?
     *
     * @return bool
     */
    public static function enabled(): bool {
        return class_exists('\local_aiconfig\byok\ai')
            && \local_aiconfig\byok\ai::uses_own_ai(self::COMPONENT, 'text');
    }

    /** Yearly price in US dollars, before GST, of a Bring your own API key licence for this plugin alone. */
    const PRICE_USD = 500;

    /**
     * Where this site stands with Bring your own API key, for the settings page and the grading screen.
     *
     * @return string One of: noconnect (AI Grader Central Config missing or older than 1.1.0), none (no licence for this
     *                plugin), credits (licensed, but Text is set to LMS Labs credits or the chosen source is
     *                not ready), active (essays are graded on the site's own AI).
     */
    public static function state(): string {
        if (!class_exists('\local_aiconfig\byok\licence') || !class_exists('\local_aiconfig\byok\router')) {
            return 'noconnect';
        }
        if (!\local_aiconfig\byok\licence::allows(self::COMPONENT)) {
            return 'none';
        }
        return self::enabled() ? 'active' : 'credits';
    }

    /**
     * Which AI made a suggestion on this site, for the decision record: "own-ai:moodle" for Moodle AI, or
     * "own-ai:" plus the vendor (anthropic, openai, gemini) for the site's own key.
     *
     * @return string
     */
    public static function model_label(): string {
        if (!class_exists('\local_aiconfig\byok\router')) {
            return 'own-ai';
        }
        $source = \local_aiconfig\byok\router::source(self::COMPONENT, 'text');
        if ($source === \local_aiconfig\byok\router::OWNKEY) {
            return 'own-ai:' . (\local_aiconfig\byok\router::vendor('text') ?? 'key');
        }
        return 'own-ai:' . $source;
    }

    /**
     * The "Bring your own API key" panel for the plugin settings page: status, price and the next step.
     *
     * @return string HTML
     */
    public static function settings_html(): string {
        $state = self::state();
        $tones = ['noconnect' => 'secondary', 'none' => 'secondary', 'credits' => 'warning', 'active' => 'success'];
        $icons = ['noconnect' => 'fa-plug', 'none' => 'fa-key', 'credits' => 'fa-coins', 'active' => 'fa-circle-check'];
        $badge = \html_writer::span(
            '<i class="fa-solid ' . $icons[$state] . '" aria-hidden="true"></i>'
                . get_string('byok_state_' . $state, 'quiz_aigrader'),
            'mds-badge badge mds-badge--subtle mds-badge--has-icon mds-badge--' . $tones[$state]
        );
        $html = \html_writer::tag('p', $badge, ['class' => 'aigrader-byok-state']);
        $html .= \html_writer::tag('p', get_string('byok_desc_' . $state, 'quiz_aigrader', self::PRICE_USD));
        if ($state === 'noconnect') {
            return \html_writer::div($html, 'aigrader-byok');
        }
        $url = new \moodle_url('/local/aiconfig/byok.php', $state === 'none' ? ['plugin' => self::COMPONENT] : []);
        $label = get_string($state === 'none' ? 'byok_subscribe' : 'byok_manage', 'quiz_aigrader', self::PRICE_USD);
        $html .= \html_writer::link(
            $url,
            '<i class="fa-solid ' . ($state === 'none' ? 'fa-bolt' : 'fa-sliders') . '" aria-hidden="true"></i> ' . s($label),
            ['class' => 'mds-btn btn ' . ($state === 'none' ? 'btn-primary' : 'btn-secondary') . ' mds-btn--size-md']
        );
        return \html_writer::div($html, 'aigrader-byok');
    }

    /**
     * Grade one essay. Same input fields as the service payload; same output as the service.
     *
     * @param array $payload questionText, studentAnswer, maxMark, language, graderInfo, rubricCriteria,
     *                       generalFeedback, extraInstructions (any missing field is treated as empty).
     * @return array ok, grade, score, criteria?, totalCriteria?, rubric, feedback, requestId, byok
     *               or ok=false with error_code and message.
     */
    public static function grade_essay(array $payload): array {
        $maxmark = (float) ($payload['maxMark'] ?? 3);
        if ($maxmark <= 0) {
            $maxmark = 3;
        }
        $rubric = self::rubric($payload['rubricCriteria'] ?? []);
        [$system, $user] = self::prompts($payload, $maxmark, $rubric);
        try {
            $raw = \local_aiconfig\byok\ai::text(self::COMPONENT, $system, $user, [
                'json' => true, 'maxtokens' => 8000, 'temperature' => 0,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error_code' => 'BYOK_ERROR', 'message' => $e->getMessage()];
        }
        try {
            return self::finish($raw, $maxmark, $rubric);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error_code' => 'Failed to parse grading response', 'message' => $e->getMessage()];
        }
    }

    /**
     * The system and user prompts, exactly as the service builds them.
     *
     * @param array $payload
     * @param float $maxmark
     * @param array $rubric Validated rubric criteria.
     * @return string[] [system, user]
     */
    public static function prompts(array $payload, float $maxmark, array $rubric): array {
        $mark = self::num($maxmark);
        $language = (string) ($payload['language'] ?? 'en') ?: 'en';
        $feedbacklanguage = self::LANGUAGES[$language] ?? self::LANGUAGES['en'];
        $nonenglish = $language !== 'en' && strpos($language, 'en-') !== 0;
        $graderinfo = (string) ($payload['graderInfo'] ?? '');
        $extra = trim((string) ($payload['extraInstructions'] ?? ''));
        $general = (string) ($payload['generalFeedback'] ?? '');

        $languageinstruction = $nonenglish
            ? "\n\n🌐 LANGUAGE REQUIREMENT:\nWrite ALL feedback in {$feedbacklanguage}.\n" .
                "- Translate section headers to {$feedbacklanguage}\n" .
                "- Keep JSON keys and grade values in English for system compatibility\n"
            : "\n\n🌐 LANGUAGE REQUIREMENT:\nWrite ALL feedback in {$feedbacklanguage}.\n" .
                "- Always respond in {$feedbacklanguage} regardless of student's answer language\n" .
                "- Use section headers: 👍 What you did well, 📉 Areas for improvement, 💡 Suggestions\n" .
                "- Keep JSON keys and grade values in English for system compatibility\n";

        $system = "You are an automated Moodle Essay Question Grader for Australian VET (Vocational Education and " .
            "Training).{$languageinstruction}

GRADE ONLY using:
1) QUESTION_TEXT
2) GRADER_INFO (Moodle \"Information for graders\")
3) STUDENT_ANSWER
4) REFERENCE MATERIAL (if provided below)
5) MODEL ANSWER / GENERAL FEEDBACK (if provided below)
Ignore all other content (attempt history, comments, navigation, etc.)

-------------------------------
🚨 RUBRIC RULES (HIGHEST PRIORITY) 🚨
-------------------------------
" . ($graderinfo !== '' ? "GRADER_INFO:\n{$graderinfo}\n\n" : '') . "Use ONLY the explicit marking criteria above.
Do NOT invent criteria.
Do NOT require extra depth unless the marking criteria explicitly say so.

-------------------------------
🚫 OUTPUT RULES (NEVER VIOLATE) 🚫
-------------------------------
NEVER write \"GRADER_INFO\" anywhere in your output — not in feedback, criteria, or any field.
\"GRADER_INFO\" is an internal system label. The student must never see it.
Refer to the marking criteria naturally: \"the criteria\", \"the marking guide\", etc.
" . ($extra !== '' ? "
-------------------------------
MANDATORY TEACHER INSTRUCTIONS
-------------------------------
The teacher has provided the following custom grading instructions.
You MUST follow these instructions - they override default grading behaviour where applicable:
{$extra}
" : '') . "
-------------------------------
🚨 BINARY SCORING (STRICT) 🚨
-------------------------------
Each criterion is MET (full mark) or NOT MET (0).
No partial marks, no decimals.
MAX_MARKS is {$mark}.

⚠️ CRITERIA vs MAX_MARKS MISMATCH RULE:
If the marking criteria mention more points (e.g., \"3 points\") than MAX_MARKS ({$mark}):
- Grade HOLISTICALLY, not per-criterion
- If student addresses the MAJORITY of criteria adequately → AWARD FULL MARKS ({$mark}/{$mark})
- Only give 0 if student completely fails to address the question
- Example: MAX_MARKS=1, criteria say \"3 parts\" → if 2+ parts addressed → score = 1/1

-------------------------------
🚨 FORBIDDEN MARK DEDUCTIONS 🚨
-------------------------------
If a criterion is met, you MUST award the mark.
You may NOT deduct marks for:
- \"could be expanded\"
- \"needs more detail\"
- \"would strengthen\"
- \"more integration needed\"
Suggestions belong in feedback ONLY.

-------------------------------
🚨 INDUSTRY-SPECIFIC GATE 🚨
-------------------------------
If the marking criteria include an \"industry-specific / workplace / site-specific\" criterion:
✅ If student uses ≥1 workplace term (tools, equipment, hazards, roles, documents, site areas) → MET → AWARD MARK.
\"Specific to WA mine operations\" = mining workplace vocabulary (haul roads, processing plant, etc.), NOT legislation.

-------------------------------
🚨 MISSING-PROOF RULE 🚨
-------------------------------
Before marking a criterion NOT MET, you must quote what is missing.
If you cannot quote missing evidence, treat it as MET.

-------------------------------
🚨 ATTEMPT CONSISTENCY 🚨
-------------------------------
Grade ONLY the current STUDENT_ANSWER.
Do NOT compare to earlier attempts or sample answers.
Same answer must always produce same score.
" . ($general !== '' ? "
-------------------------------
MODEL ANSWER / GENERAL FEEDBACK
-------------------------------
The teacher has provided this model answer or general feedback. Use it to inform your grading
but do NOT copy it verbatim into your feedback. It shows what a good answer looks like:
{$general}
" : '') . "
-------------------------------
FEEDBACK TONE
-------------------------------
Use an encouraging, professional tone. Lead with genuine strengths before areas for improvement.
Keep each feedback section to 2-3 concise, actionable sentences.";

        $pass = self::num(ceil($maxmark / 2));
        $rubricblock = '';
        if ($rubric) {
            $rubricblock = "\nAUTHORITATIVE RUBRIC_CRITERIA (JSON parsed by Moodle from the assessor's marking guide):\n" .
                json_encode($rubric, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" .
                "Return exactly one scored entry for each of these criteria, in this order.\n" .
                "Copy each label EXACTLY from this array. Use its detail to assess the student's evidence; " .
                "its max determines the full binary award.\n" .
                "Do not invent, omit, merge, rename or reweight criteria. graderInfo remains supporting marking guidance.\n";
        }
        $entry = $rubric
            ? "      \"label\": \"Exact label copied from RUBRIC_CRITERIA\",\n" .
                "      \"met\": true or false,\n" .
                "      \"evidence_quote\": \"Direct quote(s) from STUDENT_ANSWER proving this\",\n" .
                "      \"comment\": \"What the learner did on this criterion\",\n" .
                "      \"improve\": \"What would earn the remaining mark; empty string when met is true\""
            : "      \"criterion\": \"Criterion text copied exactly from the marking criteria\",\n" .
                "      \"met\": true or false,\n" .
                "      \"evidence_quote\": \"Direct quote(s) from STUDENT_ANSWER proving this\"";
        $rubricrules = $rubric
            ? "- Include EVERY supplied marking criterion exactly once, copying its label exactly.\n" .
                "- met=true awards the FULL supplied criterion max; met=false awards ZERO. No partial marks.\n" .
                "- When the supplied maxima sum to MAX_MARKS, X must equal the sum of those binary awarded marks.\n" .
                "- When the supplied maxima do not sum to MAX_MARKS, keep the existing holistic scoring rule; " .
                "do not rescale criterion marks.\n" .
                "- comment explains what the learner did on that criterion, using their actual answer.\n" .
                "- improve explains what would earn the remaining marks; it MUST be empty when met=true.\n"
            : '';

        $user = "QUESTION_TEXT:\n" . (string) ($payload['questionText'] ?? '') . "\n\n" .
            "STUDENT_ANSWER:\n" . (string) ($payload['studentAnswer'] ?? '') . "\n\n" .
            "MAX_MARKS: {$mark}{$rubricblock}\n" .
            "Grade this response out of MAX_MARKS using ONLY the marking criteria above.\n\n" .
            "A score of {$pass}/{$mark} or higher is Satisfactory. Below {$pass}/{$mark} is Not Yet Satisfactory.\n\n" .
            "Return ONLY valid JSON in this exact structure:\n\n" .
            "{\n  \"grade\": \"Satisfactory\" or \"Not Yet Satisfactory\",\n  \"score\": \"X/{$mark}\",\n" .
            "  \"criteria_results\": [\n    {\n{$entry}\n    }\n  ],\n" .
            "  \"feedback\": \"👍 What you did well\\n🟢 ...\\n📉 Areas for improvement\\n🟠 ...\\n" .
            "💡 Suggestions for next time\\n🔵 ...\"\n}\n\n" .
            "Rules:\n- X and Y must be integers\n- Y must equal {$mark}\n{$rubricrules}" .
            "- If met=false, evidence_quote must explain what is MISSING (quote what's absent)\n" .
            "- If you cannot quote missing evidence, set met=true\n- No extra text outside JSON";

        return [$system, $user];
    }

    /**
     * Parse, clean and check the model's answer, as the service does.
     *
     * @param string $raw
     * @param float $maxmark
     * @param array $rubric
     * @return array
     */
    public static function finish(string $raw, float $maxmark, array $rubric): array {
        $result = json_decode(\local_aiconfig\byok\ai::strip_fences($raw), true);
        if (!is_array($result) && preg_match('/\{[\s\S]*"grade"[\s\S]*"score"[\s\S]*"feedback"[\s\S]*\}/', $raw, $m)) {
            $result = json_decode($m[0], true);
        }
        if (!is_array($result)) {
            throw new \moodle_exception('error_grading', 'quiz_aigrader');
        }
        $result['feedback'] = self::clean_feedback($result['feedback'] ?? '');

        $out = [
            'ok' => true,
            'grade' => (string) ($result['grade'] ?? ''),
            'score' => (string) ($result['score'] ?? ''),
            'requestId' => 'byok-' . bin2hex(random_bytes(8)),
            'rubric' => $result['criteria_results'] ?? ($result['rubric'] ?? null),
            'feedback' => $result['feedback'],
            'byok' => true,
            // Kept on the decision record (EU AI Act, Article 12), so every suggestion says which AI made it.
            'modelVersion' => self::model_label(),
        ];
        if ($rubric) {
            $criteria = self::criteria($result, $rubric);
            $available = array_sum(array_column($criteria, 'max'));
            if (abs($available - $maxmark) < 1e-8) {
                $awarded = round(array_sum(array_column($criteria, 'awarded')), 8);
                $out['score'] = self::num($awarded) . '/' . self::num($maxmark);
                $out['grade'] = $awarded >= ceil($maxmark / 2) ? 'Satisfactory' : 'Not Yet Satisfactory';
            }
            $out['criteria'] = $criteria;
            $out['totalCriteria'] = count($criteria);
        }
        return $out;
    }

    /**
     * Per-criterion results against the supplied rubric (the service's essayGradingCriteria()).
     *
     * @param array $result
     * @param array $rubric
     * @return array label, awarded, max, comment, improve
     */
    public static function criteria(array $result, array $rubric): array {
        $source = $result['criteria_results'] ?? null;
        if (!is_array($source) || count($source) !== count($rubric) || !$source) {
            throw new \coding_exception('Grading response must include every marking-guide criterion');
        }
        $normalise = function (string $label): string {
            return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $label), '_'));
        };
        if (
            count(array_unique(array_column($rubric, 'key'))) !== count($rubric)
                || count(array_unique(array_map($normalise, array_column($rubric, 'label')))) !== count($rubric)
        ) {
            throw new \coding_exception('Marking guide contains duplicate or ambiguous criteria');
        }
        $labels = array_column($rubric, 'label');
        $bylabel = [];
        foreach ($source as $item) {
            if (
                !is_array($item) || !is_string($item['label'] ?? null) || isset($bylabel[$item['label']])
                    || !in_array($item['label'], $labels, true)
            ) {
                throw new \coding_exception('Grading response contains an unknown or duplicate criterion label');
            }
            if (
                !is_bool($item['met'] ?? null) || !is_string($item['evidence_quote'] ?? null)
                    || !is_string($item['comment'] ?? null) || !is_string($item['improve'] ?? null)
            ) {
                throw new \coding_exception('Grading criterion must contain a binary judgement and feedback');
            }
            $bylabel[$item['label']] = $item;
        }
        $out = [];
        foreach ($rubric as $criterion) {
            $item = $bylabel[$criterion['label']];
            $out[] = [
                'label' => $criterion['label'],
                'awarded' => $item['met'] ? $criterion['max'] : 0,
                'max' => $criterion['max'],
                'comment' => $item['comment'],
                'improve' => $item['met'] ? '' : $item['improve'],
            ];
        }
        return $out;
    }

    /**
     * Keep only well-formed rubric criteria, as the service's request schema does.
     *
     * @param mixed $criteria
     * @return array key, label, detail, max
     */
    public static function rubric($criteria): array {
        $out = [];
        foreach (is_array($criteria) ? $criteria : [] as $c) {
            $key = trim((string) ($c['key'] ?? ''));
            $label = trim((string) ($c['label'] ?? ''));
            $max = (float) ($c['max'] ?? 0);
            if ($key === '' || $label === '' || $max <= 0 || !is_finite($max)) {
                throw new \coding_exception('Invalid rubric criterion');
            }
            $out[] = ['key' => (string) $c['key'], 'label' => (string) $c['label'],
                'detail' => (string) ($c['detail'] ?? ''), 'max' => $max + 0];
        }
        return $out;
    }

    /**
     * Flatten object feedback and strip HTML and leaked instruction text, as the service does.
     *
     * @param mixed $feedback
     * @return string
     */
    public static function clean_feedback($feedback): string {
        if (is_array($feedback)) {
            $parts = [];
            foreach ($feedback as $section => $items) {
                $parts[] = (string) $section;
                foreach (is_array($items) ? $items : [$items] as $item) {
                    if (is_string($item)) {
                        $parts[] = $item;
                    }
                }
                $parts[] = '';
            }
            $feedback = trim(implode("\n", $parts));
        }
        $text = strip_tags((string) $feedback);
        $text = str_ireplace(['&nbsp;', '&#160;', '&#xa0;'], ' ', $text);
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = str_replace(
            ['&amp;', '&#38;', '&lt;', '&#60;', '&gt;', '&#62;', '&quot;', '&#34;', '&apos;', '&#39;'],
            ['&', '&', '<', '<', '>', '>', '"', '"', "'", "'"],
            $text
        );
        $text = preg_replace([
            '/\s*\((?:Australian|British|American)\s+(?:English|spelling)\)/i',
            '/\s*\(in\s+(?:Australian|British|American)?\s*English\)/i',
            '/\s*\(write\s+this\s+heading\s+in\s+[^)]+\)/i',
            '/\s*\(Be\s+(?:specific|clear|actionable)[^)]*\)/i',
            '/\s*\((?:acknowledge|celebrate|brief)[^)]*\)/i',
            '/\[translated\s+\w+\s+heading\]/i',
            '/\s*:\s*:/',
            '/[ \t]+/',
            '/\n\s*\n\s*\n/',
        ], ['', '', '', '', '', '', ':', ' ', "\n\n"], $text);
        return trim($text);
    }

    /**
     * Extra grading instructions the teacher saved for this quiz on LMS Labs, if the site still has
     * LMS Labs credentials. Best effort: an empty string when they can't be fetched.
     *
     * @param string $apibase
     * @param string $siteid
     * @param string $apikey
     * @param int $quizid
     * @return string
     */
    public static function extra_instructions(string $apibase, string $siteid, string $apikey, int $quizid): string {
        global $CFG;
        if ($siteid === '' || $apikey === '') {
            return '';
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader(['Accept: application/json', 'Authorization: Bearer ' . $apikey]);
        $body = $curl->get(
            $apibase . '/api/quiz-settings',
            ['siteId' => $siteid, 'apiKey' => $apikey, 'quizId' => $quizid],
            ['CURLOPT_TIMEOUT' => 8]
        );
        $data = json_decode((string) $body, true);
        return (!$curl->get_errno() && !empty($data['ok'])) ? trim((string) ($data['settings']['extraInstructions'] ?? '')) :
            '';
    }

    /**
     * Format a mark the way JavaScript prints numbers (3, not 3.0).
     *
     * @param float $n
     * @return string
     */
    protected static function num(float $n): string {
        return rtrim(rtrim(number_format($n, 8, '.', ''), '0'), '.');
    }
}
