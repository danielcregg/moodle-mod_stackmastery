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
 * The trained tabular teaching policy: artifact loading, state encoding and action serving.
 *
 * Loads data/policy.json, validates its encoding metadata exactly as phase3/policy_service.py
 * does, encodes mastery vectors with the pinned bins (phase3/env.py), and serves epsilon-greedy
 * actions with exact logging propensities. choose() is THE single selection brain (master plan
 * C15) — the attempt engine never re-derives any part of this pipeline. No Moodle dependencies
 * (SPL exceptions only) so the standalone fixture runner can require this file.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Policy artifact parser, pinned state/action codecs, the masked-serving pipeline and the
 * epsilon-greedy choose() entry point with exact off-policy propensities.
 */
final class policy {
    /** @var int Number of skills (env.py n_skills). */
    public const N_SKILLS = 8;

    /** @var int Number of difficulty levels (env.py n_diff). */
    public const N_DIFF = 3;

    /** @var int Mastery bins per skill (env.py N_BINS). */
    public const N_BINS = 4;

    /** @var int Total tabular states = N_BINS ** N_SKILLS. */
    public const N_STATES = 65536;

    /** @var int Total actions = N_SKILLS * N_DIFF. */
    public const N_ACTIONS = 24;

    /** @var float Mastery threshold (env.MASTERY_THRESHOLD). */
    public const THRESHOLD = 0.95;

    /** @var float[] Bin edges with >= semantics (env.BIN_EDGES). */
    public const BIN_EDGES = [0.475, 0.725, 0.95];

    /** @var string[] Canonical skill order — one source (bkt::SKILLS). */
    public const SKILLS = bkt::SKILLS;

    /** @var string Logged per step as stateencodingversion. Bump on ANY change to the pins above. */
    public const ENCODING_VERSION = 'enc-1';

    /** @var string[] Metadata keys validated when present — mirror of PolicyService "expected". */
    public const VALIDATED_KEYS = ['n_states', 'n_skills', 'n_diff', 'n_bins',
                                   'threshold', 'bin_edges', 'skills'];

    /** @var array The decoded policy.json metadata (table included, as decoded). */
    private array $meta;

    /** @var array<int, int> The sparse state => action table with load-time bounds integrity. */
    private array $table;

    /** @var string Version identifier logged per step as policyversion. */
    private string $version;

    /**
     * Use load() — instances only exist around a validated artifact.
     *
     * @param array $meta The decoded artifact metadata.
     * @param array $table The integer state => action table.
     * @param string $version The resolved policy version identifier.
     */
    private function __construct(array $meta, array $table, string $version) {
        $this->meta = $meta;
        $this->table = $table;
        $this->version = $version;
    }

    /**
     * Load and validate a policy artifact. Fail-closed: a missing or corrupt policy must never
     * silently degrade to heuristic-only serving — the attempt engine treats any exception
     * here as a fatal configuration error.
     *
     * @param string|null $path Artifact path; defaults to the shipped data/policy.json.
     * @return self The loaded policy.
     */
    public static function load(?string $path = null): self {
        $path = $path ?? __DIR__ . '/../../data/policy.json';
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("policy data file missing or unreadable: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("policy data file could not be read: {$path}");
        }
        try {
            $meta = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("policy data file is not valid JSON: {$path} ({$e->getMessage()})");
        }
        if (!is_array($meta)) {
            throw new \RuntimeException("policy data file must decode to an object: {$path}");
        }
        self::validate_meta($meta);
        $table = [];
        foreach ($meta['policy'] as $key => $value) {
            $state = (int) $key;
            if ((string) $state !== (string) $key) {
                throw new \InvalidArgumentException("policy table key '{$key}' is not a canonical integer");
            }
            if ($state < 0 || $state >= self::N_STATES) {
                throw new \InvalidArgumentException("policy table state {$state} is out of range");
            }
            if (!is_int($value)) {
                throw new \InvalidArgumentException("policy table action for state {$state} must be an integer");
            }
            if ($value < 0 || $value >= self::N_ACTIONS) {
                throw new \InvalidArgumentException("policy table action {$value} is out of range (state {$state})");
            }
            $table[$state] = $value;
        }
        $version = $meta['version'] ?? null;
        if (!is_string($version) || $version === '') {
            $version = 'shipped-' . substr(sha1($raw), 0, 12);
        }
        return new self($meta, $table, $version);
    }

    /**
     * Present-keys-only exact-equality metadata validation (PolicyService.__init__ parity): a
     * key that is present but differs from the pinned constant throws; an absent key is
     * skipped. Beyond Python (recorded divergences): the policy table must exist and be
     * non-empty, and "difficulties" must match when present.
     *
     * @param array $meta The decoded artifact metadata.
     * @return void
     */
    public static function validate_meta(array $meta): void {
        $ints = ['n_states' => self::N_STATES, 'n_skills' => self::N_SKILLS,
                 'n_diff' => self::N_DIFF, 'n_bins' => self::N_BINS];
        foreach ($ints as $key => $want) {
            if (array_key_exists($key, $meta) && (int) $meta[$key] !== $want) {
                self::meta_mismatch($key, $meta[$key], $want);
            }
        }
        if (array_key_exists('threshold', $meta) && (float) $meta['threshold'] !== self::THRESHOLD) {
            self::meta_mismatch('threshold', $meta['threshold'], self::THRESHOLD);
        }
        if (array_key_exists('bin_edges', $meta)) {
            $edges = is_array($meta['bin_edges']) ? array_values($meta['bin_edges']) : null;
            if ($edges === null || count($edges) !== count(self::BIN_EDGES)) {
                self::meta_mismatch('bin_edges', $meta['bin_edges'], self::BIN_EDGES);
            }
            foreach (self::BIN_EDGES as $i => $want) {
                if ((float) $edges[$i] !== $want) {
                    self::meta_mismatch('bin_edges', $meta['bin_edges'], self::BIN_EDGES);
                }
            }
        }
        if (array_key_exists('skills', $meta)) {
            $skills = is_array($meta['skills']) ? array_values($meta['skills']) : null;
            if ($skills !== self::SKILLS) {
                self::meta_mismatch('skills', $meta['skills'], self::SKILLS);
            }
        }
        if (array_key_exists('difficulties', $meta)) {
            $diffs = is_array($meta['difficulties']) ? array_values($meta['difficulties']) : null;
            if ($diffs !== bkt::DIFFICULTIES) {
                self::meta_mismatch('difficulties', $meta['difficulties'], bkt::DIFFICULTIES);
            }
        }
        if (!isset($meta['policy']) || !is_array($meta['policy']) || $meta['policy'] === []) {
            throw new \InvalidArgumentException('policy.json must carry a non-empty policy table');
        }
    }

    /**
     * Throw the standard metadata-mismatch error (PolicyService's message semantics).
     *
     * @param string $key The offending metadata key.
     * @param mixed $got The artifact's value.
     * @param mixed $want The pinned plugin value.
     * @return void
     */
    private static function meta_mismatch(string $key, $got, $want): void {
        $gotjson = json_encode($got);
        if ($gotjson === false) {
            $gotjson = '(unencodable)';
        }
        $wantjson = json_encode($want);
        $message = "policy.json {$key}={$gotjson} does not match this plugin ({$wantjson}); retrain or realign";
        throw new \InvalidArgumentException($message);
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
     * The resolved policy version identifier (explicit artifact "version" key, else the
     * content-addressed shipped-<sha1[:12]> of the file bytes).
     *
     * @return string The version identifier.
     */
    public function version(): string {
        return $this->version;
    }

    /**
     * The decoded artifact metadata.
     *
     * @return array The metadata array.
     */
    public function meta(): array {
        return $this->meta;
    }

    /**
     * Number of states covered by the loaded table.
     *
     * @return int The table entry count.
     */
    public function table_size(): int {
        return count($this->table);
    }

    /**
     * Encode a mastery vector to the tabular state index (env.encode: >= at every bin edge;
     * skill 0 is the most-significant base-4 digit). Guarded so a corrupt attempt JSON can
     * never fabricate a table index.
     *
     * @param array $mastery Positional vector of exactly 8 values in [0,1].
     * @return int The state index in [0, N_STATES).
     */
    public static function encode_state(array $mastery): int {
        $mastery = array_values($mastery);
        if (count($mastery) !== self::N_SKILLS) {
            throw new \InvalidArgumentException('mastery vector must have exactly ' . self::N_SKILLS . ' values');
        }
        $idx = 0;
        foreach ($mastery as $value) {
            if (!is_numeric($value)) {
                throw new \InvalidArgumentException('mastery values must be numeric');
            }
            $m = (float) $value;
            if (!is_finite($m) || $m < 0.0 || $m > 1.0) {
                $repr = self::float_repr($m);
                throw new \InvalidArgumentException("mastery values must be finite and in [0,1], got {$repr}");
            }
            $bucket = 0;
            foreach (self::BIN_EDGES as $edge) {
                if ($m >= $edge) {
                    $bucket++;
                }
            }
            $idx = $idx * self::N_BINS + $bucket;
        }
        return $idx;
    }

    /**
     * Decode an action id to its (skill, difficulty) pair (env.decode).
     *
     * @param int $action Action id in [0, N_ACTIONS).
     * @return int[] Positional [skill, difficulty].
     */
    public static function decode_action(int $action): array {
        if ($action < 0 || $action >= self::N_ACTIONS) {
            throw new \InvalidArgumentException("action must be in [0, " . self::N_ACTIONS . "), got {$action}");
        }
        return [intdiv($action, self::N_DIFF), $action % self::N_DIFF];
    }

    /**
     * Encode a (skill, difficulty) pair to its action id (env.encode_action).
     *
     * @param int $skill Skill index in [0, N_SKILLS).
     * @param int $difficulty Difficulty index in [0, N_DIFF).
     * @return int The action id.
     */
    public static function encode_action(int $skill, int $difficulty): int {
        if ($skill < 0 || $skill >= self::N_SKILLS) {
            throw new \InvalidArgumentException("skill must be in [0, " . self::N_SKILLS . "), got {$skill}");
        }
        if ($difficulty < 0 || $difficulty >= self::N_DIFF) {
            throw new \InvalidArgumentException("difficulty must be in [0, " . self::N_DIFF . "), got {$difficulty}");
        }
        return $skill * self::N_DIFF + $difficulty;
    }

    /**
     * Human-readable action label, e.g. "expand/easy" (env.action_name).
     *
     * @param int $action Action id in [0, N_ACTIONS).
     * @return string The "skillcode/difficultyname" label.
     */
    public static function action_name(int $action): string {
        [$skill, $difficulty] = self::decode_action($action);
        return self::SKILLS[$skill] . '/' . bkt::DIFFICULTIES[$difficulty];
    }

    /**
     * Mask deselected and target-reached skills to 1.0 before any lookup (spec §3): a masked
     * skill reads as fully mastered, so neither the table nor the heuristic ever picks it.
     *
     * @param array $mastery Positional vector of 8 beliefs.
     * @param array $selected Positional vector of 8 booleans (teacher's skill selection).
     * @param array $target Positional vector of 8 per-skill targets.
     * @return float[] The masked vector.
     */
    public static function mask_mastered(array $mastery, array $selected, array $target): array {
        $mastery = array_values($mastery);
        $selected = array_values($selected);
        $target = array_values($target);
        $n = self::N_SKILLS;
        if (count($mastery) !== $n || count($selected) !== $n || count($target) !== $n) {
            throw new \InvalidArgumentException("mask_mastered expects three vectors of exactly {$n} values");
        }
        $masked = [];
        foreach ($mastery as $i => $value) {
            $masked[] = (!$selected[$i] || (float) $value >= (float) $target[$i]) ? 1.0 : (float) $value;
        }
        return $masked;
    }

    /**
     * Whether every entry of a (masked) vector is at or above the policy threshold.
     *
     * @param array $masked The masked mastery vector.
     * @return bool True when all entries are >= THRESHOLD.
     */
    public static function all_mastered(array $masked): bool {
        foreach ($masked as $value) {
            if ((float) $value < self::THRESHOLD) {
                return false;
            }
        }
        return true;
    }

    /**
     * Literal port of agents.heuristic_action: teach the least-mastered un-mastered skill at
     * the ZPD-matched difficulty (np.argmin first-index tie-break; strict < at the region
     * bounds, so mastery exactly 0.475 is medium). Masked skills read 1.0 and are never chosen.
     *
     * @param array $mastery Positional vector of 8 beliefs (usually the masked vector).
     * @return int The heuristic action id.
     */
    public static function heuristic_action(array $mastery): int {
        $mastery = array_values($mastery);
        $todo = [];
        foreach ($mastery as $i => $value) {
            if ((float) $value < self::THRESHOLD) {
                $todo[] = $i;
            }
        }
        if ($todo === []) {
            return self::encode_action(0, bkt::DIFF_MEDIUM);
        }
        $skill = $todo[0];
        foreach ($todo as $i) {
            if ($mastery[$i] < $mastery[$skill]) {
                $skill = $i;
            }
        }
        $m = (float) $mastery[$skill];
        if ($m < self::BIN_EDGES[0]) {
            $difficulty = bkt::DIFF_EASY;
        } else {
            $difficulty = $m < self::BIN_EDGES[1] ? bkt::DIFF_MEDIUM : bkt::DIFF_HARD;
        }
        return self::encode_action($skill, $difficulty);
    }

    /**
     * Raw deterministic lookup on a masked vector: table hit => 'policy', miss => the
     * heuristic with 'fallback' (PolicyService parity — 2421 of 65536 states are covered).
     *
     * @param array $masked The masked mastery vector.
     * @return array ['action' => int, 'source' => string].
     */
    public function lookup(array $masked): array {
        $state = self::encode_state($masked);
        if (array_key_exists($state, $this->table)) {
            return ['action' => $this->table[$state], 'source' => 'policy'];
        }
        return ['action' => self::heuristic_action($masked), 'source' => 'fallback'];
    }

    /**
     * The spec-§3 exhausted transform: redirect to the lowest-mastery skill that still has an
     * eligible action (first-index tie-break), at the difficulty nearest that skill's ZPD
     * region (a distance tie breaks easier). Null when nothing is eligible (the engine
     * finalises the attempt; the budget cap makes this unreachable mid-attempt).
     *
     * @param array $eligible Action ids the pool can actually serve.
     * @param array $masked The masked mastery vector.
     * @return int|null The redirected action id, or null when $eligible is empty.
     */
    public static function nearest_eligible(array $eligible, array $masked): ?int {
        $masked = array_values($masked);
        if (count($masked) !== self::N_SKILLS) {
            throw new \InvalidArgumentException('masked vector must have exactly ' . self::N_SKILLS . ' values');
        }
        $byskill = [];
        foreach ($eligible as $action) {
            [$skill, $difficulty] = self::decode_action((int) $action);
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
        $m = (float) $masked[$best];
        if ($m < self::BIN_EDGES[0]) {
            $base = bkt::DIFF_EASY;
        } else {
            $base = $m < self::BIN_EDGES[1] ? bkt::DIFF_MEDIUM : bkt::DIFF_HARD;
        }
        $diffs = $byskill[$best];
        sort($diffs);
        $chosen = $diffs[0];
        foreach ($diffs as $d) {
            if (abs($d - $base) < abs($chosen - $base)) {
                $chosen = $d;
            }
        }
        return self::encode_action($best, $chosen);
    }

    /**
     * Exact probability the epsilon-greedy logging policy serves a given action (Codex-#07
     * off-policy provenance): (1 - e) + e/n for the deterministic composite, e/n otherwise;
     * a single eligible action is always served with probability 1.
     *
     * @param bool $servedisdeterministic Whether the served action equals the deterministic composite.
     * @param float $epsilon The exploration rate used for the draw.
     * @param int $neligible Number of eligible actions.
     * @return float The logging propensity.
     */
    public static function propensity(bool $servedisdeterministic, float $epsilon, int $neligible): float {
        if (!is_finite($epsilon) || $epsilon < 0.0 || $epsilon > 1.0) {
            $repr = self::float_repr($epsilon);
            throw new \InvalidArgumentException("epsilon must be a finite value in [0,1], got {$repr}");
        }
        if ($neligible < 1) {
            throw new \InvalidArgumentException("neligible must be >= 1, got {$neligible}");
        }
        if ($neligible === 1) {
            return 1.0;
        }
        if ($servedisdeterministic) {
            return (1.0 - $epsilon) + $epsilon / $neligible;
        }
        return $epsilon / $neligible;
    }

    /**
     * The full serving contract — one call per question draw (the single selection brain).
     * Pipeline: complete short-circuit; raw lookup (policy|fallback); empty-eligible
     * exhausted; ineligible raw redirected via nearest_eligible ('exhausted' overrides
     * 'fallback' — the outermost deviation is the operative provenance); strict u < epsilon
     * exploration drawing uniformly over ALL eligible actions (an explore draw equal to the
     * deterministic composite keeps the 'explore' label but its exact mu propensity).
     *
     * @param array $masked The masked mastery vector.
     * @param array $eligible Action ids the pool can actually serve (engine contract, doc 04 §8).
     * @param float $epsilon The instance's exploration rate in [0,1].
     * @param callable|null $rand Uniform [0,1) source; injectable for deterministic tests.
     * @return array recommendedaction/servedaction/skill/difficulty/source/propensity/state.
     */
    public function choose(array $masked, array $eligible, float $epsilon, ?callable $rand = null): array {
        if (!is_finite($epsilon) || $epsilon < 0.0 || $epsilon > 1.0) {
            $repr = self::float_repr($epsilon);
            throw new \InvalidArgumentException("epsilon must be a finite value in [0,1], got {$repr}");
        }
        if ($rand === null) {
            $rand = function (): float {
                return mt_rand() / (mt_getrandmax() + 1.0);
            };
        }
        $state = self::encode_state($masked);
        if (self::all_mastered($masked)) {
            return ['recommendedaction' => null, 'servedaction' => null, 'skill' => null,
                    'difficulty' => null, 'source' => 'complete', 'propensity' => null,
                    'state' => $state];
        }
        $lookup = $this->lookup($masked);
        $raw = $lookup['action'];
        $eligible = array_values(array_map('intval', $eligible));
        if ($eligible === []) {
            return ['recommendedaction' => $raw, 'servedaction' => null, 'skill' => null,
                    'difficulty' => null, 'source' => 'exhausted', 'propensity' => null,
                    'state' => $state];
        }
        if (in_array($raw, $eligible, true)) {
            $det = $raw;
            $detsrc = $lookup['source'];
        } else {
            $det = self::nearest_eligible($eligible, $masked);
            $detsrc = 'exhausted';
        }
        $served = $det;
        $source = $detsrc;
        $n = count($eligible);
        if ($epsilon > 0.0 && $rand() < $epsilon) {
            $i = (int) ($rand() * $n);
            if ($i >= $n) {
                $i = $n - 1;
            }
            $served = $eligible[$i];
            $source = 'explore';
        }
        $propensity = self::propensity($served === $det, $epsilon, $n);
        [$skill, $difficulty] = self::decode_action($served);
        return ['recommendedaction' => $raw, 'servedaction' => $served, 'skill' => $skill,
                'difficulty' => $difficulty, 'source' => $source, 'propensity' => $propensity,
                'state' => $state];
    }
}
