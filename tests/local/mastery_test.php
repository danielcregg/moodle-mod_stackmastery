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
 * Unit tests for the per-attempt mastery belief-vector wrapper.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Tests for mastery: init, strict JSON codecs, apply_result and the target queries.
 *
 * @covers \mod_stackmastery\local\mastery
 */
final class mastery_test extends \advanced_testcase {
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
     * A fresh vector holds each skill's p_init in canonical order and reports the default
     * model version 'bkt-1:default' (master plan C12).
     *
     * @return void
     */
    public function test_init_defaults(): void {
        $mastery = mastery::init();
        $this->assertSame([0.20, 0.10, 0.30, 0.18, 0.22, 0.35, 0.12, 0.25], $mastery->vector());
        $this->assertSame('bkt-1:default', $mastery->model_version());
        $this->assertSame(0.10, $mastery->get('integrate'));
        $this->assertSame(0.10, $mastery->get(1));
    }

    /**
     * from_json(to_json()) round-trips bit-identically, the emitted object is keyed by skill
     * code in canonical order, serialize_precision is the shortest-round-trip default (the
     * canary this codec relies on), and long-mantissa values survive.
     *
     * @return void
     */
    public function test_json_round_trip(): void {
        $this->assertSame('-1', ini_get('serialize_precision'));
        $mastery = mastery::init();
        $mastery->apply_result(1, 0, 1.0);   // Put a full-mantissa float into the vector.
        $json = $mastery->to_json();
        $again = mastery::from_json($json);
        $this->assertSame($mastery->vector(), $again->vector());
        $this->assertSame(bkt::SKILLS, array_keys(json_decode($json, true)));
        // A hand-pinned long-mantissa value survives parse and re-emit exactly (worked anchor
        // ex1; 0.4206643873472267 is the shortest round-trip form both Python's json and PHP's
        // serialize_precision=-1 emit for this double).
        $handjson = '{"differentiate":0.2,"integrate":0.4206643873472267,"expand":0.3,'
            . '"factor":0.18,"simplify":0.22,"solve_linear":0.35,"solve_quadratic":0.12,'
            . '"numerical":0.25}';
        $parsed = mastery::from_json($handjson);
        $this->assertSame(0.42066438734722672, $parsed->get('integrate'));
        $this->assertSame($handjson, $parsed->to_json());
    }

    /**
     * The strict JSON parser rejects invalid JSON, wrong key sets, non-numeric values and
     * out-of-range or non-finite values — a corrupt column fails loudly at resume.
     *
     * @return void
     */
    public function test_from_json_guards(): void {
        $valid = mastery::init()->to_json();
        $missing = json_decode($valid, true);
        unset($missing['numerical']);
        $extra = json_decode($valid, true);
        $extra['extra_skill'] = 0.5;
        $renamed = json_decode($valid, true);
        unset($renamed['expand']);
        $renamed['expanded'] = 0.3;
        $toobig = json_decode($valid, true);
        $toobig['factor'] = 1.5;
        $negative = json_decode($valid, true);
        $negative['factor'] = -0.1;
        $string = json_decode($valid, true);
        $string['factor'] = '0.5';
        $badjsons = [
            'invalid json' => '{',
            'non-object scalar' => '"0.5"',
            'positional array' => '[0.2,0.1,0.3,0.18,0.22,0.35,0.12,0.25]',
            'missing skill' => json_encode($missing),
            'extra skill' => json_encode($extra),
            'renamed skill' => json_encode($renamed),
            'value 1.5' => json_encode($toobig),
            'value -0.1' => json_encode($negative),
            'string value' => json_encode($string),
            'non-finite value' => str_replace('"factor":0.18', '"factor":1e999', $valid),
        ];
        foreach ($badjsons as $label => $json) {
            try {
                mastery::from_json($json);
                $this->fail("expected InvalidArgumentException for {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Replaying the first 10 steps of each full-vector sequence fixture through apply_result
     * (fraction-driven) reproduces the golden vectors — the wrapper composes is_correct and
     * update_belief identically to the bkt-level fixtures.
     *
     * @return void
     */
    public function test_apply_result_fixture_spot(): void {
        $sequences = self::fixture('bkt_sequences')['full_vector'];
        $this->assertCount(2, $sequences);
        foreach ($sequences as $seq) {
            $json = json_encode(array_combine(bkt::SKILLS, $seq['start']));
            $mastery = mastery::from_json($json);
            foreach (array_slice($seq['steps'], 0, 10) as $i => $step) {
                $result = $mastery->apply_result((int) $step['skill'], (int) $step['difficulty'], (float) $step['fraction']);
                $correct = (float) $step['fraction'] >= 0.999;
                $this->assertSame($correct, $result['correct'], "case {$seq['id']} step {$i} correct");
                $vector = $mastery->vector();
                foreach ($step['expected_vector'] as $k => $expected) {
                    $this->assertEqualsWithDelta($expected, $vector[$k], self::TOL, "case {$seq['id']} step {$i} vector[{$k}]");
                }
            }
        }
    }

    /**
     * apply_result returns the steps-row ingredients, mutates only the target skill, and
     * reproduces worked anchor ex1 (integrate, easy, prior 0.10, fraction 1.0 ->
     * 0.42066438734722672).
     *
     * @return void
     */
    public function test_apply_result_shape(): void {
        $mastery = mastery::init();
        $result = $mastery->apply_result(1, bkt::DIFF_EASY, 1.0);
        $this->assertSame(['before', 'after', 'correct'], array_keys($result));
        $this->assertSame(0.10, $result['before']);
        $this->assertTrue($result['correct']);
        $this->assertEqualsWithDelta(0.42066438734722672, $result['after'], self::TOL);
        $this->assertEqualsWithDelta(0.42066438734722672, $mastery->get(1), self::TOL);
        // Every other skill is untouched.
        $expected = [0.20, 0.10, 0.30, 0.18, 0.22, 0.35, 0.12, 0.25];
        foreach ($mastery->vector() as $i => $belief) {
            if ($i !== 1) {
                $this->assertSame($expected[$i], $belief, "skill {$i} must be untouched");
            }
        }
        // A wrong answer counts as incorrect and can lower the belief.
        $result = $mastery->apply_result(1, bkt::DIFF_EASY, 0.5);
        $this->assertFalse($result['correct']);
        // Guards: bad skill index and bad difficulty.
        try {
            $mastery->apply_result(8, 0, 1.0);
            $this->fail('expected InvalidArgumentException for skill 8');
        } catch (\InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }
        try {
            $mastery->apply_result(0, 3, 1.0);
            $this->fail('expected InvalidArgumentException for difficulty 3');
        } catch (\InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * reached() uses >= semantics (exactly-at-target counts), and all_reached() honours the
     * selected subset — with an empty selection vacuously true (recorded decision; the engine
     * guards against creating such an instance).
     *
     * @return void
     */
    public function test_reached_and_all_reached(): void {
        $json = '{"differentiate":0.95,"integrate":0.9,"expand":0.96,"factor":0.1,'
            . '"simplify":0.95,"solve_linear":0.99,"solve_quadratic":0.2,"numerical":0.94}';
        $mastery = mastery::from_json($json);
        $target = array_fill(0, 8, 0.95);
        $expectedreached = [true, false, true, false, true, true, false, false];
        $this->assertSame($expectedreached, $mastery->reached($target));
        $allselected = array_fill(0, 8, true);
        $this->assertFalse($mastery->all_reached($allselected, $target));
        // Only the at-target skills selected: reached.
        $selected = [true, false, true, false, true, true, false, false];
        $this->assertTrue($mastery->all_reached($selected, $target));
        // One below-target skill selected: not reached.
        $selected = [true, true, false, false, false, false, false, false];
        $this->assertFalse($mastery->all_reached($selected, $target));
        // Per-skill targets: integrate at 0.9 reaches a 0.9 target exactly.
        $target[1] = 0.9;
        $this->assertTrue($mastery->reached($target)[1]);
        // Empty selection is vacuously true.
        $this->assertTrue($mastery->all_reached(array_fill(0, 8, false), $target));
        // Arity guards.
        try {
            $mastery->reached([0.95]);
            $this->fail('expected InvalidArgumentException for short target');
        } catch (\InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * The fitted-params seam: a full custom set is accepted with its version tag propagated,
     * defaults apply when no version accompanies it, and malformed sets are rejected.
     *
     * @return void
     */
    public function test_custom_params_seam(): void {
        $params = bkt::PARAMS;
        $params['integrate']['p_init'] = 0.5;
        $mastery = mastery::init($params, 'bkt-1:fitted-test');
        $this->assertSame(0.5, $mastery->get('integrate'));
        $this->assertSame('bkt-1:fitted-test', $mastery->model_version());
        // Custom params without a version tag keep the default tag.
        $this->assertSame('bkt-1:default', mastery::init($params)->model_version());
        // The custom set drives the update math (different p_init -> different posterior).
        $default = mastery::init();
        $default->apply_result(1, bkt::DIFF_EASY, 1.0);
        $mastery->apply_result(1, bkt::DIFF_EASY, 1.0);
        $this->assertNotSame($default->get(1), $mastery->get(1));
        // Malformed sets are rejected.
        $missingskill = bkt::PARAMS;
        unset($missingskill['numerical']);
        $missingkey = bkt::PARAMS;
        unset($missingkey['expand']['p_slip']);
        $badinit = bkt::PARAMS;
        $badinit['factor']['p_init'] = 1.5;
        $nonfinite = bkt::PARAMS;
        $nonfinite['factor']['p_guess'] = NAN;
        $badsets = ['missing skill' => $missingskill, 'missing key' => $missingkey,
                    'p_init 1.5' => $badinit, 'NAN value' => $nonfinite];
        foreach ($badsets as $label => $set) {
            try {
                mastery::init($set);
                $this->fail("expected InvalidArgumentException for {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
