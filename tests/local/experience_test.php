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
 * Tests for the experience log writer.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * log_step validation, version stamping, duplicate rejection and the cross-language
 * mask/encode parity fixture (tests/fixtures/experience_parity.json).
 *
 * @covers \mod_stackmastery\local\experience
 */
final class experience_test extends \advanced_testcase {
    /**
     * Common setup.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Create a course, instance and one persisted attempt row.
     *
     * @param array $overrides Attempt column overrides.
     * @return \stdClass The attempt record with id.
     */
    private function make_attempt(array $overrides = []): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('stackmastery', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $mastery = experience::encode_mastery(array_fill(0, 8, 0.2));
        $record = (object) array_merge([
            'stackmasteryid' => $instance->id,
            'userid' => $user->id,
            'attemptnumber' => 1,
            'qubaid' => 0,
            'state' => 'inprogress',
            'finishreason' => null,
            'inprogressuniq' => 0,
            'currentslot' => 0,
            'pendingjson' => null,
            'preview' => 0,
            'masterycurrent' => $mastery,
            'skillssnapshot' => implode(',', bkt::SKILLS),
            'targetsnapshot' => json_encode(array_combine(bkt::SKILLS, array_fill(0, 8, 0.95))),
            'budget' => 40,
            'questionsdone' => 0,
            'reachedtarget' => 0,
            'stepstotarget' => null,
            'timetargetreached' => null,
            'masteryfinal' => null,
            'policyversion' => 'shipped-abc123def456',
            'bktmodelversion' => 'bkt-1:default',
            'timeexported' => 0,
            'timestart' => time() - 600,
            'timefinish' => 0,
            'timemodified' => time(),
        ], $overrides);
        $record->id = $DB->insert_record('stackmastery_attempts', $record);
        return $record;
    }

    /**
     * A valid decision struct.
     *
     * @param array $overrides Field overrides.
     * @return array The decision.
     */
    private function decision(array $overrides = []): array {
        return array_merge([
            'recommendedskill' => 'factor',
            'recommendeddifficulty' => 'medium',
            'servedskill' => 'factor',
            'serveddifficulty' => 'medium',
            'actionsource' => 'policy',
            'propensity' => 0.95208333,
            'policyversion' => 'shipped-abc123def456',
        ], $overrides);
    }

    /**
     * A valid question struct.
     *
     * @param array $overrides Field overrides.
     * @return array The question.
     */
    private function question(array $overrides = []): array {
        return array_merge([
            'questionid' => 11,
            'questionbankentryid' => 55,
            'questionversion' => 2,
            'slot' => 7,
            'variant' => 3,
            'stackseed' => 12345,
        ], $overrides);
    }

    /**
     * A valid outcome struct.
     *
     * @param array $overrides Field overrides.
     * @return array The outcome.
     */
    private function outcome(array $overrides = []): array {
        $before = array_fill(0, 8, 0.2);
        $after = $before;
        $after[3] = 0.35;
        return array_merge([
            'fraction' => 1.0,
            'masterybefore' => $before,
            'masteryafter' => $after,
        ], $overrides);
    }

    /**
     * Happy path: the row round-trips every field and stamps every version.
     *
     * @return void
     */
    public function test_log_step_round_trip(): void {
        global $DB;
        $attempt = $this->make_attempt();
        $id = experience::log_step($attempt, 1, $this->decision(), $this->question(), $this->outcome());
        $row = $DB->get_record('stackmastery_steps', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals($attempt->id, $row->attemptid);
        $this->assertEquals(1, $row->seq);
        $this->assertEquals(7, $row->slot);
        $this->assertEquals(11, $row->questionid);
        $this->assertEquals(55, $row->questionbankentryid);
        $this->assertEquals(2, $row->questionversion);
        $this->assertEquals(3, $row->variant);
        $this->assertEquals(12345, $row->stackseed);
        $this->assertSame('factor', $row->recommendedskill);
        $this->assertSame('medium', $row->recommendeddifficulty);
        $this->assertSame('factor', $row->servedskill);
        $this->assertSame('medium', $row->serveddifficulty);
        $this->assertSame('policy', $row->actionsource);
        $this->assertEqualsWithDelta(0.95208333, (float) $row->propensity, 1e-9);
        $this->assertEquals(1, $row->correct);
        $this->assertEqualsWithDelta(1.0, (float) $row->fraction, 1e-9);
        $this->assertSame('shipped-abc123def456', $row->policyversion);
        $this->assertSame('bkt-1:default', $row->bktmodelversion);
        $this->assertSame(policy::ENCODING_VERSION, $row->stateencodingversion);
        $this->assertSame(experience::REWARD_VERSION, $row->rewardversion);
        $this->assertGreaterThan(0, (int) $row->timeanswered);
        $before = json_decode($row->masterybefore, true);
        $this->assertSame(bkt::SKILLS, array_keys($before));
        $this->assertEqualsWithDelta(0.2, $before['differentiate'], 1e-12);
        $after = json_decode($row->masteryafter, true);
        $this->assertEqualsWithDelta(0.35, $after['factor'], 1e-12);
    }

    /**
     * The same-transaction rule is enforced, not hoped for.
     *
     * @return void
     */
    public function test_requires_transaction(): void {
        // Opt out of transaction-based reset so no transaction is open around this test.
        $this->preventResetByRollback();
        $attempt = (object) ['id' => 1, 'bktmodelversion' => 'bkt-1:default'];
        $this->expectException(\coding_exception::class);
        experience::log_step($attempt, 1, $this->decision(), $this->question(), $this->outcome());
    }

    /**
     * Unknown enum codes are rejected.
     *
     * @return void
     */
    public function test_rejects_bad_enums(): void {
        $attempt = $this->make_attempt();
        $bad = [
            ['actionsource' => 'lucky'],
            ['recommendedskill' => 'algebra'],
            ['servedskill' => 'simplify_lowest_terms'],
            ['recommendeddifficulty' => 'extreme'],
            ['serveddifficulty' => 0],
        ];
        foreach ($bad as $override) {
            try {
                experience::log_step(
                    $attempt,
                    1,
                    $this->decision($override),
                    $this->question(),
                    $this->outcome()
                );
                $this->fail('expected coding_exception for ' . json_encode($override));
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('must be one of', $e->getMessage());
            }
        }
    }

    /**
     * Propensity must be a finite probability in (0, 1].
     *
     * @return void
     */
    public function test_rejects_bad_propensity(): void {
        $attempt = $this->make_attempt();
        foreach ([0.0, -0.1, 1.5, NAN, 'high', null] as $value) {
            try {
                experience::log_step(
                    $attempt,
                    1,
                    $this->decision(['propensity' => $value]),
                    $this->question(),
                    $this->outcome()
                );
                $this->fail('expected coding_exception for propensity ' . json_encode($value));
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('propensity', $e->getMessage());
            }
        }
    }

    /**
     * Mastery vectors must be 8 finite values in [0,1].
     *
     * @return void
     */
    public function test_rejects_bad_mastery_vectors(): void {
        $attempt = $this->make_attempt();
        $bad = [
            array_fill(0, 7, 0.2),
            array_fill(0, 9, 0.2),
            array_merge(array_fill(0, 7, 0.2), [1.5]),
            array_merge(array_fill(0, 7, 0.2), [NAN]),
            array_merge(array_fill(0, 7, 0.2), ['0.2x']),
        ];
        foreach ($bad as $vector) {
            try {
                experience::log_step(
                    $attempt,
                    1,
                    $this->decision(),
                    $this->question(),
                    $this->outcome(['masteryafter' => $vector])
                );
                $this->fail('expected coding_exception for a bad mastery vector');
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('mastery', $e->getMessage());
            }
        }
    }

    /**
     * The v1 correctness mapping: derived from fraction, explicit when null, drift trapped.
     *
     * @return void
     */
    public function test_correct_derivation(): void {
        global $DB;
        $attempt = $this->make_attempt();
        $id = experience::log_step(
            $attempt,
            1,
            $this->decision(),
            $this->question(['slot' => 1]),
            $this->outcome(['fraction' => 1.0])
        );
        $this->assertEquals(1, $DB->get_field('stackmastery_steps', 'correct', ['id' => $id]));
        $id = experience::log_step(
            $attempt,
            2,
            $this->decision(),
            $this->question(['slot' => 2]),
            $this->outcome(['fraction' => 0.9989])
        );
        $this->assertEquals(0, $DB->get_field('stackmastery_steps', 'correct', ['id' => $id]));
        $id = experience::log_step(
            $attempt,
            3,
            $this->decision(),
            $this->question(['slot' => 3]),
            $this->outcome(['fraction' => null, 'correct' => true])
        );
        $row = $DB->get_record('stackmastery_steps', ['id' => $id]);
        $this->assertEquals(1, $row->correct);
        $this->assertNull($row->fraction);
        // A null fraction with no explicit correct is a programming error.
        try {
            experience::log_step(
                $attempt,
                4,
                $this->decision(),
                $this->question(['slot' => 4]),
                $this->outcome(['fraction' => null])
            );
            $this->fail('expected coding_exception for a missing correct');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('correct', $e->getMessage());
        }
        // An inconsistent supplied pair catches drift between the grader path and the caller.
        try {
            experience::log_step(
                $attempt,
                4,
                $this->decision(),
                $this->question(['slot' => 4]),
                $this->outcome(['fraction' => 1.0, 'correct' => false])
            );
            $this->fail('expected coding_exception for an inconsistent pair');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('contradicts', $e->getMessage());
        }
    }

    /**
     * Duplicate (attemptid, seq) and (attemptid, slot) are rejected before the DB backstop.
     *
     * @return void
     */
    public function test_rejects_duplicates(): void {
        $attempt = $this->make_attempt();
        experience::log_step(
            $attempt,
            1,
            $this->decision(),
            $this->question(['slot' => 1]),
            $this->outcome()
        );
        try {
            experience::log_step(
                $attempt,
                1,
                $this->decision(),
                $this->question(['slot' => 2]),
                $this->outcome()
            );
            $this->fail('expected coding_exception for a duplicate seq');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('duplicate', $e->getMessage());
        }
        try {
            experience::log_step(
                $attempt,
                2,
                $this->decision(),
                $this->question(['slot' => 1]),
                $this->outcome()
            );
            $this->fail('expected coding_exception for a duplicate slot');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('duplicate', $e->getMessage());
        }
    }

    /**
     * encode_mastery accepts positional and skill-keyed vectors and emits canonical objects.
     *
     * @return void
     */
    public function test_encode_mastery_positional_and_keyed(): void {
        $positional = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8];
        $keyed = array_combine(bkt::SKILLS, $positional);
        $this->assertSame(experience::encode_mastery($positional), experience::encode_mastery($keyed));
        $decoded = json_decode(experience::encode_mastery($keyed), true);
        $this->assertSame(bkt::SKILLS, array_keys($decoded));
        // A keyed array missing one canonical skill is rejected.
        $broken = $keyed;
        unset($broken['factor']);
        $broken['algebra'] = 0.4;
        $this->expectException(\coding_exception::class);
        experience::encode_mastery($broken);
    }

    /**
     * The bkt model version comes from the attempt's pin, falling back to the constant.
     *
     * @return void
     */
    public function test_bkt_model_version_fallback(): void {
        $pinned = (object) ['bktmodelversion' => 'bkt-1:fitted:abc12345'];
        $this->assertSame('bkt-1:fitted:abc12345', experience::bkt_model_version($pinned));
        $this->assertSame(bkt::MODEL_VERSION, experience::bkt_model_version((object) []));
        $this->assertSame(bkt::MODEL_VERSION, experience::bkt_model_version(
            (object) ['bktmodelversion' => '']
        ));
    }

    /**
     * Cross-language parity: the committed experience_parity.json mask/encode cases hold
     * against the PHP mask/encode the runtime and the export feed (python consumes the same
     * file in phase3/test_adapt_moodle_experience.py).
     *
     * @return void
     */
    public function test_mask_encode_parity_fixture(): void {
        $path = __DIR__ . '/../fixtures/experience_parity.json';
        $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(bkt::SKILLS, $fixture['_meta']['skills']);
        $cases = $fixture['mask_encode'];
        $this->assertGreaterThanOrEqual(40, count($cases));
        foreach ($cases as $case) {
            $masked = policy::mask_mastered($case['mastery'], $case['selected'], $case['target']);
            foreach ($case['masked'] as $i => $expected) {
                $this->assertEqualsWithDelta($expected, $masked[$i], 1e-9, $case['id'] . " [{$i}]");
            }
            $this->assertSame($case['state_index'], policy::encode_state($masked), $case['id']);
        }
    }
}
