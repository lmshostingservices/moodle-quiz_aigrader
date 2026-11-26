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

namespace quiz_aigrader;

use quiz_aigrader\local\byok;

/**
 * Tests for grading on the site's own AI (Bring your own AI).
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\local\byok
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_aigrader\local\byok::class)]
final class byok_test extends \advanced_testcase {
    /** @var array Requests the mocked provider received. */
    protected $requests = [];

    /**
     * Licence the site for Bring your own AI on a test key, with text on an Anthropic key.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai')) {
            $this->markTestSkipped('AI Grader Central Config 1.1.0 or later is not installed.');
        }
        $this->resetAfterTest();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = function(string $s): string {
            return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        };
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        set_config('src_text', 'ownkey', 'local_aiconfig');
        set_config('vendor_text', 'anthropic', 'local_aiconfig');
        set_config('key_anthropic', 'sk-test', 'local_aiconfig');
    }

    /**
     * Clean up the provider mock.
     */
    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        parent::tearDown();
    }

    /**
     * Make the provider answer with this JSON.
     *
     * @param array $answer
     */
    protected function answer(array $answer): void {
        $this->requests = [];
        $GLOBALS['local_aiconfig_byok_mock'] = function($url, $headers, $body) use ($answer) {
            $this->requests[] = $body;
            return ['content' => [['type' => 'text', 'text' => json_encode($answer)]]];
        };
    }

    public function test_enabled_only_with_licence_and_source(): void {
        $this->assertTrue(byok::enabled());
        set_config('src_text', 'credits', 'local_aiconfig');
        $this->assertFalse(byok::enabled());
        set_config('src_text', 'ownkey', 'local_aiconfig');
        unset_config('licencekey', 'local_aiconfig');
        $this->assertFalse(byok::enabled());
    }

    public function test_holistic_grade_returns_service_shape(): void {
        $this->answer(['grade' => 'Satisfactory', 'score' => '3/3', 'criteria_results' => [],
            'feedback' => "<p>👍 What you did well (Australian English)</p>\n🟢 Clear&nbsp;steps."]);
        $out = byok::grade_essay([
            'questionText' => 'Describe the isolation procedure.',
            'studentAnswer' => 'Lock out, tag out, test for dead.',
            'maxMark' => 3, 'language' => 'en-AU', 'graderInfo' => 'Must mention lockout.',
            'generalFeedback' => 'Isolate, lock, tag, test.', 'extraInstructions' => 'Be kind.',
        ]);
        $this->assertTrue($out['ok']);
        $this->assertTrue($out['byok']);
        $this->assertSame('3/3', $out['score']);
        $this->assertSame("👍 What you did well\n🟢 Clear steps.", $out['feedback']);
        $system = $this->requests[0]['system'];
        $this->assertStringContainsString("GRADER_INFO:\nMust mention lockout.", $system);
        $this->assertStringContainsString("MANDATORY TEACHER INSTRUCTIONS", $system);
        $this->assertStringContainsString("Write ALL feedback in Australian English.", $system);
        $this->assertStringContainsString("MAX_MARKS is 3.", $system);
        $user = $this->requests[0]['messages'][0]['content'][0]['text'];
        $this->assertStringContainsString("A score of 2/3 or higher is Satisfactory.", $user);
        $this->assertStringContainsString('"criterion": "Criterion text copied exactly', $user);
    }

    public function test_rubric_marks_are_binary_and_recomputed(): void {
        $rubric = [
            ['key' => 'a', 'label' => 'Identifies hazard', 'detail' => '', 'max' => 2],
            ['key' => 'b', 'label' => 'States control', 'detail' => '', 'max' => 2],
        ];
        $this->answer(['grade' => 'Satisfactory', 'score' => '4/4', 'feedback' => 'ok', 'criteria_results' => [
            ['label' => 'Identifies hazard', 'met' => true, 'evidence_quote' => 'q', 'comment' => 'c', 'improve' => 'x'],
            ['label' => 'States control', 'met' => false, 'evidence_quote' => 'none', 'comment' => 'c', 'improve' => 'Name one'],
        ]]);
        $out = byok::grade_essay(['questionText' => 'Q', 'studentAnswer' => 'A', 'maxMark' => 4, 'rubricCriteria' => $rubric]);
        $this->assertTrue($out['ok']);
        $this->assertSame('2/4', $out['score'], 'The model claimed 4/4; the binary sum wins.');
        $this->assertSame('Satisfactory', $out['grade']);
        $this->assertSame(2, $out['totalCriteria']);
        $this->assertSame('', $out['criteria'][0]['improve'], 'Met criteria carry no improvement note.');
        $this->assertSame('Name one', $out['criteria'][1]['improve']);
        $this->assertStringContainsString('AUTHORITATIVE RUBRIC_CRITERIA',
            $this->requests[0]['messages'][0]['content'][0]['text']);
    }

    public function test_missing_criterion_is_an_error_not_a_grade(): void {
        $rubric = [['key' => 'a', 'label' => 'One', 'detail' => '', 'max' => 1],
            ['key' => 'b', 'label' => 'Two', 'detail' => '', 'max' => 1]];
        $this->answer(['grade' => 'Satisfactory', 'score' => '2/2', 'feedback' => 'ok', 'criteria_results' => [
            ['label' => 'One', 'met' => true, 'evidence_quote' => 'q', 'comment' => 'c', 'improve' => ''],
        ]]);
        $out = byok::grade_essay(['questionText' => 'Q', 'studentAnswer' => 'A', 'maxMark' => 2, 'rubricCriteria' => $rubric]);
        $this->assertFalse($out['ok']);
    }

    public function test_provider_failure_reports_byok_error(): void {
        $GLOBALS['local_aiconfig_byok_mock'] = function() {
            throw new \moodle_exception('byok_callfailed', 'local_aiconfig', '', (object) [
                'vendor' => 'Anthropic', 'detail' => 'invalid x-api-key',
            ]);
        };
        $out = byok::grade_essay(['questionText' => 'Q', 'studentAnswer' => 'A', 'maxMark' => 1]);
        $this->assertFalse($out['ok']);
        $this->assertSame('BYOK_ERROR', $out['error_code']);
        $this->assertStringContainsString('invalid x-api-key', $out['message']);
    }

    public function test_non_english_feedback_keeps_english_keys(): void {
        [$system] = byok::prompts(['language' => 'ja'], 3, []);
        $this->assertStringContainsString('Write ALL feedback in Japanese.', $system);
        $this->assertStringContainsString('Translate section headers to Japanese', $system);
    }
}
