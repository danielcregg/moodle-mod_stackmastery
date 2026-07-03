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
 * Tests for the derive-on-read grade logic.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Grade mapping and aggregation tests (pure logic on synthetic attempt rows).
 *
 * @covers \mod_stackmastery\local\grades
 */
final class grades_test extends \advanced_testcase {
    /**
     * Build a minimal instance record (no DB needed for pure grade math).
     *
     * @param int $grademode The grade mode.
     * @return \stdClass The instance record.
     */
    private function instance(int $grademode): \stdClass {
        return (object) ['id' => 1, 'grademode' => $grademode, 'skills' => 'differentiate,integrate'];
    }

    /**
     * Build an attempt record for grading.
     *
     * @param array $overrides Field overrides.
     * @return \stdClass The attempt record.
     */
    private function attempt(array $overrides = []): \stdClass {
        $defaults = [
            'id'             => 10,
            'userid'         => 5,
            'state'          => attempt_store::STATE_COMPLETE,
            'reachedtarget'  => 0,
            'skillssnapshot' => 'differentiate,integrate',
            'masteryfinal'   => json_encode(['differentiate' => 0.5, 'integrate' => 1.0]),
            'timefinish'     => 1000,
        ];
        return (object) array_merge($defaults, $overrides);
    }

    /**
     * Binary mode: 100 when reached, 0 when not, null while in progress.
     *
     * @return void
     */
    public function test_reachedtarget_mode(): void {
        $instance = $this->instance(grades::GRADEMODE_REACHEDTARGET);
        $this->assertSame(100.0, grades::attempt_grade($instance, $this->attempt(['reachedtarget' => 1])));
        $this->assertSame(0.0, grades::attempt_grade($instance, $this->attempt()));
        $this->assertNull(grades::attempt_grade(
            $instance,
            $this->attempt(['state' => attempt_store::STATE_INPROGRESS])
        ));
    }

    /**
     * Mean mode averages the final mastery over the ATTEMPT's snapshot skills, not the
     * instance's current selection.
     *
     * @return void
     */
    public function test_meanmastery_mode_uses_snapshot(): void {
        $instance = $this->instance(grades::GRADEMODE_MEANMASTERY);
        // Mean of 0.5 and 1.0 over the two snapshot skills.
        $this->assertEqualsWithDelta(75.0, grades::attempt_grade($instance, $this->attempt()), 1e-9);

        // The snapshot wins over the instance's current skills csv.
        $attempt = $this->attempt(['skillssnapshot' => 'differentiate']);
        $this->assertEqualsWithDelta(50.0, grades::attempt_grade($instance, $attempt), 1e-9);
    }

    /**
     * Abandoned attempts grade as-is in mean mode and 0 in binary mode.
     *
     * @return void
     */
    public function test_abandoned_grading(): void {
        $abandoned = $this->attempt(['state' => attempt_store::STATE_ABANDONED]);
        $this->assertEqualsWithDelta(
            75.0,
            grades::attempt_grade($this->instance(grades::GRADEMODE_MEANMASTERY), $abandoned),
            1e-9
        );
        $this->assertSame(
            0.0,
            grades::attempt_grade($this->instance(grades::GRADEMODE_REACHEDTARGET), $abandoned)
        );
    }

    /**
     * Corrupt mastery JSON grades as null (with a debugging notice), never an exception.
     *
     * @return void
     */
    public function test_corrupt_json_is_null(): void {
        $instance = $this->instance(grades::GRADEMODE_MEANMASTERY);
        $this->assertNull(grades::attempt_grade($instance, $this->attempt(['masteryfinal' => '{oops'])));
        $this->assertDebuggingCalled();
        $this->assertNull(grades::attempt_grade($instance, $this->attempt(['masteryfinal' => null])));
        $this->assertDebuggingCalled();
    }

    /**
     * get_user_grades keeps the highest gradable attempt per user with its finish time, skips
     * in-progress and corrupt attempts, and scopes to one user on request.
     *
     * @return void
     */
    public function test_get_user_grades_highest(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $pool = $this->getDataGenerator()->get_plugin_generator('mod_stackmastery')->create_pool([
            'course' => $course->id, 'skills' => ['differentiate'], 'percell' => 1,
        ]);
        $instance = $this->getDataGenerator()->create_module('stackmastery', [
            'course'         => $course->id,
            'poolcategoryid' => $pool->category->id,
            'grademode'      => grades::GRADEMODE_MEANMASTERY,
        ]);
        $instance = $DB->get_record('stackmastery', ['id' => $instance->id], '*', MUST_EXIST);

        $insert = function (int $userid, int $number, array $overrides) use ($DB, $instance): void {
            $now = 1000 + $number;
            $DB->insert_record('stackmastery_attempts', (object) array_merge([
                'stackmasteryid'  => $instance->id,
                'userid'          => $userid,
                'attemptnumber'   => $number,
                'qubaid'          => 0,
                'state'           => attempt_store::STATE_COMPLETE,
                'inprogressuniq'  => $number,
                'currentslot'     => 0,
                'preview'         => 0,
                'masterycurrent'  => json_encode(array_fill_keys(skills::CODES, 0.2)),
                'skillssnapshot'  => 'differentiate',
                'targetsnapshot'  => json_encode(array_fill_keys(skills::CODES, 0.95)),
                'budget'          => 40,
                'questionsdone'   => 0,
                'reachedtarget'   => 0,
                'policyversion'   => 'p',
                'bktmodelversion' => 'b',
                'timeexported'    => 0,
                'timestart'       => $now - 100,
                'timefinish'      => $now,
                'timemodified'    => $now,
            ], $overrides));
        };

        $u1 = 101;
        $u2 = 102;
        $insert($u1, 1, ['masteryfinal' => json_encode(['differentiate' => 0.4])]);
        $insert($u1, 2, ['masteryfinal' => json_encode(['differentiate' => 0.8])]);
        $insert($u1, 3, ['masteryfinal' => json_encode(['differentiate' => 0.6])]);
        // An open attempt and a corrupt one never contribute.
        $insert($u1, 4, ['state' => attempt_store::STATE_INPROGRESS, 'inprogressuniq' => 0,
            'timefinish' => 0, 'masteryfinal' => null]);
        $insert($u2, 1, ['masteryfinal' => '{oops']);

        $grades = grades::get_user_grades($instance);
        $this->assertDebuggingCalled();
        $this->assertArrayHasKey($u1, $grades);
        $this->assertArrayNotHasKey($u2, $grades);
        $this->assertEqualsWithDelta(80.0, $grades[$u1]->rawgrade, 1e-9);
        $this->assertSame(1002, (int) $grades[$u1]->dategraded);

        $only = grades::get_user_grades($instance, $u1);
        $this->assertCount(1, $only);
    }
}
