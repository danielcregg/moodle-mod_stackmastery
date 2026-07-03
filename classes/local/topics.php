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
 * Storage and slug rules for per-instance custom topics (stackmastery_topics).
 *
 * A custom topic is a teacher-worded skill backed by a matched forge question template
 * (custom-topics design D1). Rows are instance-owned, carry no user data, and their slugs are
 * the custom skill codes used in question tags, the attempt skillssnapshot and the mastery
 * JSON keys.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Topic row reads, the save-time sync primitive and the deterministic slug derivation.
 */
final class topics {
    /** @var int Maximum custom topics per instance (a semester of weekly topics). */
    public const MAX_TOPICS = 12;

    /** @var int Maximum slug length; keeps headroom inside every char32 skill column. */
    public const SLUG_MAX = 28;

    /** @var int Slug stem length used when a uniquifying numeric suffix is appended. */
    public const SLUG_STEM = 26;

    /**
     * Pinned fallback list of forge template type codes, used to seed the reserved-name set
     * when local_stackforge is not installed. Custom tags are site-global, so a slug must
     * never collide with any current or plausible future core or template code.
     *
     * @var string[]
     */
    public const TEMPLATE_TYPE_FALLBACK = ['differentiate', 'integrate', 'expand', 'factor',
                                           'simplify_lowest_terms', 'solve_linear',
                                           'solve_quadratic', 'numerical', 'set_theory'];

    /**
     * The instance's topic rows ordered by sortorder (creation order).
     *
     * @param int $stackmasteryid The instance id.
     * @return array The stackmastery_topics records as a positional list.
     */
    public static function for_instance(int $stackmasteryid): array {
        global $DB;
        $rows = $DB->get_records(
            'stackmastery_topics',
            ['stackmasteryid' => $stackmasteryid],
            'sortorder ASC, id ASC'
        );
        return array_values($rows);
    }

    /**
     * Synchronise the instance's topic rows with the form's working list.
     *
     * Each item is an array with keys slug (string or null), label (string) and templatetype
     * (string). An item whose slug matches an existing row KEEPS that row by slug identity
     * (its stored label and templatetype are trusted; only sortorder may move) - the hidden
     * form field is never allowed to rewrite a persisted row (design D10). Every other item is
     * a NEW row: any supplied slug is discarded and re-derived from the label via make_slug().
     * Existing rows not matched by any item are deleted. The 12-topic cap is enforced here as
     * defence in depth behind the form validation.
     *
     * Removed rows are deleted before new rows are inserted so a freed slug can be reused
     * within the same save without tripping the unique (stackmasteryid, slug) index.
     *
     * @param int $stackmasteryid The instance id.
     * @param array $items The working list, in display order.
     * @return void
     * @throws \invalid_parameter_exception On a cap, label or templatetype violation.
     */
    public static function sync(int $stackmasteryid, array $items): void {
        global $DB;
        if (count($items) > self::MAX_TOPICS) {
            throw new \invalid_parameter_exception(
                'at most ' . self::MAX_TOPICS . ' custom topics per instance'
            );
        }

        $existing = [];
        foreach (self::for_instance($stackmasteryid) as $row) {
            $existing[(string) $row->slug] = $row;
        }

        // Pass 1: validate and classify each item as kept (slug identity) or new.
        $clean = [];
        $keptslugs = [];
        foreach ($items as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '' || \core_text::strlen($label) > 255) {
                throw new \invalid_parameter_exception('topic label must be 1 to 255 characters');
            }
            $templatetype = (string) ($item['templatetype'] ?? '');
            if (preg_match('/^[a-z0-9_]{1,32}$/', $templatetype) !== 1) {
                throw new \invalid_parameter_exception("invalid topic template type '{$templatetype}'");
            }
            $slug = isset($item['slug']) && is_string($item['slug']) ? trim($item['slug']) : '';
            $kept = $slug !== '' && isset($existing[$slug]) && !in_array($slug, $keptslugs, true);
            if ($kept) {
                $keptslugs[] = $slug;
            }
            $clean[] = ['slug' => $kept ? $slug : null, 'label' => $label, 'templatetype' => $templatetype];
        }

        // Delete removed rows first so their slugs are reusable within this save.
        foreach ($existing as $slug => $row) {
            if (!in_array($slug, $keptslugs, true)) {
                $DB->delete_records('stackmastery_topics', ['id' => $row->id]);
            }
        }

        // Pass 2: apply in display order; new rows derive their slug against kept + new slugs.
        $now = time();
        $taken = $keptslugs;
        foreach ($clean as $sortorder => $item) {
            if ($item['slug'] !== null) {
                $row = $existing[$item['slug']];
                if ((int) $row->sortorder !== $sortorder) {
                    $DB->update_record('stackmastery_topics', (object) [
                        'id' => $row->id,
                        'sortorder' => $sortorder,
                        'timemodified' => $now,
                    ]);
                }
                continue;
            }
            $slug = self::make_slug($item['label'], $taken);
            $taken[] = $slug;
            $DB->insert_record('stackmastery_topics', (object) [
                'stackmasteryid' => $stackmasteryid,
                'sortorder' => $sortorder,
                'slug' => $slug,
                'label' => $item['label'],
                'templatetype' => $item['templatetype'],
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Derive a topic slug from a label (design D1, deterministic): lowercase, strip everything
     * outside [a-z0-9], truncate to 28. If the result is empty, a reserved name, or already
     * taken, truncate to 26 and append 2, 3, ... until free.
     *
     * @param string $label The teacher's topic label.
     * @param array $taken Slugs already in use on the instance (and being assigned this pass).
     * @return string The derived slug, matching [a-z0-9]{1,28}.
     */
    public static function make_slug(string $label, array $taken): string {
        $base = substr(preg_replace('/[^a-z0-9]/', '', \core_text::strtolower($label)), 0, self::SLUG_MAX);
        $blocked = array_merge(self::reserved(), $taken);
        if ($base !== '' && !in_array($base, $blocked, true)) {
            return $base;
        }
        $stem = substr($base, 0, self::SLUG_STEM);
        // Two suffix digits keep the slug within 28; the blocked set (12 topics + ~20 reserved
        // names) can never exhaust 98 candidates.
        for ($n = 2; $n < 100; $n++) {
            $slug = $stem . $n;
            if (!in_array($slug, $blocked, true)) {
                return $slug;
            }
        }
        throw new \coding_exception('could not derive a unique topic slug');
    }

    /**
     * The reserved names a slug may never take: the 8 core skill codes, every forge template
     * type code (the live registry when local_stackforge is installed, ALWAYS including the
     * pinned fallback list so the set is monotone even while the forge registry evolves), plus
     * the sentinels custom and none.
     *
     * @return string[] The reserved names.
     */
    public static function reserved(): array {
        $types = self::TEMPLATE_TYPE_FALLBACK;
        if (class_exists('\\local_stackforge\\local\\template_registry')) {
            $types = array_merge($types, \local_stackforge\local\template_registry::types());
        }
        return array_values(array_unique(array_merge(bkt::SKILLS, $types, ['custom', 'none'])));
    }
}
