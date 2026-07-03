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
 * Tests for the reached-target custom completion rule.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\completion;

use mod_stackmastery\local\attempt_store;
use mod_stackmastery\local\skills;

/**
 * The completionreachedtarget rule.
 *
 * @covers \mod_stackmastery\completion\custom_completion
 */
final class custom_completion_test extends \advanced_testcase {
    /**
     * Create course, instance (with the rule enabled) and a student.
     *
     * @return \stdClass Object with course, instance, cm, student.
     */
    private function make(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $pool = $this->getDataGenerator()->get_plugin_generator('mod_stackmastery')->create_pool([
            'course' => $course->id, 'skills' => ['differentiate'], 'percell' => 1,
        ]);
        $instance = $this->getDataGenerator()->create_module('stackmastery', [
            'course'                  => $course->id,
            'poolcategoryid'          => $pool->category->id,
            'completion'              => COMPLETION_TRACKING_AUTOMATIC,
            'completionreachedtarget' => 1,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        return (object) ['course' => $course, 'instance' => $instance, 'cm' => $cm,
            'student' => $student];
    }

    /**
     * Insert an attempt row.
     *
     * @param int $instanceid Instance id.
     * @param int $userid User id.
     * @param int $reachedtarget The flag.
     * @return int The attempt id.
     */
    private function insert_attempt(int $instanceid, int $userid, int $reachedtarget): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('stackmastery_attempts', (object) [
            'stackmasteryid'  => $instanceid,
            'userid'          => $userid,
            'attemptnumber'   => 1,
            'qubaid'          => 0,
            'state'           => attempt_store::STATE_COMPLETE,
            'inprogressuniq'  => 1,
            'currentslot'     => 0,
            'preview'         => 0,
            'masterycurrent'  => json_encode(array_fill_keys(skills::CODES, 0.9)),
            'skillssnapshot'  => 'differentiate',
            'targetsnapshot'  => json_encode(array_fill_keys(skills::CODES, 0.95)),
            'budget'          => 40,
            'questionsdone'   => 5,
            'reachedtarget'   => $reachedtarget,
            'masteryfinal'    => json_encode(array_fill_keys(skills::CODES, 0.96)),
            'policyversion'   => 'p',
            'bktmodelversion' => 'b',
            'timeexported'    => 0,
            'timestart'       => $now - 100,
            'timefinish'      => $now,
            'timemodified'    => $now,
        ]);
    }

    /**
     * The rule is defined, described and sorted.
     *
     * @return void
     */
    public function test_rule_shape(): void {
        $this->resetAfterTest();
        $made = $this->make();

        $this->assertSame(['completionreachedtarget'], custom_completion::get_defined_custom_rules());
        $completion = new custom_completion($made->cm, (int) $made->student->id);
        $descriptions = $completion->get_custom_rule_descriptions();
        $this->assertArrayHasKey('completionreachedtarget', $descriptions);
        $this->assertSame(
            ['completionview', 'completionreachedtarget', 'completionusegrade'],
            $completion->get_sort_order()
        );
    }

    /**
     * INCOMPLETE with no attempts, INCOMPLETE with an unreached attempt, COMPLETE once any
     * attempt reached the target.
     *
     * @return void
     */
    public function test_get_state(): void {
        $this->resetAfterTest();
        $made = $this->make();
        $completion = new custom_completion($made->cm, (int) $made->student->id);

        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionreachedtarget'));

        $this->insert_attempt((int) $made->instance->id, (int) $made->student->id, 0);
        $completion = new custom_completion($made->cm, (int) $made->student->id);
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionreachedtarget'));

        global $DB;
        $DB->set_field('stackmastery_attempts', 'reachedtarget', 1, [
            'stackmasteryid' => $made->instance->id, 'userid' => $made->student->id,
        ]);
        $completion = new custom_completion($made->cm, (int) $made->student->id);
        $this->assertSame(COMPLETION_COMPLETE, $completion->get_state('completionreachedtarget'));
    }

    /**
     * Another user's reached attempt never completes this user.
     *
     * @return void
     */
    public function test_get_state_is_per_user(): void {
        $this->resetAfterTest();
        $made = $this->make();
        $other = $this->getDataGenerator()->create_and_enrol($made->course, 'student');
        $this->insert_attempt((int) $made->instance->id, (int) $other->id, 1);

        $completion = new custom_completion($made->cm, (int) $made->student->id);
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionreachedtarget'));
    }

    /**
     * An unknown rule is rejected.
     *
     * @return void
     */
    public function test_unknown_rule_rejected(): void {
        $this->resetAfterTest();
        $made = $this->make();
        $completion = new custom_completion($made->cm, (int) $made->student->id);
        $this->expectException(\coding_exception::class);
        $completion->get_state('nosuchrule');
    }
}
