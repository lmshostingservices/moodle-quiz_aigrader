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

use quiz_aigrader\local\confirmed;
use quiz_aigrader\local\criteria;

/**
 * Tests for criteria an assessor confirms per question (5.0.4).
 *
 * @package    quiz_aigrader
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_aigrader\local\confirmed
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_aigrader\local\confirmed::class)]
final class confirmed_test extends \advanced_testcase {
    /** The question that exposed the problem. */
    public const SAM_TEXT = '<p>Sam notices a hazard on site. Describe one action he should take and explain why.</p>'
        . '<p>Marking Criteria (2 marks total)</p><p>1 mark: Describe one action Sam should take (e.g. report or '
        . 'control the hazard).</p><p>1 mark: Explain why this action is important.</p>';

    /**
     * Submitted criteria are checked the way the server must check them.
     */
    public function test_validate(): void {
        [$ok, $errors] = confirmed::validate([
            ['label' => ' Describes one action ', 'max' => '1'],
            ['label' => 'Explains why it matters', 'max' => 1],
        ], 2.0);
        $this->assertSame([], $errors);
        $this->assertSame('Describes one action', $ok[0]['label']);
        $this->assertSame(1.0, $ok[0]['max']);

        // Decimal comma, and the total checked to the cent.
        [, $errors] = confirmed::validate([['label' => 'A', 'max' => '0,5'], ['label' => 'B', 'max' => '1,5']], 2.0);
        $this->assertSame([], $errors);

        $cases = [
            'confirm_error_total' => [[['label' => 'A', 'max' => 1], ['label' => 'B', 'max' => 2]], 2.0],
            'confirm_error_label' => [[['label' => '  ', 'max' => 1], ['label' => 'B', 'max' => 1]], 2.0],
            'confirm_error_mark' => [[['label' => 'A', 'max' => 0], ['label' => 'B', 'max' => 2]], 2.0],
            'confirm_error_duplicate' => [[['label' => 'Same', 'max' => 1], ['label' => 'same', 'max' => 1]], 2.0],
            'confirm_error_long' => [[['label' => str_repeat('x', 201), 'max' => 2]], 2.0],
            'confirm_error_empty' => [[], 2.0],
        ];
        foreach ($cases as $code => [$rows, $max]) {
            [$clean, $errors] = confirmed::validate($rows, $max);
            $this->assertContains($code, $errors, $code);
            $this->assertSame([], $clean, $code);
        }
        $many = [];
        for ($i = 1; $i <= 11; $i++) {
            $many[] = ['label' => "Criterion $i", 'max' => 1];
        }
        $this->assertContains('confirm_error_count', confirmed::validate($many, 11.0)[1]);
        $this->assertContains('confirm_error_mark', confirmed::validate([['label' => 'A', 'max' => 'NaN']], 1.0)[1]);
        $this->assertContains('confirm_error_mark', confirmed::validate([['label' => 'A', 'max' => -2]], -2.0)[1]);
        $this->assertContains('confirm_error_empty', confirmed::validate('not a list', 2.0)[1]);
        // The wording is kept as typed, apart from its spacing; it is escaped wherever shown.
        [$ok] = confirmed::validate([['label' => "Cites\u{00A0} <3   sources\n", 'max' => 2]], 2.0);
        $this->assertSame('Cites <3 sources', $ok[0]['label']);
        // Criteria that differ only in punctuation share a key, and would share a score.
        $this->assertContains(
            'confirm_error_duplicate',
            confirmed::validate([['label' => 'Safety: PPE', 'max' => 1], ['label' => 'Safety - PPE', 'max' => 1]], 2.0)[1]
        );
        $this->assertContains('confirm_error_label', confirmed::validate([['label' => '+++', 'max' => 1]], 1.0)[1]);
        // A mark that rounds to nothing.
        $this->assertContains('confirm_error_mark', confirmed::validate([['label' => 'A', 'max' => 0.001]], 0.0)[1]);
    }

    /**
     * Unconfirmed, confirmed, then stale when the question or its mark changes.
     */
    public function test_state_lifecycle(): void {
        $this->resetAfterTest();
        set_config('criteria_confirmation', 1, 'quiz_aigrader');
        $hash = confirmed::fields_hash('', self::SAM_TEXT, '', 1.0);

        $state = confirmed::state(10, 20, $hash, 2.0);
        $this->assertSame(confirmed::STATE_REQUIRED, $state['state']);

        [$clean] = confirmed::validate([
            ['label' => 'Describes one action', 'max' => 1],
            ['label' => 'Explains why it matters', 'max' => 1],
        ], 2.0);
        confirmed::save(10, 20, $clean, 2.0, $hash, criteria::SOURCE_QUESTIONTEXT, 5);

        $state = confirmed::state(10, 20, $hash, 2.0);
        $this->assertSame(confirmed::STATE_CONFIRMED, $state['state']);
        $this->assertCount(2, $state['criteria']);
        $this->assertSame('describes_one_action', $state['criteria'][0]['key']);
        $this->assertSame(5, (int)$state['record']->confirmedby);

        // The question edited.
        $edited = confirmed::fields_hash('', self::SAM_TEXT . '<p>Extra.</p>', '', 1.0);
        $this->assertSame(confirmed::STATE_STALE, confirmed::state(10, 20, $edited, 2.0)['state']);
        // Its default mark changed.
        $this->assertSame(
            confirmed::STATE_STALE,
            confirmed::state(10, 20, confirmed::fields_hash('', self::SAM_TEXT, '', 2.0), 2.0)['state']
        );
        // At another mark (a random slot worth more) it has its own confirmation, not yet made.
        $this->assertSame(confirmed::STATE_REQUIRED, confirmed::state(10, 20, $hash, 4.0)['state']);
        [$four] = confirmed::validate([['label' => 'Describes one action', 'max' => 4]], 4.0);
        confirmed::save(10, 20, $four, 4.0, $hash, criteria::SOURCE_QUESTIONTEXT, 5, true);
        $this->assertSame(confirmed::STATE_CONFIRMED, confirmed::state(10, 20, $hash, 4.0)['state']);
        $this->assertSame('bulk', confirmed::state(10, 20, $hash, 4.0)['record']->method);
        $this->assertCount(2, confirmed::state(10, 20, $hash, 2.0)['criteria']);
        $this->assertSame('reviewed', confirmed::state(10, 20, $hash, 2.0)['record']->method);
        // Another quiz, or another question, has nothing confirmed.
        $this->assertSame(confirmed::STATE_REQUIRED, confirmed::state(11, 20, $hash, 2.0)['state']);
        $this->assertSame(confirmed::STATE_REQUIRED, confirmed::state(10, 21, $hash, 2.0)['state']);

        // Confirming again replaces; withdrawing removes.
        [$three] = confirmed::validate([
            ['label' => 'A', 'max' => 0.5], ['label' => 'B', 'max' => 0.5], ['label' => 'C', 'max' => 1],
        ], 2.0);
        confirmed::save(10, 20, $three, 2.0, $hash, criteria::SOURCE_QUESTIONTEXT, 6);
        global $DB;
        $this->assertSame(2, $DB->count_records('quiz_aigrader_criteria', ['quizid' => 10, 'questionid' => 20]));
        $this->assertCount(3, confirmed::state(10, 20, $hash, 2.0)['criteria']);
        confirmed::reset(10, 20, 2.0);
        $this->assertSame(confirmed::STATE_REQUIRED, confirmed::state(10, 20, $hash, 2.0)['state']);
    }

    /**
     * A damaged record is never used.
     */
    public function test_damaged_record_is_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $hash = confirmed::fields_hash('', 'Q', '', 1.0);
        $DB->insert_record('quiz_aigrader_criteria', (object)[
            'quizid' => 1, 'questionid' => 2, 'criteria' => '{not json', 'maxmark' => 2, 'fieldshash' => $hash,
            'proposedsource' => null, 'confirmedby' => 0, 'timeconfirmed' => time(),
        ]);
        $this->assertSame(confirmed::STATE_STALE, confirmed::state(1, 2, $hash, 2.0)['state']);

        // Criteria that no longer add up to the mark.
        $DB->set_field('quiz_aigrader_criteria', 'criteria', json_encode([['label' => 'A', 'max' => 1]]), ['quizid' => 1]);
        $this->assertSame(confirmed::STATE_STALE, confirmed::state(1, 2, $hash, 2.0)['state']);
    }

    /**
     * Only confirmed criteria are certain; what was read is otherwise a proposal.
     */
    public function test_apply(): void {
        $this->resetAfterTest();
        $resolved = criteria::for_fields('', self::SAM_TEXT, '', 2.0, 1.0);
        $this->assertSame(criteria::CERTAIN, $resolved['confidence']);

        // Required: even a rubric read exactly is not used until confirmed.
        $applied = confirmed::apply($resolved, ['state' => confirmed::STATE_REQUIRED, 'criteria' => [], 'record' => null]);
        $this->assertSame([], $applied['criteria']);
        $this->assertSame(confirmed::STATE_REQUIRED, $applied['confirmation']);
        $this->assertCount(2, confirmed::proposal($resolved, null));
        $this->assertSame(1.0, confirmed::proposal($resolved, null)[0]['max']);

        // Confirmed: the confirmed set, whatever was read.
        $hash = confirmed::fields_hash('', self::SAM_TEXT, '', 1.0);
        [$clean] = confirmed::validate([['label' => 'Takes an action', 'max' => 2]], 2.0);
        $record = confirmed::save(3, 4, $clean, 2.0, $hash, 'questiontext', 7);
        $state = confirmed::state(3, 4, $hash, 2.0);
        $applied = confirmed::apply($resolved, $state);
        $this->assertSame(criteria::CERTAIN, $applied['confidence']);
        $this->assertSame(confirmed::SOURCE, $applied['source']);
        $this->assertSame('Takes an action', $applied['criteria'][0]['label']);
        $this->assertSame([], $applied['warnings']);
        $this->assertSame('Takes an action', confirmed::proposal($resolved, $record)[0]['label']);

        // Levels of answer are not proposed as criteria.
        $bands = criteria::for_fields('<p>2 marks: full answer with an example</p><p>1 mark: partial answer</p>'
            . '<p>0 marks: not attempted</p>', '', '', 2.0, 2.0);
        $this->assertContains('bands', $bands['reasons']);
        $this->assertSame([], confirmed::proposal($bands, null));

        // Acceptable points in a pick-list are not proposed as criteria.
        $pick = criteria::for_fields(
            '<p>Award 1 mark for each correct barrier, up to 4 marks. Acceptable answers include:</p>'
            . '<ul><li>Language</li><li>Hearing</li><li>Culture</li><li>Jargon</li><li>Noise</li><li>Stress</li></ul>',
            '',
            '',
            4.0,
            4.0
        );
        $this->assertSame([], confirmed::proposal($pick, null));

        // Percentage weights are proposed as shares of the question's mark.
        $percent = criteria::for_fields(
            '<ul><li>Defines it (20%)</li><li>Gives examples (30%)</li><li>Prevention (50%)</li></ul>',
            '',
            '',
            10.0,
            10.0
        );
        $this->assertSame([2.0, 3.0, 5.0], array_column(confirmed::proposal($percent, null), 'max'));

        // Off: the reading as it is.
        $applied = confirmed::apply($resolved, ['state' => confirmed::STATE_OFF, 'criteria' => [], 'record' => null]);
        $this->assertCount(2, $applied['criteria']);
    }

    /**
     * The setting is on unless switched off.
     */
    public function test_required_setting(): void {
        $this->resetAfterTest();
        unset_config('criteria_confirmation', 'quiz_aigrader');
        $this->assertTrue(confirmed::required());
        set_config('criteria_confirmation', 0, 'quiz_aigrader');
        $this->assertFalse(confirmed::required());
        $this->assertSame(confirmed::STATE_OFF, confirmed::state(1, 1, 'x', 1.0)['state']);
        set_config('criteria_confirmation', 1, 'quiz_aigrader');
        $this->assertTrue(confirmed::required());
    }
}
