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
 * Tests for the shared attempt data-access primitives.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Uniques, scoped deletion (question usages included) and the qubaid join.
 *
 * @covers \mod_stackmastery\local\attempt_store
 */
final class attempt_store_test extends \advanced_testcase {
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
     * @param int $seq Step sequence.
     * @param int $slot Usage slot.
     * @return int The step id.
     */
    private function insert_step(int $attemptid, int $seq, int $slot): int {
        global $DB;
        return (int) $DB->insert_record('stackmastery_steps', (object) [
            'attemptid'             => $attemptid,
            'seq'                   => $seq,
            'slot'                  => $slot,
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
     * The one-open-attempt unique holds on the CI databases: a closed attempt frees the slot,
     * a second concurrent open row trips a write exception.
     *
     * @return void
     */
    public function test_one_open_attempt_unique(): void {
        global $DB;
        $made = $this->make_instance();
        $user = $this->getDataGenerator()->create_user();

        $first = $this->insert_attempt((int) $made->instance->id, (int) $user->id);
        // Close it the way the runtime does: same UPDATE that leaves inprogress.
        $DB->update_record('stackmastery_attempts', (object) [
            'id' => $first, 'state' => attempt_store::STATE_COMPLETE, 'inprogressuniq' => $first,
        ]);
        // A new open attempt is fine, and is the one get_open_attempt finds.
        $second = $this->insert_attempt(
            (int) $made->instance->id,
            (int) $user->id,
            ['attemptnumber' => 2]
        );
        $open = attempt_store::get_open_attempt((int) $made->instance->id, (int) $user->id);
        $this->assertSame($second, (int) $open->id);

        // A concurrent second open attempt violates the DB backstop.
        $this->expectException(\dml_write_exception::class);
        $this->insert_attempt((int) $made->instance->id, (int) $user->id, ['attemptnumber' => 3]);
    }

    /**
     * One step per (attempt, seq) and per (attempt, slot).
     *
     * @return void
     */
    public function test_step_uniques(): void {
        $made = $this->make_instance();
        $user = $this->getDataGenerator()->create_user();
        $attemptid = $this->insert_attempt((int) $made->instance->id, (int) $user->id);
        $this->insert_step($attemptid, 1, 1);

        try {
            $this->insert_step($attemptid, 1, 2);
            $this->fail('Duplicate (attemptid, seq) must throw.');
        } catch (\dml_write_exception $e) {
            $this->assertInstanceOf(\dml_write_exception::class, $e);
        }
        try {
            $this->insert_step($attemptid, 2, 1);
            $this->fail('Duplicate (attemptid, slot) must throw.');
        } catch (\dml_write_exception $e) {
            $this->assertInstanceOf(\dml_write_exception::class, $e);
        }
        $this->insert_step($attemptid, 2, 2);
        $this->assertCount(2, attempt_store::get_steps($attemptid));
    }

    /**
     * delete_attempts removes exactly the requested scope: rows, steps, snapshots AND the
     * question usages, leaving other users and instances intact.
     *
     * @return void
     */
    public function test_delete_attempts_scoping(): void {
        global $DB;
        $one = $this->make_instance();
        $two = $this->make_instance();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $rows = [];
        foreach ([[$one, $usera], [$one, $userb], [$two, $usera]] as [$made, $user]) {
            $qubaid = $this->make_usage($made->context);
            $attemptid = $this->insert_attempt(
                (int) $made->instance->id,
                (int) $user->id,
                ['qubaid' => $qubaid]
            );
            $this->insert_step($attemptid, 1, 1);
            $DB->insert_record('stackmastery_pool_snapshot', (object) [
                'attemptid' => $attemptid, 'skill' => 'differentiate', 'difficulty' => 'easy',
                'questionbankentryid' => 1, 'questionid' => 1, 'questionversion' => 1,
                'timeserved' => null, 'invalid' => 0, 'timecreated' => time(),
            ]);
            $rows[] = ['attemptid' => $attemptid, 'qubaid' => $qubaid,
                'instance' => (int) $made->instance->id, 'userid' => (int) $user->id];
        }

        $affected = attempt_store::delete_attempts((int) $one->instance->id, [(int) $usera->id]);
        $this->assertSame([(int) $usera->id], $affected);

        // The targeted attempt is fully gone, usage included.
        $this->assertFalse($DB->record_exists('stackmastery_attempts', ['id' => $rows[0]['attemptid']]));
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $rows[0]['attemptid']]));
        $this->assertSame(0, $DB->count_records(
            'stackmastery_pool_snapshot',
            ['attemptid' => $rows[0]['attemptid']]
        ));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $rows[0]['qubaid']]));

        // The other user's and the other instance's data survive.
        foreach ([1, 2] as $index) {
            $this->assertTrue($DB->record_exists('stackmastery_attempts', ['id' => $rows[$index]['attemptid']]));
            $this->assertTrue($DB->record_exists('question_usages', ['id' => $rows[$index]['qubaid']]));
        }

        // Deleting the rest of instance one leaves instance two alone.
        $affected = attempt_store::delete_attempts((int) $one->instance->id);
        $this->assertSame([(int) $userb->id], $affected);
        $this->assertTrue($DB->record_exists('stackmastery_attempts', ['id' => $rows[2]['attemptid']]));
    }

    /**
     * The qubaid join selects exactly the instance's (optionally one user's) usage ids.
     *
     * @return void
     */
    public function test_usages_for_instance_join(): void {
        global $DB;
        $made = $this->make_instance();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $qubaida = $this->make_usage($made->context);
        $qubaidb = $this->make_usage($made->context);
        $this->insert_attempt((int) $made->instance->id, (int) $usera->id, ['qubaid' => $qubaida]);
        $this->insert_attempt((int) $made->instance->id, (int) $userb->id, ['qubaid' => $qubaidb]);

        $join = attempt_store::usages_for_instance((int) $made->instance->id);
        $ids = $DB->get_fieldset_sql(
            'SELECT qu.id FROM ' . $join->from_question_attempts('qa') .
            ' JOIN {question_usages} qu ON qu.id = qa.questionusageid WHERE ' . $join->where(),
            $join->from_where_params()
        );
        $this->assertEqualsCanonicalizing([$qubaida, $qubaidb], array_map('intval', $ids));

        $join = attempt_store::usages_for_instance((int) $made->instance->id, (int) $usera->id);
        \question_engine::delete_questions_usage_by_activities($join);
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $qubaida]));
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $qubaidb]));
    }

    /**
     * Retention purge, stale-open scan and orphan sweep behave and stay in their lanes.
     *
     * @return void
     */
    public function test_purge_stale_and_sweep(): void {
        global $DB;
        $made = $this->make_instance();
        $user = $this->getDataGenerator()->create_user();
        $now = time();

        // An old finished attempt loses its steps to the purge; a fresh one keeps them.
        $oldattempt = $this->insert_attempt((int) $made->instance->id, (int) $user->id, [
            'state' => attempt_store::STATE_COMPLETE, 'inprogressuniq' => 1,
            'timefinish' => $now - 40 * DAYSECS,
        ]);
        $this->insert_step($oldattempt, 1, 1);
        $freshattempt = $this->insert_attempt((int) $made->instance->id, (int) $user->id, [
            'attemptnumber' => 2, 'state' => attempt_store::STATE_COMPLETE, 'inprogressuniq' => 2,
            'timefinish' => $now - DAYSECS,
        ]);
        $this->insert_step($freshattempt, 1, 1);

        $deleted = attempt_store::purge_expired_steps($now - 30 * DAYSECS);
        $this->assertSame(1, $deleted);
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $oldattempt]));
        $this->assertSame(1, $DB->count_records('stackmastery_steps', ['attemptid' => $freshattempt]));
        // The attempt rows themselves survive retention.
        $this->assertTrue($DB->record_exists('stackmastery_attempts', ['id' => $oldattempt]));

        // Stale-open scan: only sufficiently old open attempts are returned, oldest first.
        $staleopen = $this->insert_attempt((int) $made->instance->id, (int) $user->id, [
            'attemptnumber' => 3, 'timemodified' => $now - 10 * DAYSECS,
        ]);
        $stale = attempt_store::get_stale_open_attempts($now - 7 * DAYSECS);
        $this->assertSame([$staleopen], array_map(fn($a) => (int) $a->id, $stale));

        // Orphan sweep: an unreferenced usage, a dead-attempt step and an AGED dead-attempt
        // snapshot go; a fresh dead-attempt snapshot survives the age guard.
        $orphanusage = $this->make_usage($made->context);
        $this->insert_step(999999, 1, 1);
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

        $swept = attempt_store::sweep_orphans(6 * HOURSECS);
        $this->assertSame(1, $swept['usages']);
        $this->assertSame(1, $swept['steps']);
        $this->assertSame(1, $swept['snapshots']);
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $orphanusage]));
        $this->assertTrue($DB->record_exists('stackmastery_pool_snapshot', ['attemptid' => 999998]));
        // Live rows were never touched.
        $this->assertSame(1, $DB->count_records('stackmastery_steps', ['attemptid' => $freshattempt]));
    }
}
