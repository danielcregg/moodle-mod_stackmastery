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
 * Tests for the cleanup scheduled task.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\task;

use mod_stackmastery\local\attempt_store;
use mod_stackmastery\local\skills;

/**
 * Retention purge (cutoff + batch), the permanent abandon-phase guard, orphan/artifact sweeps,
 * export-file retention and the log-only export-run reconciliation.
 *
 * The abandon phase itself cannot run here by design: the attempt manager ships in a later work
 * package and the task guards on its existence (master plan C29). The guard test below documents
 * exactly that no-op and skips itself once the manager exists.
 *
 * @covers \mod_stackmastery\task\cleanup_task
 */
final class cleanup_task_test extends \advanced_testcase {
    /**
     * Common setup.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/question/engine/lib.php');
        set_config('allowedqtypes', 'shortanswer', 'mod_stackmastery');
        // Keep every test independent of the (later-package) attempt engine: the abandon phase
        // is off unless a test opts in. The guard test re-enables it explicitly.
        set_config('abandonafter', 0, 'mod_stackmastery');
    }

    /**
     * Create a course + instance and return both with the module context.
     *
     * @return \stdClass Object with course, instance, context.
     */
    private function make_instance(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $pool = $this->getDataGenerator()->get_plugin_generator('mod_stackmastery')->create_pool([
            'course' => $course->id, 'skills' => ['differentiate'], 'percell' => 1,
        ]);
        $instance = $this->getDataGenerator()->create_module('stackmastery', [
            'course' => $course->id, 'poolcategoryid' => $pool->category->id,
        ]);
        $cm = get_coursemodule_from_instance('stackmastery', $instance->id);
        return (object) [
            'course'   => $course,
            'instance' => $instance,
            'context'  => \context_module::instance($cm->id),
        ];
    }

    /**
     * Create a real saved question usage owned by mod_stackmastery.
     *
     * @param \context_module $context The module context.
     * @return int The usage id.
     */
    private function make_usage(\context_module $context): int {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(
            ['contextid' => $context->get_course_context()->id]
        );
        $question = $questiongenerator->create_question(
            'shortanswer',
            'frogtoad',
            ['category' => $category->id]
        );

        $quba = \question_engine::make_questions_usage_by_activity('mod_stackmastery', $context);
        $quba->set_preferred_behaviour('deferredfeedback');
        $quba->add_question(\question_bank::load_question($question->id));
        $quba->start_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);
        return $quba->get_id();
    }

    /**
     * Insert an attempt row.
     *
     * @param int $instanceid Instance id.
     * @param int $userid User id.
     * @param array $overrides Field overrides.
     * @return int The attempt id.
     */
    private function insert_attempt(int $instanceid, int $userid, array $overrides = []): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('stackmastery_attempts', (object) array_merge([
            'stackmasteryid'  => $instanceid,
            'userid'          => $userid,
            'attemptnumber'   => 1,
            'qubaid'          => 0,
            'state'           => attempt_store::STATE_INPROGRESS,
            'inprogressuniq'  => 0,
            'currentslot'     => 0,
            'preview'         => 0,
            'masterycurrent'  => json_encode(array_fill_keys(skills::CODES, 0.2)),
            'skillssnapshot'  => implode(',', skills::CODES),
            'targetsnapshot'  => json_encode(array_fill_keys(skills::CODES, 0.95)),
            'budget'          => 40,
            'questionsdone'   => 0,
            'reachedtarget'   => 0,
            'policyversion'   => 'p',
            'bktmodelversion' => 'b',
            'timeexported'    => 0,
            'timestart'       => $now,
            'timefinish'      => 0,
            'timemodified'    => $now,
        ], $overrides));
    }

    /**
     * Insert a step row.
     *
     * @param int $attemptid Attempt id.
     * @param int $seq Step sequence (also used as the slot).
     * @return int The step id.
     */
    private function insert_step(int $attemptid, int $seq): int {
        global $DB;
        return (int) $DB->insert_record('stackmastery_steps', (object) [
            'attemptid'             => $attemptid,
            'seq'                   => $seq,
            'slot'                  => $seq,
            'questionid'            => 1,
            'questionbankentryid'   => 1,
            'questionversion'       => 1,
            'variant'               => 1,
            'stackseed'             => null,
            'recommendedskill'      => 'differentiate',
            'recommendeddifficulty' => 'easy',
            'servedskill'           => 'differentiate',
            'serveddifficulty'      => 'easy',
            'actionsource'          => 'policy',
            'propensity'            => 1,
            'masterybefore'         => json_encode(array_fill_keys(skills::CODES, 0.2)),
            'correct'               => 1,
            'fraction'              => 1.0,
            'masteryafter'          => json_encode(array_fill_keys(skills::CODES, 0.3)),
            'policyversion'         => 'p',
            'bktmodelversion'       => 'b',
            'stateencodingversion'  => 'enc-1',
            'rewardversion'         => 'reward-1',
            'timeanswered'          => time(),
        ]);
    }

    /**
     * Insert an export run row.
     *
     * @param string $filename The recorded export file name.
     * @param int $timecreated The run time.
     * @return int The row id.
     */
    private function insert_exportrun(string $filename, int $timecreated): int {
        global $DB;
        return (int) $DB->insert_record('stackmastery_exportruns', (object) [
            'filename'      => $filename,
            'sha256'        => str_repeat('a', 64),
            'attempts'      => 1,
            'steps'         => 2,
            'skippederrors' => 0,
            'timecreated'   => $timecreated,
        ]);
    }

    /**
     * Create a file in the export directory with a given mtime, the way the task resolves it.
     *
     * @param string $name The file name.
     * @param int $mtime The modification time to stamp.
     * @return string The absolute path.
     */
    private function make_export_file(string $name, int $mtime): string {
        $dir = cleanup_task::export_directory();
        make_writable_directory($dir);
        $path = $dir . '/' . $name;
        file_put_contents($path, "{}\n");
        touch($path, $mtime);
        return $path;
    }

    /**
     * Run the task capturing its mtrace output.
     *
     * @return string The task output.
     */
    private function run_task(): string {
        $task = new cleanup_task();
        ob_start();
        $task->execute();
        return ob_get_clean();
    }

    /**
     * The retention purge respects the cutoff and the per-run batch bound, deletes steps only,
     * and is off by default.
     *
     * @return void
     */
    public function test_retention_purge_respects_cutoff_and_batch(): void {
        global $DB;
        $made = $this->make_instance();
        $user = $this->getDataGenerator()->create_user();
        $now = time();

        $oldest = $this->insert_attempt((int) $made->instance->id, (int) $user->id, [
            'state' => attempt_store::STATE_COMPLETE, 'inprogressuniq' => 1,
            'timefinish' => $now - 40 * DAYSECS,
        ]);
        $this->insert_step($oldest, 1);
        $this->insert_step($oldest, 2);
        $older = $this->insert_attempt((int) $made->instance->id, (int) $user->id, [
            'attemptnumber' => 2, 'state' => attempt_store::STATE_COMPLETE, 'inprogressuniq' => 2,
            'timefinish' => $now - 35 * DAYSECS,
        ]);
        $this->insert_step($older, 1);
        $fresh = $this->insert_attempt((int) $made->instance->id, (int) $user->id, [
            'attemptnumber' => 3, 'state' => attempt_store::STATE_COMPLETE, 'inprogressuniq' => 3,
            'timefinish' => $now - DAYSECS,
        ]);
        $this->insert_step($fresh, 1);
        $open = $this->insert_attempt((int) $made->instance->id, (int) $user->id, [
            'attemptnumber' => 4, 'timemodified' => $now - 60 * DAYSECS,
        ]);
        $this->insert_step($open, 1);

        // Default configuration keeps everything (stepretention = 0 = forever).
        set_config('stepretention', 0, 'mod_stackmastery');
        $this->run_task();
        $this->assertSame(5, $DB->count_records('stackmastery_steps'));

        // The batch bound processes the oldest finished attempt first and no more.
        $deleted = attempt_store::purge_expired_steps($now - 30 * DAYSECS, 1);
        $this->assertSame(2, $deleted);
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $oldest]));
        $this->assertSame(1, $DB->count_records('stackmastery_steps', ['attemptid' => $older]));

        // The task then purges the rest past the cutoff, and only that.
        set_config('stepretention', 30 * DAYSECS, 'mod_stackmastery');
        $output = $this->run_task();
        $this->assertStringContainsString('purged 1 step rows', $output);
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $older]));
        $this->assertSame(1, $DB->count_records('stackmastery_steps', ['attemptid' => $fresh]));
        $this->assertSame(1, $DB->count_records('stackmastery_steps', ['attemptid' => $open]));

        // Retention deletes the experience log only: the attempt rows survive.
        $this->assertSame(4, $DB->count_records('stackmastery_attempts', ['stackmasteryid' => $made->instance->id]));
    }

    /**
     * The abandon phase is a documented no-op while the attempt manager does not exist: stale
     * open attempts are left untouched and the task does not throw (master plan C29).
     *
     * This test self-skips once the attempt engine lands; the live abandon path is that work
     * package's to test.
     *
     * @return void
     */
    public function test_abandon_phase_is_noop_without_manager(): void {
        global $DB;
        $made = $this->make_instance();
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        set_config('abandonafter', 7 * DAYSECS, 'mod_stackmastery');
        $staleid = $this->insert_attempt((int) $made->instance->id, (int) $user->id, [
            'timemodified' => $now - 8 * DAYSECS,
        ]);

        if (class_exists(\mod_stackmastery\local\attempt_manager::class)) {
            $this->markTestSkipped('attempt_manager exists: the live abandon path is covered by the engine tests.');
        }

        $output = $this->run_task();
        $this->assertStringContainsString('skipping the abandon phase', $output);

        $attempt = $DB->get_record('stackmastery_attempts', ['id' => $staleid], '*', MUST_EXIST);
        $this->assertSame(attempt_store::STATE_INPROGRESS, $attempt->state);
        $this->assertSame(0, (int) $attempt->inprogressuniq);
        $this->assertSame(0, (int) $attempt->timefinish);
        $this->assertSame($now - 8 * DAYSECS, (int) $attempt->timemodified);

        // With the setting off the phase does not even scan.
        set_config('abandonafter', 0, 'mod_stackmastery');
        $this->assertStringContainsString('auto-abandon is off', $this->run_task());
    }

    /**
     * The orphan sweep removes crash debris (usages, steps, aged snapshots, stale .tmp export
     * artifacts) and nothing else.
     *
     * @return void
     */
    public function test_orphan_sweep_and_stale_tmp_artifacts(): void {
        global $DB;
        $made = $this->make_instance();
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        set_config('exportfileretention', 35 * DAYSECS, 'mod_stackmastery');

        // A live attempt with steps that must survive.
        $liveusage = $this->make_usage($made->context);
        $live = $this->insert_attempt(
            (int) $made->instance->id,
            (int) $user->id,
            ['qubaid' => $liveusage]
        );
        $this->insert_step($live, 1);

        // Crash debris: an unreferenced usage, a dead-attempt step, an aged dead-attempt
        // snapshot and a fresh dead-attempt snapshot (protected by the age guard).
        $orphanusage = $this->make_usage($made->context);
        $this->insert_step(999999, 1);
        $DB->insert_record('stackmastery_pool_snapshot', (object) [
            'attemptid' => 999999, 'skill' => 'differentiate', 'difficulty' => 'easy',
            'questionbankentryid' => 1, 'questionid' => 1, 'questionversion' => 1,
            'timeserved' => null, 'invalid' => 0, 'timecreated' => $now - DAYSECS,
        ]);
        $DB->insert_record('stackmastery_pool_snapshot', (object) [
            'attemptid' => 999998, 'skill' => 'differentiate', 'difficulty' => 'easy',
            'questionbankentryid' => 2, 'questionid' => 2, 'questionversion' => 1,
            'timeserved' => null, 'invalid' => 0, 'timecreated' => $now,
        ]);

        // Export artifacts: a stale .tmp goes, a fresh .tmp and a young export file stay.
        $staletmp = $this->make_export_file('stackmastery_experience_a.jsonl.tmp', $now - 7 * HOURSECS);
        $freshtmp = $this->make_export_file('stackmastery_experience_b.jsonl.tmp', $now);
        $youngfile = $this->make_export_file('stackmastery_experience_c.jsonl', $now - DAYSECS);

        $this->run_task();

        $this->assertFalse($DB->record_exists('question_usages', ['id' => $orphanusage]));
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => 999999]));
        $this->assertSame(0, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => 999999]));
        $this->assertSame(1, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => 999998]));

        $this->assertTrue($DB->record_exists('question_usages', ['id' => $liveusage]));
        $this->assertSame(1, $DB->count_records('stackmastery_steps', ['attemptid' => $live]));

        $this->assertFileDoesNotExist($staletmp);
        $this->assertFileExists($freshtmp);
        $this->assertFileExists($youngfile);
    }

    /**
     * Export-file retention deletes only matching files past the horizon; 0 keeps everything.
     *
     * @return void
     */
    public function test_export_file_retention(): void {
        $now = time();
        set_config('exportfileretention', 10 * DAYSECS, 'mod_stackmastery');

        $old = $this->make_export_file('stackmastery_experience_old.jsonl', $now - 11 * DAYSECS);
        $young = $this->make_export_file('stackmastery_experience_new.jsonl', $now - DAYSECS);
        $othername = $this->make_export_file('notes.txt', $now - 30 * DAYSECS);

        $output = $this->run_task();
        $this->assertStringContainsString('deleted 1 export files', $output);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($young);
        $this->assertFileExists($othername);

        // Retention 0 turns the file pruning off entirely.
        set_config('exportfileretention', 0, 'mod_stackmastery');
        $ancient = $this->make_export_file('stackmastery_experience_ancient.jsonl', $now - 400 * DAYSECS);
        $this->run_task();
        $this->assertFileExists($ancient);
    }

    /**
     * Export runs whose file is missing are reported, log only: fresh rows (rename may be in
     * flight) and rows past the retention horizon are not flagged, and no rows are deleted.
     *
     * @return void
     */
    public function test_exportruns_missing_file_logging(): void {
        global $DB;
        $now = time();
        set_config('exportfileretention', 35 * DAYSECS, 'mod_stackmastery');

        $this->make_export_file('present.jsonl', $now - 2 * DAYSECS);
        $this->insert_exportrun('present.jsonl', $now - 2 * DAYSECS);
        $this->insert_exportrun('gone.jsonl', $now - 2 * DAYSECS);
        $this->insert_exportrun('fresh.jsonl', $now - 600);
        $this->insert_exportrun('ancient.jsonl', $now - 40 * DAYSECS);

        $output = $this->run_task();

        $this->assertStringContainsString('missing its file gone.jsonl', $output);
        $this->assertStringNotContainsString('fresh.jsonl', $output);
        $this->assertStringNotContainsString('ancient.jsonl', $output);
        $this->assertStringNotContainsString('missing its file present.jsonl', $output);

        // Log only: the run rows are provenance and are never deleted here.
        $this->assertSame(4, $DB->count_records('stackmastery_exportruns'));

        // With nothing missing inside the window, the reconciliation reports clean.
        $DB->delete_records('stackmastery_exportruns', ['filename' => 'gone.jsonl']);
        $this->assertStringContainsString('reconcile cleanly', $this->run_task());
    }
}
