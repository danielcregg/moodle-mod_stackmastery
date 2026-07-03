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
 * Golden-fixture and native unit tests for the pure BKT math.
 *
 * The fixtures under tests/fixtures/ are emitted by phase3/tools/make_stackmastery_fixtures.py
 * (the phase3 Python code is the oracle); every float comparison is at tolerance 1e-9.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Tests for the pure BKT math (posterior, learn, ZPD, effective params, the two updates).
 *
 * @covers \mod_stackmastery\local\bkt
 */
final class bkt_test extends \advanced_testcase {
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
     * Family A: posterior parity with student_model.bkt_posterior, including raw extreme
     * slip/guess pairs and the den == 0 degenerates that must return the prior.
     *
     * @return void
     */
    public function test_posterior_fixtures(): void {
        $cases = self::fixture('bkt_core')['posterior'];
        $this->assertCount(209, $cases);
        foreach ($cases as $case) {
            $got = bkt::posterior((float) $case['prior'], (bool) $case['correct'], (float) $case['slip'], (float) $case['guess']);
            $this->assertEqualsWithDelta($case['expected'], $got, self::TOL, "case {$case['id']}");
        }
    }

    /**
     * Family B: learning-transition parity with student_model.bkt_learn (includes the
     * mastery-1.0 fixed point and transit 1.0).
     *
     * @return void
     */
    public function test_learn_fixtures(): void {
        $cases = self::fixture('bkt_core')['learn'];
        $this->assertCount(42, $cases);
        foreach ($cases as $case) {
            $got = bkt::learn((float) $case['mastery'], (float) $case['transit']);
            $this->assertEqualsWithDelta($case['expected'], $got, self::TOL, "case {$case['id']}");
        }
    }

    /**
     * Family C1: ZPD-fit parity with student_model.zpd_fit.
     *
     * @return void
     */
    public function test_zpd_fixtures(): void {
        $cases = self::fixture('bkt_core')['zpd'];
        $this->assertCount(30, $cases);
        foreach ($cases as $case) {
            $got = bkt::zpd_fit((int) $case['difficulty'], (float) $case['mastery']);
            $this->assertEqualsWithDelta($case['expected'], $got, self::TOL, "case {$case['id']}");
        }
    }

    /**
     * Families C2 + C3: effective-parameter parity with student_model.effective_params over
     * every real skill, plus the synthetic rows on which each clip bound actually binds.
     *
     * @return void
     */
    public function test_effective_params_fixtures(): void {
        $cases = self::fixture('bkt_core')['effective'];
        $this->assertCount(270, $cases);
        foreach ($cases as $case) {
            $got = bkt::effective_params($case['params'], (int) $case['difficulty'], (float) $case['mastery']);
            foreach ($case['expected'] as $i => $expected) {
                $this->assertEqualsWithDelta($expected, $got[$i], self::TOL, "case {$case['id']} component {$i}");
            }
        }
    }

    /**
     * Family D: the deployed per-answer update (effective params at the PRIOR, posterior,
     * learn, clamp) against the generator's update_belief_ref, including the clamp-binding
     * rows that must return exactly 0.999.
     *
     * @return void
     */
    public function test_update_belief_fixtures(): void {
        $cases = self::fixture('bkt_update')['update'];
        $this->assertCount(340, $cases);
        foreach ($cases as $case) {
            $got = bkt::update_belief((float) $case['prior'], (int) $case['difficulty'], (bool) $case['correct'], $case['params']);
            $this->assertEqualsWithDelta($case['expected'], $got, self::TOL, "case {$case['id']}");
        }
    }

    /**
     * Family F: the simulator-latent and deployed updates are distinct paths, and on the F2
     * rows the deployed belief FALLS below the prior while the latent state rises above it
     * (the Codex-#07 "belief can drop" phenomenon).
     *
     * @return void
     */
    public function test_latent_vs_deployed_fixtures(): void {
        $cases = self::fixture('bkt_update')['latent_vs_deployed'];
        $this->assertCount(28, $cases);
        foreach ($cases as $case) {
            $prior = (float) $case['prior'];
            $difficulty = (int) $case['difficulty'];
            $latent = bkt::latent_update($prior, $difficulty, $case['params']);
            $deployed = bkt::update_belief($prior, $difficulty, (bool) $case['correct'], $case['params']);
            $this->assertEqualsWithDelta($case['expected_latent'], $latent, self::TOL, "case {$case['id']} latent");
            $this->assertEqualsWithDelta($case['expected_deployed'], $deployed, self::TOL, "case {$case['id']} deployed");
            $this->assertNotSame($latent, $deployed, "case {$case['id']} paths must differ");
            if (strpos($case['id'], 'F2') === 0) {
                $this->assertLessThan($prior, $deployed, "case {$case['id']} deployed must fall");
                $this->assertGreaterThan($prior, $latent, "case {$case['id']} latent must rise");
            }
        }
    }

    /**
     * Family E1: 25-step single-skill sequences with recorded correctness flags — PHP
     * propagates its own belief, so any per-step drift compounds and surfaces here.
     *
     * @return void
     */
    public function test_sequence_fixtures_single(): void {
        $sequences = self::fixture('bkt_sequences')['single_skill'];
        $this->assertCount(8, $sequences);
        foreach ($sequences as $seq) {
            $m = (float) $seq['start'];
            foreach ($seq['steps'] as $i => $step) {
                $m = bkt::update_belief($m, (int) $step['difficulty'], (bool) $step['correct'], $seq['params']);
                $this->assertEqualsWithDelta($step['expected_after'], $m, self::TOL, "case {$seq['id']} step {$i}");
            }
        }
    }

    /**
     * The spec-§3 v1 correctness rule is total: >= 0.999, NAN counts as incorrect, INF as
     * correct, and nothing throws.
     *
     * @return void
     */
    public function test_is_correct_boundaries(): void {
        $this->assertTrue(bkt::is_correct(1.0));
        $this->assertTrue(bkt::is_correct(0.999));
        $this->assertFalse(bkt::is_correct(0.9989999999));
        $this->assertTrue(bkt::is_correct(0.99900000001));
        $this->assertFalse(bkt::is_correct(0.5));
        $this->assertFalse(bkt::is_correct(0.0));
        $this->assertFalse(bkt::is_correct(-1.0));
        $this->assertFalse(bkt::is_correct(NAN));
        $this->assertTrue(bkt::is_correct(INF));
    }

    /**
     * The guarded entry points fail fast (deterministic exceptions instead of NaN
     * propagation) on bad difficulty, out-of-range or non-finite beliefs, and bad params.
     *
     * @return void
     */
    public function test_input_guards(): void {
        $params = bkt::PARAMS['integrate'];
        $badtransit = $params;
        unset($badtransit['p_transit']);
        $nanparams = $params;
        $nanparams['p_guess'] = NAN;
        $calls = [
            'difficulty -1' => function () use ($params) {
                bkt::update_belief(0.5, -1, true, $params);
            },
            'difficulty 3' => function () use ($params) {
                bkt::update_belief(0.5, 3, true, $params);
            },
            'prior NAN' => function () use ($params) {
                bkt::update_belief(NAN, 0, true, $params);
            },
            'prior -0.1' => function () use ($params) {
                bkt::update_belief(-0.1, 0, true, $params);
            },
            'prior 1.1' => function () use ($params) {
                bkt::update_belief(1.1, 0, true, $params);
            },
            'missing p_transit' => function () use ($badtransit) {
                bkt::update_belief(0.5, 0, true, $badtransit);
            },
            'NAN p_guess' => function () use ($nanparams) {
                bkt::effective_params($nanparams, 0, 0.5);
            },
            'latent difficulty 3' => function () use ($params) {
                bkt::latent_update(0.5, 3, $params);
            },
            'latent mastery 1.1' => function () use ($params) {
                bkt::latent_update(1.1, 0, $params);
            },
        ];
        foreach ($calls as $label => $call) {
            try {
                $call();
                $this->fail("expected InvalidArgumentException for {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Spot checks of the observation model (transcribed for completeness; not part of the
     * deployed update path).
     *
     * @return void
     */
    public function test_predict_correct_spot(): void {
        $this->assertEqualsWithDelta(0.55, bkt::predict_correct(0.5, 0.1, 0.2), self::TOL);
        $this->assertEqualsWithDelta(0.2, bkt::predict_correct(0.0, 0.1, 0.2), self::TOL);
        $this->assertEqualsWithDelta(0.9, bkt::predict_correct(1.0, 0.1, 0.2), self::TOL);
    }
}
