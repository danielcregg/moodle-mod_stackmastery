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
 * Query layer for the teacher report.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local\report;

use mod_stackmastery\local\attempt_store;
use mod_stackmastery\local\grades;
use mod_stackmastery\local\skills;

/**
 * Funnel, aggregate and per-attempt queries behind report.php.
 *
 * Reads only: every method is a pure query over the denormalised attempt columns the runtime
 * stamps at the crossing/finish moment (reachedtarget, stepstotarget, timetargetreached,
 * finishreason) plus the steps table. Nothing here recomputes trajectories.
 */
final class report_data {
    /**
     * Distinct-user funnel counts for one instance.
     *
     * @param int $stackmasteryid Instance id.
     * @return \stdClass Object with started, answered, completed and reached counts.
     */
    public static function funnel(int $stackmasteryid): \stdClass {
        global $DB;
        $params = ['stackmasteryid' => $stackmasteryid];
        $started = $DB->count_records_sql(
            'SELECT COUNT(DISTINCT a.userid)
               FROM {stackmastery_attempts} a
              WHERE a.stackmasteryid = :stackmasteryid',
            $params
        );
        $answered = $DB->count_records_sql(
            'SELECT COUNT(DISTINCT a.userid)
               FROM {stackmastery_attempts} a
              WHERE a.stackmasteryid = :stackmasteryid
                AND EXISTS (SELECT 1 FROM {stackmastery_steps} s WHERE s.attemptid = a.id)',
            $params
        );
        $completed = $DB->count_records_sql(
            'SELECT COUNT(DISTINCT a.userid)
               FROM {stackmastery_attempts} a
              WHERE a.stackmasteryid = :stackmasteryid AND a.state = :state',
            $params + ['state' => attempt_store::STATE_COMPLETE]
        );
        $reached = $DB->count_records_sql(
            'SELECT COUNT(DISTINCT a.userid)
               FROM {stackmastery_attempts} a
              WHERE a.stackmasteryid = :stackmasteryid AND a.reachedtarget = 1',
            $params
        );
        return (object) [
            'started'   => (int) $started,
            'answered'  => (int) $answered,
            'completed' => (int) $completed,
            'reached'   => (int) $reached,
        ];
    }

    /**
     * Cohort statistics for the overview stat cards.
     *
     * Medians are computed over target-reaching attempts only (the denormalised stepstotarget
     * and timetargetreached columns); the exploration share counts actionsource explore steps
     * against all logged steps and is null when no steps exist (never a division error).
     *
     * @param int $stackmasteryid Instance id.
     * @return \stdClass Object with inprogress, abandoned, medianstepstotarget,
     *         mediantimetotarget and exploreshare (0..1 or null).
     */
    public static function stats(int $stackmasteryid): \stdClass {
        global $DB;
        $inprogress = $DB->count_records('stackmastery_attempts', [
            'stackmasteryid' => $stackmasteryid,
            'state'          => attempt_store::STATE_INPROGRESS,
        ]);
        $abandoned = $DB->count_records('stackmastery_attempts', [
            'stackmasteryid' => $stackmasteryid,
            'state'          => attempt_store::STATE_ABANDONED,
        ]);

        $reaching = $DB->get_records_select(
            'stackmastery_attempts',
            'stackmasteryid = :stackmasteryid AND reachedtarget = 1',
            ['stackmasteryid' => $stackmasteryid],
            'id',
            'id, stepstotarget, timetargetreached, timestart'
        );
        $steps = [];
        $times = [];
        foreach ($reaching as $attempt) {
            if ($attempt->stepstotarget !== null) {
                $steps[] = (float) $attempt->stepstotarget;
            }
            if ($attempt->timetargetreached !== null) {
                $times[] = (float) ($attempt->timetargetreached - $attempt->timestart);
            }
        }

        $nsteps = 0;
        $nexplore = 0;
        foreach (self::step_aggregates($stackmasteryid) as $aggregate) {
            $nsteps += (int) $aggregate->nsteps;
            $nexplore += (int) $aggregate->nexplore;
        }

        return (object) [
            'inprogress'           => (int) $inprogress,
            'abandoned'            => (int) $abandoned,
            'medianstepstotarget'  => self::median($steps),
            'mediantimetotarget'   => self::median($times),
            'exploreshare'         => $nsteps > 0 ? $nexplore / $nsteps : null,
        ];
    }

    /**
     * Per-attempt step aggregates (step count and explore count) for one instance.
     *
     * @param int $stackmasteryid Instance id.
     * @return array<int, \stdClass> Map attemptid => object with nsteps and nexplore.
     */
    public static function step_aggregates(int $stackmasteryid): array {
        global $DB;
        $sql = "SELECT s.attemptid,
                       COUNT(1) AS nsteps,
                       SUM(CASE WHEN s.actionsource = :explore THEN 1 ELSE 0 END) AS nexplore
                  FROM {stackmastery_steps} s
                  JOIN {stackmastery_attempts} a ON a.id = s.attemptid
                 WHERE a.stackmasteryid = :stackmasteryid
              GROUP BY s.attemptid";
        return $DB->get_records_sql($sql, [
            'stackmasteryid' => $stackmasteryid,
            'explore'        => 'explore',
        ]);
    }

    /**
     * One overview row per attempt (every attempt, not just the best; D16).
     *
     * Each row object carries: attempt (the full attempts record), user (the user record, for
     * fullname() and the download email column), nsteps, exploreshare (0..1 or null), grade
     * (0..100 or null, always the gradebook mapping), counted (true on the user's
     * grade-contributing attempt), mastery (decoded map or null) and snapshotskills.
     * Rows of deleted users are excluded.
     *
     * @param \stdClass $instance The stackmastery instance record.
     * @return \stdClass[] Rows ordered by user then attempt number.
     */
    public static function overview_rows(\stdClass $instance): array {
        global $DB;
        $attempts = attempt_store::get_attempts((int) $instance->id);
        if ($attempts === []) {
            return [];
        }
        $userids = array_unique(array_map(fn($attempt) => (int) $attempt->userid, $attempts));
        $users = $DB->get_records_list('user', 'id', $userids);

        // The grade-contributing (highest) attempt per user, matching grades::get_user_grades.
        $bestid = [];
        $bestgrade = [];
        foreach ($attempts as $attempt) {
            $grade = grades::attempt_grade($instance, $attempt);
            if ($grade === null) {
                continue;
            }
            $uid = (int) $attempt->userid;
            if (!isset($bestgrade[$uid]) || $grade > $bestgrade[$uid]) {
                $bestgrade[$uid] = $grade;
                $bestid[$uid] = (int) $attempt->id;
            }
        }

        $aggregates = self::step_aggregates((int) $instance->id);
        $rows = [];
        foreach ($attempts as $attempt) {
            $uid = (int) $attempt->userid;
            $user = $users[$uid] ?? null;
            if ($user === null || !empty($user->deleted)) {
                continue;
            }
            $aggregate = $aggregates[(int) $attempt->id] ?? null;
            $nsteps = $aggregate === null ? 0 : (int) $aggregate->nsteps;
            $masteryjson = empty($attempt->masteryfinal)
                ? (string) $attempt->masterycurrent
                : (string) $attempt->masteryfinal;
            $mastery = json_decode($masteryjson, true);
            $rows[] = (object) [
                'attempt'        => $attempt,
                'user'           => $user,
                'nsteps'         => $nsteps,
                'exploreshare'   => $nsteps > 0 ? ((int) $aggregate->nexplore) / $nsteps : null,
                'grade'          => grades::attempt_grade($instance, $attempt),
                'counted'        => isset($bestid[$uid]) && $bestid[$uid] === (int) $attempt->id,
                'mastery'        => is_array($mastery) ? $mastery : null,
                'snapshotskills' => skills::decode_csv((string) $attempt->skillssnapshot),
            ];
        }
        return $rows;
    }

    /**
     * The mastery column codes: the instance's current selection unioned with every row's
     * snapshot skills, in canonical order.
     *
     * @param \stdClass $instance The instance record.
     * @param \stdClass[] $rows overview_rows() output.
     * @return string[] Skill codes.
     */
    public static function skill_columns(\stdClass $instance, array $rows): array {
        $wanted = skills::decode_csv((string) $instance->skills);
        foreach ($rows as $row) {
            foreach ($row->snapshotskills as $code) {
                $wanted[] = $code;
            }
        }
        $columns = [];
        foreach (skills::CODES as $code) {
            if (in_array($code, $wanted, true)) {
                $columns[] = $code;
            }
        }
        return $columns;
    }

    /**
     * The steps of one attempt with question names joined, ordered by seq.
     *
     * @param \stdClass $attempt The attempt record.
     * @return \stdClass[] Step records, each with an added questionname property.
     */
    public static function attempt_steps(\stdClass $attempt): array {
        global $DB;
        $steps = attempt_store::get_steps((int) $attempt->id);
        if ($steps === []) {
            return [];
        }
        $questionids = array_unique(array_map(fn($step) => (int) $step->questionid, $steps));
        $names = $DB->get_records_list('question', 'id', $questionids, '', 'id, name');
        foreach ($steps as $step) {
            $step->questionname = isset($names[(int) $step->questionid])
                ? (string) $names[(int) $step->questionid]->name
                : '';
        }
        return $steps;
    }

    /**
     * Load one attempt of this instance, or fail: the instance scoping guards forged ids.
     *
     * @param \stdClass $instance The instance record.
     * @param int $attemptid The attempt id from the request.
     * @return \stdClass The attempt record.
     */
    public static function attempt_record(\stdClass $instance, int $attemptid): \stdClass {
        global $DB;
        return $DB->get_record('stackmastery_attempts', [
            'id'             => $attemptid,
            'stackmasteryid' => (int) $instance->id,
        ], '*', MUST_EXIST);
    }

    /**
     * Median of a list of numbers: middle value, or the mean of the two middles.
     *
     * @param float[] $values The values (any order).
     * @return float|null The median, or null for an empty list.
     */
    public static function median(array $values): ?float {
        if ($values === []) {
            return null;
        }
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }
        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2.0;
    }
}
