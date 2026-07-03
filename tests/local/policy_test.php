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
 * Golden-fixture and native unit tests for the trained-policy port.
 *
 * The fixtures under tests/fixtures/ are emitted by phase3/tools/make_stackmastery_fixtures.py
 * against phase3/policy_service.py, env.py and agents.py (the oracle); float comparisons are
 * at tolerance 1e-9, states/actions/sources are exact.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Tests for the policy artifact loader, codecs, mask/heuristic pipeline and choose().
 *
 * @covers \mod_stackmastery\local\policy
 */
final class policy_test extends \advanced_testcase {
    /** @var float Absolute tolerance for float comparisons (matches the fixture meta). */
    private const TOL = 1e-9;

    /**
     * Load a committed golden-fixture file.
     *
     * @param string $name Fixture basename without extension.
     * @return array The decoded fixture data.
     */
    private static function fixture(string $name): array {
        $path = __DIR__ . '/../fixtures/' . $name . '.json';
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A minimal valid artifact meta for the tamper table.
     *
     * @return array The base meta.
     */
    private static function base_meta(): array {
        return [
            'skills' => policy::SKILLS,
            'difficulties' => bkt::DIFFICULTIES,
            'bin_edges' => [0.475, 0.725, 0.95],
            'threshold' => 0.95,
            'n_states' => 65536,
            'n_actions' => 24,
            'n_skills' => 8,
            'n_diff' => 3,
            'n_bins' => 4,
            'policy' => ['0' => 6],
        ];
    }

    /**
     * The deterministic recommend equivalent of PolicyService.recommend_next: complete
     * short-circuit, else table lookup with heuristic fallback.
     *
     * @param policy $policy The loaded policy.
     * @param array $vec The mastery vector.
     * @return array ['action' => int|null, 'source' => string].
     */
    private static function recommend(policy $policy, array $vec): array {
        if (policy::all_mastered($vec)) {
            return ['action' => null, 'source' => 'complete'];
        }
        return $policy->lookup($vec);
    }

    /**
     * Family G: state-encoding parity with env.encode — >= at every bin edge, skill 0 the
     * most-significant base-4 digit, one-ulp-below values staying in the lower bucket.
     *
     * @return void
     */
    public function test_encode_state_fixtures(): void {
        $cases = self::fixture('policy_encode')['encode'];
        $this->assertCount(153, $cases);
        foreach ($cases as $case) {
            $got = policy::encode_state($case['mastery']);
            $this->assertSame($case['expected_state'], $got, "case {$case['id']}");
        }
    }

    /**
     * Family H: the action codec round-trips all 24 actions and their names (env.decode /
     * env.encode_action / env.action_name parity).
     *
     * @return void
     */
    public function test_action_codec_fixtures(): void {
        $rows = self::fixture('policy_encode')['codec'];
        $this->assertCount(24, $rows);
        foreach ($rows as $row) {
            [$skill, $difficulty] = policy::decode_action($row['action']);
            $this->assertSame($row['skill'], $skill, "decode {$row['action']} skill");
            $this->assertSame($row['difficulty'], $difficulty, "decode {$row['action']} difficulty");
            $this->assertSame($row['name'], policy::action_name($row['action']), "name {$row['action']}");
            $encoded = policy::encode_action($row['skill'], $row['difficulty']);
            $this->assertSame($row['action'], $encoded, "encode {$row['skill']}/{$row['difficulty']}");
        }
    }

    /**
     * Family I: heuristic parity with agents.heuristic_action (np.argmin first-index
     * tie-break; strict < at the 0.475/0.725 region bounds).
     *
     * @return void
     */
    public function test_heuristic_fixtures(): void {
        $cases = self::fixture('policy_encode')['heuristic'];
        $this->assertCount(12, $cases);
        foreach ($cases as $case) {
            $got = policy::heuristic_action($case['mastery']);
            $this->assertSame($case['expected_action'], $got, "case {$case['id']}");
        }
    }

    /**
     * Family J: recommend parity with PolicyService.recommend_next on the REAL shipped
     * artifact — table hits, heuristic fallbacks and the complete short-circuit.
     *
     * @return void
     */
    public function test_recommend_parity_fixtures(): void {
        $policy = policy::load();
        $cases = self::fixture('policy_lookup')['recommend'];
        $this->assertCount(73, $cases);
        foreach ($cases as $case) {
            $expected = $case['expected'];
            $got = self::recommend($policy, $case['mastery']);
            $this->assertSame($expected['action'], $got['action'], "case {$case['id']} action");
            $this->assertSame($expected['source'], $got['source'], "case {$case['id']} source");
            if ($expected['action'] !== null) {
                [$skill, $difficulty] = policy::decode_action($got['action']);
                $this->assertSame($expected['skill'], $skill, "case {$case['id']} skill");
                $this->assertSame($expected['difficulty'], $difficulty, "case {$case['id']} difficulty");
            }
        }
    }

    /**
     * Family K: the mask transform (deselected / at-target skills read 1.0) and the lookup on
     * the masked vector, including the all-at-target complete degenerate.
     *
     * @return void
     */
    public function test_mask_fixtures(): void {
        $policy = policy::load();
        $cases = self::fixture('policy_lookup')['mask'];
        $this->assertCount(10, $cases);
        foreach ($cases as $case) {
            $masked = policy::mask_mastered($case['mastery'], $case['selected'], $case['target']);
            foreach ($case['expected_masked'] as $i => $expected) {
                $this->assertEqualsWithDelta($expected, $masked[$i], self::TOL, "case {$case['id']} masked[{$i}]");
            }
            $this->assertSame($case['expected_state'], policy::encode_state($masked), "case {$case['id']} state");
            $got = self::recommend($policy, $masked);
            $this->assertSame($case['expected_action'], $got['action'], "case {$case['id']} action");
            $this->assertSame($case['expected_source'], $got['source'], "case {$case['id']} source");
        }
    }

    /**
     * Family E2: the cross-language serve -> update -> serve loop — replay the full-vector
     * sequences through bkt::update_belief and choose() (epsilon 0, all 24 actions eligible).
     *
     * @return void
     */
    public function test_sequence_fixtures_vector(): void {
        $policy = policy::load();
        $all24 = range(0, 23);
        $sequences = self::fixture('bkt_sequences')['full_vector'];
        $this->assertCount(2, $sequences);
        foreach ($sequences as $seq) {
            $vec = array_map('floatval', $seq['start']);
            foreach ($seq['steps'] as $i => $step) {
                $skill = (int) $step['skill'];
                $correct = bkt::is_correct((float) $step['fraction']);
                $params = bkt::PARAMS[bkt::SKILLS[$skill]];
                $vec[$skill] = bkt::update_belief($vec[$skill], (int) $step['difficulty'], $correct, $params);
                foreach ($step['expected_vector'] as $k => $expected) {
                    $this->assertEqualsWithDelta($expected, $vec[$k], self::TOL, "case {$seq['id']} step {$i} vector[{$k}]");
                }
                $this->assertSame($step['expected_state'], policy::encode_state($vec), "case {$seq['id']} step {$i} state");
                $choice = $policy->choose($vec, $all24, 0.0);
                $this->assertSame($step['expected_action'], $choice['servedaction'], "case {$seq['id']} step {$i} action");
                $this->assertSame($step['expected_source'], $choice['source'], "case {$seq['id']} step {$i} source");
            }
        }
    }

    /**
     * The shipped artifact loads, matches the fixture-pinned md5, covers 2421 states, serves
     * the known spot actions for constructed states 0/1/2/1106, and gets a content-addressed
     * shipped-<sha1[:12]> version.
     *
     * @return void
     */
    public function test_shipped_policy_integrity(): void {
        $datafile = __DIR__ . '/../../data/policy.json';
        $policy = policy::load();
        $this->assertSame(2421, $policy->table_size());
        $meta = self::fixture('policy_lookup')['meta'];
        $this->assertSame($meta['policy_md5'], md5_file($datafile));
        $this->assertSame($meta['entries'], $policy->table_size());
        // Constructed bucket-representative vectors decoding known table states (doc 04 §9.2 J2).
        $spots = [
            [[0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2], 6],
            [[0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.55], 12],
            [[0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.8], 16],
            [[0.2, 0.2, 0.55, 0.2, 0.55, 0.55, 0.2, 0.8], 2],
        ];
        foreach ($spots as $i => [$vec, $action]) {
            $got = $policy->lookup($vec);
            $this->assertSame('policy', $got['source'], "spot {$i} source");
            $this->assertSame($action, $got['action'], "spot {$i} action");
        }
        $this->assertStringStartsWith('shipped-', $policy->version());
        $want = 'shipped-' . substr(sha1(file_get_contents($datafile)), 0, 12);
        $this->assertSame($want, $policy->version());
    }

    /**
     * Metadata tamper table: a present key that differs from the pinned encoding throws; an
     * absent validated key or an unknown extra key passes (PolicyService parity, documented);
     * the policy table itself is required, non-empty and bounds-checked at load.
     *
     * @return void
     */
    public function test_validate_meta_table(): void {
        $throws = [
            'wrong bin edge' => ['bin_edges', [0.5, 0.725, 0.95]],
            'wrong edge count' => ['bin_edges', [0.475, 0.725]],
            'wrong threshold' => ['threshold', 0.9],
            'wrong n_bins' => ['n_bins', 3],
            'wrong n_states' => ['n_states', 6561],
            'wrong n_skills' => ['n_skills', 7],
            'skills reordered' => ['skills', array_reverse(policy::SKILLS)],
            'skills renamed' => ['skills', array_merge(['calculus'], array_slice(policy::SKILLS, 1))],
            'wrong difficulties' => ['difficulties', ['easy', 'hard', 'medium']],
        ];
        foreach ($throws as $label => [$key, $value]) {
            $meta = self::base_meta();
            $meta[$key] = $value;
            try {
                policy::validate_meta($meta);
                $this->fail("expected InvalidArgumentException for {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
        // Absent validated key passes (Python present-keys-only parity, recorded decision).
        $meta = self::base_meta();
        unset($meta['n_states']);
        policy::validate_meta($meta);
        // Unknown extra key passes.
        $meta = self::base_meta();
        $meta['trained_on'] = 'simulator';
        policy::validate_meta($meta);
        $this->addToAssertionCount(2);
        // The policy table is required and non-empty.
        $meta = self::base_meta();
        unset($meta['policy']);
        try {
            policy::validate_meta($meta);
            $this->fail('expected InvalidArgumentException for missing policy');
        } catch (\InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }
        $meta = self::base_meta();
        $meta['policy'] = [];
        try {
            policy::validate_meta($meta);
            $this->fail('expected InvalidArgumentException for empty policy');
        } catch (\InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }
        // Table integrity at load: out-of-range state, out-of-range action, non-numeric key.
        $dir = make_request_directory();
        $tables = [
            'state 65536' => ['65536' => 0],
            'action 24' => ['0' => 24],
            'non-numeric key' => ['abc' => 0],
        ];
        $i = 0;
        foreach ($tables as $label => $table) {
            $meta = self::base_meta();
            $meta['policy'] = $table;
            $path = $dir . '/tampered' . $i++ . '.json';
            file_put_contents($path, json_encode($meta));
            try {
                policy::load($path);
                $this->fail("expected InvalidArgumentException for {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
        // A missing file fails closed with RuntimeException.
        try {
            policy::load($dir . '/nonexistent.json');
            $this->fail('expected RuntimeException for missing file');
        } catch (\RuntimeException $e) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * choose() branch coverage with an injected RNG queue: greedy/explore/strict-epsilon
     * boundary, fallback and exhausted transforms, empty-eligible and complete short-circuits,
     * and exact propensities on every path.
     *
     * @return void
     */
    public function test_choose_branches(): void {
        $policy = policy::load();
        $state0 = array_fill(0, 8, 0.2);   // Encodes to state 0 -> table action 6.
        $rng = function (array $queue): callable {
            return function () use (&$queue): float {
                return array_shift($queue);
            };
        };

        // Epsilon 0, greedy action eligible: policy source, propensity exactly 1.0.
        $r = $policy->choose($state0, range(0, 23), 0.0);
        $this->assertSame(6, $r['recommendedaction']);
        $this->assertSame(6, $r['servedaction']);
        $this->assertSame(2, $r['skill']);
        $this->assertSame(0, $r['difficulty']);
        $this->assertSame('policy', $r['source']);
        $this->assertSame(1.0, $r['propensity']);
        $this->assertSame(0, $r['state']);

        // A first draw strictly below epsilon explores; drawn index (int)(0.5 * 3) = 1; draw != mu.
        $r = $policy->choose($state0, [0, 1, 2], 0.05, $rng([0.049999, 0.5]));
        $this->assertSame(6, $r['recommendedaction']);
        $this->assertSame(1, $r['servedaction']);
        $this->assertSame('explore', $r['source']);
        $this->assertEqualsWithDelta(0.05 / 3, $r['propensity'], 1e-15);

        // Explore draw landing ON the deterministic composite keeps the explore label but the
        // mu propensity (1 - e) + e/n.
        $r = $policy->choose($state0, [6, 0], 0.05, $rng([0.0, 0.0]));
        $this->assertSame(6, $r['servedaction']);
        $this->assertSame('explore', $r['source']);
        $this->assertEqualsWithDelta((1.0 - 0.05) + 0.05 / 2, $r['propensity'], 1e-15);

        // A first draw exactly AT epsilon does NOT explore (strict <).
        $r = $policy->choose($state0, [6, 0], 0.05, $rng([0.05]));
        $this->assertSame(6, $r['servedaction']);
        $this->assertSame('policy', $r['source']);
        $this->assertEqualsWithDelta((1.0 - 0.05) + 0.05 / 2, $r['propensity'], 1e-15);

        // Unseen state (3 is not in the shipped table): heuristic fallback.
        $unseen = array_fill(0, 8, 0.1);
        $unseen[7] = 0.96;
        $r = $policy->choose($unseen, range(0, 23), 0.0);
        $this->assertSame(0, $r['servedaction']);
        $this->assertSame('fallback', $r['source']);
        $this->assertSame(3, $r['state']);

        // Greedy action ineligible: exhausted transform to the nearest-eligible action.
        $r = $policy->choose($state0, [0, 1, 2], 0.0);
        $this->assertSame(6, $r['recommendedaction']);
        $this->assertSame(0, $r['servedaction']);
        $this->assertSame('exhausted', $r['source']);
        $this->assertSame(1.0, $r['propensity']);

        // Unseen AND ineligible: exhausted overrides fallback; n=1 propensity is 1.0.
        $r = $policy->choose($unseen, [5], 0.05, $rng([0.9]));
        $this->assertSame(0, $r['recommendedaction']);
        $this->assertSame(5, $r['servedaction']);
        $this->assertSame('exhausted', $r['source']);
        $this->assertSame(1.0, $r['propensity']);

        // Empty eligible: null serve, exhausted, raw recommendation preserved.
        $r = $policy->choose($state0, [], 0.05);
        $this->assertSame(6, $r['recommendedaction']);
        $this->assertNull($r['servedaction']);
        $this->assertNull($r['skill']);
        $this->assertNull($r['difficulty']);
        $this->assertSame('exhausted', $r['source']);
        $this->assertNull($r['propensity']);

        // All masked: complete short-circuit.
        $r = $policy->choose(array_fill(0, 8, 1.0), range(0, 23), 0.05);
        $this->assertNull($r['recommendedaction']);
        $this->assertNull($r['servedaction']);
        $this->assertSame('complete', $r['source']);
        $this->assertNull($r['propensity']);
        $this->assertSame(65535, $r['state']);
    }

    /**
     * The exhausted transform: lowest-mastery skill among skills WITH eligible actions
     * (first-index tie-break), ZPD-region base difficulty, distance ties break easier.
     *
     * @return void
     */
    public function test_nearest_eligible(): void {
        // Skill 1 has the lowest mastery but no eligible action -> skill 0 wins.
        $masked = [0.5, 0.05, 0.9, 0.9, 0.9, 0.9, 0.9, 0.9];
        $this->assertSame(1, policy::nearest_eligible([0, 1, 2], $masked));

        // First-index tie-break between equal-mastery skills 0 and 1.
        $masked = [0.2, 0.2, 0.9, 0.9, 0.9, 0.9, 0.9, 0.9];
        $this->assertSame(0, policy::nearest_eligible([3, 0], $masked));

        // Base difficulty from the ZPD region of the substituted skill (0.8 -> hard).
        $masked = [0.9, 0.9, 0.8, 0.9, 0.9, 0.9, 0.9, 0.9];
        $this->assertSame(8, policy::nearest_eligible([6, 7, 8], $masked));

        // Distance tie between easy and hard around a medium base -> easier wins.
        $masked = [0.9, 0.9, 0.9, 0.5, 0.9, 0.9, 0.9, 0.9];
        $this->assertSame(9, policy::nearest_eligible([9, 11], $masked));

        // A single eligible action is returned regardless of mastery shape.
        $masked = [0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1];
        $this->assertSame(23, policy::nearest_eligible([23], $masked));

        // Nothing eligible -> null (the engine finalises the attempt).
        $this->assertNull(policy::nearest_eligible([], $masked));
    }

    /**
     * The epsilon-greedy logging propensity: (1 - e) + e/n for the deterministic composite,
     * e/n otherwise, the n=1 edge always 1.0, and the input guards.
     *
     * @return void
     */
    public function test_propensity_arithmetic(): void {
        $this->assertSame(1.0, policy::propensity(true, 0.0, 24));
        $this->assertEqualsWithDelta(0.95 + 0.05 / 24, policy::propensity(true, 0.05, 24), 1e-15);
        $this->assertEqualsWithDelta(0.05 / 24, policy::propensity(false, 0.05, 24), 1e-15);
        $this->assertSame(1.0, policy::propensity(true, 0.5, 1));
        $this->assertSame(1.0, policy::propensity(false, 0.5, 1));
        $guards = [
            'epsilon -0.1' => function () {
                policy::propensity(true, -0.1, 5);
            },
            'epsilon 1.1' => function () {
                policy::propensity(true, 1.1, 5);
            },
            'neligible 0' => function () {
                policy::propensity(true, 0.05, 0);
            },
        ];
        foreach ($guards as $label => $call) {
            try {
                $call();
                $this->fail("expected InvalidArgumentException for {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Codec and encoder guards: out-of-range actions/skills/difficulties and malformed
     * mastery vectors all fail fast.
     *
     * @return void
     */
    public function test_codec_guards(): void {
        $guards = [
            'decode -1' => function () {
                policy::decode_action(-1);
            },
            'decode 24' => function () {
                policy::decode_action(24);
            },
            'encode skill 8' => function () {
                policy::encode_action(8, 0);
            },
            'encode difficulty 3' => function () {
                policy::encode_action(0, 3);
            },
            'encode_state arity 7' => function () {
                policy::encode_state(array_fill(0, 7, 0.5));
            },
            'encode_state NAN' => function () {
                $vec = array_fill(0, 8, 0.5);
                $vec[3] = NAN;
                policy::encode_state($vec);
            },
            'encode_state 1.5' => function () {
                $vec = array_fill(0, 8, 0.5);
                $vec[3] = 1.5;
                policy::encode_state($vec);
            },
        ];
        foreach ($guards as $label => $call) {
            try {
                $call();
                $this->fail("expected InvalidArgumentException for {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
