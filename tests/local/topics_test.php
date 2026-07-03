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
 * Tests for the custom-topic storage and slug rules.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * topics::sync round-trips, slug derivation (reserved names, collisions, the cap) and ordering.
 *
 * @covers \mod_stackmastery\local\topics
 */
final class topics_test extends \advanced_testcase {
    /**
     * Common setup.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The make_slug battery: derivation, stripping, truncation, reserved names, collisions and
     * the suffixing rules of design D1.
     *
     * @return void
     */
    public function test_make_slug_battery(): void {
        // Plain derivation: lowercase, strip non [a-z0-9].
        $this->assertSame('settheory', topics::make_slug('Set theory', []));
        $this->assertSame('venndiagrams101', topics::make_slug('  Venn diagrams 101! ', []));
        // Truncation to 28.
        $long = str_repeat('ab', 20); // 40 chars.
        $this->assertSame(substr($long, 0, 28), topics::make_slug($long, []));
        // Reserved core skill codes are uniquified, never taken verbatim.
        $this->assertSame('numerical2', topics::make_slug('Numerical', []));
        $this->assertSame('expand2', topics::make_slug('Expand', []));
        // The sentinels are reserved too.
        $this->assertSame('custom2', topics::make_slug('Custom', []));
        $this->assertSame('none2', topics::make_slug('None', []));
        // Taken slugs uniquify with increasing suffixes.
        $this->assertSame('settheory2', topics::make_slug('Set theory', ['settheory']));
        $this->assertSame('settheory3', topics::make_slug('Set theory', ['settheory', 'settheory2']));
        // When suffixing, the stem is truncated to 26 first so the result stays within 28.
        $base28 = substr(str_repeat('z', 30), 0, 28);
        $slug = topics::make_slug(str_repeat('z', 30), [$base28]);
        $this->assertSame(substr($base28, 0, 26) . '2', $slug);
        $this->assertLessThanOrEqual(28, strlen($slug));
        // A label that strips to nothing still yields a deterministic slug.
        $slug = topics::make_slug('!!!', []);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{1,28}$/', $slug);
        $this->assertSame('2', $slug);
        // Every produced slug obeys the pattern.
        foreach (['Set theory', 'Numerical', '!!!', str_repeat('q', 60)] as $label) {
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9]{1,28}$/',
                topics::make_slug($label, [])
            );
        }
    }

    /**
     * The reserved set blocks every current and plausible future core or template code: the 8
     * core skills, the pinned 9 forge template types (present with or without the live forge
     * registry) and the sentinels.
     *
     * @return void
     */
    public function test_reserved_names(): void {
        $reserved = topics::reserved();
        foreach (bkt::SKILLS as $code) {
            $this->assertContains($code, $reserved);
        }
        foreach (topics::TEMPLATE_TYPE_FALLBACK as $type) {
            $this->assertContains($type, $reserved);
        }
        $this->assertContains('set_theory', $reserved);
        $this->assertContains('custom', $reserved);
        $this->assertContains('none', $reserved);
    }

    /**
     * sync creates rows with derived slugs, sortorder and timestamps; for_instance returns
     * them in order.
     *
     * @return void
     */
    public function test_sync_creates_rows_in_order(): void {
        topics::sync(101, [
            ['slug' => null, 'label' => 'Set theory', 'templatetype' => 'set_theory'],
            ['slug' => null, 'label' => 'Venn diagrams', 'templatetype' => 'set_theory'],
        ]);
        $rows = topics::for_instance(101);
        $this->assertCount(2, $rows);
        $this->assertSame(['settheory', 'venndiagrams'], array_column($rows, 'slug'));
        $this->assertSame(['Set theory', 'Venn diagrams'], array_column($rows, 'label'));
        $this->assertSame(['set_theory', 'set_theory'], array_column($rows, 'templatetype'));
        $this->assertSame([0, 1], array_map('intval', array_column($rows, 'sortorder')));
        foreach ($rows as $row) {
            $this->assertGreaterThan(0, (int) $row->timecreated);
        }
        // Another instance is fully independent (same labels, same slugs, different rows).
        topics::sync(202, [['slug' => null, 'label' => 'Set theory', 'templatetype' => 'set_theory']]);
        $this->assertCount(1, topics::for_instance(202));
        $this->assertCount(2, topics::for_instance(101));
    }

    /**
     * A matched slug keeps its row (id and stored label/templatetype untouched: the hidden
     * field can never rewrite a persisted row), removed slugs delete, reorders update
     * sortorder, and a freed slug is reusable within the same save.
     *
     * @return void
     */
    public function test_sync_keeps_matches_deletes_removed_and_reorders(): void {
        topics::sync(7, [
            ['slug' => null, 'label' => 'Set theory', 'templatetype' => 'set_theory'],
            ['slug' => null, 'label' => 'Venn diagrams', 'templatetype' => 'set_theory'],
        ]);
        $before = topics::for_instance(7);
        $setid = (int) $before[0]->id;

        // Keep settheory (with a TAMPERED label and templatetype, which must be ignored),
        // drop venndiagrams, add a new topic, and put the new one first.
        topics::sync(7, [
            ['slug' => null, 'label' => 'Unions', 'templatetype' => 'set_theory'],
            ['slug' => 'settheory', 'label' => 'HACKED', 'templatetype' => 'differentiate'],
        ]);
        $rows = topics::for_instance(7);
        $this->assertSame(['unions', 'settheory'], array_column($rows, 'slug'));
        $this->assertSame([0, 1], array_map('intval', array_column($rows, 'sortorder')));
        $kept = $rows[1];
        $this->assertSame($setid, (int) $kept->id, 'a matched slug keeps its row');
        $this->assertSame('Set theory', $kept->label, 'stored label wins over the form payload');
        $this->assertSame('set_theory', $kept->templatetype, 'stored templatetype wins');

        // A supplied slug that matches nothing is treated as a NEW row with a re-derived slug.
        topics::sync(7, [
            ['slug' => 'doesnotexist', 'label' => 'Complements', 'templatetype' => 'set_theory'],
        ]);
        $rows = topics::for_instance(7);
        $this->assertSame(['complements'], array_column($rows, 'slug'));

        // Removing a topic frees its slug for reuse in the same save.
        topics::sync(7, [
            ['slug' => null, 'label' => 'Complements', 'templatetype' => 'set_theory'],
        ]);
        $rows = topics::for_instance(7);
        $this->assertSame(['complements'], array_column($rows, 'slug'));
    }

    /**
     * Duplicate labels in one save uniquify against each other; the cap and the field
     * validations throw.
     *
     * @return void
     */
    public function test_sync_collisions_cap_and_validation(): void {
        topics::sync(9, [
            ['slug' => null, 'label' => 'Set theory', 'templatetype' => 'set_theory'],
            ['slug' => null, 'label' => 'Set Theory!', 'templatetype' => 'set_theory'],
        ]);
        $this->assertSame(['settheory', 'settheory2'], array_column(topics::for_instance(9), 'slug'));

        // 13 topics trip the cap.
        $items = [];
        for ($i = 0; $i < 13; $i++) {
            $items[] = ['slug' => null, 'label' => 'Topic ' . $i, 'templatetype' => 'set_theory'];
        }
        try {
            topics::sync(9, $items);
            $this->fail('expected the 12-topic cap to throw');
        } catch (\invalid_parameter_exception $e) {
            $this->addToAssertionCount(1);
        }
        // Empty label and malformed templatetype are rejected.
        try {
            topics::sync(9, [['slug' => null, 'label' => '   ', 'templatetype' => 'set_theory']]);
            $this->fail('expected an empty label to throw');
        } catch (\invalid_parameter_exception $e) {
            $this->addToAssertionCount(1);
        }
        try {
            topics::sync(9, [['slug' => null, 'label' => 'Fine', 'templatetype' => 'Bad Type!']]);
            $this->fail('expected a malformed templatetype to throw');
        } catch (\invalid_parameter_exception $e) {
            $this->addToAssertionCount(1);
        }
        // Nothing above corrupted the stored rows.
        $this->assertCount(2, topics::for_instance(9));
    }
}
