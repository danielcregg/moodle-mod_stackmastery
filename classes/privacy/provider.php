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
 * Privacy Subsystem implementation for mod_stackmastery.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_stackmastery\local\attempt_store;

/**
 * Privacy provider for the STACK Mastery activity.
 *
 * The plugin stores attempts (with per-skill mastery estimates), a per-question experience log
 * (steps, linked to users only via their attempt) and a per-attempt pool snapshot; the student's
 * actual answers live in the core question subsystem, which is declared and exported/deleted via
 * core_question's helpers. There is no AI backend and no network call: question selection is a
 * local policy-file lookup. The optional experience export writes pseudonymised training files
 * into moodledata (no user ids; per-run random keys whose salt is discarded, so rows cannot be
 * re-linked once a run completes) - the export subsystem is therefore declared for transparency
 * only, and file rows are neither exported nor deleted per user. All deletions route through
 * {@see attempt_store::delete_attempts()} so the deletion path exists exactly once.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this activity module.
     *
     * @param collection $collection The metadata collection to add items to.
     * @return collection The populated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('stackmastery_attempts', [
            'userid'         => 'privacy:metadata:stackmastery_attempts:userid',
            'attemptnumber'  => 'privacy:metadata:stackmastery_attempts:attemptnumber',
            'state'          => 'privacy:metadata:stackmastery_attempts:state',
            'masterycurrent' => 'privacy:metadata:stackmastery_attempts:masterycurrent',
            'masteryfinal'   => 'privacy:metadata:stackmastery_attempts:masteryfinal',
            'skillssnapshot' => 'privacy:metadata:stackmastery_attempts:skillssnapshot',
            'targetsnapshot' => 'privacy:metadata:stackmastery_attempts:targetsnapshot',
            'timestart'      => 'privacy:metadata:stackmastery_attempts:timestart',
            'timefinish'     => 'privacy:metadata:stackmastery_attempts:timefinish',
        ], 'privacy:metadata:stackmastery_attempts');

        // Steps deliberately carry no userid; they are the user's data via their attempt.
        $collection->add_database_table('stackmastery_steps', [
            'seq'                   => 'privacy:metadata:stackmastery_steps:seq',
            'recommendedskill'      => 'privacy:metadata:stackmastery_steps:recommendedskill',
            'recommendeddifficulty' => 'privacy:metadata:stackmastery_steps:recommendeddifficulty',
            'servedskill'           => 'privacy:metadata:stackmastery_steps:servedskill',
            'serveddifficulty'      => 'privacy:metadata:stackmastery_steps:serveddifficulty',
            'actionsource'          => 'privacy:metadata:stackmastery_steps:actionsource',
            'correct'               => 'privacy:metadata:stackmastery_steps:correct',
            'fraction'              => 'privacy:metadata:stackmastery_steps:fraction',
            'masterybefore'         => 'privacy:metadata:stackmastery_steps:masterybefore',
            'masteryafter'          => 'privacy:metadata:stackmastery_steps:masteryafter',
            'timeanswered'          => 'privacy:metadata:stackmastery_steps:timeanswered',
        ], 'privacy:metadata:stackmastery_steps');

        // Holds no personal content (a frozen list of course questions) but is keyed to a user's
        // attempt, so it is declared for transparency and deleted with the attempt.
        $collection->add_database_table('stackmastery_pool_snapshot', [
            'skill'               => 'privacy:metadata:stackmastery_pool_snapshot:skill',
            'difficulty'          => 'privacy:metadata:stackmastery_pool_snapshot:difficulty',
            'questionbankentryid' => 'privacy:metadata:stackmastery_pool_snapshot:questionbankentryid',
            'questionid'          => 'privacy:metadata:stackmastery_pool_snapshot:questionid',
            'questionversion'     => 'privacy:metadata:stackmastery_pool_snapshot:questionversion',
            'timeserved'          => 'privacy:metadata:stackmastery_pool_snapshot:timeserved',
            'timecreated'         => 'privacy:metadata:stackmastery_pool_snapshot:timecreated',
        ], 'privacy:metadata:stackmastery_pool_snapshot');

        // The question usage (the student's actual answers) lives in core question tables.
        $collection->add_subsystem_link('core_question', [], 'privacy:metadata:core_question');

        // Transparency-only: the optional experience export writes pseudonymised files into
        // moodledata. Post-run they cannot be re-linked to a user (the per-run salt is
        // discarded), so they are not exported or deleted per user. Core requires at least
        // one described field on an external location.
        $collection->add_external_location_link('exportfiles', [
            'seqkey' => 'privacy:metadata:exportfiles:seqkey',
        ], 'privacy:metadata:exportfiles');

        return $collection;
    }

    /**
     * Find the contexts holding personal data for a user.
     *
     * Covers both attempt owners and users whose only trace is inside the question usage of
     * somebody's attempt (for example a future manual comment), via core_question's helper.
     *
     * @param int $userid The user to search for.
     * @return contextlist The contexts containing the user's data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Contexts where the user owns an attempt.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {stackmastery} s ON s.id = cm.instance
                  JOIN {stackmastery_attempts} a ON a.stackmasteryid = s.id
                 WHERE a.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname'      => 'stackmastery',
            'userid'       => $userid,
        ]);

        // Contexts where the user acted inside a question usage owned by any attempt here.
        $qubaid = \core_question\privacy\provider::get_related_question_usages_for_user(
            'rel',
            'mod_stackmastery',
            'a.qubaid',
            $userid
        );
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {stackmastery} s ON s.id = cm.instance
                  JOIN {stackmastery_attempts} a ON a.stackmasteryid = s.id
                  " . $qubaid->from . "
                 WHERE " . $qubaid->where();
        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname'      => 'stackmastery',
        ] + $qubaid->from_where_params();
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Find the users holding personal data inside one context.
     *
     * @param userlist $userlist The userlist to populate for its context.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $params = [
            'cmid'    => $context->instanceid,
            'modname' => 'stackmastery',
        ];

        // Attempt owners.
        $sql = "SELECT a.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {stackmastery} s ON s.id = cm.instance
                  JOIN {stackmastery_attempts} a ON a.stackmasteryid = s.id
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, $params);

        // Anyone with question_attempt_steps inside our usages (manual markers etc).
        $sql = "SELECT a.qubaid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {stackmastery} s ON s.id = cm.instance
                  JOIN {stackmastery_attempts} a ON a.stackmasteryid = s.id
                 WHERE cm.id = :cmid";
        \core_question\privacy\provider::get_users_in_context_from_sql($userlist, 'qn', $sql, $params);
    }

    /**
     * Export the user's data in the approved contexts.
     *
     * For attempts the user owns, the attempt record, its steps, any pool snapshot rows and the
     * full question usage are exported. For usages the user merely acted in, core_question
     * exports only that involvement.
     *
     * @param approved_contextlist $contextlist The approved contexts to export.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $cm = self::cm_from_context($context);
            if (!$cm) {
                continue;
            }

            // Attempts the user owns.
            $attempts = $DB->get_records(
                'stackmastery_attempts',
                ['stackmasteryid' => $cm->instance, 'userid' => $userid],
                'attemptnumber'
            );
            foreach ($attempts as $attempt) {
                $subcontext = self::attempt_subcontext($attempt);
                writer::with_context($context)->export_data($subcontext, self::attempt_export_data($attempt));
                self::export_usage($userid, $context, $subcontext, $attempt, true);
            }

            // Usages of other users' attempts the user acted in (only their involvement).
            $qubaid = \core_question\privacy\provider::get_related_question_usages_for_user(
                'rel',
                'mod_stackmastery',
                'a.qubaid',
                $userid
            );
            $sql = "SELECT DISTINCT a.id
                      FROM {stackmastery_attempts} a
                      " . $qubaid->from . "
                     WHERE a.stackmasteryid = :instanceid
                       AND a.userid <> :userid
                       AND " . $qubaid->where();
            $params = [
                'instanceid' => $cm->instance,
                'userid'     => $userid,
            ] + $qubaid->from_where_params();
            foreach ($DB->get_fieldset_sql($sql, $params) as $attemptid) {
                $attempt = $DB->get_record('stackmastery_attempts', ['id' => $attemptid]);
                if ($attempt) {
                    self::export_usage($userid, $context, self::attempt_subcontext($attempt), $attempt, false);
                }
            }
        }
    }

    /**
     * Delete all user data in one context.
     *
     * @param \context $context The context to purge.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        $cm = self::cm_from_context($context);
        if (!$cm) {
            return;
        }
        $userids = attempt_store::delete_attempts((int) $cm->instance, null);
        self::reset_grades((int) $cm->instance, $userids);
    }

    /**
     * Delete one user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts for the user.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $cm = self::cm_from_context($context);
            if (!$cm) {
                continue;
            }
            $userids = attempt_store::delete_attempts((int) $cm->instance, [$userid]);
            self::reset_grades((int) $cm->instance, $userids);
        }
    }

    /**
     * Delete the data of several users in one context.
     *
     * @param approved_userlist $userlist The approved users and their context.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $cm = self::cm_from_context($userlist->get_context());
        if (!$cm) {
            return;
        }
        $targets = array_map('intval', $userlist->get_userids());
        if ($targets === []) {
            return;
        }
        $userids = attempt_store::delete_attempts((int) $cm->instance, $targets);
        self::reset_grades((int) $cm->instance, $userids);
    }

    /**
     * The export subcontext of one attempt.
     *
     * @param \stdClass $attempt The attempt record.
     * @return string[] The subcontext path.
     */
    private static function attempt_subcontext(\stdClass $attempt): array {
        return [get_string('attempts', 'mod_stackmastery'), (string) $attempt->attemptnumber];
    }

    /**
     * Delegate the export of an attempt's question usage to core_question.
     *
     * @param int $userid The user being exported.
     * @param \context_module $context The module context.
     * @param string[] $subcontext The attempt subcontext.
     * @param \stdClass $attempt The attempt record.
     * @param bool $isowner Whether the exported user owns the attempt.
     * @return void
     */
    private static function export_usage(
        int $userid,
        \context_module $context,
        array $subcontext,
        \stdClass $attempt,
        bool $isowner
    ): void {
        global $CFG;

        if ((int) $attempt->qubaid <= 0) {
            // Two-phase start: an attempt row can exist before its usage is provisioned.
            return;
        }
        require_once($CFG->dirroot . '/question/engine/lib.php');
        // Formative activity: the defaults show marks and feedback, matching what the student
        // saw in-attempt. (No ->context: the class has no such property and core never reads
        // one on the export path; assigning it would be a dynamic property on PHP 8.2+.)
        $options = new \question_display_options();
        \core_question\privacy\provider::export_question_usage(
            $userid,
            $context,
            $subcontext,
            (int) $attempt->qubaid,
            $options,
            $isowner
        );
    }

    /**
     * Build the exportable representation of one attempt, its steps and its pool snapshot.
     *
     * The pool snapshot is included when rows exist (only open attempts have them; the rows are
     * deleted when the attempt reaches a terminal state).
     *
     * @param \stdClass $attempt The attempt record.
     * @return \stdClass The export data object.
     */
    private static function attempt_export_data(\stdClass $attempt): \stdClass {
        global $DB;

        $data = (object) [
            'attemptnumber'   => (int) $attempt->attemptnumber,
            'state'           => $attempt->state,
            'finishreason'    => $attempt->finishreason,
            'skills'          => $attempt->skillssnapshot,
            'target'          => self::decode_json($attempt->targetsnapshot),
            'currentmastery'  => self::decode_json($attempt->masterycurrent),
            'finalmastery'    => self::decode_json($attempt->masteryfinal),
            'budget'          => (int) $attempt->budget,
            'questionsdone'   => (int) $attempt->questionsdone,
            'reachedtarget'   => transform::yesno($attempt->reachedtarget),
            'policyversion'   => $attempt->policyversion,
            'bktmodelversion' => $attempt->bktmodelversion,
            'timestart'       => transform::datetime($attempt->timestart),
            'timefinish'      => $attempt->timefinish > 0 ? transform::datetime($attempt->timefinish) : null,
            'steps'           => [],
            'poolsnapshot'    => [],
        ];

        foreach (attempt_store::get_steps((int) $attempt->id) as $step) {
            $data->steps[] = (object) [
                'seq'                   => (int) $step->seq,
                'slot'                  => (int) $step->slot,
                'recommendedskill'      => $step->recommendedskill,
                'recommendeddifficulty' => $step->recommendeddifficulty,
                'servedskill'           => $step->servedskill,
                'serveddifficulty'      => $step->serveddifficulty,
                'actionsource'          => $step->actionsource,
                'propensity'            => (float) $step->propensity,
                'questionbankentryid'   => (int) $step->questionbankentryid,
                'questionversion'       => (int) $step->questionversion,
                'variant'               => (int) $step->variant,
                'stackseed'             => $step->stackseed === null ? null : (int) $step->stackseed,
                'correct'               => transform::yesno($step->correct),
                'fraction'              => $step->fraction === null ? null : (float) $step->fraction,
                'masterybefore'         => self::decode_json($step->masterybefore),
                'masteryafter'          => self::decode_json($step->masteryafter),
                'timeanswered'          => transform::datetime($step->timeanswered),
            ];
        }

        $snapshot = $DB->get_records(
            'stackmastery_pool_snapshot',
            ['attemptid' => $attempt->id],
            'skill, difficulty, questionbankentryid'
        );
        foreach ($snapshot as $row) {
            $data->poolsnapshot[] = (object) [
                'skill'               => $row->skill,
                'difficulty'          => $row->difficulty,
                'questionbankentryid' => (int) $row->questionbankentryid,
                'questionid'          => (int) $row->questionid,
                'questionversion'     => (int) $row->questionversion,
                'timeserved'          => $row->timeserved === null ? null : transform::datetime($row->timeserved),
                'timecreated'         => transform::datetime($row->timecreated),
            ];
        }

        return $data;
    }

    /**
     * Tolerant JSON decode: a corrupt column can never fatal a subject access request.
     *
     * @param string|null $raw The raw column value.
     * @return mixed The decoded value, the raw string when undecodable, or null.
     */
    private static function decode_json(?string $raw) {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw);
        return $decoded === null ? $raw : $decoded;
    }

    /**
     * Resolve a context to this module's course module record, or null.
     *
     * @param \context $context Any context.
     * @return \stdClass|null The cm record when the context is one of our modules.
     */
    private static function cm_from_context(\context $context): ?\stdClass {
        if (!$context instanceof \context_module) {
            return null;
        }
        $cm = get_coursemodule_from_id('stackmastery', $context->instanceid);
        return $cm ?: null;
    }

    /**
     * Push (null) grades for users whose attempts were deleted.
     *
     * @param int $instanceid The instance id.
     * @param int[] $userids The affected users, as returned by attempt_store::delete_attempts().
     * @return void
     */
    private static function reset_grades(int $instanceid, array $userids): void {
        global $CFG, $DB;

        if ($userids === []) {
            return;
        }
        $instance = $DB->get_record('stackmastery', ['id' => $instanceid]);
        if (!$instance) {
            return;
        }
        require_once($CFG->dirroot . '/mod/stackmastery/lib.php');
        foreach ($userids as $userid) {
            stackmastery_update_grades($instance, (int) $userid);
        }
    }
}
