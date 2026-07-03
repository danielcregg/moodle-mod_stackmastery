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
 * Tests for the starter pack's pure tagging plan and the shipped bank contents.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * The file-name derived tag plan (no qtype_stack needed; the real import is covered by the
 * CAS-gated starter_pack_cas_test).
 *
 * @covers \mod_stackmastery\local\starter_pack
 */
final class starter_pack_test extends \advanced_testcase {
    /**
     * The tag plan maps indices to difficulties and gives single-file types all three tags.
     *
     * @return void
     */
    public function test_tag_plan_difficulty_rules(): void {
        $plan = starter_pack::tag_plan([
            'differentiate_1.xml',
            'differentiate_2.xml',
            'differentiate_3.xml',
            'differentiate_4.xml',
            'expand_1.xml',
            'simplify_lowest_terms_1.xml',
        ]);

        // Multi-file types tag by index: _1 easy, _2 medium, _3 hard, _4 medium.
        $this->assertSame(['skill' => 'differentiate', 'difficulties' => ['easy']], $plan['differentiate_1.xml']);
        $this->assertSame(['skill' => 'differentiate', 'difficulties' => ['medium']], $plan['differentiate_2.xml']);
        $this->assertSame(['skill' => 'differentiate', 'difficulties' => ['hard']], $plan['differentiate_3.xml']);
        $this->assertSame(['skill' => 'differentiate', 'difficulties' => ['medium']], $plan['differentiate_4.xml']);

        // A type with a single file covers the whole skill: all three difficulty tags.
        $this->assertSame(
            ['skill' => 'expand', 'difficulties' => ['easy', 'medium', 'hard']],
            $plan['expand_1.xml']
        );

        // The forge type crosses the vocabulary boundary to the canonical skill code.
        $this->assertSame('simplify', $plan['simplify_lowest_terms_1.xml']['skill']);
        $this->assertSame(['easy', 'medium', 'hard'], $plan['simplify_lowest_terms_1.xml']['difficulties']);
    }

    /**
     * Unknown types and unparseable names are omitted from the plan, never guessed.
     *
     * @return void
     */
    public function test_tag_plan_skips_unknown_files(): void {
        $plan = starter_pack::tag_plan([
            'geometry_1.xml',
            'README.md',
            'notaquestion.xml',
            'factor_1.xml',
        ]);
        $this->assertSame(['factor_1.xml'], array_keys($plan));
    }

    /**
     * The shipped bank is complete: 14 files, every one in the plan, covering all 8 skills at
     * every difficulty.
     *
     * @return void
     */
    public function test_shipped_bank_covers_every_cell(): void {
        $files = starter_pack::bank_files();
        $this->assertCount(14, $files);

        $plan = starter_pack::tag_plan($files);
        $this->assertCount(14, $plan, 'Every shipped file must parse into the tag plan.');

        $covered = [];
        foreach ($plan as $tags) {
            foreach ($tags['difficulties'] as $difficulty) {
                $covered[$tags['skill']][$difficulty] = true;
            }
        }
        foreach (skills::CODES as $skill) {
            foreach (skills::DIFFICULTIES as $difficulty) {
                $this->assertTrue(
                    !empty($covered[$skill][$difficulty]),
                    "Shipped bank leaves cell {$skill}/{$difficulty} empty."
                );
            }
        }

        // Every shipped file is a single readable STACK question with a name (the idempotency key).
        foreach ($files as $file) {
            $name = starter_pack::question_name(starter_pack::bank_directory() . '/' . $file);
            $this->assertNotSame('', $name, "Shipped file {$file} has no readable question name.");
        }
    }
}
