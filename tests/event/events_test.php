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
 * Tests for every mod_stackmastery event class.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\event;

/**
 * Payload validation, rendering and backup mappings of the seven events.
 *
 * @covers \mod_stackmastery\event\attempt_started
 * @covers \mod_stackmastery\event\attempt_completed
 * @covers \mod_stackmastery\event\step_submitted
 * @covers \mod_stackmastery\event\report_viewed
 * @covers \mod_stackmastery\event\policy_promoted
 * @covers \mod_stackmastery\event\course_module_viewed
 * @covers \mod_stackmastery\event\course_module_instance_list_viewed
 */
final class events_test extends \advanced_testcase {
    /**
     * Course + instance + student fixture.
     *
     * @return \stdClass Object with course, instance, cm, context, student.
     */
    private function make(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $pool = $this->getDataGenerator()->get_plugin_generator('mod_stackmastery')->create_pool([
            'course' => $course->id, 'skills' => ['differentiate'], 'percell' => 1,
        ]);
        $instance = $this->getDataGenerator()->create_module('stackmastery', [
            'course' => $course->id, 'poolcategoryid' => $pool->category->id,
        ]);
        $cm = get_coursemodule_from_instance('stackmastery', $instance->id);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return (object) [
            'course'   => $course,
            'instance' => $instance,
            'cm'       => $cm,
            'context'  => \context_module::instance($cm->id),
            'student'  => $student,
        ];
    }

    /**
     * Trigger an event inside a sink and return the captured instance.
     *
     * @param \core\event\base $event The event.
     * @return \core\event\base The captured event.
     */
    private function capture(\core\event\base $event): \core\event\base {
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(1, $events);
        return reset($events);
    }

    /**
     * attempt_started renders and maps.
     *
     * @return void
     */
    public function test_attempt_started(): void {
        $this->resetAfterTest();
        $made = $this->make();

        $event = attempt_started::create([
            'objectid'      => 77,
            'context'       => $made->context,
            'relateduserid' => $made->student->id,
            'other'         => ['stackmasteryid' => $made->instance->id],
        ]);
        $captured = $this->capture($event);
        $this->assertSame('c', $captured->crud);
        $this->assertSame(\core\event\base::LEVEL_PARTICIPATING, $captured->edulevel);
        $this->assertSame('stackmastery_attempts', $captured->objecttable);
        $this->assertNotEmpty($captured::get_name());
        $this->assertStringContainsString('started', $captured->get_description());
        $this->assertInstanceOf(\moodle_url::class, $captured->get_url());
        $this->assertSame(
            ['db' => 'stackmastery_attempts', 'restore' => 'stackmastery_attempt'],
            attempt_started::get_objectid_mapping()
        );
        $this->assertArrayHasKey('stackmasteryid', attempt_started::get_other_mapping());
    }

    /**
     * attempt_started requires relateduserid and stackmasteryid.
     *
     * @return void
     */
    public function test_attempt_started_validation(): void {
        $this->resetAfterTest();
        $made = $this->make();

        try {
            attempt_started::create([
                'objectid' => 77,
                'context'  => $made->context,
                'other'    => ['stackmasteryid' => $made->instance->id],
            ]);
            $this->fail('Missing relateduserid must throw.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('relateduserid', $e->getMessage());
        }
        try {
            attempt_started::create([
                'objectid'      => 77,
                'context'       => $made->context,
                'relateduserid' => $made->student->id,
            ]);
            $this->fail('Missing stackmasteryid must throw.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('stackmasteryid', $e->getMessage());
        }
    }

    /**
     * attempt_completed carries the finishreason enum and the reached flag.
     *
     * @return void
     */
    public function test_attempt_completed(): void {
        $this->resetAfterTest();
        $made = $this->make();

        $event = attempt_completed::create([
            'objectid'      => 77,
            'context'       => $made->context,
            'relateduserid' => $made->student->id,
            'other'         => [
                'stackmasteryid' => $made->instance->id,
                'reason'         => 'target',
                'reachedtarget'  => 1,
            ],
        ]);
        $captured = $this->capture($event);
        $this->assertSame('u', $captured->crud);
        $this->assertStringContainsString('target', $captured->get_description());
        $this->assertSame(
            ['db' => 'stackmastery_attempts', 'restore' => 'stackmastery_attempt'],
            attempt_completed::get_objectid_mapping()
        );

        $this->expectException(\coding_exception::class);
        attempt_completed::create([
            'objectid'      => 78,
            'context'       => $made->context,
            'relateduserid' => $made->student->id,
            'other'         => ['stackmasteryid' => $made->instance->id, 'reachedtarget' => 0],
        ]);
    }

    /**
     * step_submitted requires the full categorical payload.
     *
     * @return void
     */
    public function test_step_submitted(): void {
        $this->resetAfterTest();
        $made = $this->make();

        $event = step_submitted::create([
            'objectid'      => 501,
            'context'       => $made->context,
            'relateduserid' => $made->student->id,
            'other'         => [
                'attemptid'    => 77,
                'seq'          => 3,
                'skill'        => 'differentiate',
                'difficulty'   => 'medium',
                'actionsource' => 'policy',
                'correct'      => 1,
            ],
        ]);
        $captured = $this->capture($event);
        $this->assertSame('stackmastery_steps', $captured->objecttable);
        $this->assertStringContainsString('differentiate', $captured->get_description());
        $this->assertSame(
            ['db' => 'stackmastery_steps', 'restore' => 'stackmastery_step'],
            step_submitted::get_objectid_mapping()
        );
        $this->assertArrayHasKey('attemptid', step_submitted::get_other_mapping());

        $this->expectException(\coding_exception::class);
        step_submitted::create([
            'objectid'      => 502,
            'context'       => $made->context,
            'relateduserid' => $made->student->id,
            'other'         => ['attemptid' => 77, 'seq' => 4],
        ]);
    }

    /**
     * report_viewed carries a mode and has no objectid mapping.
     *
     * @return void
     */
    public function test_report_viewed(): void {
        $this->resetAfterTest();
        $made = $this->make();

        $event = report_viewed::create([
            'context' => $made->context,
            'other'   => ['mode' => 'overview'],
        ]);
        $captured = $this->capture($event);
        $this->assertSame('r', $captured->crud);
        $this->assertSame(\core\event\base::LEVEL_TEACHING, $captured->edulevel);
        $this->assertStringContainsString('overview', $captured->get_description());
        $this->assertFalse(report_viewed::get_objectid_mapping());

        $this->expectException(\coding_exception::class);
        report_viewed::create(['context' => $made->context]);
    }

    /**
     * policy_promoted lives in the system context and requires the id/source triple.
     *
     * @return void
     */
    public function test_policy_promoted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $event = policy_promoted::create([
            'context' => \context_system::instance(),
            'other'   => [
                'oldpolicyid'      => 'shipped-abc123def456',
                'newpolicyid'      => 'moodle-20260703-01',
                'source'           => 'promote',
                'datasetsha'       => null,
                'gateavgquestions' => null,
            ],
        ]);
        $captured = $this->capture($event);
        $this->assertSame('u', $captured->crud);
        $this->assertSame(\core\event\base::LEVEL_OTHER, $captured->edulevel);
        $this->assertStringContainsString('moodle-20260703-01', $captured->get_description());
        $this->assertFalse(policy_promoted::get_objectid_mapping());

        $this->expectException(\coding_exception::class);
        policy_promoted::create([
            'context' => \context_system::instance(),
            'other'   => ['oldpolicyid' => 'a', 'newpolicyid' => 'b'],
        ]);
    }

    /**
     * The two conventional view events fire with the module's shapes.
     *
     * @return void
     */
    public function test_view_events(): void {
        $this->resetAfterTest();
        $made = $this->make();

        $event = course_module_viewed::create([
            'objectid' => $made->instance->id,
            'context'  => $made->context,
        ]);
        $captured = $this->capture($event);
        $this->assertSame('stackmastery', $captured->objecttable);
        $this->assertSame('r', $captured->crud);
        $this->assertSame(
            ['db' => 'stackmastery', 'restore' => 'stackmastery'],
            course_module_viewed::get_objectid_mapping()
        );

        $event = course_module_instance_list_viewed::create([
            'context' => \context_course::instance($made->course->id),
        ]);
        $captured = $this->capture($event);
        $this->assertSame('r', $captured->crud);
    }
}
