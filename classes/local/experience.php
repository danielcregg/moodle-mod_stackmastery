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
 * The experience log writer: the ONLY code path allowed to insert stackmastery_steps rows.
 *
 * One row per answered question, written inside the SAME delegated transaction as the QUBA save,
 * BKT update and attempt-row update (spec section 3 step 5; master plan C16). The writer asserts
 * the transaction, validates every enum and vector, derives the v1 correctness mapping and stamps
 * the four provenance versions in one place, so every logged experience is interpretable forever.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Transaction-asserted, enum-validated, version-stamping writer for the experience log.
 */
final class experience {
    /**
     * Reward-spec version stamped per step (master plan C12: this constant lives HERE). The
     * shaped reward itself is deliberately not stored; offline recomputes it under this id and
     * hard-fails on a mismatch instead of silently mixing objectives across a code upgrade.
     *
     * @var string
     */
    public const REWARD_VERSION = 'reward-1';

    /** @var string[] Legal actionsource values (design 05 section 2.3 semantics). */
    public const SOURCES = ['policy', 'explore', 'fallback', 'exhausted'];

    /**
     * Insert one experience row. MUST be called with a delegated transaction open and the
     * attempt lock held by the caller (the attempt engine owns the lock and computes seq).
     *
     * @param \stdClass $attempt The stackmastery_attempts row (already updated in this transaction).
     * @param int $seq 1-based answered-question counter within the attempt.
     * @param array $decision recommendedskill, recommendeddifficulty, servedskill,
     *     serveddifficulty, actionsource, propensity and policyversion (the version in force when
     *     THIS action was selected; mid-attempt promotes make it differ from the attempt row's).
     * @param array $question questionid, questionbankentryid, questionversion, slot,
     *     variant (optional, default 1) and stackseed (optional, nullable).
     * @param array $outcome fraction (float|null), correct (optional bool when fraction is null),
     *     masterybefore and masteryafter (8-value vectors, positional or keyed by skill code).
     * @return int The new step id.
     * @throws \coding_exception On any validation failure (programming error, never user input).
     */
    public static function log_step(\stdClass $attempt, int $seq, array $decision, array $question,
            array $outcome): int {
        global $DB;
        if (!$DB->is_transaction_started()) {
            throw new \coding_exception(
                'experience::log_step must be called inside the submission transaction (spec s3 step 5)');
        }
        $attemptid = (int) ($attempt->id ?? 0);
        if ($attemptid <= 0) {
            throw new \coding_exception('log_step needs a persisted attempt row with an id');
        }
        if ($seq < 1) {
            throw new \coding_exception("step seq must be >= 1, got {$seq}");
        }

        $recskill = self::require_code($decision, 'recommendedskill', bkt::SKILLS);
        $recdifficulty = self::require_code($decision, 'recommendeddifficulty', bkt::DIFFICULTIES);
        $servedskill = self::require_code($decision, 'servedskill', bkt::SKILLS);
        $serveddifficulty = self::require_code($decision, 'serveddifficulty', bkt::DIFFICULTIES);
        $actionsource = self::require_code($decision, 'actionsource', self::SOURCES);

        $propensity = $decision['propensity'] ?? null;
        if (!is_numeric($propensity)) {
            throw new \coding_exception('propensity must be a finite probability in (0, 1]');
        }
        $propensity = (float) $propensity;
        if (!is_finite($propensity) || $propensity <= 0.0 || $propensity > 1.0) {
            throw new \coding_exception('propensity must be a finite probability in (0, 1]');
        }

        $policyversion = $decision['policyversion'] ?? null;
        if (!is_string($policyversion) || $policyversion === '' || strlen($policyversion) > 64) {
            throw new \coding_exception('policyversion must be a non-empty string of at most 64 characters');
        }

        $questionid = self::require_positive_int($question, 'questionid');
        $questionbankentryid = self::require_positive_int($question, 'questionbankentryid');
        $questionversion = self::require_positive_int($question, 'questionversion');
        $slot = self::require_positive_int($question, 'slot');
        $variant = $question['variant'] ?? 1;
        if (!is_numeric($variant) || (int) $variant < 1) {
            throw new \coding_exception('variant must be a positive integer');
        }
        $stackseed = $question['stackseed'] ?? null;
        if ($stackseed !== null && !is_numeric($stackseed)) {
            throw new \coding_exception('stackseed must be an integer or null');
        }

        [$correct, $fraction] = self::resolve_correct($outcome);

        if (!isset($outcome['masterybefore']) || !is_array($outcome['masterybefore'])) {
            throw new \coding_exception('outcome masterybefore must be an 8-value mastery vector');
        }
        if (!isset($outcome['masteryafter']) || !is_array($outcome['masteryafter'])) {
            throw new \coding_exception('outcome masteryafter must be an 8-value mastery vector');
        }
        $masterybefore = self::encode_mastery($outcome['masterybefore']);
        $masteryafter = self::encode_mastery($outcome['masteryafter']);

        // Proactive duplicate rejection; the unique DB indexes remain the concurrency backstop.
        if ($DB->record_exists('stackmastery_steps', ['attemptid' => $attemptid, 'seq' => $seq])) {
            throw new \coding_exception("duplicate experience row for attempt {$attemptid} seq {$seq}");
        }
        if ($DB->record_exists('stackmastery_steps', ['attemptid' => $attemptid, 'slot' => $slot])) {
            throw new \coding_exception("duplicate experience row for attempt {$attemptid} slot {$slot}");
        }

        $record = (object) [
            'attemptid' => $attemptid,
            'seq' => $seq,
            'slot' => $slot,
            'questionid' => $questionid,
            'questionbankentryid' => $questionbankentryid,
            'questionversion' => $questionversion,
            'variant' => (int) $variant,
            'stackseed' => $stackseed === null ? null : (int) $stackseed,
            'recommendedskill' => $recskill,
            'recommendeddifficulty' => $recdifficulty,
            'servedskill' => $servedskill,
            'serveddifficulty' => $serveddifficulty,
            'actionsource' => $actionsource,
            'propensity' => $propensity,
            'masterybefore' => $masterybefore,
            'correct' => (int) $correct,
            'fraction' => $fraction,
            'masteryafter' => $masteryafter,
            'policyversion' => $policyversion,
            'bktmodelversion' => self::bkt_model_version($attempt),
            'stateencodingversion' => policy::ENCODING_VERSION,
            'rewardversion' => self::REWARD_VERSION,
            'timeanswered' => time(),
        ];
        return $DB->insert_record('stackmastery_steps', $record);
    }

    /**
     * The bkt model/parameter version to stamp: the attempt row pins it at start (parameters
     * never change mid-attempt); an empty pin falls back to the shipped default constant.
     *
     * @param \stdClass $attempt The stackmastery_attempts row.
     * @return string The bkt-1:<skills_source> composite.
     */
    public static function bkt_model_version(\stdClass $attempt): string {
        $version = $attempt->bktmodelversion ?? '';
        if (!is_string($version) || $version === '') {
            return bkt::MODEL_VERSION;
        }
        return $version;
    }

    /**
     * Validated json_encode of an 8-value mastery vector as the storage form: a JSON object
     * keyed by skill code in canonical order. Accepts a positional list (canonical order) or an
     * array already keyed by the 8 skill codes; every value must be a finite number in [0,1].
     *
     * @param array $mastery The vector, positional or keyed by skill code.
     * @return string The JSON object string.
     * @throws \coding_exception On any shape or value failure.
     */
    public static function encode_mastery(array $mastery): string {
        $n = count(bkt::SKILLS);
        if (count($mastery) !== $n) {
            throw new \coding_exception("mastery vector must have exactly {$n} values");
        }
        $positional = array_keys($mastery) === range(0, $n - 1);
        $out = [];
        foreach (bkt::SKILLS as $i => $code) {
            $key = $positional ? $i : $code;
            if (!array_key_exists($key, $mastery)) {
                throw new \coding_exception("mastery vector is missing skill '{$code}'");
            }
            $value = $mastery[$key];
            if (!is_int($value) && !is_float($value)) {
                throw new \coding_exception("mastery value for '{$code}' must be a number");
            }
            $value = (float) $value;
            if (!is_finite($value) || $value < 0.0 || $value > 1.0) {
                throw new \coding_exception("mastery value for '{$code}' must be finite and in [0,1]");
            }
            $out[$code] = $value;
        }
        return json_encode($out, JSON_THROW_ON_ERROR);
    }

    /**
     * Resolve the (correct, fraction) pair: correct derives from fraction via the v1 rule
     * (bkt::is_correct, fraction >= 0.999); a supplied correct must agree with a supplied
     * fraction (drift trap); a null fraction requires an explicit correct.
     *
     * @param array $outcome The caller's outcome struct.
     * @return array Positional [bool correct, float|null fraction].
     * @throws \coding_exception On a missing or inconsistent pair.
     */
    private static function resolve_correct(array $outcome): array {
        $fraction = $outcome['fraction'] ?? null;
        $suppliedcorrect = array_key_exists('correct', $outcome) ? $outcome['correct'] : null;
        if ($fraction === null) {
            if (!is_bool($suppliedcorrect) && !is_int($suppliedcorrect)) {
                throw new \coding_exception('a null fraction requires an explicit boolean correct');
            }
            return [(bool) $suppliedcorrect, null];
        }
        if (!is_numeric($fraction)) {
            throw new \coding_exception('fraction must be a finite number or null');
        }
        $fraction = (float) $fraction;
        if (!is_finite($fraction)) {
            throw new \coding_exception('fraction must be a finite number or null');
        }
        $derived = bkt::is_correct($fraction);
        if ($suppliedcorrect !== null && (bool) $suppliedcorrect !== $derived) {
            throw new \coding_exception('supplied correct contradicts the fraction-derived value');
        }
        return [$derived, $fraction];
    }

    /**
     * Fetch a required enum-coded string field and validate it against an allow-list.
     *
     * @param array $data The caller's struct.
     * @param string $key The field name.
     * @param array $allowed The legal values.
     * @return string The validated code.
     * @throws \coding_exception On a missing or unknown code.
     */
    private static function require_code(array $data, string $key, array $allowed): string {
        $value = $data[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $repr = is_scalar($value) ? (string) $value : gettype($value);
            throw new \coding_exception("{$key} must be one of [" . implode(', ', $allowed) . "], got '{$repr}'");
        }
        return $value;
    }

    /**
     * Fetch a required strictly positive integer field.
     *
     * @param array $data The caller's struct.
     * @param string $key The field name.
     * @return int The validated integer.
     * @throws \coding_exception On a missing or non-positive value.
     */
    private static function require_positive_int(array $data, string $key): int {
        $value = $data[$key] ?? null;
        if (!is_numeric($value) || (int) $value != $value || (int) $value <= 0) {
            throw new \coding_exception("{$key} must be a positive integer");
        }
        return (int) $value;
    }
}
