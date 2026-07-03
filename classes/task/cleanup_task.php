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
 * Daily cleanup: abandon stale attempts, apply retention, sweep orphans and export files.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\task;

use mod_stackmastery\local\attempt_store;

/**
 * Scheduled cleanup task, in four idempotent phases.
 *
 * Phase 1 abandon-finalises in-progress attempts untouched for longer than the abandonafter
 * setting (0 = off), grading as-is through the attempt manager. Phase 2 purges the per-question
 * experience log of finished attempts past the stepretention horizon (0 = keep forever).
 * Phase 3 sweeps crash debris: orphaned question usages, steps and snapshot rows, plus stale
 * temporary export artifacts. Phase 4 deletes experience-export files past the
 * exportfileretention horizon and reports (log only) export runs whose file has gone missing.
 *
 * Phase 1 sits behind a PERMANENT class_exists guard on the attempt manager: the manager ships
 * in a later work package, so the phase is a deliberate no-op until it exists and then activates
 * without any edit to this file.
 */
class cleanup_task extends \core\task\scheduled_task {
    /** @var int Stale in-progress attempts finalised per run. */
    const ABANDON_BATCH = 200;

    /** @var int Export runs inspected per reconciliation pass. */
    const RECONCILE_BATCH = 500;

    /**
     * The task display name.
     *
     * @return string The localized name.
     */
    public function get_name() {
        return get_string('cleanuptask', 'mod_stackmastery');
    }

    /**
     * Run the four cleanup phases.
     *
     * @return void
     */
    public function execute() {
        $this->abandon_stale_attempts();
        $this->purge_expired_steps();
        $this->sweep_orphans();
        $this->apply_export_file_retention();
    }

    /**
     * Phase 1: finalise stale in-progress attempts as abandoned, grading as-is.
     *
     * No-op while \mod_stackmastery\local\attempt_manager does not exist (permanent guard: the
     * attempt engine arrives in a later work package and this file is never edited for it).
     * One bad attempt never wedges the task.
     *
     * @return void
     */
    protected function abandon_stale_attempts(): void {
        $abandonafter = (int) get_config('mod_stackmastery', 'abandonafter');
        if ($abandonafter <= 0) {
            mtrace('mod_stackmastery cleanup: auto-abandon is off (abandonafter = 0).');
            return;
        }
        if (!class_exists(\mod_stackmastery\local\attempt_manager::class)) {
            mtrace('mod_stackmastery cleanup: attempt engine not installed; skipping the abandon phase.');
            return;
        }

        $stale = attempt_store::get_stale_open_attempts(time() - $abandonafter, self::ABANDON_BATCH);
        $managers = [];
        $done = 0;
        $failed = 0;
        foreach ($stale as $attempt) {
            try {
                $manager = $this->manager_for_instance((int) $attempt->stackmasteryid, $managers);
                if ($manager === null) {
                    // Instance or course module gone; the rows are the orphan sweep's business.
                    continue;
                }
                $manager->abandon_attempt($attempt);
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                mtrace('mod_stackmastery cleanup: failed to abandon attempt ' . $attempt->id . ': ' . $e->getMessage());
            }
        }
        $suffix = $failed > 0 ? " ({$failed} failed)" : '';
        mtrace("mod_stackmastery cleanup: abandoned {$done} stale attempts{$suffix}.");
    }

    /**
     * A per-instance attempt manager, cached across the batch. Null when the instance is gone.
     *
     * Only ever called behind the phase-1 class_exists guard.
     *
     * @param int $instanceid The stackmastery instance id.
     * @param array $managers The per-run cache, keyed by instance id.
     * @return object|null The attempt manager, or null when the instance/cm no longer exists.
     */
    protected function manager_for_instance(int $instanceid, array &$managers): ?object {
        global $DB;

        if (!array_key_exists($instanceid, $managers)) {
            $managers[$instanceid] = null;
            $instance = $DB->get_record('stackmastery', ['id' => $instanceid]);
            $cm = false;
            if ($instance) {
                $cm = get_coursemodule_from_instance('stackmastery', $instance->id, $instance->course);
            }
            if ($instance && $cm) {
                $managers[$instanceid] = \mod_stackmastery\local\attempt_manager::create(
                    $instance,
                    \cm_info::create($cm),
                    \context_module::instance($cm->id)
                );
            }
        }
        return $managers[$instanceid];
    }

    /**
     * Phase 2: purge the experience log of finished attempts past the retention horizon.
     *
     * Attempts, final mastery, grades, question usages and snapshots are unaffected; 0 keeps
     * the log forever (destruction of research data is opt-in, never silent).
     *
     * @return void
     */
    protected function purge_expired_steps(): void {
        $retention = (int) get_config('mod_stackmastery', 'stepretention');
        if ($retention <= 0) {
            mtrace('mod_stackmastery cleanup: step retention is off (stepretention = 0).');
            return;
        }
        $purged = attempt_store::purge_expired_steps(time() - $retention);
        mtrace("mod_stackmastery cleanup: purged {$purged} step rows past the retention horizon.");
    }

    /**
     * Phase 3: sweep crash debris - orphaned usages/steps/snapshots and stale .tmp export files.
     *
     * A .tmp file in the export directory is a crash artifact of the export engine's
     * write-then-rename contract; anything older than the age guard is dead.
     *
     * @return void
     */
    protected function sweep_orphans(): void {
        $counts = attempt_store::sweep_orphans();
        mtrace('mod_stackmastery cleanup: orphan sweep removed ' . $counts['usages'] . ' usages, '
            . $counts['steps'] . ' steps, ' . $counts['snapshots'] . ' snapshot rows.');

        $tmps = self::delete_stale_files('*.tmp', time() - 6 * HOURSECS);
        if ($tmps > 0) {
            mtrace("mod_stackmastery cleanup: removed {$tmps} stale temporary export artifacts.");
        }
    }

    /**
     * Phase 4: delete export files past exportfileretention, then reconcile the run log.
     *
     * Export files are re-derivable while the step log lives, so pruning them is independent of
     * step retention. The reconciliation is LOG ONLY: an exportruns row whose file is missing
     * inside the retention window marks a crash between the watermark commit and the file rename
     * (those episodes never reached a training file - detectably, by design). Rows old enough
     * for their file to have been pruned by retention are expected to have no file and are not
     * reported; neither are very fresh rows whose rename may still be in flight.
     *
     * @return void
     */
    protected function apply_export_file_retention(): void {
        global $DB;

        $retention = (int) get_config('mod_stackmastery', 'exportfileretention');
        if ($retention > 0) {
            $deleted = self::delete_stale_files('stackmastery_experience_*.jsonl', time() - $retention);
            mtrace("mod_stackmastery cleanup: deleted {$deleted} export files past the file-retention horizon.");
        }

        $since = $retention > 0 ? time() - $retention + DAYSECS : 0;
        $grace = time() - HOURSECS;
        $runs = $DB->get_records_select(
            'stackmastery_exportruns',
            'timecreated >= :since AND timecreated < :grace',
            ['since' => $since, 'grace' => $grace],
            'timecreated DESC',
            'id, filename, timecreated',
            0,
            self::RECONCILE_BATCH
        );
        $dir = self::export_directory();
        $missing = 0;
        foreach ($runs as $run) {
            $filename = clean_param($run->filename, PARAM_FILE);
            if ($filename === '' || is_file($dir . '/' . $filename)) {
                continue;
            }
            $missing++;
            mtrace('mod_stackmastery cleanup: export run ' . $run->id . ' is missing its file '
                . $run->filename . ' (episodes in that run never reached a training file).');
        }
        if ($missing === 0) {
            mtrace('mod_stackmastery cleanup: export run files reconcile cleanly.');
        }
    }

    /**
     * The experience-export directory inside moodledata.
     *
     * Shared contract with the export engine: export files are written here as
     * stackmastery_experience_*.jsonl via a .tmp-then-rename sequence; this task prunes both.
     * The directory is never created here - absent means nothing to clean.
     *
     * @return string The absolute directory path.
     */
    public static function export_directory(): string {
        global $CFG;
        return $CFG->dataroot . '/stackmastery/export';
    }

    /**
     * Delete files matching a pattern in the export directory older than a cutoff.
     *
     * @param string $pattern The glob pattern, relative to the export directory.
     * @param int $cutoff Files with mtime strictly before this are deleted.
     * @return int Files deleted.
     */
    protected static function delete_stale_files(string $pattern, int $cutoff): int {
        $dir = self::export_directory();
        if (!is_dir($dir)) {
            return 0;
        }
        $deleted = 0;
        $paths = glob($dir . '/' . $pattern);
        foreach (($paths ?: []) as $path) {
            if (!is_file($path)) {
                continue;
            }
            $mtime = filemtime($path);
            if ($mtime !== false && $mtime < $cutoff && unlink($path)) {
                $deleted++;
            }
        }
        return $deleted;
    }
}
