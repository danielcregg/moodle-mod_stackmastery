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
 * The per-attempt belief vector wrapper over the pure BKT math.
 *
 * A thin, canonical-order wrapper over bkt::update_belief that owns (de)serialisation to the
 * attempt/steps JSON columns (objects keyed by skill code). The tracked key set is EXACTLY the
 * skill manifest's codes: without a manifest this is the 8 canonical core skills, bit-for-bit
 * as before custom topics; with a manifest it is the 8 core codes plus the attempt's custom
 * topic slugs, with per-code parameters resolved by the manifest (codec contract, design D3:
 * the manifest is passed, never inferred). No Moodle dependencies and no DB access -
 * persistence belongs to the attempt engine, and the standalone fixture runner requires this
 * file without a bootstrap (a null manifest never touches the skill_manifest class).
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Per-attempt mastery beliefs: init from p_init, strict JSON codecs, one graded-answer update
 * (apply_result) and the target-reached queries the attempt engine terminates on.
 */
final class mastery {
    /** @var string[] The tracked skill codes, in manifest order (default bkt::SKILLS). */
    private array $codes;

    /** @var float[] Positional belief vector, order = $codes. */
    private array $belief;

    /** @var array<string, array<string, float>> Per-skill BKT parameters keyed by skill code. */
    private array $params;

    /** @var string The BKT model/parameter version tag for step logging. */
    private string $modelversion;

    /**
     * Use init() or from_json().
     *
     * @param array $codes The tracked skill codes in order.
     * @param array $belief Positional belief vector in code order.
     * @param array $params Validated per-skill parameters keyed by skill code.
     * @param string $modelversion Version tag accompanying the parameter set.
     */
    private function __construct(array $codes, array $belief, array $params, string $modelversion) {
        $this->codes = $codes;
        $this->belief = $belief;
        $this->params = $params;
        $this->modelversion = $modelversion;
    }

    /**
     * A fresh vector: each skill starts at its p_init. Custom parameters (a future
     * fitted_skills.json) may be injected with an accompanying version tag - the v1 runtime
     * always uses the defaults, but the seam exists without migration. With a manifest the
     * tracked keys are the manifest's codes and, absent an explicit override, the per-code
     * parameters come from the manifest (custom slugs get bkt::DEFAULT_CUSTOM_PARAMS); without
     * one, behaviour is the historical exactly-8 form, bit-for-bit.
     *
     * @param array|null $params Per-skill parameter override; defaults to bkt::PARAMS or, with
     *     a manifest, the manifest's parameter map.
     * @param string|null $modelversion Version tag for the set; defaults to bkt::MODEL_VERSION.
     * @param skill_manifest|null $manifest The attempt's skill manifest, or null for core-8.
     * @return self The fresh mastery vector.
     */
    public static function init(
        ?array $params = null,
        ?string $modelversion = null,
        ?skill_manifest $manifest = null
    ): self {
        [$codes, $params, $version] = self::normalise_params($params, $modelversion, $manifest);
        $belief = [];
        foreach ($codes as $code) {
            $belief[] = $params[$code]['p_init'];
        }
        return new self($codes, $belief, $params, $version);
    }

    /**
     * Parse an attempt-row JSON object {skillcode: float}. Strict: exactly the tracked codes
     * (the 8 canonical skills without a manifest; the manifest's codes with one), every value a
     * finite number in [0,1] - a corrupt live-mastery column fails loudly at attempt resume
     * instead of serving garbage actions.
     *
     * @param string $json The stored JSON object.
     * @param array|null $params Per-skill parameter override; defaults to bkt::PARAMS or, with
     *     a manifest, the manifest's parameter map.
     * @param string|null $modelversion Version tag for the set; defaults to bkt::MODEL_VERSION.
     * @param skill_manifest|null $manifest The attempt's skill manifest, or null for core-8.
     * @return self The parsed mastery vector.
     */
    public static function from_json(
        string $json,
        ?array $params = null,
        ?string $modelversion = null,
        ?skill_manifest $manifest = null
    ): self {
        [$codes, $params, $version] = self::normalise_params($params, $modelversion, $manifest);
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('mastery JSON is invalid: ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('mastery JSON must be an object keyed by skill code');
        }
        if (count($data) !== count($codes)) {
            throw new \InvalidArgumentException(
                'mastery JSON must carry exactly the ' . count($codes) . ' tracked skill codes'
            );
        }
        $belief = [];
        foreach ($codes as $code) {
            if (!array_key_exists($code, $data)) {
                throw new \InvalidArgumentException("mastery JSON is missing skill '{$code}'");
            }
            $value = $data[$code];
            if (!is_int($value) && !is_float($value)) {
                throw new \InvalidArgumentException("mastery JSON value for '{$code}' must be a number");
            }
            $value = (float) $value;
            if (!is_finite($value) || $value < 0.0 || $value > 1.0) {
                throw new \InvalidArgumentException("mastery JSON value for '{$code}' must be finite and in [0,1]");
            }
            $belief[] = $value;
        }
        return new self($codes, $belief, $params, $version);
    }

    /**
     * Serialise to the storage form: a JSON object keyed by skill code in tracked-code order.
     * Float fidelity relies on PHP's default serialize_precision=-1 (shortest round-trip);
     * from_json(to_json()) is bit-identical.
     *
     * @return string The JSON object string.
     */
    public function to_json(): string {
        $out = [];
        foreach ($this->codes as $i => $code) {
            $out[$code] = $this->belief[$i];
        }
        return json_encode($out, JSON_THROW_ON_ERROR);
    }

    /**
     * The positional belief vector (order = the tracked codes), ready for the mask transform
     * and the selection engines.
     *
     * @return float[] The belief vector.
     */
    public function vector(): array {
        return $this->belief;
    }

    /**
     * The tracked skill codes in vector order.
     *
     * @return string[] The codes.
     */
    public function codes(): array {
        return $this->codes;
    }

    /**
     * One skill's current belief, by index or skill code.
     *
     * @param int|string $skill Skill index in [0, n) or a tracked skill code.
     * @return float The belief.
     */
    public function get(int|string $skill): float {
        if (is_string($skill)) {
            $idx = array_search($skill, $this->codes, true);
            if ($idx === false) {
                throw new \InvalidArgumentException("unknown skill code '{$skill}'");
            }
            return $this->belief[$idx];
        }
        if ($skill < 0 || $skill >= count($this->codes)) {
            throw new \InvalidArgumentException("skill index out of range: {$skill}");
        }
        return $this->belief[$skill];
    }

    /**
     * Apply one graded answer: fraction -> bkt::is_correct -> bkt::update_belief; mutates this
     * skill only. The fraction is the raw STACK fraction - a non-finite value simply counts as
     * incorrect (is_correct is total) and is the caller's to log untouched.
     *
     * @param int $skill Skill index in [0, n).
     * @param int $difficulty Difficulty index 0..2.
     * @param float $fraction The raw STACK fraction.
     * @return array ['before' => float, 'after' => float, 'correct' => bool] - the steps-row ingredients.
     */
    public function apply_result(int $skill, int $difficulty, float $fraction): array {
        if ($skill < 0 || $skill >= count($this->codes)) {
            throw new \InvalidArgumentException("skill index out of range: {$skill}");
        }
        $code = $this->codes[$skill];
        $before = $this->belief[$skill];
        $correct = bkt::is_correct($fraction);
        $after = bkt::update_belief($before, $difficulty, $correct, $this->params[$code]);
        $this->belief[$skill] = $after;
        return ['before' => $before, 'after' => $after, 'correct' => $correct];
    }

    /**
     * Per-skill target check with >= semantics (a belief exactly at target counts as reached).
     *
     * @param array $target Positional vector of per-skill targets, one per tracked code.
     * @return bool[] Per-skill belief[i] >= target[i].
     */
    public function reached(array $target): array {
        $target = array_values($target);
        if (count($target) !== count($this->codes)) {
            throw new \InvalidArgumentException(
                'target vector must have exactly ' . count($this->codes) . ' values'
            );
        }
        $out = [];
        foreach ($this->belief as $i => $belief) {
            $out[] = $belief >= (float) $target[$i];
        }
        return $out;
    }

    /**
     * Whether every SELECTED skill has reached its target (the attempt-success condition).
     * An empty selection is vacuously true - the engine guards against creating such an
     * instance (recorded decision, doc 04 §11).
     *
     * @param array $selected Positional vector of booleans, one per tracked code.
     * @param array $target Positional vector of per-skill targets, one per tracked code.
     * @return bool True when no selected skill is below target.
     */
    public function all_reached(array $selected, array $target): bool {
        $selected = array_values($selected);
        if (count($selected) !== count($this->codes)) {
            throw new \InvalidArgumentException(
                'selected vector must have exactly ' . count($this->codes) . ' values'
            );
        }
        $reached = $this->reached($target);
        foreach ($selected as $i => $sel) {
            if ($sel && !$reached[$i]) {
                return false;
            }
        }
        return true;
    }

    /**
     * The version tag of the parameter set in force (logged per step as bktmodelversion).
     *
     * @return string The model version.
     */
    public function model_version(): string {
        return $this->modelversion;
    }

    /**
     * Resolve the tracked codes and validate/normalise the parameter set: every tracked code
     * covered, each carrying finite p_init (in [0,1]), p_transit, p_slip and p_guess.
     *
     * Without a manifest, codes are the 8 canonical skills and a null override resolves to
     * bkt::PARAMS - the historical behaviour, bit-for-bit. With a manifest, codes are the
     * manifest's and a null override resolves to the manifest's parameter map (custom slugs
     * get bkt::DEFAULT_CUSTOM_PARAMS).
     *
     * @param array|null $params The override set, or null for the defaults.
     * @param string|null $modelversion Accompanying version tag, or null for bkt::MODEL_VERSION.
     * @param skill_manifest|null $manifest The skill manifest, or null for core-8.
     * @return array Positional [codes, params, version].
     */
    private static function normalise_params(?array $params, ?string $modelversion, ?skill_manifest $manifest): array {
        $version = $modelversion ?? bkt::MODEL_VERSION;
        $codes = $manifest === null ? bkt::SKILLS : $manifest->codes();
        if ($params === null) {
            $defaults = $manifest === null ? bkt::PARAMS : $manifest->params();
            return [$codes, $defaults, $version];
        }
        $clean = [];
        foreach ($codes as $code) {
            if (!isset($params[$code]) || !is_array($params[$code])) {
                throw new \InvalidArgumentException("params must cover skill '{$code}'");
            }
            $row = [];
            foreach (['p_init', 'p_transit', 'p_slip', 'p_guess'] as $key) {
                if (!array_key_exists($key, $params[$code])) {
                    throw new \InvalidArgumentException("params for '{$code}' is missing {$key}");
                }
                $value = $params[$code][$key];
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    throw new \InvalidArgumentException("params {$key} for '{$code}' must be a finite number");
                }
                $row[$key] = (float) $value;
            }
            if ($row['p_init'] < 0.0 || $row['p_init'] > 1.0) {
                throw new \InvalidArgumentException("params p_init for '{$code}' must be in [0,1]");
            }
            $clean[$code] = $row;
        }
        return [$codes, $clean, $version];
    }
}
