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
 * Tests for the per-instance/per-attempt skill manifest.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Manifest construction, code order, labels, params, forge types and the snapshot codec.
 *
 * @covers \mod_stackmastery\local\skill_manifest
 */
final class skill_manifest_test extends \advanced_testcase {
    /**
     * A topic row shaped like a stackmastery_topics record.
     *
     * @param string $slug The slug.
     * @param string $label The label.
     * @param string $templatetype The template type.
     * @param int $sortorder The sort order.
     * @return \stdClass The row.
     */
    private function topic(string $slug, string $label, string $templatetype, int $sortorder): \stdClass {
        return (object) [
            'id' => 1000 + $sortorder,
            'stackmasteryid' => 1,
            'sortorder' => $sortorder,
            'slug' => $slug,
            'label' => $label,
            'templatetype' => $templatetype,
            'timecreated' => 1,
            'timemodified' => 1,
        ];
    }

    /**
     * Core-only instance manifests reproduce the historical semantics exactly: canonical-order
     * selection, all-8 back-fill on an empty csv, snapshot csv equal to skills::encode_csv.
     *
     * @return void
     */
    public function test_from_instance_core_only(): void {
        $instance = (object) ['id' => 1, 'skills' => 'integrate,differentiate'];
        $manifest = skill_manifest::from_instance($instance, []);
        $this->assertFalse($manifest->has_custom());
        $this->assertSame(bkt::SKILLS, $manifest->codes());
        $this->assertSame(['differentiate', 'integrate'], $manifest->selected());
        $this->assertSame(skills::encode_csv(['integrate', 'differentiate']), $manifest->snapshot_csv());
        $this->assertSame([], $manifest->custom());
        $this->assertSame(bkt::PARAMS, $manifest->params());
        $this->assertSame('differentiate', $manifest->forge_type('differentiate'));
        $this->assertSame('simplify_lowest_terms', $manifest->forge_type('simplify'));

        // The historical normalisation: empty csv with NO topics means all 8.
        $manifest = skill_manifest::from_instance((object) ['id' => 1, 'skills' => ''], []);
        $this->assertSame(bkt::SKILLS, $manifest->selected());
    }

    /**
     * With topics: codes are the 8 core codes then slugs in sortorder; custom topics are always
     * selected; params and forge types resolve per code; an empty core csv means custom-only.
     *
     * @return void
     */
    public function test_from_instance_with_topics(): void {
        $topics = [
            $this->topic('settheory', 'Set theory', 'set_theory', 0),
            $this->topic('venn', 'Venn diagrams', 'set_theory', 1),
        ];
        $instance = (object) ['id' => 1, 'skills' => 'factor'];
        $manifest = skill_manifest::from_instance($instance, $topics);
        $this->assertTrue($manifest->has_custom());
        $this->assertSame(array_merge(bkt::SKILLS, ['settheory', 'venn']), $manifest->codes());
        $this->assertSame(['factor', 'settheory', 'venn'], $manifest->selected());
        $this->assertSame('factor,settheory,venn', $manifest->snapshot_csv());
        $this->assertSame(['settheory', 'venn'], array_keys($manifest->custom()));
        $this->assertSame('Set theory', $manifest->custom()['settheory']->label);
        $this->assertSame('Set theory', $manifest->label('settheory'));
        $this->assertSame('set_theory', $manifest->forge_type('settheory'));
        $params = $manifest->params();
        $this->assertSame(bkt::DEFAULT_CUSTOM_PARAMS, $params['settheory']);
        $this->assertSame(bkt::PARAMS['factor'], $params['factor']);
        $this->assertCount(10, $params);

        // Custom-only: an empty core csv with topics present selects the slugs alone.
        $manifest = skill_manifest::from_instance((object) ['id' => 1, 'skills' => ''], $topics);
        $this->assertSame(['settheory', 'venn'], $manifest->selected());
        $this->assertSame(array_merge(bkt::SKILLS, ['settheory', 'venn']), $manifest->codes());
        $this->assertSame('settheory,venn', $manifest->snapshot_csv());
    }

    /**
     * The snapshot codec round-trips: from_attempt(snapshot_csv()) reproduces the manifest,
     * with labels joined from the live rows.
     *
     * @return void
     */
    public function test_snapshot_round_trip(): void {
        $topics = [$this->topic('settheory', 'Set theory', 'set_theory', 0)];
        $instance = (object) ['id' => 1, 'skills' => 'differentiate,integrate'];
        $original = skill_manifest::from_instance($instance, $topics);
        $attempt = (object) ['skillssnapshot' => $original->snapshot_csv()];
        $again = skill_manifest::from_attempt($instance, $attempt, $topics);
        $this->assertSame($original->codes(), $again->codes());
        $this->assertSame($original->selected(), $again->selected());
        $this->assertSame('Set theory', $again->label('settheory'));
        $this->assertSame('set_theory', $again->forge_type('settheory'));
        $this->assertSame($original->snapshot_csv(), $again->snapshot_csv());
    }

    /**
     * A topic row deleted mid-attempt degrades to slug-as-label with no forge type; the slug
     * stays a tracked, selected code so the attempt replays.
     *
     * @return void
     */
    public function test_from_attempt_survives_deleted_topic_row(): void {
        $instance = (object) ['id' => 1, 'skills' => 'differentiate'];
        $attempt = (object) ['skillssnapshot' => 'differentiate,settheory'];
        $manifest = skill_manifest::from_attempt($instance, $attempt, []);
        $this->assertTrue($manifest->has_custom());
        $this->assertSame(['differentiate', 'settheory'], $manifest->selected());
        $this->assertSame('settheory', $manifest->label('settheory'));
        $this->assertNull($manifest->forge_type('settheory'));
        $this->assertContains('settheory', $manifest->codes());
    }

    /**
     * Legacy and defensive parsing: an empty snapshot backfills all 8 (skills::decode_csv
     * parity for pre-topics rows); malformed tokens are dropped; duplicates collapse; an
     * unknown code labels as itself.
     *
     * @return void
     */
    public function test_from_attempt_edge_cases(): void {
        $instance = (object) ['id' => 1, 'skills' => ''];
        $manifest = skill_manifest::from_attempt($instance, (object) ['skillssnapshot' => ''], []);
        $this->assertSame(bkt::SKILLS, $manifest->selected());
        $this->assertFalse($manifest->has_custom());

        $attempt = (object) ['skillssnapshot' => 'factor, settheory ,settheory,NOT VALID,x_y'];
        $manifest = skill_manifest::from_attempt($instance, $attempt, []);
        $this->assertSame(['factor', 'settheory'], $manifest->selected());
        $this->assertSame('unknowncode', $manifest->label('unknowncode'));
        $this->assertNull($manifest->forge_type('unknowncode'));

        // Core labels resolve through the lang pack.
        $this->assertSame(skills::label('factor'), $manifest->label('factor'));
    }
}
