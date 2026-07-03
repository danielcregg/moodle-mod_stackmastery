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
 * Tests for the mod_stackmastery data generator.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery;

use mod_stackmastery\local\skills;

/**
 * The generator's own tests.
 *
 * @covers \mod_stackmastery_generator
 */
final class generator_test extends \advanced_testcase {
    /**
     * A record with only course and poolcategoryid creates a full instance with the defaults
     * and a 0 to 100 grade item.
     *
     * @return void
     */
    public function test_create_instance_defaults(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/gradelib.php');

        $course = $this->getDataGenerator()->create_course();
        $pool = $this->getDataGenerator()->get_plugin_generator('mod_stackmastery')->create_pool([
            'course'  => $course->id,
            'skills'  => ['differentiate'],
            'percell' => 1,
        ]);
        $instance = $this->getDataGenerator()->create_module('stackmastery', [
            'course'         => $course->id,
            'poolcategoryid' => $pool->category->id,
        ]);

        $record = $DB->get_record('stackmastery', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertSame(implode(',', skills::CODES), $record->skills);
        $this->assertEqualsWithDelta(0.95, (float) $record->targetmastery, 1e-6);
        $this->assertSame(40, (int) $record->budget);
        $this->assertSame(0, (int) $record->maxattempts);
        $this->assertSame(0, (int) $record->grademode);
        $this->assertSame(1, (int) $record->showprogress);
        $this->assertNotEmpty(get_coursemodule_from_instance('stackmastery', $instance->id));

        $grading = grade_get_grades($course->id, 'mod', 'stackmastery', $instance->id);
        $this->assertNotEmpty($grading->items);
        $this->assertEqualsWithDelta(100.0, (float) $grading->items[0]->grademax, 1e-6);
    }

    /**
     * A poolcategory NAME resolves to its category id (the Behat path).
     *
     * @return void
     */
    public function test_create_instance_resolves_named_pool(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $pool = $this->getDataGenerator()->get_plugin_generator('mod_stackmastery')->create_pool([
            'course'       => $course->id,
            'skills'       => ['differentiate'],
            'percell'      => 1,
            'categoryname' => 'Pool A',
        ]);
        $instance = $this->getDataGenerator()->create_module('stackmastery', [
            'course'       => $course->id,
            'poolcategory' => 'Pool A',
        ]);
        $record = $DB->get_record('stackmastery', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertSame((int) $pool->category->id, (int) $record->poolcategoryid);
    }

    /**
     * Neither poolcategoryid nor poolcategory is a coding error.
     *
     * @return void
     */
    public function test_create_instance_requires_pool(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $this->expectException(\coding_exception::class);
        $this->getDataGenerator()->create_module('stackmastery', ['course' => $course->id]);
    }

    /**
     * create_pool builds the requested matrix: every question READY, distinct bank entries,
     * exactly the two production tags each.
     *
     * @return void
     */
    public function test_create_pool_shape(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $pool = $this->getDataGenerator()->get_plugin_generator('mod_stackmastery')->create_pool([
            'course'  => $course->id,
            'skills'  => ['differentiate', 'integrate'],
            'percell' => 2,
        ]);

        $entryids = [];
        $count = 0;
        foreach (['differentiate', 'integrate'] as $skill) {
            foreach (skills::DIFFICULTIES as $difficulty) {
                $this->assertCount(2, $pool->questions[$skill][$difficulty]);
                foreach ($pool->questions[$skill][$difficulty] as $question) {
                    $count++;
                    $entryids[] = $question->questionbankentryid;
                    $tags = \core_tag_tag::get_item_tags_array('core_question', 'question', $question->id);
                    sort($tags);
                    $expected = [skills::diff_tag($difficulty), skills::skill_tag($skill)];
                    sort($expected);
                    $this->assertSame($expected, array_values($tags));
                }
            }
        }
        $this->assertSame(12, $count);
        $this->assertCount(12, array_unique($entryids));
    }

    /**
     * Requesting a question type that is not installed names the dependency clearly.
     *
     * @return void
     */
    public function test_create_pool_missing_qtype_guarded(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $this->expectException(\coding_exception::class);
        $this->getDataGenerator()->get_plugin_generator('mod_stackmastery')->create_pool([
            'course' => $course->id,
            'qtype'  => 'definitelynotinstalled',
        ]);
    }
}
