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
 * The per-instance/per-attempt skill manifest: core skills plus custom topic slugs.
 *
 * The single source for "which skills does this instance or attempt track" (custom-topics
 * design D3). The vector stays a superset exactly as before custom topics: codes() always
 * carries all 8 core codes (mask semantics preserved) followed by the custom slugs, and
 * selection masking extends over it unchanged.
 *
 * CODEC CONTRACT (Codex #10 HIGH): the manifest is PASSED, never inferred, at every vector
 * boundary. Custom-aware code must never run manifest data through skills::decode_csv() or
 * skills::encode_csv() - those intersect with the core 8 and back-fill ALL 8 on an empty
 * result, silently destroying topic slugs. Snapshot encoding/decoding belongs to the manifest
 * itself: snapshot_csv() writes the attempt skillssnapshot value and from_attempt() parses it.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Immutable value object mapping manifest codes to labels, BKT parameters and forge types.
 */
final class skill_manifest {
    /** @var string Pattern a stored custom code (topic slug) must match to be recognised. */
    public const CODE_PATTERN = '/^[a-z0-9]{1,32}$/';

    /** @var string[] Selected core codes, canonical (bkt::SKILLS) order. */
    private array $coreselected;

    /** @var array<string, \stdClass> Custom slug => object with label and templatetype, in order. */
    private array $custom;

    /**
     * Use from_instance() or from_attempt().
     *
     * @param array $coreselected Selected core codes in canonical order.
     * @param array $custom Map of slug to an object carrying label and templatetype, in order.
     */
    private function __construct(array $coreselected, array $custom) {
        $this->coreselected = $coreselected;
        $this->custom = $custom;
    }

    /**
     * Manifest of an instance: the teacher's core selection (skills csv column) plus the
     * instance's topic rows.
     *
     * The historical "empty skills csv means all 8" normalisation becomes CONDITIONAL here: it
     * applies only when the instance has zero topic rows. With topics present, an empty csv
     * means "no core skills selected" (a custom-only instance is legal, design D3). lib.php's
     * write-side normalisation of the skills column is made conditional to match (WP-D).
     *
     * @param \stdClass $instance The stackmastery instance record (skills is read).
     * @param array $topics stackmastery_topics rows for the instance, in sortorder
     *     (topics::for_instance()).
     * @return self The instance manifest.
     */
    public static function from_instance(\stdClass $instance, array $topics): self {
        $core = self::core_subset((string) ($instance->skills ?? ''));
        $custom = [];
        foreach ($topics as $row) {
            $custom[(string) $row->slug] = (object) [
                'label' => (string) $row->label,
                'templatetype' => (string) $row->templatetype,
            ];
        }
        if ($custom === [] && $core === []) {
            $core = bkt::SKILLS;
        }
        return new self($core, $custom);
    }

    /**
     * Manifest of an attempt: the frozen skillssnapshot csv is the truth. Core tokens become the
     * selected core codes; every other well-formed token is a custom slug, kept in snapshot
     * order. Labels and template types are joined from the LIVE topic rows; a topic row deleted
     * mid-attempt degrades to slug-as-label with no forge type (the attempt still replays).
     *
     * A snapshot with no custom tokens and no core tokens (legacy or corrupt) degrades to the
     * historical all-8 normalisation, matching skills::decode_csv() for pre-topics rows.
     *
     * @param \stdClass $instance The stackmastery instance record. Accepted for constructor
     *     symmetry and future per-instance policy; the frozen snapshot wins, so it is not read.
     * @param \stdClass $attempt The stackmastery_attempts row (skillssnapshot is read).
     * @param array $topics The instance's LIVE topic rows (topics::for_instance()), used only
     *     to resolve labels and template types for the snapshot's slugs.
     * @return self The attempt manifest.
     */
    public static function from_attempt(\stdClass $instance, \stdClass $attempt, array $topics): self {
        unset($instance);
        $bytopic = [];
        foreach ($topics as $row) {
            $bytopic[(string) $row->slug] = $row;
        }
        $tokens = array_map('trim', explode(',', (string) ($attempt->skillssnapshot ?? '')));
        $core = self::core_subset((string) ($attempt->skillssnapshot ?? ''));
        $custom = [];
        foreach ($tokens as $token) {
            if ($token === '' || in_array($token, bkt::SKILLS, true)) {
                continue;
            }
            if (preg_match(self::CODE_PATTERN, $token) !== 1) {
                // A malformed token cannot be a slug written by snapshot_csv(); drop it rather
                // than poison every keyed JSON codec downstream.
                continue;
            }
            if (isset($custom[$token])) {
                continue;
            }
            $row = $bytopic[$token] ?? null;
            $custom[$token] = (object) [
                'label' => $row === null ? $token : (string) $row->label,
                'templatetype' => $row === null ? '' : (string) $row->templatetype,
            ];
        }
        if ($custom === [] && $core === []) {
            $core = bkt::SKILLS;
        }
        return new self($core, $custom);
    }

    /**
     * Every code this manifest tracks: all 8 core codes in canonical order (always, so the
     * vector stays a mask-friendly superset), then the custom slugs in sortorder.
     *
     * @return string[] The manifest codes.
     */
    public function codes(): array {
        return array_merge(bkt::SKILLS, array_keys($this->custom));
    }

    /**
     * The active codes: the selected core codes (canonical order) plus ALL custom slugs (a
     * custom topic is always active while its row exists).
     *
     * @return string[] The selected codes.
     */
    public function selected(): array {
        return array_merge($this->coreselected, array_keys($this->custom));
    }

    /**
     * The custom entries: slug mapped to an object carrying label and templatetype.
     *
     * @return array The slug-keyed map, in manifest order.
     */
    public function custom(): array {
        return $this->custom;
    }

    /**
     * Whether this manifest carries any custom topic - THE branch flag between the trained
     * policy path (core only) and the heuristic selector (design D4).
     *
     * @return bool True when at least one custom slug is present.
     */
    public function has_custom(): bool {
        return $this->custom !== [];
    }

    /**
     * Display label for a code: core codes resolve through skills::label() (lang strings),
     * custom slugs resolve to the teacher's topic label. An unknown code renders as itself
     * (graceful fallback for report surfaces).
     *
     * @param string $code A manifest code.
     * @return string The label.
     */
    public function label(string $code): string {
        if (isset($this->custom[$code])) {
            return $this->custom[$code]->label;
        }
        if (in_array($code, bkt::SKILLS, true)) {
            return skills::label($code);
        }
        return $code;
    }

    /**
     * BKT parameters per manifest code: core codes from bkt::PARAMS, custom slugs from the
     * documented bkt::DEFAULT_CUSTOM_PARAMS default.
     *
     * @return array Map of code to its parameter array, in codes() order.
     */
    public function params(): array {
        $out = [];
        foreach (bkt::SKILLS as $code) {
            $out[$code] = bkt::PARAMS[$code];
        }
        foreach (array_keys($this->custom) as $slug) {
            $out[$slug] = bkt::DEFAULT_CUSTOM_PARAMS;
        }
        return $out;
    }

    /**
     * The forge template type used to generate questions for a code: core codes via
     * skills::forge_type(), custom slugs via their matched topic templatetype. Null for an
     * unknown code or a custom slug whose topic row no longer exists.
     *
     * @param string $code A manifest code.
     * @return string|null The forge template type, or null.
     */
    public function forge_type(string $code): ?string {
        if (isset($this->custom[$code])) {
            $type = $this->custom[$code]->templatetype;
            return $type === '' ? null : $type;
        }
        if (in_array($code, bkt::SKILLS, true)) {
            return skills::forge_type($code);
        }
        return null;
    }

    /**
     * The attempt skillssnapshot value: the csv of selected(). This is the manifest's OWN
     * codec (never skills::encode_csv, which drops slugs); from_attempt() is its inverse.
     *
     * @return string The snapshot csv.
     */
    public function snapshot_csv(): string {
        return implode(',', $this->selected());
    }

    /**
     * The canonical-order core subset of a skills csv (no all-8 back-fill; callers decide).
     *
     * @param string $csv Comma-separated codes.
     * @return string[] The selected core codes in canonical order.
     */
    private static function core_subset(string $csv): array {
        $tokens = array_map('trim', explode(',', $csv));
        return array_values(array_intersect(bkt::SKILLS, $tokens));
    }
}
