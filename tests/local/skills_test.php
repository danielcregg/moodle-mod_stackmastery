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
 * Tests for the skill registry's forge-type translation.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * The reverse forge-type map used by the pool builder and the nightly refill task.
 *
 * @covers \mod_stackmastery\local\skills
 */
final class skills_test extends \advanced_testcase {
    /**
     * forge_type() is the exact inverse of FORGE_TYPE_MAP for every canonical skill code.
     *
     * @return void
     */
    public function test_forge_type_inverts_the_map(): void {
        // The map is 1:1, so the round trip must recover every forge type and every skill.
        foreach (skills::FORGE_TYPE_MAP as $forgetype => $skill) {
            $this->assertSame($forgetype, skills::forge_type($skill));
        }
        foreach (skills::CODES as $skill) {
            $forgetype = skills::forge_type($skill);
            $this->assertNotNull($forgetype, "Skill {$skill} has no forge type.");
            $this->assertSame($skill, skills::FORGE_TYPE_MAP[$forgetype]);
        }
        // The one non-identity pair is the simplify vocabulary boundary.
        $this->assertSame('simplify_lowest_terms', skills::forge_type('simplify'));
    }

    /**
     * forge_type() rejects anything outside the canonical vocabulary.
     *
     * @return void
     */
    public function test_forge_type_unknown_code_is_null(): void {
        $this->assertNull(skills::forge_type('algebra'));
        $this->assertNull(skills::forge_type('simplify_lowest_terms'));
        $this->assertNull(skills::forge_type(''));
    }
}
