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
 * The ZPD heuristic selection engine for attempts that track custom topics (design D4).
 *
 * When an attempt's skill manifest carries any custom slug the trained tabular policy is never
 * consulted (its 4^8/24 geometry is meaningless over N skills); THIS class is the selection
 * brain instead. Every rule is the exact N-ary generalisation of the shipped core-8 pipeline:
 * the deterministic pick generalises policy::heuristic_action() (argmin belief over
 * below-threshold codes, manifest-order tie-break, difficulty banding with the same strict-<
 * semantics at policy::BIN_EDGES[0]/[1]), the empty-cell ladder generalises
 * policy::nearest_eligible() (same-skill nearest difficulty, lower first on a distance tie,
 * then the next-least-mastered skill), and the epsilon exploration and propensity algebra are
 * shared with the policy path via policy::propensity(). A unit test pins deterministic parity
 * with policy::heuristic_action() on all-core vectors.
 *
 * Actions are generalised ids over the manifest: action = skillindex * 3 + difficultyindex,
 * where skillindex indexes skill_manifest::codes(). For a core-only manifest these ids equal
 * the trained policy's action ids.
 *
 * No Moodle dependencies (SPL exceptions only), mirroring policy.php.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Deterministic ZPD pick, ladder redirect and epsilon-greedy serving over N manifest codes.
 */
final class heuristic_selector {
    /**
     * State encoding id stamped on every step served by this engine. Meaning: the step's
     * mastery JSON is keyed by the attempt manifest's codes (8 core + custom slugs); there is
     * NO positional tabular packing. The retrain pipeline's v1 adapter and export hard-exclude
     * this version (design D5 firewall), so 8-dim training never ingests custom rows.
     *
     * @var string
     */
    public const ENCODING_VERSION = 'enc-custom-1';

    /** @var string Policy version id stamped on custom attempts and their steps. */
    public const POLICY_VERSION = 'heuristic-1';

    /** @var int Difficulty levels per skill (shared geometry with the trained policy). */
    public const N_DIFF = policy::N_DIFF;

    /**
     * Encode a (skill, difficulty) pair to a generalised action id over n skills.
     *
     * @param int $skill Skill index in [0, nskills).
     * @param int $difficulty Difficulty index in [0, N_DIFF).
     * @param int $nskills Number of manifest codes.
     * @return int The action id.
     */
    public static function encode_action(int $skill, int $difficulty, int $nskills): int {
        if ($nskills < 1) {
            throw new \InvalidArgumentException("nskills must be >= 1, got {$nskills}");
        }
        if ($skill < 0 || $skill >= $nskills) {
            throw new \InvalidArgumentException("skill must be in [0, {$nskills}), got {$skill}");
        }
        if ($difficulty < 0 || $difficulty >= self::N_DIFF) {
            throw new \InvalidArgumentException("difficulty must be in [0, " . self::N_DIFF . "), got {$difficulty}");
        }
        return $skill * self::N_DIFF + $difficulty;
    }

    /**
     * Decode a generalised action id to its (skill, difficulty) pair.
     *
     * @param int $action Action id in [0, nskills * N_DIFF).
     * @param int $nskills Number of manifest codes.
     * @return int[] Positional [skill, difficulty].
     */
    public static function decode_action(int $action, int $nskills): array {
        $max = $nskills * self::N_DIFF;
        if ($action < 0 || $action >= $max) {
            throw new \InvalidArgumentException("action must be in [0, {$max}), got {$action}");
        }
        return [intdiv($action, self::N_DIFF), $action % self::N_DIFF];
    }

    /**
     * N-ary mask transform (policy::mask_mastered generalised): deselected and target-reached
     * codes read 1.0, so no rule below ever picks them.
     *
     * @param array $mastery Positional belief vector over the manifest codes.
     * @param array $selected Positional selected flags, same length.
     * @param array $target Positional per-code targets, same length.
     * @return float[] The masked vector.
     */
    public static function mask_mastered(array $mastery, array $selected, array $target): array {
        $mastery = array_values($mastery);
        $selected = array_values($selected);
        $target = array_values($target);
        $n = count($mastery);
        if ($n < 1 || count($selected) !== $n || count($target) !== $n) {
            throw new \InvalidArgumentException('mask_mastered expects three vectors of equal, non-zero length');
        }
        $masked = [];
        foreach ($mastery as $i => $value) {
            $masked[] = (!$selected[$i] || (float) $value >= (float) $target[$i]) ? 1.0 : (float) $value;
        }
        return $masked;
    }

    /**
     * The deterministic ZPD pick: teach the least-mastered below-threshold code at the
     * ZPD-matched difficulty. Exact generalisation of policy::heuristic_action(): argmin with
     * first-index (manifest-order) tie-break; strict < at both region bounds, so a belief of
     * exactly BIN_EDGES[0] is medium and exactly BIN_EDGES[1] is hard; an all-mastered vector
     * falls back to (0, medium) exactly as the core port does.
     *
     * @param array $masked The masked belief vector over the manifest codes.
     * @return int The generalised action id.
     */
    public static function deterministic_action(array $masked): int {
        $masked = array_values($masked);
        $n = count($masked);
        $todo = [];
        foreach ($masked as $i => $value) {
            if ((float) $value < policy::THRESHOLD) {
                $todo[] = $i;
            }
        }
        if ($todo === []) {
            return self::encode_action(0, bkt::DIFF_MEDIUM, $n);
        }
        $skill = $todo[0];
        foreach ($todo as $i) {
            if ($masked[$i] < $masked[$skill]) {
                $skill = $i;
            }
        }
        return self::encode_action($skill, self::band((float) $masked[$skill]), $n);
    }

    /**
     * The empty-cell ladder (policy::nearest_eligible generalised): of the codes that still
     * have an eligible action, take the least-mastered (first-index tie-break) at the eligible
     * difficulty nearest its ZPD band (a distance tie breaks easier). Because the deterministic
     * pick IS the argmin code, this is "same skill, nearest difficulty; else the
     * next-least-mastered skill". Null when nothing is eligible.
     *
     * @param array $eligible Generalised action ids the pool can actually serve.
     * @param array $masked The masked belief vector over the manifest codes.
     * @return int|null The redirected action id, or null when $eligible is empty.
     */
    public static function nearest_eligible(array $eligible, array $masked): ?int {
        $masked = array_values($masked);
        $n = count($masked);
        if ($n < 1) {
            throw new \InvalidArgumentException('masked vector must not be empty');
        }
        $byskill = [];
        foreach ($eligible as $action) {
            [$skill, $difficulty] = self::decode_action((int) $action, $n);
            $byskill[$skill][] = $difficulty;
        }
        if ($byskill === []) {
            return null;
        }
        ksort($byskill);
        $best = -1;
        foreach ($byskill as $skill => $unused) {
            if ($best < 0 || $masked[$skill] < $masked[$best]) {
                $best = $skill;
            }
        }
        $base = self::band((float) $masked[$best]);
        $diffs = $byskill[$best];
        sort($diffs);
        $chosen = $diffs[0];
        foreach ($diffs as $d) {
            if (abs($d - $base) < abs($chosen - $base)) {
                $chosen = $d;
            }
        }
        return self::encode_action($best, $chosen, $n);
    }

    /**
     * The full serving contract for a custom attempt - one call per question draw, mirroring
     * policy::choose() stage for stage: complete short-circuit; deterministic ZPD pick (source
     * 'heuristic' where the policy path says 'policy'/'fallback'); empty-eligible 'exhausted';
     * an ineligible deterministic pick laddered via nearest_eligible() with 'exhausted' as the
     * operative provenance; strict u < epsilon exploration drawing uniformly over ALL eligible
     * actions; the exact logging propensity via policy::propensity() (identical algebra:
     * chosen 1 - e + e/K, explored e/K, single eligible 1).
     *
     * The returned array carries the same keys as policy::choose(); 'state' is always null
     * because enc-custom-1 has no positional packed state.
     *
     * @param array $masked The masked belief vector over the manifest codes.
     * @param array $eligible Generalised action ids the pool can actually serve.
     * @param float $epsilon The attempt's exploration rate in [0,1].
     * @param callable|null $rand Uniform [0,1) source; injectable for deterministic tests.
     * @return array recommendedaction/servedaction/skill/difficulty/source/propensity/state.
     */
    public static function choose(array $masked, array $eligible, float $epsilon, ?callable $rand = null): array {
        if (!is_finite($epsilon) || $epsilon < 0.0 || $epsilon > 1.0) {
            throw new \InvalidArgumentException('epsilon must be a finite value in [0,1]');
        }
        $masked = array_values($masked);
        $n = count($masked);
        if ($n < 1) {
            throw new \InvalidArgumentException('masked vector must not be empty');
        }
        foreach ($masked as $value) {
            if (!is_numeric($value) || !is_finite((float) $value) || $value < 0.0 || $value > 1.0) {
                throw new \InvalidArgumentException('masked values must be finite and in [0,1]');
            }
        }
        if ($rand === null) {
            $rand = function (): float {
                return mt_rand() / (mt_getrandmax() + 1.0);
            };
        }
        if (policy::all_mastered($masked)) {
            return ['recommendedaction' => null, 'servedaction' => null, 'skill' => null,
                    'difficulty' => null, 'source' => 'complete', 'propensity' => null,
                    'state' => null];
        }
        $raw = self::deterministic_action($masked);
        $eligible = array_values(array_map('intval', $eligible));
        foreach ($eligible as $action) {
            // Fail fast on an out-of-range id (decode throws), matching the policy path's guards.
            self::decode_action($action, $n);
        }
        if ($eligible === []) {
            return ['recommendedaction' => $raw, 'servedaction' => null, 'skill' => null,
                    'difficulty' => null, 'source' => 'exhausted', 'propensity' => null,
                    'state' => null];
        }
        if (in_array($raw, $eligible, true)) {
            $det = $raw;
            $detsrc = 'heuristic';
        } else {
            $det = self::nearest_eligible($eligible, $masked);
            $detsrc = 'exhausted';
        }
        $served = $det;
        $source = $detsrc;
        $k = count($eligible);
        if ($epsilon > 0.0 && $rand() < $epsilon) {
            $i = (int) ($rand() * $k);
            if ($i >= $k) {
                $i = $k - 1;
            }
            $served = $eligible[$i];
            $source = 'explore';
        }
        $propensity = policy::propensity($served === $det, $epsilon, $k);
        [$skill, $difficulty] = self::decode_action($served, $n);
        return ['recommendedaction' => $raw, 'servedaction' => $served, 'skill' => $skill,
                'difficulty' => $difficulty, 'source' => $source, 'propensity' => $propensity,
                'state' => null];
    }

    /**
     * The ZPD difficulty band for a belief (strict < at both bounds, matching
     * policy::heuristic_action and nearest_eligible exactly).
     *
     * @param float $belief The masked belief.
     * @return int The difficulty index.
     */
    private static function band(float $belief): int {
        if ($belief < policy::BIN_EDGES[0]) {
            return bkt::DIFF_EASY;
        }
        return $belief < policy::BIN_EDGES[1] ? bkt::DIFF_MEDIUM : bkt::DIFF_HARD;
    }
}
