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
 * Canonical skill registry: codes, tag names, labels and csv codecs.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * The single Moodle-side home for skill vocabulary derived facts.
 *
 * The canonical codes themselves live on {@see bkt::SKILLS} (the no-Moodle math core) so the
 * vocabulary can never fork; this class aliases them and adds everything tag- and UI-shaped.
 */
final class skills {
    /**
     * Canonical skill codes IN POLICY ORDER. Aliased from the math core by construction so the
     * two can never drift. Never reorder: the trained state encoding depends on it.
     */
    public const CODES = bkt::SKILLS;

    /** Difficulty codes in action-space order (policy action = skill * 3 + difficulty). */
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    /** Question-tag name prefix for skills. Full tag = prefix . code. */
    public const TAG_SKILL_PREFIX = 'stackmastery_skill_';

    /** Question-tag name prefix for difficulties. Full tag = prefix . code. */
    public const TAG_DIFF_PREFIX = 'stackmastery_diff_';

    /**
     * Map a stack-question-forge template type to a canonical skill code.
     *
     * This is the ONLY translation layer between the forge vocabulary and the policy vocabulary
     * (notably simplify_lowest_terms maps to simplify). The forge tagging work must use it.
     */
    public const FORGE_TYPE_MAP = [
        'differentiate'          => 'differentiate',
        'integrate'              => 'integrate',
        'expand'                 => 'expand',
        'factor'                 => 'factor',
        'simplify_lowest_terms'  => 'simplify',
        'solve_linear'           => 'solve_linear',
        'solve_quadratic'        => 'solve_quadratic',
        'numerical'              => 'numerical',
    ];

    /**
     * Map a canonical skill code back to its stack-question-forge template type.
     *
     * The exact inverse of FORGE_TYPE_MAP, which is 1:1 by construction (used by the pool
     * builder and the nightly refill task to queue forge jobs for a skill).
     *
     * @param string $code A canonical skill code.
     * @return string|null The forge template type, or null for an unknown code.
     */
    public static function forge_type(string $code): ?string {
        $type = array_search($code, self::FORGE_TYPE_MAP, true);
        return $type === false ? null : $type;
    }

    /**
     * The full question tag name for a skill code.
     *
     * @param string $code A canonical skill code.
     * @return string The tag name, e.g. stackmastery_skill_differentiate.
     */
    public static function skill_tag(string $code): string {
        return self::TAG_SKILL_PREFIX . $code;
    }

    /**
     * The full question tag name for a difficulty code.
     *
     * @param string $code A difficulty code (easy, medium or hard).
     * @return string The tag name, e.g. stackmastery_diff_easy.
     */
    public static function diff_tag(string $code): string {
        return self::TAG_DIFF_PREFIX . $code;
    }

    /**
     * Whether a string is a canonical skill code.
     *
     * @param string $code Candidate code.
     * @return bool True when the code is one of the canonical 8.
     */
    public static function is_skill(string $code): bool {
        return in_array($code, self::CODES, true);
    }

    /**
     * Whether a string is a difficulty code.
     *
     * @param string $code Candidate code.
     * @return bool True when the code is easy, medium or hard.
     */
    public static function is_difficulty(string $code): bool {
        return in_array($code, self::DIFFICULTIES, true);
    }

    /**
     * Human label for a skill code.
     *
     * @param string $code A canonical skill code.
     * @return string The localised label.
     */
    public static function label(string $code): string {
        return get_string('skill_' . $code, 'mod_stackmastery');
    }

    /**
     * Human label for a difficulty code.
     *
     * @param string $code A difficulty code.
     * @return string The localised label.
     */
    public static function difficulty_label(string $code): string {
        return get_string('difficulty_' . $code, 'mod_stackmastery');
    }

    /**
     * Decode a skills csv into a validated, canonical-order subset of the codes.
     *
     * Unknown codes are dropped; an empty csv decodes to the full canonical set (the lib.php
     * normalisation rule for the instance column).
     *
     * @param string $csv Comma-separated skill codes.
     * @return string[] Canonical-order subset of skill codes.
     */
    public static function decode_csv(string $csv): array {
        $wanted = array_map('trim', explode(',', $csv));
        $subset = array_values(array_intersect(self::CODES, $wanted));
        return $subset === [] ? self::CODES : $subset;
    }

    /**
     * Encode a set of skill codes as a csv in canonical order, deduped and validated.
     *
     * @param string[] $codes Skill codes in any order.
     * @return string Canonical-order csv.
     */
    public static function encode_csv(array $codes): string {
        return implode(',', array_values(array_intersect(self::CODES, $codes)));
    }
}
