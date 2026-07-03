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
 * Tests for the nightly pool refill task.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\task;

/**
 * The refill task's gating, gap enumeration, job arguments and per-run cap.
 *
 * local_stackforge is not installed in CI, so the forge dependency is exercised through the
 * task's two seams: a testable anonymous subclass makes forge_available() true and records every
 * queue_forge_job() call instead of queueing a real job.
 *
 * @covers \mod_stackmastery\task\pool_refill_task
 */
final class pool_refill_task_test extends \advanced_testcase {
    /**
     * Common setup: shortanswer pools (no CAS in CI).
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('allowedqtypes', 'shortanswer', 'mod_stackmastery');
    }

    /**
     * A testable task: the forge is "available" and queued jobs are recorded, not sent.
     *
     * @return pool_refill_task The stubbed task, carrying a public queued array.
     */
    private function make_task(): pool_refill_task {
        return new class extends pool_refill_task {
            /** @var array[] Recorded queue_forge_job() argument lists. */
            public $queued = [];

            /**
             * Pretend the forge job API is installed.
             *
             * @return bool Always true.
             */
            protected function forge_available(): bool {
                return true;
            }

            /**
             * Record the job instead of queueing it.
             *
             * @param int $courseid The instance's course.
             * @param int $userid The attributed user.
             * @param int $categoryid The pool category.
             * @param string $qtype The forge template type.
             * @param string $difficulty The difficulty code.
             * @param int $count Questions requested.
             * @return int A fake job id.
             */
            protected function queue_forge_job(
                int $courseid,
                int $userid,
                int $categoryid,
                string $qtype,
                string $difficulty,
                int $count
            ): int {
                $this->queued[] = [$courseid, $userid, $categoryid, $qtype, $difficulty, $count];
                return count($this->queued);
            }
        };
    }

    /**
     * Run a task capturing its mtrace output.
     *
     * @param pool_refill_task $task The task.
     * @return string The captured output.
     */
    private function run_task(pool_refill_task $task): string {
        ob_start();
        $task->execute();
        return ob_get_clean();
    }

    /**
     * Create a course + tagged pool + instance limited to the given skills.
     *
     * @param string[] $skills Skill codes for the pool and the instance.
     * @param int $percell Questions per (skill, difficulty) cell.
     * @return \stdClass Object with course, pool and instance.
     */
    private function make_setup(array $skills, int $percell): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $pool = $this->getDataGenerator()->get_plugin_generator('mod_stackmastery')->create_pool([
            'course'  => $course->id,
            'skills'  => $skills,
            'percell' => $percell,
        ]);
        $instance = $this->getDataGenerator()->create_module('stackmastery', [
            'course'         => $course->id,
            'poolcategoryid' => $pool->category->id,
            'skills'         => implode(',', $skills),
        ]);
        return (object) ['course' => $course, 'pool' => $pool, 'instance' => $instance];
    }

    /**
     * With the poolrefill setting off (the default) the task queues nothing, even for thin pools.
     *
     * @return void
     */
    public function test_setting_off_queues_nothing(): void {
        $this->make_setup(['differentiate'], 1);
        set_config('poolrefill', 0, 'mod_stackmastery');
        set_config('poolrefilltarget', 3, 'mod_stackmastery');

        $task = $this->make_task();
        $output = $this->run_task($task);

        $this->assertSame([], $task->queued);
        $this->assertStringContainsString('poolrefill setting is disabled', $output);
    }

    /**
     * Without the forge job API the enabled task is an explicit no-op.
     *
     * This documents the gating in CI (local_stackforge is not installed there) and self-skips
     * on a site that has the forge, where the no-op cannot be observed.
     *
     * @return void
     */
    public function test_inert_without_forge(): void {
        $this->make_setup(['differentiate'], 1);
        set_config('poolrefill', 1, 'mod_stackmastery');
        if (class_exists('\\local_stackforge\\generator')) {
            $this->markTestSkipped('local_stackforge is installed; the availability no-op cannot be observed.');
        }

        $output = $this->run_task(new pool_refill_task());
        $this->assertStringContainsString('not available', $output);
    }

    /**
     * Enabled with a thin pool: one job per thin cell with the exact expected arguments
     * (admin-attributed, forge-type-mapped, missing count), and invalid pool categories skipped.
     *
     * @return void
     */
    public function test_refill_queues_one_job_per_thin_cell(): void {
        $made = $this->make_setup(['differentiate', 'simplify'], 1);
        // An instance whose pool category no longer exists must be skipped entirely.
        $broken = $this->getDataGenerator()->create_module('stackmastery', [
            'course'         => $made->course->id,
            'poolcategoryid' => 999999,
        ]);
        set_config('poolrefill', 1, 'mod_stackmastery');
        set_config('poolrefilltarget', 3, 'mod_stackmastery');

        $task = $this->make_task();
        $output = $this->run_task($task);

        // 2 skills x 3 difficulties, each holding 1 of 3 questions: 6 jobs of 2.
        $this->assertCount(6, $task->queued);
        $adminid = (int) get_admin()->id;
        $bytype = [];
        foreach ($task->queued as $job) {
            [$courseid, $userid, $categoryid, $qtype, $difficulty, $count] = $job;
            $this->assertSame((int) $made->course->id, $courseid);
            $this->assertSame($adminid, $userid);
            $this->assertSame((int) $made->pool->category->id, $categoryid);
            $this->assertContains($difficulty, ['easy', 'medium', 'hard']);
            $this->assertSame(2, $count);
            $bytype[$qtype][] = $difficulty;
        }
        // The skill codes are translated to forge template types (simplify -> simplify_lowest_terms).
        $this->assertSame(['differentiate', 'simplify_lowest_terms'], array_keys($bytype));
        $this->assertEqualsCanonicalizing(['easy', 'medium', 'hard'], $bytype['differentiate']);
        $this->assertEqualsCanonicalizing(['easy', 'medium', 'hard'], $bytype['simplify_lowest_terms']);
        $this->assertStringContainsString('queued 6 generation job(s)', $output);
        $this->assertSame(999999, (int) $broken->poolcategoryid);
    }

    /**
     * A full cell (at or above target) queues nothing for that cell.
     *
     * @return void
     */
    public function test_full_cells_queue_nothing(): void {
        $this->make_setup(['numerical'], 3);
        set_config('poolrefill', 1, 'mod_stackmastery');
        set_config('poolrefilltarget', 3, 'mod_stackmastery');

        $task = $this->make_task();
        $output = $this->run_task($task);

        $this->assertSame([], $task->queued);
        $this->assertStringContainsString('queued 0 generation job(s)', $output);
    }

    /**
     * The per-run cap bounds the whole run across instances, and the cap is logged.
     *
     * @return void
     */
    public function test_job_cap_across_instances(): void {
        // Two all-8-skills instances with empty pool categories: 24 thin cells each, 48 total.
        $this->make_setup(\mod_stackmastery\local\skills::CODES, 0);
        $this->make_setup(\mod_stackmastery\local\skills::CODES, 0);
        set_config('poolrefill', 1, 'mod_stackmastery');
        set_config('poolrefilltarget', 3, 'mod_stackmastery');

        $task = $this->make_task();
        $output = $this->run_task($task);

        $this->assertCount(pool_refill_task::MAX_JOBS_PER_RUN, $task->queued);
        $this->assertStringContainsString('per-run cap', $output);
    }

    /**
     * The poolrefilltarget setting is clamped to 1..20 at read, with 3 as the unset default.
     *
     * @return void
     */
    public function test_refill_target_clamps(): void {
        unset_config('poolrefilltarget', 'mod_stackmastery');
        $this->assertSame(3, pool_refill_task::refill_target());

        set_config('poolrefilltarget', 7, 'mod_stackmastery');
        $this->assertSame(7, pool_refill_task::refill_target());

        set_config('poolrefilltarget', 0, 'mod_stackmastery');
        $this->assertSame(1, pool_refill_task::refill_target());

        set_config('poolrefilltarget', 99, 'mod_stackmastery');
        $this->assertSame(20, pool_refill_task::refill_target());
    }
}
