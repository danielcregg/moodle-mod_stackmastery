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
 * Backup and restore round-trip tests for mod_stackmastery.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery;

use mod_stackmastery\local\bkt;

/**
 * Duplicate-module (no user data) and full userinfo backup/restore round trips.
 *
 * @covers \backup_stackmastery_activity_task
 * @covers \backup_stackmastery_activity_structure_step
 * @covers \restore_stackmastery_activity_task
 * @covers \restore_stackmastery_activity_structure_step
 */
final class backup_restore_test extends \advanced_testcase {
    /** @var string[] attempt columns that must round-trip unchanged (identity and remapped ones excluded). */
    private const ATTEMPT_PLAIN_FIELDS = [
        'attemptnumber', 'state', 'finishreason', 'preview', 'skillssnapshot',
        'budget', 'questionsdone', 'reachedtarget', 'stepstotarget', 'timetargetreached',
        'policyversion', 'bktmodelversion', 'timeexported', 'timestart', 'timefinish',
        'timemodified',
    ];

    /** @var string[] step columns that must round-trip unchanged (the two question pointers excluded). */
    private const STEP_PLAIN_FIELDS = [
        'seq', 'slot', 'questionversion', 'variant', 'recommendedskill',
        'recommendeddifficulty', 'servedskill', 'serveddifficulty', 'actionsource',
        'propensity', 'correct', 'fraction', 'policyversion', 'bktmodelversion',
        'stateencodingversion', 'rewardversion', 'timeanswered',
    ];

    /**
     * Common setup.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
    }

    /**
     * Duplicating the module without user data keeps every instance setting.
     *
     * The pool category is NOT part of an import backup (no questions are
     * annotated without userinfo), so the master-plan rule applies: it restores
     * as 0 (or, if core ever includes it, as a remap) and the teacher re-selects.
     *
     * @return void
     */
    public function test_duplicate_module_round_trip(): void {
        global $DB;

        $this->setAdminUser();
        set_config('allowedqtypes', 'shortanswer', 'mod_stackmastery');
        set_config('epsilon', 0.07, 'mod_stackmastery');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        /** @var \mod_stackmastery_generator $smgenerator */
        $smgenerator = $generator->get_plugin_generator('mod_stackmastery');
        $pool = $smgenerator->create_pool([
            'course'  => $course->id,
            'skills'  => ['differentiate', 'integrate'],
            'percell' => 1,
        ]);
        $instance = $generator->create_module('stackmastery', [
            'course'                  => $course->id,
            'poolcategoryid'          => $pool->category->id,
            'skills'                  => 'differentiate,integrate',
            'targetmastery'           => 0.9,
            'budget'                  => 17,
            'maxattempts'             => 3,
            'grademode'               => 1,
            'showprogress'            => 0,
            'timeopen'                => 1750000000,
            'timeclose'               => 1760000000,
            'completionreachedtarget' => 1,
            'intro'                   => '<p>Practise until mastered.</p>',
            'introformat'             => FORMAT_HTML,
        ]);
        $original = $DB->get_record('stackmastery', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertEquals(0.07, (float) $original->epsilon, 'the admin epsilon was snapshot at creation');

        // Move the admin epsilon: the copy must keep the original snapshot, proving
        // the restore path inserts the backed-up row instead of re-running add_instance.
        set_config('epsilon', 0.19, 'mod_stackmastery');

        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        $newcm = duplicate_module($course, $cm);

        $this->assertNotNull($newcm);
        $this->assertSame('stackmastery', $newcm->modname);
        $this->assertNotEquals((int) $instance->id, (int) $newcm->instance);
        $copy = $DB->get_record('stackmastery', ['id' => $newcm->instance], '*', MUST_EXIST);

        $this->assertEquals($course->id, $copy->course);
        $plainfields = [
            'skills', 'targetvector', 'budget', 'maxattempts', 'grademode', 'showprogress',
            'timeopen', 'timeclose', 'completionreachedtarget', 'intro', 'introformat',
            'timecreated', 'timemodified',
        ];
        foreach ($plainfields as $field) {
            $this->assertEquals($original->{$field}, $copy->{$field}, "instance field {$field} survives duplication");
        }
        $this->assertEquals((float) $original->targetmastery, (float) $copy->targetmastery);
        $this->assertEquals(0.07, (float) $copy->epsilon, 'the copy keeps the creation-time epsilon snapshot');

        // The pool category either remaps (same site) or resets to 0 for teacher
        // re-selection; it must never point anywhere else.
        $this->assertContains((int) $copy->poolcategoryid, [0, (int) $original->poolcategoryid]);

        // No user data was duplicated, and exactly one copy was made.
        $this->assertSame(0, $DB->count_records('stackmastery_attempts', ['stackmasteryid' => $copy->id]));
        $this->assertSame(2, $DB->count_records('stackmastery', []));
    }

    /**
     * Full backup/restore WITH user data round-trips attempts, steps, usages,
     * snapshots and the frozen open-slot context, applying the unmapped-id rules.
     *
     * Seeded shapes:
     * - a finished attempt (student, closed, inprogressuniq = id) with a real
     *   2-slot answered QUBA and 3 steps, the third referencing a question that is
     *   NOT in the backup (kept with pointers cleared);
     * - a SLOTLESS in-progress attempt (student, qubaid 0) with 4 snapshot rows,
     *   one referencing the outside question (dropped on restore);
     * - an open-slot in-progress attempt (student2) whose pendingjson remaps;
     * - an open-slot in-progress attempt (student3) whose pendingjson references
     *   the outside question (restored as SLOTLESS: currentslot 0, pendingjson null).
     *
     * @return void
     */
    public function test_userinfo_backup_restore_round_trip(): void {
        global $DB;

        $this->setAdminUser();
        $site = $this->build_site();
        $t = 1750000000;

        $q1 = $site->pool->questions['differentiate']['easy'][0];
        $q2 = $site->pool->questions['integrate']['easy'][0];
        $q3 = $site->pool->questions['differentiate']['medium'][0];

        // Attempt 1 (student): finished, target reached, real answered QUBA.
        $quba1 = $this->make_usage($site->context, [$q1->id, $q2->id], true);
        $attempt1 = $this->insert_attempt([
            'stackmasteryid'    => $site->instance->id,
            'userid'            => $site->student->id,
            'attemptnumber'     => 1,
            'qubaid'            => $quba1->get_id(),
            'state'             => 'complete',
            'finishreason'      => 'target',
            'masterycurrent'    => $this->mastery_json(0.97),
            'masteryfinal'      => $this->mastery_json(0.97),
            'questionsdone'     => 3,
            'reachedtarget'     => 1,
            'stepstotarget'     => 3,
            'timetargetreached' => $t + 300,
            'timestart'         => $t,
            'timefinish'        => $t + 300,
            'timemodified'      => $t + 300,
        ], true);

        $this->insert_step($attempt1->id, 1, $q1, ['timeanswered' => $t + 100]);
        $this->insert_step($attempt1->id, 2, $q2, [
            'servedskill'   => 'integrate',
            'correct'       => 0,
            'fraction'      => 0.8,
            'timeanswered'  => $t + 200,
        ]);
        // Step 3 references a question that will NOT be part of the backup.
        $this->insert_step($attempt1->id, 3, $site->outsidequestion, ['timeanswered' => $t + 300]);

        // Attempt 2 (student): in-progress and SLOTLESS with qubaid 0 forever.
        $attempt2 = $this->insert_attempt([
            'stackmasteryid' => $site->instance->id,
            'userid'         => $site->student->id,
            'attemptnumber'  => 2,
            'timestart'      => $t + 400,
            'timemodified'   => $t + 400,
        ]);
        $this->insert_snapshot($attempt2->id, 'differentiate', 'easy', $q1, $t + 400);
        $this->insert_snapshot($attempt2->id, 'integrate', 'easy', $q2, $t + 400);
        $this->insert_snapshot($attempt2->id, 'differentiate', 'medium', $q3, $t + 400);
        // A snapshot row for the outside question: must be DROPPED on restore.
        $this->insert_snapshot($attempt2->id, 'integrate', 'medium', $site->outsidequestion, $t + 400);

        // Attempt 3 (student2): in progress with an open slot; pendingjson remaps.
        $quba3 = $this->make_usage($site->context, [$q3->id], false);
        $pending3 = $this->pending_context($q3, $t + 500);
        $attempt3 = $this->insert_attempt([
            'stackmasteryid' => $site->instance->id,
            'userid'         => $site->student2->id,
            'qubaid'         => $quba3->get_id(),
            'currentslot'    => 1,
            'pendingjson'    => json_encode($pending3),
            'timestart'      => $t + 500,
            'timemodified'   => $t + 500,
        ]);
        $this->insert_snapshot($attempt3->id, 'differentiate', 'easy', $q1, $t + 500);
        $this->insert_snapshot($attempt3->id, 'integrate', 'easy', $q2, $t + 500);
        $served = $this->insert_snapshot($attempt3->id, 'differentiate', 'medium', $q3, $t + 500);
        $DB->set_field('stackmastery_pool_snapshot', 'timeserved', $t + 500, ['id' => $served->id]);

        // Attempt 4 (student3): open slot whose pendingjson references the outside
        // question - unmappable, so it must restore as SLOTLESS.
        $quba4 = $this->make_usage($site->context, [$q1->id], false);
        $attempt4 = $this->insert_attempt([
            'stackmasteryid' => $site->instance->id,
            'userid'         => $site->student3->id,
            'qubaid'         => $quba4->get_id(),
            'currentslot'    => 1,
            'pendingjson'    => json_encode($this->pending_context($site->outsidequestion, $t + 600)),
            'timestart'      => $t + 600,
            'timemodified'   => $t + 600,
        ]);

        $originals = [
            1 => [$site->student->id, $attempt1],
            2 => [$site->student->id, $attempt2],
            3 => [$site->student2->id, $attempt3],
            4 => [$site->student3->id, $attempt4],
        ];

        // Round trip into a fresh course.
        $newcourse = $this->getDataGenerator()->create_course();
        $backupid = $this->backup_activity_with_users($site->cm->id);
        $this->restore_into($backupid, $newcourse->id);

        $newinstance = $DB->get_record('stackmastery', ['course' => $newcourse->id], '*', MUST_EXIST);

        // Instance settings survive; the pool category remapped to an existing,
        // different category (it travelled with the annotated questions).
        $this->assertSame((string) $site->instancerecord->skills, (string) $newinstance->skills);
        $this->assertEquals((float) $site->instancerecord->targetmastery, (float) $newinstance->targetmastery);
        $this->assertEquals($site->instancerecord->budget, $newinstance->budget);
        $this->assertNotEquals(0, (int) $newinstance->poolcategoryid);
        $this->assertNotEquals((int) $site->pool->category->id, (int) $newinstance->poolcategoryid);
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $newinstance->poolcategoryid]));

        // All four attempts restored for the mapped (same-site) users.
        $newattempts = $DB->get_records(
            'stackmastery_attempts',
            ['stackmasteryid' => $newinstance->id],
            'userid, attemptnumber'
        );
        $this->assertCount(4, $newattempts);
        $bynumber = [];
        foreach ($newattempts as $newattempt) {
            foreach ($originals as $key => [$userid, $old]) {
                if (
                    (int) $newattempt->userid === (int) $userid
                        && (int) $newattempt->attemptnumber === (int) $old->attemptnumber
                ) {
                    $bynumber[$key] = $newattempt;
                }
            }
        }
        $this->assertCount(4, $bynumber, 'every attempt matched by (user, attemptnumber)');

        // No inprogressuniq placeholder may survive the restore.
        $this->assertSame(0, $DB->count_records_select('stackmastery_attempts', 'inprogressuniq < 0'));

        // Attempt 1: finished attempt round-trips with its QUBA.
        $new1 = $bynumber[1];
        $this->assert_plain_fields($attempt1, $new1, self::ATTEMPT_PLAIN_FIELDS, 'attempt1');
        $this->assertSame((string) $attempt1->masterycurrent, (string) $new1->masterycurrent);
        $this->assertSame((string) $attempt1->masteryfinal, (string) $new1->masteryfinal);
        $this->assertSame((string) $attempt1->targetsnapshot, (string) $new1->targetsnapshot);
        $this->assertNull($new1->pendingjson);
        $this->assertSame(0, (int) $new1->currentslot);
        $this->assertSame((int) $new1->id, (int) $new1->inprogressuniq, 'closed attempts carry inprogressuniq = id');
        $this->assertNotEquals(0, (int) $new1->qubaid);
        $this->assertNotEquals((int) $attempt1->qubaid, (int) $new1->qubaid);
        $usage1 = $DB->get_record('question_usages', ['id' => $new1->qubaid], '*', MUST_EXIST);
        $this->assertSame('mod_stackmastery', $usage1->component);
        $this->assertSame(2, $DB->count_records('question_attempts', ['questionusageid' => $new1->qubaid]));

        // Attempt 1 steps: kept in full; pointers remapped, or cleared when the
        // question is outside the backup.
        $newsteps = $DB->get_records('stackmastery_steps', ['attemptid' => $new1->id], 'seq');
        $this->assertCount(3, $newsteps);
        $newsteps = array_values($newsteps);
        $oldsteps = [$this->step_row($attempt1->id, 1), $this->step_row($attempt1->id, 2),
            $this->step_row($attempt1->id, 3)];
        foreach ($oldsteps as $i => $oldstep) {
            $this->assert_plain_fields($oldstep, $newsteps[$i], self::STEP_PLAIN_FIELDS, 'step' . ($i + 1));
            $this->assertSame((string) $oldstep->masterybefore, (string) $newsteps[$i]->masterybefore);
            $this->assertSame((string) $oldstep->masteryafter, (string) $newsteps[$i]->masteryafter);
            $this->assertNull($newsteps[$i]->stackseed);
        }
        foreach ([0, 1] as $i) {
            $this->assertNotEquals(0, (int) $newsteps[$i]->questionid, 'mapped step keeps a question pointer');
            $this->assertNotEquals((int) $oldsteps[$i]->questionid, (int) $newsteps[$i]->questionid);
            $this->assertTrue($DB->record_exists('question', ['id' => $newsteps[$i]->questionid]));
            $this->assertTrue($DB->record_exists(
                'question_bank_entries',
                ['id' => $newsteps[$i]->questionbankentryid]
            ));
        }
        $quba1restored = \question_engine::load_questions_usage_by_activity((int) $new1->qubaid);
        $this->assertEquals(
            (int) $newsteps[0]->questionid,
            (int) $quba1restored->get_question(1)->id,
            'step 1 question pointer stays consistent with the restored usage slot 1'
        );
        // The unmappable step was KEPT, with both pointers cleared.
        $this->assertSame(0, (int) $newsteps[2]->questionid);
        $this->assertSame(0, (int) $newsteps[2]->questionbankentryid);

        // Attempt 2: the qubaid-0 SLOTLESS attempt stays qubaid 0.
        $new2 = $bynumber[2];
        $this->assert_plain_fields($attempt2, $new2, self::ATTEMPT_PLAIN_FIELDS, 'attempt2');
        $this->assertSame(0, (int) $new2->qubaid, 'a never-provisioned attempt keeps qubaid 0');
        $this->assertSame(0, (int) $new2->currentslot);
        $this->assertNull($new2->pendingjson);
        $this->assertSame(0, (int) $new2->inprogressuniq, 'open attempts keep inprogressuniq 0');
        $this->assertNull($new2->masteryfinal);
        $this->assertSame((string) $attempt2->masterycurrent, (string) $new2->masterycurrent);

        // Attempt 2 snapshots: the three mapped rows survive, the outside row dropped.
        $snapshots2 = $DB->get_records('stackmastery_pool_snapshot', ['attemptid' => $new2->id], 'id');
        $this->assertCount(3, $snapshots2);
        foreach ($snapshots2 as $row) {
            $this->assertTrue($DB->record_exists('question', ['id' => $row->questionid]));
            $this->assertTrue($DB->record_exists('question_bank_entries', ['id' => $row->questionbankentryid]));
            $this->assertNotEquals(
                'integrate/medium',
                $row->skill . '/' . $row->difficulty,
                'the unmappable snapshot row was dropped'
            );
        }

        // Attempt 3: open slot preserved with a remapped pending context.
        $new3 = $bynumber[3];
        $this->assertSame(1, (int) $new3->currentslot);
        $this->assertNotEquals(0, (int) $new3->qubaid);
        $pending = json_decode((string) $new3->pendingjson, true);
        $this->assertIsArray($pending);
        $this->assertNotEquals((int) $q3->id, (int) $pending['questionid'], 'pendingjson questionid remapped');
        $this->assertNotEquals((int) $q3->questionbankentryid, (int) $pending['qbeid'], 'pendingjson qbeid remapped');
        $this->assertTrue($DB->record_exists('question', ['id' => $pending['questionid']]));
        $this->assertTrue($DB->record_exists('question_bank_entries', ['id' => $pending['qbeid']]));
        $quba3restored = \question_engine::load_questions_usage_by_activity((int) $new3->qubaid);
        $this->assertEquals(
            (int) $pending['questionid'],
            (int) $quba3restored->get_question(1)->id,
            'the remapped pending question is the question in the restored open slot'
        );
        foreach (['seq', 'slot', 'version', 'variant', 'propensity', 'servedskill', 'source'] as $key) {
            $this->assertEquals($pending3[$key], $pending[$key], "pendingjson key {$key} preserved");
        }
        $snapshots3 = $DB->get_records('stackmastery_pool_snapshot', ['attemptid' => $new3->id], 'id');
        $this->assertCount(3, $snapshots3);
        $servedrows = array_filter($snapshots3, static fn($row) => $row->timeserved !== null);
        $this->assertCount(1, $servedrows, 'the served marker survived the restore');

        // Attempt 4: unmappable pending context restores as SLOTLESS in progress.
        $new4 = $bynumber[4];
        $this->assertSame('inprogress', $new4->state);
        $this->assertSame(0, (int) $new4->currentslot, 'unmappable open slot falls back to slotless');
        $this->assertNull($new4->pendingjson);
        $this->assertNotEquals(0, (int) $new4->qubaid, 'the usage itself still restores');
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $new4->qubaid]));
        $this->assertSame(0, (int) $new4->inprogressuniq);

        // The source instance is untouched.
        $this->assertSame(4, $DB->count_records(
            'stackmastery_attempts',
            ['stackmasteryid' => $site->instance->id]
        ));
        $this->assertSame(4, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => $attempt2->id]));
        $this->assertSame(3, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => $attempt3->id]));
        $this->assertSame(3, $DB->count_records('stackmastery_steps', ['attemptid' => $attempt1->id]));
    }

    /**
     * Builds the source site: config, course, pool, instance, users and the
     * outside category/question that is deliberately never part of the backup.
     *
     * @return \stdClass site bundle
     */
    private function build_site(): \stdClass {
        global $DB;

        set_config('allowedqtypes', 'shortanswer', 'mod_stackmastery');
        set_config('epsilon', 0.05, 'mod_stackmastery');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        /** @var \mod_stackmastery_generator $smgenerator */
        $smgenerator = $generator->get_plugin_generator('mod_stackmastery');
        $pool = $smgenerator->create_pool([
            'course'  => $course->id,
            'skills'  => ['differentiate', 'integrate'],
            'percell' => 1,
        ]);
        $instance = $generator->create_module('stackmastery', [
            'course'         => $course->id,
            'poolcategoryid' => $pool->category->id,
            'skills'         => 'differentiate,integrate',
        ]);
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);

        // A question in a second course-context category: no question usage ever
        // references it, so it is not annotated and never enters the backup.
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $outsidecategory = $questiongenerator->create_question_category([
            'contextid' => \context_course::instance($course->id)->id,
            'name'      => 'Outside the backup',
        ]);
        $outsidequestion = $questiongenerator->create_question(
            'shortanswer',
            'frogtoad',
            ['category' => $outsidecategory->id]
        );
        $outsideversion = $DB->get_record(
            'question_versions',
            ['questionid' => $outsidequestion->id],
            '*',
            MUST_EXIST
        );
        $outsidequestion->questionbankentryid = (int) $outsideversion->questionbankentryid;
        $outsidequestion->version = (int) $outsideversion->version;

        return (object) [
            'course'          => $course,
            'pool'            => $pool,
            'instance'        => $instance,
            'instancerecord'  => $DB->get_record('stackmastery', ['id' => $instance->id], '*', MUST_EXIST),
            'cm'              => $cm,
            'context'         => \context_module::instance($cm->id),
            'student'         => $generator->create_and_enrol($course, 'student'),
            'student2'        => $generator->create_and_enrol($course, 'student'),
            'student3'        => $generator->create_and_enrol($course, 'student'),
            'outsidequestion' => $outsidequestion,
        ];
    }

    /**
     * Creates and saves a real question usage, optionally answered and finished.
     *
     * @param \context_module $context the activity context owning the usage
     * @param int[] $questionids questions to add, one slot each
     * @param bool $finished answer every slot correctly and finish when true
     * @return \question_usage_by_activity the saved usage
     */
    private function make_usage(
        \context_module $context,
        array $questionids,
        bool $finished
    ): \question_usage_by_activity {
        $quba = \question_engine::make_questions_usage_by_activity('mod_stackmastery', $context);
        $quba->set_preferred_behaviour('adaptivenopenalty');
        foreach ($questionids as $questionid) {
            $quba->add_question(\question_bank::load_question($questionid), 1.0);
        }
        $quba->start_all_questions();
        if ($finished) {
            $responses = [];
            foreach ($quba->get_slots() as $slot) {
                $responses[$slot] = ['answer' => 'frog', '-submit' => 1];
            }
            $quba->process_all_actions(time(), $quba->prepare_simulated_post_data($responses));
            $quba->finish_all_questions();
        }
        \question_engine::save_questions_usage_by_activity($quba);
        return $quba;
    }

    /**
     * An all-8-keys mastery vector JSON in canonical order.
     *
     * @param float $value the value for every skill
     * @return string JSON object
     */
    private function mastery_json(float $value): string {
        return json_encode(array_fill_keys(bkt::SKILLS, $value));
    }

    /**
     * Inserts an attempt row with sane defaults, honouring the inprogressuniq
     * semantics (0 open, = id closed).
     *
     * @param array $overrides column overrides
     * @param bool $closed rewrite inprogressuniq to the new id after insert
     * @return \stdClass the inserted row, re-read from the database
     */
    private function insert_attempt(array $overrides, bool $closed = false): \stdClass {
        global $DB;
        $defaults = [
            'attemptnumber'     => 1,
            'qubaid'            => 0,
            'state'             => 'inprogress',
            'finishreason'      => null,
            'inprogressuniq'    => 0,
            'currentslot'       => 0,
            'pendingjson'       => null,
            'preview'           => 0,
            'masterycurrent'    => $this->mastery_json(0.1),
            'skillssnapshot'    => 'differentiate,integrate',
            'targetsnapshot'    => json_encode(array_fill_keys(bkt::SKILLS, 0.95)),
            'budget'            => 6,
            'questionsdone'     => 0,
            'reachedtarget'     => 0,
            'stepstotarget'     => null,
            'timetargetreached' => null,
            'masteryfinal'      => null,
            'policyversion'     => 'shipped-0123456789ab',
            'bktmodelversion'   => 'bkt-1:default',
            'timeexported'      => 0,
            'timestart'         => 1750000000,
            'timefinish'        => 0,
            'timemodified'      => 1750000000,
        ];
        $record = (object) array_merge($defaults, $overrides);
        $record->id = $DB->insert_record('stackmastery_attempts', $record);
        if ($closed) {
            $DB->set_field('stackmastery_attempts', 'inprogressuniq', $record->id, ['id' => $record->id]);
            if ((int) $record->timefinish === 0) {
                $DB->set_field('stackmastery_attempts', 'timefinish', $record->timestart, ['id' => $record->id]);
            }
        }
        return $DB->get_record('stackmastery_attempts', ['id' => $record->id], '*', MUST_EXIST);
    }

    /**
     * Inserts an experience step row for the given question.
     *
     * @param int $attemptid owning attempt id
     * @param int $seq step sequence (slot equals seq in v1)
     * @param \stdClass $question a generator question with questionbankentryid and version
     * @param array $overrides column overrides
     * @return \stdClass the inserted row, re-read from the database
     */
    private function insert_step(int $attemptid, int $seq, \stdClass $question, array $overrides = []): \stdClass {
        global $DB;
        $defaults = [
            'attemptid'             => $attemptid,
            'seq'                   => $seq,
            'slot'                  => $seq,
            'questionid'            => (int) $question->id,
            'questionbankentryid'   => (int) $question->questionbankentryid,
            'questionversion'       => (int) $question->version,
            'variant'               => 1,
            'stackseed'             => null,
            'recommendedskill'      => 'differentiate',
            'recommendeddifficulty' => 'easy',
            'servedskill'           => 'differentiate',
            'serveddifficulty'      => 'easy',
            'actionsource'          => 'policy',
            'propensity'            => 0.9666666667,
            'masterybefore'         => $this->mastery_json(0.1),
            'correct'               => 1,
            'fraction'              => 1.0,
            'masteryafter'          => $this->mastery_json(0.35),
            'policyversion'         => 'shipped-0123456789ab',
            'bktmodelversion'       => 'bkt-1:default',
            'stateencodingversion'  => 'enc-1',
            'rewardversion'         => 'reward-1',
            'timeanswered'          => 1750000100,
        ];
        $record = (object) array_merge($defaults, $overrides);
        $record->id = $DB->insert_record('stackmastery_steps', $record);
        return $DB->get_record('stackmastery_steps', ['id' => $record->id], '*', MUST_EXIST);
    }

    /**
     * Inserts a pool snapshot row for the given cell and question.
     *
     * @param int $attemptid owning attempt id
     * @param string $skill cell skill code
     * @param string $difficulty cell difficulty code
     * @param \stdClass $question a generator question with questionbankentryid and version
     * @param int $timecreated freeze time
     * @return \stdClass the inserted row, re-read from the database
     */
    private function insert_snapshot(
        int $attemptid,
        string $skill,
        string $difficulty,
        \stdClass $question,
        int $timecreated
    ): \stdClass {
        global $DB;
        $record = (object) [
            'attemptid'           => $attemptid,
            'skill'               => $skill,
            'difficulty'          => $difficulty,
            'questionbankentryid' => (int) $question->questionbankentryid,
            'questionid'          => (int) $question->id,
            'questionversion'     => (int) $question->version,
            'timeserved'          => null,
            'invalid'             => 0,
            'timecreated'         => $timecreated,
        ];
        $record->id = $DB->insert_record('stackmastery_pool_snapshot', $record);
        return $DB->get_record('stackmastery_pool_snapshot', ['id' => $record->id], '*', MUST_EXIST);
    }

    /**
     * A realistic frozen open-slot pending context for the given question.
     *
     * @param \stdClass $question a generator question with questionbankentryid and version
     * @param int $timecreated provisioning time
     * @return array the pending context, pre-json_encode
     */
    private function pending_context(\stdClass $question, int $timecreated): array {
        return [
            'seq'                   => 1,
            'slot'                  => 1,
            'qbeid'                 => (int) $question->questionbankentryid,
            'questionid'            => (int) $question->id,
            'version'               => (int) $question->version,
            'variant'               => 1,
            'stackseed'             => null,
            'recommendedskill'      => 'differentiate',
            'recommendeddifficulty' => 'medium',
            'servedskill'           => 'differentiate',
            'serveddifficulty'      => 'medium',
            'source'                => 'policy',
            'propensity'            => 0.9666666667,
            'epsilon'               => 0.05,
            'eligiblecount'         => 3,
            'policyversion'         => 'shipped-0123456789ab',
            'masterybefore'         => json_decode($this->mastery_json(0.1), true),
            'timecreated'           => $timecreated,
        ];
    }

    /**
     * Backs up one activity with user data and returns the backup id.
     *
     * The mod_h5pactivity restore-test incantation: MODE_IMPORT just creates the
     * backup directory (no zipping), and the users setting is force-unlocked so
     * user data rides along.
     *
     * @param int $cmid the course module to back up
     * @return string the backup id
     */
    private function backup_activity_with_users(int $cmid): string {
        global $CFG, $USER;

        // Turn off file logging, otherwise it can't delete the file (Windows).
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $cmid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();
        return $backupid;
    }

    /**
     * Restores a backup into the given course, with user data.
     *
     * @param string $backupid the backup id from backup_activity_with_users()
     * @param int $courseid target course
     * @return void
     */
    private function restore_into(string $backupid, int $courseid): void {
        global $USER;
        $rc = new \restore_controller(
            $backupid,
            $courseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        $rc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
    }

    /**
     * Re-reads a seeded step row by its natural key.
     *
     * @param int $attemptid the source attempt id
     * @param int $seq the step sequence
     * @return \stdClass the row
     */
    private function step_row(int $attemptid, int $seq): \stdClass {
        global $DB;
        return $DB->get_record(
            'stackmastery_steps',
            ['attemptid' => $attemptid, 'seq' => $seq],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Asserts that every listed column survived the round trip unchanged.
     *
     * @param \stdClass $old the source row
     * @param \stdClass $new the restored row
     * @param string[] $fields column names
     * @param string $label assertion message prefix
     * @return void
     */
    private function assert_plain_fields(\stdClass $old, \stdClass $new, array $fields, string $label): void {
        foreach ($fields as $field) {
            $this->assertEquals($old->{$field}, $new->{$field}, "{$label}: field {$field} survives the round trip");
        }
    }
}
