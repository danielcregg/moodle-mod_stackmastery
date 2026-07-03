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
 * Pure static Bayesian-Knowledge-Tracing math for the adaptive mastery loop.
 *
 * Transcribed exactly (identical literals and operation order, so PHP doubles match numpy
 * float64 bit-for-bit) from phase3/student_model.py; the deployed per-answer composition
 * update_belief() is defined by docs/plans/stackmastery/04-bkt-policy-ports.md and pinned by
 * the golden fixtures in tests/fixtures/. This class has NO Moodle dependencies (SPL
 * exceptions only) so the standalone fixture runner can require it without a bootstrap.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Pure BKT math: the Bayes posterior on an observed answer, the learning transition, the
 * ZPD-scaled effective parameters, and the two named per-answer update compositions
 * (deployed vs simulator-latent — kept distinct so no porter can conflate them).
 */
final class bkt {
    /** @var string Version tag logged per step as bktmodelversion (algo:params composite). */
    public const MODEL_VERSION = 'bkt-1:default';

    /** @var string[] Canonical skill order — the single source; MUST match policy.json "skills". */
    public const SKILLS = ['differentiate', 'integrate', 'expand', 'factor',
                           'simplify', 'solve_linear', 'solve_quadratic', 'numerical'];

    /** @var string[] Difficulty names in index order (matches student_model.DIFFICULTIES). */
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    /** @var int Difficulty index for easy questions. */
    public const DIFF_EASY = 0;

    /** @var int Difficulty index for medium questions. */
    public const DIFF_MEDIUM = 1;

    /** @var int Difficulty index for hard questions. */
    public const DIFF_HARD = 2;

    /**
     * Default per-skill parameters == student_model.DEFAULT_SKILLS (what the shipped
     * policy.json was trained against, skills_source=default). A future fitted_skills.json is
     * passed as an override array to the pure functions; these constants are the v1 baseline.
     *
     * @var array<string, array<string, float>>
     */
    public const PARAMS = [
        'differentiate'   => ['p_init' => 0.20, 'p_transit' => 0.18, 'p_slip' => 0.10, 'p_guess' => 0.15],
        'integrate'       => ['p_init' => 0.10, 'p_transit' => 0.10, 'p_slip' => 0.12, 'p_guess' => 0.12],
        'expand'          => ['p_init' => 0.30, 'p_transit' => 0.25, 'p_slip' => 0.08, 'p_guess' => 0.20],
        'factor'          => ['p_init' => 0.18, 'p_transit' => 0.15, 'p_slip' => 0.12, 'p_guess' => 0.15],
        'simplify'        => ['p_init' => 0.22, 'p_transit' => 0.20, 'p_slip' => 0.10, 'p_guess' => 0.18],
        'solve_linear'    => ['p_init' => 0.35, 'p_transit' => 0.28, 'p_slip' => 0.07, 'p_guess' => 0.22],
        'solve_quadratic' => ['p_init' => 0.12, 'p_transit' => 0.12, 'p_slip' => 0.13, 'p_guess' => 0.13],
        'numerical'       => ['p_init' => 0.25, 'p_transit' => 0.20, 'p_slip' => 0.10, 'p_guess' => 0.20],
    ];

    /** @var float[] ZPD target mastery per difficulty (student_model._DIFF_TARGET). */
    public const ZPD_TARGET = [0.35, 0.60, 0.85];

    /** @var float[] Guess-rate multiplier per difficulty (student_model._DIFF_GUESS_MULT). */
    public const GUESS_MULT = [1.30, 1.00, 0.60];

    /** @var float[] Slip-rate multiplier per difficulty (student_model._DIFF_SLIP_MULT). */
    public const SLIP_MULT = [0.85, 1.00, 1.25];

    /** @var float Width of the Gaussian ZPD bump (student_model._ZPD_WIDTH). */
    public const ZPD_WIDTH = 0.20;

    /** @var float Floor of the ZPD fit for badly matched questions (student_model._ZPD_FLOOR). */
    public const ZPD_FLOOR = 0.08;

    /** @var float STACK fraction at/above which an answer counts as BKT-correct (spec §3, v1). */
    public const CORRECT_FRACTION = 0.999;

    /** @var float Upper belief clamp — prevents the absorbing belief-1.0 state (mirrors mastery.js). */
    public const BELIEF_MAX = 0.999;

    /**
     * How well a difficulty matches the current mastery: 1.0 when matched, falling to the
     * floor when not. Pure transcription of student_model.zpd_fit (guard-free by design).
     *
     * @param int $difficulty Difficulty index 0..2.
     * @param float $mastery Current mastery in [0,1].
     * @return float The ZPD fit multiplier in [ZPD_FLOOR, 1].
     */
    public static function zpd_fit(int $difficulty, float $mastery): float {
        $delta = self::ZPD_TARGET[$difficulty] - $mastery;
        return self::ZPD_FLOOR + (1.0 - self::ZPD_FLOOR)
            * exp(-($delta * $delta) / (2.0 * self::ZPD_WIDTH ** 2));
    }

    /**
     * The (slip, guess, transit) actually in force for one question, given its difficulty and
     * the student's current mastery (student_model.effective_params; np.clip == min/max here).
     *
     * @param array $params Per-skill parameters carrying p_slip, p_guess and p_transit.
     * @param int $difficulty Difficulty index 0..2.
     * @param float $mastery Current mastery in [0,1].
     * @return float[] Positional [slip, guess, transit] — matches the Python tuple order.
     */
    public static function effective_params(array $params, int $difficulty, float $mastery): array {
        self::require_difficulty($difficulty);
        self::require_prob($mastery, 'mastery');
        self::require_params($params);
        $guess = self::clip((float) $params['p_guess'] * self::GUESS_MULT[$difficulty], 0.02, 0.60);
        $slip = self::clip((float) $params['p_slip'] * self::SLIP_MULT[$difficulty], 0.02, 0.40);
        $transit = self::clip((float) $params['p_transit'] * self::zpd_fit($difficulty, $mastery), 0.0, 0.95);
        return [$slip, $guess, $transit];
    }

    /**
     * Learning transition: an un-mastered skill is partly learned this step
     * (student_model.bkt_learn).
     *
     * @param float $mastery Current mastery.
     * @param float $transit Effective learning rate for this question.
     * @return float The post-learning mastery.
     */
    public static function learn(float $mastery, float $transit): float {
        return $mastery + (1.0 - $mastery) * $transit;
    }

    /**
     * Observation model: P(correct) at this mastery level (student_model.predict_correct).
     *
     * @param float $mastery Current mastery.
     * @param float $slip Effective slip rate.
     * @param float $guess Effective guess rate.
     * @return float The probability of a correct answer.
     */
    public static function predict_correct(float $mastery, float $slip, float $guess): float {
        return $mastery * (1.0 - $slip) + (1.0 - $mastery) * $guess;
    }

    /**
     * Bayes update P(known | one observed answer) — student_model.bkt_posterior. NOTE the
     * incorrect branch uses (1 - guess), not (1 - slip). A zero denominator returns the prior.
     *
     * @param float $prior Prior belief the skill is known.
     * @param bool $correct Whether the observed answer was correct.
     * @param float $slip Effective slip rate.
     * @param float $guess Effective guess rate.
     * @return float The posterior belief.
     */
    public static function posterior(float $prior, bool $correct, float $slip, float $guess): float {
        if ($correct) {
            $num = $prior * (1.0 - $slip);
            $den = $num + (1.0 - $prior) * $guess;
        } else {
            $num = $prior * $slip;
            $den = $num + (1.0 - $prior) * (1.0 - $guess);
        }
        return $den > 0.0 ? $num / $den : $prior;
    }

    /**
     * Whether a STACK fraction counts as a BKT-correct answer (spec §3 v1 rule). Total
     * function: NAN >= 0.999 is false, so a weird fraction is simply incorrect, never fatal.
     *
     * @param float $fraction The raw STACK fraction.
     * @return bool True when the fraction is at or above CORRECT_FRACTION.
     */
    public static function is_correct(float $fraction): bool {
        return $fraction >= self::CORRECT_FRACTION;
    }

    /**
     * THE deployed per-answer belief update (design 04 §1): effective parameters evaluated at
     * the PRIOR belief (the belief the question was selected against), Bayes posterior on the
     * observed answer, learning transition, then clamp to [0, BELIEF_MAX]. Difficulty
     * conditioning preserves the anti-gaming property: an easy question's inflated guess rate
     * makes a correct answer less informative.
     *
     * @param float $prior Belief before this answer, in [0,1].
     * @param int $difficulty Difficulty index 0..2.
     * @param bool $correct Whether the answer was BKT-correct.
     * @param array $params Per-skill parameters carrying p_slip, p_guess and p_transit.
     * @return float The updated belief in [0, BELIEF_MAX].
     */
    public static function update_belief(float $prior, int $difficulty, bool $correct, array $params): float {
        [$slip, $guess, $transit] = self::effective_params($params, $difficulty, $prior);
        $post = self::posterior($prior, $correct, $slip, $guess);
        return self::clip(self::learn($post, $transit), 0.0, self::BELIEF_MAX);
    }

    /**
     * The SIMULATOR-latent update (StudentSimulator.practice dynamics): learning transition
     * only — correctness never moves the state. Reference/tests only; never called by the
     * runtime serving path. Kept so the two update paths stay named, distinct and fixtured.
     *
     * @param float $mastery Latent mastery before practising, in [0,1].
     * @param int $difficulty Difficulty index 0..2.
     * @param array $params Per-skill parameters carrying p_slip, p_guess and p_transit.
     * @return float The post-practice latent mastery.
     */
    public static function latent_update(float $mastery, int $difficulty, array $params): float {
        $effective = self::effective_params($params, $difficulty, $mastery);
        return self::learn($mastery, $effective[2]);
    }

    /**
     * Clamp a finite value into [lo, hi] (np.clip for finite scalars).
     *
     * @param float $x The value.
     * @param float $lo Lower bound.
     * @param float $hi Upper bound.
     * @return float The clamped value.
     */
    private static function clip(float $x, float $lo, float $hi): float {
        return min(max($x, $lo), $hi);
    }

    /**
     * Fail fast on an invalid difficulty index.
     *
     * @param int $difficulty The difficulty index to validate.
     * @return void
     */
    private static function require_difficulty(int $difficulty): void {
        if ($difficulty < 0 || $difficulty > 2) {
            throw new \InvalidArgumentException("difficulty must be 0, 1 or 2, got {$difficulty}");
        }
    }

    /**
     * Fail fast on a non-finite or out-of-range probability (deterministic failure instead of
     * NaN propagation — PHP min/max are order-dependent under NAN, unlike np.clip).
     *
     * @param float $p The value to validate.
     * @param string $what Name used in the exception message.
     * @return void
     */
    private static function require_prob(float $p, string $what): void {
        if (!is_finite($p) || $p < 0.0 || $p > 1.0) {
            $repr = self::float_repr($p);
            throw new \InvalidArgumentException("{$what} must be a finite probability in [0,1], got {$repr}");
        }
    }

    /**
     * Render a float for an exception message. NAN/INF-safe: PHP 8.5+ warns when a NAN is
     * coerced to string, and a warning inside an exception path would mask the real error.
     *
     * @param float $x The value.
     * @return string A warning-free representation.
     */
    private static function float_repr(float $x): string {
        if (is_nan($x)) {
            return 'NAN';
        }
        if (!is_finite($x)) {
            return $x > 0 ? 'INF' : '-INF';
        }
        return (string) $x;
    }

    /**
     * Fail fast on a params array missing a rate key or holding a non-finite value.
     *
     * @param array $params The per-skill parameter array to validate.
     * @return void
     */
    private static function require_params(array $params): void {
        foreach (['p_slip', 'p_guess', 'p_transit'] as $key) {
            if (!array_key_exists($key, $params)) {
                throw new \InvalidArgumentException("params is missing {$key}");
            }
            if (!is_numeric($params[$key]) || !is_finite((float) $params[$key])) {
                throw new \InvalidArgumentException("params {$key} must be a finite number");
            }
        }
    }
}
