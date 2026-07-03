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
 * Restore structure step for the stackmastery activity module.
 *
 * @package    mod_stackmastery
 * @category   backup
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step to restore one stackmastery activity, with question usages.
 *
 * Deviation from the mod_quiz pattern, on purpose: quiz stashes the attempt row
 * and only inserts it from inform_new_usage_id(), because quiz_attempts.uniqueid
 * is NOT NULL unique. A stackmastery attempt may legally have qubaid = 0 forever
 * (never provisioned / early-completed), in which case the backup holds NO
 * question_usage element and inform_new_usage_id() is never called - so the
 * attempt row is inserted immediately in process_stackmastery_attempt() with
 * qubaid 0 (a legal value: plain FK, non-unique index) and inform_new_usage_id()
 * only rewires qubaid when a usage actually arrives. This also guarantees the
 * attempt row and its mapping exist before the masterystep/poolentry children.
 *
 * Unmappable-id rules (master plan section 4, WP-7):
 * - instance poolcategoryid unmapped: keep 0; the teacher re-selects (never guess).
 * - step questionid/questionbankentryid unmapped: KEEP the row with 0 (the
 *   experience tuple retains full training value; only the pointer degrades).
 * - pool snapshot entry unmapped: DROP the row (a dangling entry would let the
 *   draw path start a nonexistent question; budget capping tolerates shrinkage).
 * - pendingjson questionid/qbeid unmapped: restore the attempt as SLOTLESS
 *   (currentslot 0, pendingjson NULL) - the legitimate recovery state; the next
 *   view re-provisions.
 *
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_stackmastery_activity_structure_step extends restore_questions_activity_structure_step {
    /** @var int|null the just-inserted attempt id, or null when the attempt was skipped. */
    protected $currentattemptid = null;

    /**
     * Defines the structure to be processed by this restore step.
     *
     * @return restore_path_element[] the paths wrapped into the standard activity structure
     */
    protected function define_structure() {

        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('stackmastery', '/activity/stackmastery');

        if ($userinfo) {
            $attempt = new restore_path_element(
                'stackmastery_attempt',
                '/activity/stackmastery/attempts/attempt'
            );
            $paths[] = $attempt;

            // Add the question usage paths (usage, question attempts, steps, data).
            $this->add_question_usages($attempt, $paths);

            $paths[] = new restore_path_element(
                'stackmastery_masterystep',
                '/activity/stackmastery/attempts/attempt/masterysteps/masterystep'
            );
            $paths[] = new restore_path_element(
                'stackmastery_poolentry',
                '/activity/stackmastery/attempts/attempt/poolsnapshots/poolentry'
            );
        }

        // Return the paths wrapped into the standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Processes the stackmastery instance element.
     *
     * @param array|stdClass $data the data from the XML file
     * @return void
     */
    protected function process_stackmastery($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();

        // Configuration dates roll with the course; user data timestamps never do (MDL-9367).
        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timeclose = $this->apply_date_offset($data->timeclose);

        // The pool category may live outside the backup scope (course category or
        // system context, or a no-userinfo backup that included no questions at
        // all). If it cannot be mapped, keep 0: view.php's teacher banner and the
        // mod_form revalidation force an explicit re-selection. Never guess.
        $oldcategoryid = (int) $data->poolcategoryid;
        if ($oldcategoryid) {
            $data->poolcategoryid = (int) $this->get_mappingid('question_category', $oldcategoryid, 0);
            if (!$data->poolcategoryid) {
                $this->log('stackmastery question pool category ' . $oldcategoryid .
                        ' is not included in the backup; the pool category was reset and ' .
                        'must be re-selected in the activity settings.', backup::LOG_WARNING);
            }
        }

        // Insert the stackmastery record and immediately map the activity instance.
        $newitemid = $DB->insert_record('stackmastery', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Processes an attempt element.
     *
     * The row is inserted here (not in inform_new_usage_id) with qubaid 0, so
     * SLOTLESS qubaid-0 attempts - whose backup carries no question_usage element
     * at all - restore correctly, and the masterystep/poolentry children always
     * find their parent. inform_new_usage_id() rewires qubaid afterwards when a
     * usage element does follow.
     *
     * @param array|stdClass $data the data from the XML file
     * @return void
     */
    protected function process_stackmastery_attempt($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->stackmasteryid = $this->get_new_parentid('stackmastery');

        // Get the user mapping; skip the whole attempt if the user is not restorable.
        $olduserid = $data->userid;
        $data->userid = $this->get_mappingid('user', $olduserid, 0);
        if (!$data->userid) {
            $this->log('Mapped user ID not found for user ' . $olduserid . ', stackmastery ' .
                    $data->stackmasteryid . ', attempt ' . $data->attemptnumber .
                    '. Skipping the attempt.', backup::LOG_INFO);
            // Null parent: the usage (if any) restores unlinked and the
            // masterystep/poolentry children of this attempt are skipped.
            $this->currentattemptid = null;
            return;
        }

        // The usage is restored by the base class AFTER this element; a qubaid-0
        // attempt never gets that call. Start at 0 in both cases (legal state).
        $data->qubaid = 0;

        // Remap the frozen open-slot context, or fall back to SLOTLESS.
        // Invariant: pendingjson is non-null iff currentslot > 0.
        if ((int) $data->currentslot > 0) {
            $pending = json_decode((string) $data->pendingjson, true);
            $newquestionid = is_array($pending) && !empty($pending['questionid'])
                    ? $this->get_mappingid('question', $pending['questionid'], 0) : 0;
            $newqbeid = is_array($pending) && !empty($pending['qbeid'])
                    ? $this->get_mappingid('question_bank_entry', $pending['qbeid'], 0) : 0;
            if ($newquestionid && $newqbeid) {
                $pending['questionid'] = (int) $newquestionid;
                $pending['qbeid'] = (int) $newqbeid;
                $data->pendingjson = json_encode($pending);
            } else {
                // The open slot's question cannot be mapped: restore as SLOTLESS
                // in-progress (the recovery state); the next view re-provisions.
                $data->currentslot = 0;
                $data->pendingjson = null;
                $this->log(
                    'stackmastery attempt ' . $oldid . ': the open-slot pending context ' .
                        'could not be remapped; the attempt was restored as slotless.',
                    backup::LOG_WARNING
                );
            }
        } else {
            $data->pendingjson = null;
        }

        // Preserve the inprogressuniq semantics: 0 while open, = attempt id at
        // close. The new id is unknown before the insert, so closed attempts are
        // inserted with a per-row placeholder (-oldid: never 0, never a real id,
        // unique per backup - safe under the (stackmasteryid,userid,inprogressuniq)
        // unique index) and rewritten to the new id straight after.
        $closed = (int) $data->inprogressuniq !== 0;
        if ($closed) {
            $data->inprogressuniq = -((int) $oldid);
        }

        $newitemid = $DB->insert_record('stackmastery_attempts', $data);
        if ($closed) {
            $DB->set_field('stackmastery_attempts', 'inprogressuniq', $newitemid, ['id' => $newitemid]);
        }

        $this->set_mapping('stackmastery_attempt', $oldid, $newitemid);
        $this->currentattemptid = (int) $newitemid;
    }

    /**
     * Links the restored question usage to the current attempt.
     *
     * Only called when the backup actually contained a question_usage element for
     * the attempt; qubaid-0 attempts keep the 0 written at insert time.
     *
     * @param int $newusageid the id of the newly created question usage
     * @return void
     */
    protected function inform_new_usage_id($newusageid) {
        global $DB;

        if (empty($this->currentattemptid)) {
            // The attempt was skipped (unmappable user): the usage restores
            // unlinked, exactly like mod_quiz's skipped attempts.
            return;
        }
        $DB->set_field(
            'stackmastery_attempts',
            'qubaid',
            $newusageid,
            ['id' => $this->currentattemptid]
        );
    }

    /**
     * Processes an experience step (masterystep) element.
     *
     * Unmapped question pointers are kept as 0: the experience tuple
     * (state/action/outcome/provenance) retains full training value.
     *
     * @param array|stdClass $data the data from the XML file
     * @return void
     */
    protected function process_stackmastery_masterystep($data) {
        global $DB;

        $data = (object) $data;

        if (empty($this->currentattemptid)) {
            // The owning attempt was skipped.
            return;
        }
        $data->attemptid = $this->currentattemptid;

        $oldquestionid = (int) $data->questionid;
        $oldqbeid = (int) $data->questionbankentryid;
        $data->questionid = $oldquestionid
                ? (int) $this->get_mappingid('question', $oldquestionid, 0) : 0;
        $data->questionbankentryid = $oldqbeid
                ? (int) $this->get_mappingid('question_bank_entry', $oldqbeid, 0) : 0;
        if (($oldquestionid && !$data->questionid) || ($oldqbeid && !$data->questionbankentryid)) {
            $this->log('stackmastery step ' . $data->seq . ' of attempt ' . $data->attemptid .
                    ': the question is not included in the backup; the step was kept with its ' .
                    'question pointer cleared.', backup::LOG_WARNING);
        }

        $DB->insert_record('stackmastery_steps', $data);
    }

    /**
     * Processes a pool snapshot (poolentry) element.
     *
     * A row whose question bank entry or pinned version cannot be mapped is
     * DROPPED: a dangling snapshot entry would let the draw path start a
     * nonexistent question, and draw-time budget capping tolerates a shrunken
     * snapshot.
     *
     * @param array|stdClass $data the data from the XML file
     * @return void
     */
    protected function process_stackmastery_poolentry($data) {
        global $DB;

        $data = (object) $data;

        if (empty($this->currentattemptid)) {
            // The owning attempt was skipped.
            return;
        }
        $data->attemptid = $this->currentattemptid;

        $newqbeid = (int) $this->get_mappingid('question_bank_entry', $data->questionbankentryid, 0);
        $newquestionid = (int) $this->get_mappingid('question', $data->questionid, 0);
        if (!$newqbeid || !$newquestionid) {
            $this->log('stackmastery pool snapshot entry for attempt ' . $data->attemptid .
                    ' (' . $data->skill . '/' . $data->difficulty . '): the question is not ' .
                    'included in the backup; the entry was dropped.', backup::LOG_WARNING);
            return;
        }
        $data->questionbankentryid = $newqbeid;
        $data->questionid = $newquestionid;

        $DB->insert_record('stackmastery_pool_snapshot', $data);
    }

    /**
     * Extra actions once the structure has been processed: restore the area files.
     *
     * @return void
     */
    protected function after_execute() {
        global $DB;
        parent::after_execute();
        // Add stackmastery related files, no itemid to match.
        $this->add_related_files('mod_stackmastery', 'intro', null);

        // Normalise any in-progress attempt that restored with an open slot but no question
        // usage (a partial or hand-edited backup can carry currentslot/pendingjson without a
        // restorable QUBA). Such a row is NOT slotless, so the engine's recovery path would
        // never run and submits would throw; slotless is the designed safe state - the next
        // view legitimately re-provisions (codex #09).
        $instanceid = $this->task->get_activityid();
        $wedged = $DB->get_records_select(
            'stackmastery_attempts',
            "stackmasteryid = :sid AND state = 'inprogress' AND currentslot > 0 AND qubaid = 0",
            ['sid' => $instanceid],
            '',
            'id'
        );
        if ($wedged !== []) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($wedged));
            $DB->set_field_select('stackmastery_attempts', 'currentslot', 0, "id $insql", $params);
            $DB->set_field_select('stackmastery_attempts', 'pendingjson', null, "id $insql", $params);
            $this->log(
                'stackmastery attempt(s) restored without a question usage were reset to the '
                    . 'slotless state and will re-provision on next view: ids '
                    . implode(', ', array_keys($wedged)),
                \backup::LOG_WARNING
            );
        }
    }
}
