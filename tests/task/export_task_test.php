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
 * Tests for the experience export scheduled task wrapper.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\task;

use mod_stackmastery\local\bkt;
use mod_stackmastery\local\experience;
use mod_stackmastery\local\export;

/**
 * Inert-by-default behaviour (setting off = no file, no run row) and delegation when enabled.
 *
 * @covers \mod_stackmastery\task\export_experience_task
 */
final class export_task_test extends \advanced_testcase {
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
     * Seed one finished attempt with one logged step.
     *
     * @return \stdClass The attempt record.
     */
    private function seed_attempt(): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module(
            'stackmastery',
            ['course' => $course->id, 'poolcategory' => 'WP4 pool']
        );
        $user = $this->getDataGenerator()->create_user();
        $mastery = experience::encode_mastery(array_fill(0, 8, 0.2));
        $attempt = (object) [
            'stackmasteryid' => $instance->id,
            'userid' => $user->id,
            'attemptnumber' => 1,
            'qubaid' => 0,
            'state' => 'complete',
            'finishreason' => 'target',
            'inprogressuniq' => 0,
            'currentslot' => 0,
            'pendingjson' => null,
            'preview' => 0,
            'masterycurrent' => $mastery,
            'skillssnapshot' => 'factor',
            'targetsnapshot' => json_encode(array_combine(bkt::SKILLS, array_fill(0, 8, 0.95))),
            'budget' => 40,
            'questionsdone' => 1,
            'reachedtarget' => 1,
            'stepstotarget' => null,
            'timetargetreached' => null,
            'masteryfinal' => $mastery,
            'policyversion' => 'shipped-abc123def456',
            'bktmodelversion' => 'bkt-1:default',
            'timeexported' => 0,
            'timestart' => time() - 600,
            'timefinish' => time() - 60,
            'timemodified' => time(),
        ];
        $attempt->id = $DB->insert_record('stackmastery_attempts', $attempt);
        $DB->set_field('stackmastery_attempts', 'inprogressuniq', $attempt->id, ['id' => $attempt->id]);
        $before = array_fill(0, 8, 0.2);
        $after = $before;
        $after[3] = 0.4;
        experience::log_step($attempt, 1, [
            'recommendedskill' => 'factor',
            'recommendeddifficulty' => 'easy',
            'servedskill' => 'factor',
            'serveddifficulty' => 'easy',
            'actionsource' => 'policy',
            'propensity' => 1.0,
            'policyversion' => 'shipped-abc123def456',
        ], [
            'questionid' => 101,
            'questionbankentryid' => 51,
            'questionversion' => 1,
            'slot' => 1,
        ], [
            'fraction' => 1.0,
            'masterybefore' => $before,
            'masteryafter' => $after,
        ]);
        return $attempt;
    }

    /**
     * Setting off (the inert default): mtrace + return, no file, no run row, no stamping.
     *
     * @return void
     */
    public function test_disabled_task_is_a_noop(): void {
        global $DB;
        $attempt = $this->seed_attempt();
        $this->assertEmpty(get_config('mod_stackmastery', 'experienceexport'));
        $task = new export_experience_task();
        $this->expectOutputRegex('/experience export disabled/');
        $task->execute();
        $this->assertSame(0, $DB->count_records('stackmastery_exportruns'));
        $this->assertSame([], glob(export::export_dir() . '/*.jsonl'));
        $this->assertEquals(0, $DB->get_field(
            'stackmastery_attempts',
            'timeexported',
            ['id' => $attempt->id]
        ));
    }

    /**
     * Setting on: the task delegates to export::run and reports the run.
     *
     * @return void
     */
    public function test_enabled_task_delegates_to_export(): void {
        global $DB;
        set_config('experienceexport', 1, 'mod_stackmastery');
        $this->seed_attempt();
        $task = new export_experience_task();
        $this->expectOutputRegex('/exported 1 attempts \/ 1 steps/');
        $task->execute();
        $runs = $DB->get_records('stackmastery_exportruns');
        $this->assertCount(1, $runs);
        $run = reset($runs);
        $this->assertFileExists(export::export_dir() . '/' . $run->filename);
    }

    /**
     * Setting on with nothing to export: the task reports and leaves no artefacts.
     *
     * @return void
     */
    public function test_enabled_task_with_nothing_new(): void {
        global $DB;
        set_config('experienceexport', 1, 'mod_stackmastery');
        $task = new export_experience_task();
        $this->expectOutputRegex('/nothing new to export/');
        $task->execute();
        $this->assertSame(0, $DB->count_records('stackmastery_exportruns'));
    }
}
