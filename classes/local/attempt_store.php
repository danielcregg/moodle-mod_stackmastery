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
 * Shared data-access primitives for attempts, steps and pool snapshots.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * THE deletion/query primitive.
 *
 * The privacy provider, the cleanup task, course reset and stackmastery_delete_instance() all
 * route deletions through here so the deletion path (usages, then steps, then snapshots, then
 * attempts, in one transaction) is implemented exactly once. Pure DB plus question-engine calls;
 * no lifecycle logic and no capability checks (callers gate).
 */
final class attempt_store {
    /** Attempt state: open. */
    public const STATE_INPROGRESS = 'inprogress';

    /** Attempt state: finished normally (target, budget, exhausted, user or timeclose). */
    public const STATE_COMPLETE = 'complete';

    /** Attempt state: closed by the cleanup task after sitting untouched. */
    public const STATE_ABANDONED = 'abandoned';

    /**
     * The single open attempt of a user in an instance, or null.
     *
     * @param int $stackmasteryid Instance id.
     * @param int $userid User id.
     * @return \stdClass|null The open attempt record, or null.
     */
    public static function get_open_attempt(int $stackmasteryid, int $userid): ?\stdClass {
        global $DB;
        $record = $DB->get_record('stackmastery_attempts', [
            'stackmasteryid' => $stackmasteryid,
            'userid'         => $userid,
            'state'          => self::STATE_INPROGRESS,
        ]);
        return $record ?: null;
    }

    /**
     * Attempts of an instance, ordered by user then attempt number.
     *
     * @param int $stackmasteryid Instance id.
     * @param int|null $userid Restrict to one user; null = all users.
     * @param string|null $state Restrict to one state; null = all states.
     * @return \stdClass[] Attempt records.
     */
    public static function get_attempts(int $stackmasteryid, ?int $userid = null, ?string $state = null): array {
        global $DB;
        $conditions = ['stackmasteryid' => $stackmasteryid];
        if ($userid !== null) {
            $conditions['userid'] = $userid;
        }
        if ($state !== null) {
            $conditions['state'] = $state;
        }
        return array_values($DB->get_records('stackmastery_attempts', $conditions, 'userid, attemptnumber'));
    }

    /**
     * The steps of one attempt, ordered by seq.
     *
     * @param int $attemptid Attempt id.
     * @return \stdClass[] Step records.
     */
    public static function get_steps(int $attemptid): array {
        global $DB;
        return array_values($DB->get_records('stackmastery_steps', ['attemptid' => $attemptid], 'seq'));
    }

    /**
     * A qubaid_join selecting every question usage owned by this instance (optionally one user's).
     *
     * @param int $stackmasteryid Instance id.
     * @param int|null $userid Restrict to one user; null = all users.
     * @return \qubaid_join The join usable with the question engine's bulk APIs.
     */
    public static function usages_for_instance(int $stackmasteryid, ?int $userid = null): \qubaid_join {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $where = 'sma.stackmasteryid = :sm_instanceid';
        $params = ['sm_instanceid' => $stackmasteryid];
        if ($userid !== null) {
            $where .= ' AND sma.userid = :sm_userid';
            $params['sm_userid'] = $userid;
        }
        return new \qubaid_join('{stackmastery_attempts} sma', 'sma.qubaid', $where, $params);
    }

    /**
     * Delete attempts, their question usages, steps and snapshot rows, in one transaction.
     *
     * Order inside the transaction: usages (via the question engine, never raw deletes of
     * question tables), then steps, then snapshots, then the attempt rows.
     *
     * @param int $stackmasteryid Instance id.
     * @param int[]|null $userids Restrict to these users; null = all users.
     * @return int[] Distinct userids whose attempts were removed (callers push grade resets).
     */
    public static function delete_attempts(int $stackmasteryid, ?array $userids = null): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $where = 'stackmasteryid = :stackmasteryid';
        $params = ['stackmasteryid' => $stackmasteryid];
        $joinwhere = 'sma.stackmasteryid = :sm_instanceid';
        $joinparams = ['sm_instanceid' => $stackmasteryid];
        if ($userids !== null) {
            if ($userids === []) {
                return [];
            }
            [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
            $where .= " AND userid $usersql";
            $params += $userparams;
            [$jusersql, $juserparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ju');
            $joinwhere .= " AND sma.userid $jusersql";
            $joinparams += $juserparams;
        }

        $affected = $DB->get_fieldset_select('stackmastery_attempts', 'DISTINCT userid', $where, $params);
        if ($affected === []) {
            return [];
        }

        $transaction = $DB->start_delegated_transaction();
        \question_engine::delete_questions_usage_by_activities(
            new \qubaid_join('{stackmastery_attempts} sma', 'sma.qubaid', $joinwhere, $joinparams)
        );
        $subselect = "attemptid IN (SELECT id FROM {stackmastery_attempts} WHERE $where)";
        $DB->delete_records_select('stackmastery_steps', $subselect, $params);
        $DB->delete_records_select('stackmastery_pool_snapshot', $subselect, $params);
        $DB->delete_records_select('stackmastery_attempts', $where, $params);
        $transaction->allow_commit();

        return array_map('intval', $affected);
    }

    /**
     * Retention purge of the experience log: delete steps of finished attempts older than a cutoff.
     *
     * Never touches in-progress attempts (an ancient open attempt is first abandoned by the
     * cleanup task's earlier phase and only ages into this one afterwards). Idempotent: a re-scan
     * of already-purged attempts deletes zero rows.
     *
     * @param int $cutoff Attempts with 0 < timefinish < $cutoff qualify.
     * @param int $maxattemptsperrun Batch bound per run.
     * @return int Step rows deleted.
     */
    public static function purge_expired_steps(int $cutoff, int $maxattemptsperrun = 1000): int {
        global $DB;

        $attemptids = array_keys($DB->get_records_select(
            'stackmastery_attempts',
            'state <> :inprogress AND timefinish > 0 AND timefinish < :cutoff',
            ['inprogress' => self::STATE_INPROGRESS, 'cutoff' => $cutoff],
            'timefinish',
            'id',
            0,
            $maxattemptsperrun
        ));

        $deleted = 0;
        foreach (array_chunk($attemptids, 250) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'a');
            $deleted += $DB->count_records_select('stackmastery_steps', "attemptid $insql", $params);
            $DB->delete_records_list('stackmastery_steps', 'attemptid', $chunk);
        }
        return $deleted;
    }

    /**
     * Stale open attempts for the abandon sweep, oldest first.
     *
     * @param int $cutoff Attempts with timemodified < $cutoff qualify.
     * @param int $max Batch bound per run.
     * @return \stdClass[] Attempt records.
     */
    public static function get_stale_open_attempts(int $cutoff, int $max = 200): array {
        global $DB;
        return array_values($DB->get_records_select(
            'stackmastery_attempts',
            'state = :inprogress AND timemodified < :cutoff',
            ['inprogress' => self::STATE_INPROGRESS, 'cutoff' => $cutoff],
            'timemodified',
            '*',
            0,
            $max
        ));
    }

    /**
     * Safety-net sweep for crash debris.
     *
     * Deletes: question usages of this component with no attempt row; steps whose attempt is
     * gone; snapshot rows whose attempt is gone AND that are older than the age guard (the guard
     * covers the insert-before-attempt ordering inside the start transaction).
     *
     * @param int $minage Minimum age in seconds for orphan snapshot rows.
     * @return array{usages: int, steps: int, snapshots: int} Deletion counts.
     */
    public static function sweep_orphans(int $minage = 6 * HOURSECS): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $counts = ['usages' => 0, 'steps' => 0, 'snapshots' => 0];

        $usageids = $DB->get_fieldset_sql(
            "SELECT qu.id
               FROM {question_usages} qu
          LEFT JOIN {stackmastery_attempts} a ON a.qubaid = qu.id
              WHERE qu.component = 'mod_stackmastery' AND a.id IS NULL"
        );
        foreach (array_chunk($usageids, 100) as $chunk) {
            \question_engine::delete_questions_usage_by_activities(new \qubaid_list($chunk));
            $counts['usages'] += count($chunk);
        }

        $orphanwhere = 'attemptid NOT IN (SELECT id FROM {stackmastery_attempts})';
        $counts['steps'] = $DB->count_records_select('stackmastery_steps', $orphanwhere);
        if ($counts['steps'] > 0) {
            $DB->delete_records_select('stackmastery_steps', $orphanwhere);
        }

        $snapwhere = $orphanwhere . ' AND timecreated < :maxage';
        $snapparams = ['maxage' => time() - $minage];
        $counts['snapshots'] = $DB->count_records_select('stackmastery_pool_snapshot', $snapwhere, $snapparams);
        if ($counts['snapshots'] > 0) {
            $DB->delete_records_select('stackmastery_pool_snapshot', $snapwhere, $snapparams);
        }

        return $counts;
    }
}
