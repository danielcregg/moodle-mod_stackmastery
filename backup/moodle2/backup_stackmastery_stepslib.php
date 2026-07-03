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
 * Backup structure step for the stackmastery activity module.
 *
 * @package    mod_stackmastery
 * @category   backup
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the complete stackmastery structure for backup, with question usages.
 *
 * The QUBA subtree hangs off each attempt keyed by the attempt's qubaid field
 * (the mod_quiz mechanism). Attempts with qubaid 0 (never provisioned, or
 * early-completed before any question was drawn) are legal: the question_usage
 * source query simply matches no row, so no usage subtree is emitted for them.
 *
 * The per-answer experience rows are backed up as masterysteps/masterystep
 * (NOT steps/step: element names must be unique across the whole structure and
 * the question usage subtree already owns steps/step).
 *
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_stackmastery_activity_structure_step extends backup_questions_activity_structure_step {
    /**
     * Defines the backup structure of mod_stackmastery.
     *
     * @return backup_nested_element the root element wrapped into the standard activity structure
     */
    protected function define_structure() {

        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element separately. Field lists are every column of
        // db/install.xml except id and the foreign key rewired on restore.
        $stackmastery = new backup_nested_element('stackmastery', ['id'], [
            'name', 'intro', 'introformat', 'skills', 'targetmastery', 'targetvector',
            'poolcategoryid', 'budget', 'maxattempts', 'grademode', 'showprogress',
            'epsilon', 'timeopen', 'timeclose', 'completionreachedtarget',
            'timecreated', 'timemodified']);

        // Custom topics are per-instance teacher configuration (not user data), so they
        // ride with the activity settings unconditionally, like the skills column.
        $topics = new backup_nested_element('topics');

        $topic = new backup_nested_element('topic', ['id'], [
            'sortorder', 'slug', 'label', 'templatetype', 'timecreated', 'timemodified']);

        $attempts = new backup_nested_element('attempts');

        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid', 'attemptnumber', 'qubaid', 'state', 'finishreason',
            'inprogressuniq', 'currentslot', 'pendingjson', 'preview',
            'masterycurrent', 'skillssnapshot', 'targetsnapshot', 'budget',
            'questionsdone', 'reachedtarget', 'stepstotarget', 'timetargetreached',
            'masteryfinal', 'policyversion', 'bktmodelversion', 'timeexported',
            'timestart', 'timefinish', 'timemodified']);

        // This module owns question usages, so produce the related question attempt
        // data attached to each attempt, matched by the qubaid field. This also
        // annotates every used question so backup_calculate_question_categories
        // pulls the right categories into the backup.
        $this->add_question_usages($attempt, 'qubaid');

        $masterysteps = new backup_nested_element('masterysteps');

        $masterystep = new backup_nested_element('masterystep', ['id'], [
            'seq', 'slot', 'questionid', 'questionbankentryid', 'questionversion',
            'variant', 'stackseed', 'recommendedskill', 'recommendeddifficulty',
            'servedskill', 'serveddifficulty', 'actionsource', 'propensity',
            'masterybefore', 'correct', 'fraction', 'masteryafter',
            'policyversion', 'bktmodelversion', 'stateencodingversion',
            'rewardversion', 'timeanswered']);

        $poolsnapshots = new backup_nested_element('poolsnapshots');

        $poolentry = new backup_nested_element('poolentry', ['id'], [
            'skill', 'difficulty', 'questionbankentryid', 'questionid',
            'questionversion', 'timeserved', 'invalid', 'timecreated']);

        // Build the tree.
        $stackmastery->add_child($topics);
        $topics->add_child($topic);

        $stackmastery->add_child($attempts);
        $attempts->add_child($attempt);

        $attempt->add_child($masterysteps);
        $masterysteps->add_child($masterystep);

        $attempt->add_child($poolsnapshots);
        $poolsnapshots->add_child($poolentry);

        // Define sources.
        $stackmastery->set_source_table('stackmastery', ['id' => backup::VAR_ACTIVITYID]);

        $topic->set_source_table(
            'stackmastery_topics',
            ['stackmasteryid' => backup::VAR_PARENTID],
            'sortorder ASC'
        );

        // All the attempt data (attempts, experience steps, pool snapshot) is user
        // data: only included with userinfo. Preview attempts are excluded, like
        // mod_quiz (always 0 in v1 anyway).
        if ($userinfo) {
            $attempt->set_source_sql(
                '
                    SELECT *
                      FROM {stackmastery_attempts}
                     WHERE stackmasteryid = :stackmasteryid AND preview = 0',
                ['stackmasteryid' => backup::VAR_PARENTID]
            );

            $masterystep->set_source_table(
                'stackmastery_steps',
                ['attemptid' => backup::VAR_PARENTID],
                'seq ASC'
            );

            $poolentry->set_source_table(
                'stackmastery_pool_snapshot',
                ['attemptid' => backup::VAR_PARENTID],
                'id ASC'
            );
        }

        // Define id annotations. Step and snapshot question references are
        // deliberately NOT annotated: they are historical/frozen pointers that may
        // legitimately dangle (question deleted later); only the questions actually
        // present in the usages force inclusion. Restore handles unmapped ones
        // (keep-with-0 / drop / slotless rules). poolcategoryid is intentionally not
        // annotated either: the pool category may live outside the backup scope and
        // restore falls back to 0 plus teacher re-selection.
        $attempt->annotate_ids('user', 'userid');

        // Define file annotations. The intro area has no itemid.
        $stackmastery->annotate_files('mod_stackmastery', 'intro', null);

        // Return the root element (stackmastery), wrapped into the standard activity structure.
        return $this->prepare_activity_structure($stackmastery);
    }
}
