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
 * Nightly pool refill: queue forge generation jobs for thin pool cells.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\task;

use mod_stackmastery\local\pool;
use mod_stackmastery\local\skill_manifest;
use mod_stackmastery\local\skills;
use mod_stackmastery\local\topics;

/**
 * Keeps every instance's question pool topped up without teacher attention.
 *
 * Inert unless the poolrefill admin setting is on. For each stackmastery instance whose pool
 * category still exists, it enumerates the manifest vocabulary (selected core skills plus the
 * instance's custom topics, spec D6) by the three difficulties (the same thin-cell definition
 * as the "Build my pool" button) and queues one STACK Question Forge job per cell below the
 * poolrefilltarget setting, mastery-tagged so the questions enter the pool as soon as the
 * forge validates them; custom-topic jobs carry their slug as an explicit tag skill. Jobs are
 * attributed to the primary administrator and the run is capped at MAX_JOBS_PER_RUN across all
 * instances; the remainder waits for the next night.
 *
 * The forge dependency is optional and sits behind two small seams (forge_available() and
 * queue_forge_job()) so the task is testable where local_stackforge is not installed.
 */
class pool_refill_task extends \core\task\scheduled_task {
    /** @var int Generation jobs queued per run, across all instances. */
    const MAX_JOBS_PER_RUN = 30;

    /** @var int Questions requested per job (the forge's own per-job clamp). */
    const PER_JOB_CAP = 10;

    /**
     * The task display name.
     *
     * @return string The localized name.
     */
    public function get_name() {
        return get_string('poolrefilltask', 'mod_stackmastery');
    }

    /**
     * Queue forge jobs for every thin cell, up to the per-run cap.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        if (!get_config('mod_stackmastery', 'poolrefill')) {
            mtrace('mod_stackmastery pool refill: off (poolrefill setting is disabled).');
            return;
        }
        if (!$this->forge_available()) {
            mtrace('mod_stackmastery pool refill: the STACK Question Forge job API '
                . '(local_stackforge) is not available; nothing to queue.');
            return;
        }

        $target = self::refill_target();
        $adminid = (int) get_admin()->id;
        $jobs = 0;
        $capped = false;

        $instances = $DB->get_records('stackmastery', null, 'id ASC');
        foreach ($instances as $instance) {
            $categoryid = (int) $instance->poolcategoryid;
            if ($categoryid <= 0 || !$DB->record_exists('question_categories', ['id' => $categoryid])) {
                continue;
            }
            $manifest = skill_manifest::from_instance($instance, topics::for_instance((int) $instance->id));
            $gaps = pool::cell_gaps(pool::cell_counts($categoryid, $manifest->selected()), $target);
            foreach ($gaps as $skill => $row) {
                $forgetype = $manifest->forge_type((string) $skill);
                if ($forgetype === null) {
                    continue;
                }
                // Custom topics need an explicit tag skill (their slug); core codes keep the
                // forge-type mapping default.
                $tagskill = skills::is_skill((string) $skill) ? null : (string) $skill;
                foreach ($row as $difficulty => $missing) {
                    if ($jobs >= self::MAX_JOBS_PER_RUN) {
                        $capped = true;
                        break 3;
                    }
                    $count = min(self::PER_JOB_CAP, (int) $missing);
                    $jobid = $this->queue_forge_job(
                        (int) $instance->course,
                        $adminid,
                        $categoryid,
                        $forgetype,
                        $difficulty,
                        $count,
                        $tagskill
                    );
                    $jobs++;
                    mtrace("mod_stackmastery pool refill: instance {$instance->id} cell "
                        . "{$skill}/{$difficulty} is {$missing} below target {$target}; "
                        . "queued forge job {$jobid} for {$count} question(s).");
                }
            }
        }

        if ($capped) {
            mtrace('mod_stackmastery pool refill: per-run cap of ' . self::MAX_JOBS_PER_RUN
                . ' jobs reached; remaining thin cells wait for the next run.');
        }
        mtrace("mod_stackmastery pool refill: queued {$jobs} generation job(s).");
    }

    /**
     * The per-cell refill target from the poolrefilltarget setting, clamped to 1..20 at read.
     *
     * @return int The target questions per (skill, difficulty) cell.
     */
    public static function refill_target(): int {
        $raw = get_config('mod_stackmastery', 'poolrefilltarget');
        $target = ($raw === false || $raw === '') ? 3 : (int) $raw;
        return min(20, max(1, $target));
    }

    /**
     * Whether the forge's public job API is installed (the optional-dependency seam).
     *
     * @return bool True when local_stackforge exposes queue_generation().
     */
    protected function forge_available(): bool {
        return class_exists('\\local_stackforge\\generator')
            && method_exists('\\local_stackforge\\generator', 'queue_generation');
    }

    /**
     * Queue one forge generation job, mastery-tagged (the stubbable seam for tests).
     *
     * Only ever called behind forge_available().
     *
     * @param int $courseid The instance's course.
     * @param int $userid The user the job is attributed to.
     * @param int $categoryid The pool category.
     * @param string $qtype The forge template type.
     * @param string $difficulty One of easy, medium or hard.
     * @param int $count Questions to request.
     * @param string|null $tagskill Explicit mastery tag skill (a custom-topic slug), or null
     *        for the core forge-type mapping.
     * @return int The forge job id.
     */
    protected function queue_forge_job(
        int $courseid,
        int $userid,
        int $categoryid,
        string $qtype,
        string $difficulty,
        int $count,
        ?string $tagskill = null
    ): int {
        return \local_stackforge\generator::queue_generation(
            $courseid,
            $userid,
            $categoryid,
            $qtype,
            $difficulty,
            $count,
            true,
            $tagskill
        );
    }
}
