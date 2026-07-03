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
 * Tests for the custom-topics heuristic selection engine.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Parity with the shipped core-8 heuristic, the empty-cell ladder, epsilon propensity and the
 * exhausted/complete terminals.
 *
 * @covers \mod_stackmastery\local\heuristic_selector
 */
final class heuristic_selector_test extends \advanced_testcase {
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
     * Hand-built 8-length vectors probing every branch of the deterministic pick, INCLUDING
     * the exact bin-edge boundary values 0.475 and 0.725 (strict-< semantics: exactly at the
     * lower edge is medium, exactly at the upper edge is hard) and argmin ties.
     *
     * @return array The vectors.
     */
    private static function boundary_vectors(): array {
        return [
            array_fill(0, 8, 0.2),
            array_fill(0, 8, 0.475),
            array_fill(0, 8, 0.725),
            array_fill(0, 8, 0.94999),
            [0.475, 0.2, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0],
            [0.725, 0.725, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0],
            [1.0, 1.0, 1.0, 0.4749999, 1.0, 1.0, 1.0, 1.0],
            [1.0, 1.0, 1.0, 0.475, 1.0, 1.0, 1.0, 1.0],
            [1.0, 1.0, 1.0, 0.7249999, 1.0, 1.0, 1.0, 1.0],
            [1.0, 1.0, 1.0, 0.725, 1.0, 1.0, 1.0, 1.0],
            [0.3, 0.3, 0.3, 0.3, 0.3, 0.3, 0.3, 0.3],
            [0.9, 0.8, 0.7, 0.6, 0.5, 0.4, 0.3, 0.2],
            [0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9],
            [0.95, 0.95, 0.95, 0.95, 0.95, 0.95, 0.95, 0.9499],
            array_fill(0, 8, 0.999),
        ];
    }

    /**
     * THE parity pin (spec D4): on all-core (8-length) masked vectors the deterministic pick
     * equals policy::heuristic_action exactly - across the committed golden heuristic fixture
     * family AND a hand battery that includes the 0.475/0.725 boundaries.
     *
     * @return void
     */
    public function test_deterministic_parity_with_policy_heuristic(): void {
        $cases = self::fixture('policy_encode')['heuristic'];
        $this->assertGreaterThanOrEqual(10, count($cases));
        foreach ($cases as $case) {
            $got = heuristic_selector::deterministic_action($case['mastery']);
            $this->assertSame($case['expected_action'], $got, "fixture {$case['id']}");
            $this->assertSame(policy::heuristic_action($case['mastery']), $got, "fixture {$case['id']} vs policy");
            $this->assertSame(
                policy::decode_action($got),
                heuristic_selector::decode_action($got, 8),
                "fixture {$case['id']} decode parity"
            );
        }
        foreach (self::boundary_vectors() as $i => $vector) {
            $this->assertSame(
                policy::heuristic_action($vector),
                heuristic_selector::deterministic_action($vector),
                "boundary vector {$i}"
            );
        }
    }

    /**
     * The ladder generalises policy::nearest_eligible exactly on 8-length vectors, across
     * assorted eligible sets.
     *
     * @return void
     */
    public function test_nearest_eligible_parity(): void {
        $eligiblesets = [
            [0, 1, 2],
            [2],
            [5, 11, 17, 23],
            [1, 4, 7, 10, 13, 16, 19, 22],
            [21, 22, 23],
            range(0, 23),
        ];
        foreach (self::boundary_vectors() as $i => $vector) {
            foreach ($eligiblesets as $j => $eligible) {
                $this->assertSame(
                    policy::nearest_eligible($eligible, $vector),
                    heuristic_selector::nearest_eligible($eligible, $vector),
                    "vector {$i} eligible set {$j}"
                );
            }
        }
        $this->assertNull(heuristic_selector::nearest_eligible([], array_fill(0, 8, 0.2)));
    }

    /**
     * N-ary behaviour beyond 8 codes: argmin with manifest-order tie-break, banding, and the
     * generalised action codec.
     *
     * @return void
     */
    public function test_nary_deterministic_pick(): void {
        // 10 codes; the 9th (index 8, a custom slug) is least mastered at 0.3 -> easy.
        $masked = [1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 0.3, 0.6];
        $action = heuristic_selector::deterministic_action($masked);
        $this->assertSame([8, bkt::DIFF_EASY], heuristic_selector::decode_action($action, 10));
        // Tie between index 8 and 9 breaks to the earlier manifest position.
        $masked = [1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 0.5, 0.5];
        $action = heuristic_selector::deterministic_action($masked);
        $this->assertSame([8, bkt::DIFF_MEDIUM], heuristic_selector::decode_action($action, 10));
        // Banding at the edges for a custom index: 0.725 exactly is hard.
        $masked = [1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 0.725];
        $action = heuristic_selector::deterministic_action($masked);
        $this->assertSame([8, bkt::DIFF_HARD], heuristic_selector::decode_action($action, 9));
        // The codec round-trips over N skills.
        $this->assertSame(27, heuristic_selector::encode_action(9, 0, 10));
        $this->assertSame([9, 0], heuristic_selector::decode_action(27, 10));
        // Mask parity with the core transform on 8, and N-ary masking beyond it.
        $mastery = [0.2, 0.96, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9];
        $selected = [true, true, false, true, true, true, true, true];
        $target = array_fill(0, 8, 0.95);
        $this->assertSame(
            policy::mask_mastered($mastery, $selected, $target),
            heuristic_selector::mask_mastered($mastery, $selected, $target)
        );
        $masked = heuristic_selector::mask_mastered(
            [0.2, 0.99, 0.5],
            [true, true, false],
            [0.95, 0.95, 0.95]
        );
        $this->assertSame([0.2, 1.0, 1.0], $masked);
    }

    /**
     * choose(): the deterministic composite is served with source 'heuristic' when its cell is
     * eligible; an ineligible pick ladders (same skill, nearest difficulty, lower on a tie;
     * then the next-least-mastered skill) with source 'exhausted'.
     *
     * @return void
     */
    public function test_choose_deterministic_and_ladder(): void {
        // 9 codes, custom index 8 least mastered at 0.2 -> wants (8, easy) = action 24.
        $masked = [1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 0.2];
        $all = range(0, 26);
        $decision = heuristic_selector::choose($masked, $all, 0.0);
        $this->assertSame(24, $decision['recommendedaction']);
        $this->assertSame(24, $decision['servedaction']);
        $this->assertSame([8, bkt::DIFF_EASY], [$decision['skill'], $decision['difficulty']]);
        $this->assertSame('heuristic', $decision['source']);
        $this->assertSame(1.0, $decision['propensity']);
        $this->assertNull($decision['state']);

        // Easy cell of skill 8 empty -> same skill at the nearest difficulty (medium).
        $decision = heuristic_selector::choose($masked, [25, 26, 3], 0.0);
        $this->assertSame(24, $decision['recommendedaction']);
        $this->assertSame(25, $decision['servedaction']);
        $this->assertSame('exhausted', $decision['source']);

        // Skill 8 fully drained -> the next-least-mastered skill with eligibility (index 1
        // at 0.5 -> medium band; only easy/hard offered -> distance tie breaks LOWER).
        $masked = [1.0, 0.5, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 0.2];
        $decision = heuristic_selector::choose($masked, [3, 5], 0.0);
        $this->assertSame(24, $decision['recommendedaction']);
        $this->assertSame(3, $decision['servedaction'], 'distance tie breaks to the easier cell');
        $this->assertSame('exhausted', $decision['source']);

        // No eligible action at all -> exhausted with a null served action.
        $decision = heuristic_selector::choose($masked, [], 0.0);
        $this->assertSame('exhausted', $decision['source']);
        $this->assertNull($decision['servedaction']);
        $this->assertNull($decision['propensity']);

        // All mastered -> complete short-circuit.
        $decision = heuristic_selector::choose(array_fill(0, 9, 1.0), $all, 0.0);
        $this->assertSame('complete', $decision['source']);
        $this->assertNull($decision['servedaction']);
        $this->assertNull($decision['recommendedaction']);
    }

    /**
     * Epsilon exploration: the injected RNG drives the draw; propensities are the exact
     * epsilon-mixture values shared with the policy path (chosen 1 - e + e/K, explored e/K,
     * single eligible 1).
     *
     * @return void
     */
    public function test_choose_epsilon_and_propensity(): void {
        $masked = [1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 0.2];
        $eligible = [24, 25, 26, 0];
        // First draw 0.99 >= epsilon: no exploration; the composite keeps the mixture propensity.
        $rng = self::rng_sequence([0.99]);
        $decision = heuristic_selector::choose($masked, $eligible, 0.2, $rng);
        $this->assertSame('heuristic', $decision['source']);
        $this->assertSame(24, $decision['servedaction']);
        $this->assertEqualsWithDelta(0.8 + 0.2 / 4, $decision['propensity'], 1e-12);

        // Draws 0.0 then 0.99: explore branch picks index 3 of the eligible list (action 0).
        $rng = self::rng_sequence([0.0, 0.99]);
        $decision = heuristic_selector::choose($masked, $eligible, 0.2, $rng);
        $this->assertSame('explore', $decision['source']);
        $this->assertSame(0, $decision['servedaction']);
        $this->assertEqualsWithDelta(0.2 / 4, $decision['propensity'], 1e-12);

        // An explore draw that lands on the composite keeps the 'explore' label with mu.
        $rng = self::rng_sequence([0.0, 0.0]);
        $decision = heuristic_selector::choose($masked, $eligible, 0.2, $rng);
        $this->assertSame('explore', $decision['source']);
        $this->assertSame(24, $decision['servedaction']);
        $this->assertEqualsWithDelta(0.8 + 0.2 / 4, $decision['propensity'], 1e-12);

        // A single eligible action is served with probability 1.
        $decision = heuristic_selector::choose($masked, [26], 0.2, self::rng_sequence([0.99]));
        $this->assertSame(1.0, $decision['propensity']);

        // Guards: bad epsilon and out-of-range eligible ids throw.
        try {
            heuristic_selector::choose($masked, $eligible, 1.5);
            $this->fail('expected InvalidArgumentException for epsilon 1.5');
        } catch (\InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }
        try {
            heuristic_selector::choose($masked, [27], 0.0);
            $this->fail('expected InvalidArgumentException for an out-of-range action');
        } catch (\InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * A deterministic uniform source that replays a fixed sequence.
     *
     * @param array $draws The values to return in order (then 0.5 forever).
     * @return callable The RNG.
     */
    private static function rng_sequence(array $draws): callable {
        $i = 0;
        return function () use ($draws, &$i) {
            return $draws[$i++] ?? 0.5;
        };
    }
}
