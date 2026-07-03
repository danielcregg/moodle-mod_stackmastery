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
 * The question pool: eligibility queries, coverage validation and the attempt-start snapshot.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

use core_question\local\bank\question_version_status;

/**
 * Single home of "which questions are eligible".
 *
 * mod_form validation, the view.php teacher banner and the attempt-start snapshot all use the
 * same definition: latest READY question version in the pool category that itself carries both a
 * skill tag and a difficulty tag and whose question type is admin-allowed (default stack).
 */
final class pool {
    /**
     * Question types eligible for the pool, from the admin setting (default stack).
     *
     * @return string[] Lower-case question type names, never empty.
     */
    public static function qtypes(): array {
        $csv = (string) get_config('mod_stackmastery', 'allowedqtypes');
        $types = array_values(array_filter(array_map('trim', explode(',', $csv))));
        return $types === [] ? ['stack'] : $types;
    }

    /**
     * Count eligible questions per (skill, difficulty) cell.
     *
     * Every requested cell is present in the result, zero-filled; a missing tag simply leaves its
     * row or column at zero.
     *
     * @param int $categoryid Question category id (no subcategories).
     * @param string[] $skillcodes Subset of skills::CODES to count.
     * @param string[]|null $qtypes Question types to include; null = the admin setting.
     * @return array<string, array<string, int>> Map skillcode => difficulty => count.
     */
    public static function cell_counts(int $categoryid, array $skillcodes, ?array $qtypes = null): array {
        global $DB;

        $counts = [];
        foreach ($skillcodes as $skill) {
            $counts[$skill] = array_fill_keys(skills::DIFFICULTIES, 0);
        }
        if ($skillcodes === []) {
            return $counts;
        }

        $qtypes = $qtypes ?? self::qtypes();
        [$qtypesql, $qtypeparams] = $DB->get_in_or_equal($qtypes, SQL_PARAMS_NAMED, 'qt');
        foreach ($skillcodes as $skill) {
            foreach (skills::DIFFICULTIES as $difficulty) {
                $params = $qtypeparams + [
                    'categoryid' => $categoryid,
                    'ready'      => question_version_status::QUESTION_STATUS_READY,
                    'ready2'     => question_version_status::QUESTION_STATUS_READY,
                    'skilltag'   => skills::skill_tag($skill),
                    'difftag'    => skills::diff_tag($difficulty),
                ];
                $sql = "SELECT COUNT(DISTINCT qv.questionbankentryid)
                          FROM {question_versions} qv
                          JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                          JOIN {question} q ON q.id = qv.questionid
                         WHERE qbe.questioncategoryid = :categoryid
                           AND q.parent = 0
                           AND q.qtype $qtypesql
                           AND qv.status = :ready
                           AND qv.version = (SELECT MAX(v2.version)
                                               FROM {question_versions} v2
                                              WHERE v2.questionbankentryid = qv.questionbankentryid
                                                AND v2.status = :ready2)
                           AND EXISTS (SELECT 1
                                         FROM {tag_instance} ti1
                                         JOIN {tag} t1 ON t1.id = ti1.tagid
                                        WHERE ti1.itemtype = 'question' AND ti1.component = 'core_question'
                                          AND ti1.itemid = qv.questionid AND t1.name = :skilltag)
                           AND EXISTS (SELECT 1
                                         FROM {tag_instance} ti2
                                         JOIN {tag} t2 ON t2.id = ti2.tagid
                                        WHERE ti2.itemtype = 'question' AND ti2.component = 'core_question'
                                          AND ti2.itemid = qv.questionid AND t2.name = :difftag)";
                $counts[$skill][$difficulty] = (int) $DB->count_records_sql($sql, $params);
            }
        }
        return $counts;
    }

    /**
     * Cells below a per-cell target, with how many questions each is missing.
     *
     * Pure math on a cell_counts() map, so the pool builder page and the nightly refill task
     * share one definition of a thin cell.
     *
     * @param array $counts Map of skill code to difficulty code to question count.
     * @param int $target Wanted questions per cell.
     * @return array Map of skill code to difficulty code to missing count, containing only
     *         cells below the target.
     */
    public static function cell_gaps(array $counts, int $target): array {
        $gaps = [];
        foreach ($counts as $skill => $row) {
            foreach ($row as $difficulty => $count) {
                if ((int) $count < $target) {
                    $gaps[$skill][$difficulty] = $target - (int) $count;
                }
            }
        }
        return $gaps;
    }

    /**
     * Pure pool validation used by mod_form and the view.php teacher banner.
     *
     * @param int $categoryid Question category id.
     * @param string[] $skillcodes Selected skill codes.
     * @return array{errors: array<string, string>, warnings: string[]} Errors keyed by form field
     *         (empty cells hard-block); warnings are human strings for cells with 1 or 2 questions.
     */
    public static function validate_selection(int $categoryid, array $skillcodes): array {
        global $DB;

        $result = ['errors' => [], 'warnings' => []];
        if (!$DB->record_exists('question_categories', ['id' => $categoryid])) {
            $result['errors']['poolcategoryid'] = get_string('errpoolcategorymissing', 'mod_stackmastery');
            return $result;
        }

        $counts = self::cell_counts($categoryid, $skillcodes);
        $emptycells = [];
        foreach ($counts as $skill => $row) {
            foreach ($row as $difficulty => $count) {
                $cellname = $skill . '/' . $difficulty;
                if ($count === 0) {
                    $emptycells[] = $cellname;
                } else if ($count < 3) {
                    $result['warnings'][] = get_string(
                        'warnpoolthin',
                        'mod_stackmastery',
                        ['cell' => $cellname, 'count' => $count]
                    );
                }
            }
        }
        if ($emptycells !== []) {
            $result['errors']['poolcategoryid'] = get_string(
                'errpoolempty',
                'mod_stackmastery',
                ['cells' => implode(', ', $emptycells)]
            );
        }
        return $result;
    }

    /**
     * Flat question-category menu for the mod_form select.
     *
     * Categories of the course context, its category ancestors and the system context, excluding
     * the synthetic top containers. Keys are plain category ids (never the core "id,contextid"
     * composite; that trap is why this does not reuse question_category_options()).
     *
     * @param \context $coursecontext The course context.
     * @return array<int, string> Map categoryid => "Context: Category name".
     */
    public static function category_menu(\context $coursecontext): array {
        global $DB;

        $contextids = $coursecontext->get_parent_context_ids(true);
        [$ctxsql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        $records = $DB->get_records_select(
            'question_categories',
            "contextid $ctxsql AND parent <> 0",
            $params,
            'contextid, sortorder, name'
        );

        $menu = [];
        foreach ($records as $record) {
            $context = \context::instance_by_id($record->contextid, IGNORE_MISSING);
            $prefix = $context ? $context->get_context_name(false, true) : '';
            $menu[(int) $record->id] = ($prefix === '' ? '' : $prefix . ': ') . format_string($record->name);
        }
        return $menu;
    }

    /**
     * Freeze the eligible pool for an attempt: one row per (skill, difficulty, question version).
     *
     * The caller (the attempt manager) runs this inside its start transaction. Rows pin the
     * latest READY version of each eligible entry at this moment; mid-attempt question edits can
     * never change a running attempt.
     *
     * @param \stdClass $instance The stackmastery instance record (poolcategoryid is read).
     * @param int $attemptid The attempt the snapshot belongs to.
     * @param string[] $selectedskills Skill codes to snapshot.
     * @return array{cells: array<string, array<string, int>>, distinct: int} Per-cell counts and
     *         the distinct eligible entry count (the budget cap input).
     */
    public static function build_snapshot(\stdClass $instance, int $attemptid, array $selectedskills): array {
        global $DB;

        $qtypes = self::qtypes();
        [$qtypesql, $qtypeparams] = $DB->get_in_or_equal($qtypes, SQL_PARAMS_NAMED, 'qt');
        $now = time();
        $cells = [];
        $rows = [];
        $entries = [];
        foreach ($selectedskills as $skill) {
            $cells[$skill] = array_fill_keys(skills::DIFFICULTIES, 0);
            foreach (skills::DIFFICULTIES as $difficulty) {
                $params = $qtypeparams + [
                    'categoryid' => (int) $instance->poolcategoryid,
                    'ready'      => question_version_status::QUESTION_STATUS_READY,
                    'ready2'     => question_version_status::QUESTION_STATUS_READY,
                    'skilltag'   => skills::skill_tag($skill),
                    'difftag'    => skills::diff_tag($difficulty),
                ];
                $sql = "SELECT qv.questionbankentryid, qv.questionid, qv.version
                          FROM {question_versions} qv
                          JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                          JOIN {question} q ON q.id = qv.questionid
                         WHERE qbe.questioncategoryid = :categoryid
                           AND q.parent = 0
                           AND q.qtype $qtypesql
                           AND qv.status = :ready
                           AND qv.version = (SELECT MAX(v2.version)
                                               FROM {question_versions} v2
                                              WHERE v2.questionbankentryid = qv.questionbankentryid
                                                AND v2.status = :ready2)
                           AND EXISTS (SELECT 1
                                         FROM {tag_instance} ti1
                                         JOIN {tag} t1 ON t1.id = ti1.tagid
                                        WHERE ti1.itemtype = 'question' AND ti1.component = 'core_question'
                                          AND ti1.itemid = qv.questionid AND t1.name = :skilltag)
                           AND EXISTS (SELECT 1
                                         FROM {tag_instance} ti2
                                         JOIN {tag} t2 ON t2.id = ti2.tagid
                                        WHERE ti2.itemtype = 'question' AND ti2.component = 'core_question'
                                          AND ti2.itemid = qv.questionid AND t2.name = :difftag)
                      ORDER BY qv.questionbankentryid";
                $found = $DB->get_records_sql($sql, $params);
                foreach ($found as $version) {
                    $rows[] = (object) [
                        'attemptid'           => $attemptid,
                        'skill'               => $skill,
                        'difficulty'          => $difficulty,
                        'questionbankentryid' => (int) $version->questionbankentryid,
                        'questionid'          => (int) $version->questionid,
                        'questionversion'     => (int) $version->version,
                        'timeserved'          => null,
                        'invalid'             => 0,
                        'timecreated'         => $now,
                    ];
                    $entries[(int) $version->questionbankentryid] = true;
                    $cells[$skill][$difficulty]++;
                }
            }
        }
        if ($rows !== []) {
            $DB->insert_records('stackmastery_pool_snapshot', $rows);
        }
        return ['cells' => $cells, 'distinct' => count($entries)];
    }

    /**
     * Distinct unserved, valid question bank entries left in an attempt's snapshot.
     *
     * @param int $attemptid The attempt id.
     * @return int Count of distinct questionbankentryid values still drawable.
     */
    public static function distinct_entry_count(int $attemptid): int {
        global $DB;
        $sql = "SELECT COUNT(DISTINCT questionbankentryid)
                  FROM {stackmastery_pool_snapshot}
                 WHERE attemptid = :attemptid AND timeserved IS NULL AND invalid = 0";
        return (int) $DB->count_records_sql($sql, ['attemptid' => $attemptid]);
    }

    /**
     * Unseen counts per cell for the selector's eligibility map.
     *
     * @param int $attemptid The attempt id.
     * @return array<string, array<string, int>> Map skill => difficulty => unseen valid count.
     */
    public static function eligible_cells(int $attemptid): array {
        global $DB;
        $sql = "SELECT " . $DB->sql_concat('skill', "'|'", 'difficulty') . " AS cell,
                       skill, difficulty, COUNT(1) AS unseen
                  FROM {stackmastery_pool_snapshot}
                 WHERE attemptid = :attemptid AND timeserved IS NULL AND invalid = 0
              GROUP BY skill, difficulty";
        $cells = [];
        foreach ($DB->get_records_sql($sql, ['attemptid' => $attemptid]) as $row) {
            $cells[$row->skill][$row->difficulty] = (int) $row->unseen;
        }
        return $cells;
    }

    /**
     * Uniform random unseen snapshot row of a cell.
     *
     * @param int $attemptid The attempt id.
     * @param string $skill Skill code of the cell.
     * @param string $difficulty Difficulty code of the cell.
     * @return \stdClass|null A snapshot row, or null when the cell is exhausted.
     */
    public static function draw(int $attemptid, string $skill, string $difficulty): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('stackmastery_pool_snapshot', [
            'attemptid'  => $attemptid,
            'skill'      => $skill,
            'difficulty' => $difficulty,
            'timeserved' => null,
            'invalid'    => 0,
        ], 'questionbankentryid');
        if ($rows === []) {
            return null;
        }
        $rows = array_values($rows);
        return $rows[random_int(0, count($rows) - 1)];
    }

    /**
     * Mark every snapshot row sharing a question bank entry as served.
     *
     * A multi-tagged question burns once, everywhere (repeats are disallowed in v1).
     *
     * @param int $attemptid The attempt id.
     * @param int $questionbankentryid The served entry.
     * @param int $timenow Serve time.
     * @return void
     */
    public static function mark_served(int $attemptid, int $questionbankentryid, int $timenow): void {
        global $DB;
        $DB->set_field('stackmastery_pool_snapshot', 'timeserved', $timenow, [
            'attemptid'           => $attemptid,
            'questionbankentryid' => $questionbankentryid,
        ]);
    }

    /**
     * Mark one snapshot row invalid (its pinned version could not be started).
     *
     * @param int $rowid The snapshot row id.
     * @return void
     */
    public static function mark_invalid(int $rowid): void {
        global $DB;
        $DB->set_field('stackmastery_pool_snapshot', 'invalid', 1, ['id' => $rowid]);
    }

    /**
     * Delete an attempt's snapshot rows (called when the attempt reaches a terminal state).
     *
     * @param int $attemptid The attempt id.
     * @return void
     */
    public static function delete_snapshot(int $attemptid): void {
        global $DB;
        $DB->delete_records('stackmastery_pool_snapshot', ['attemptid' => $attemptid]);
    }
}
