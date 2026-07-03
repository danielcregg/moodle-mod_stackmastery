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
 * Tests for the teacher-report query layer.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local\report;

use mod_stackmastery\local\bkt;
use mod_stackmastery\local\grades;

/**
 * Funnel counts, cohort stats, medians, overview rows and the step drilldown, on directly
 * seeded attempt and step rows (the runtime is exercised by the WP-5 walkthrough suite).
 *
 * @covers \mod_stackmastery\local\report\report_data
 */
final class report_data_test extends \advanced_testcase {
    /** @var int Monotonic source of placeholder inprogressuniq values for terminal seeds. */
    private static int $uniqseq = 0;

    /** @var \stdClass The course. */
    private $course;

    /** @var \stdClass The stackmastery instance record. */
    private $instance;

    /** @var \stdClass The question category backing poolcategoryid. */
    private $qcat;

    /**
     * Common setup: a course and one instance over an (empty) pool category.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->qcat = $generator->get_plugin_generator('core_question')->create_question_category(
            ['contextid' => \context_course::instance($this->course->id)->id]
        );
        $this->instance = $generator->create_module('stackmastery', [
            'course'         => $this->course->id,
            'poolcategoryid' => $this->qcat->id,
            'skills'         => 'differentiate,integrate',
            'grademode'      => grades::GRADEMODE_REACHEDTARGET,
        ]);
    }

    /**
     * Seed one attempt row.
     *
     * @param int $userid The user.
     * @param array $overrides Column overrides.
     * @return \stdClass The attempt record with id.
     */
    private function seed_attempt(int $userid, array $overrides = []): \stdClass {
        global $DB;
        $mastery = json_encode(array_combine(bkt::SKILLS, array_fill(0, 8, 0.2)));
        $record = (object) array_merge([
            'stackmasteryid'    => $this->instance->id,
            'userid'            => $userid,
            'attemptnumber'     => 1,
            'qubaid'            => 0,
            'state'             => 'complete',
            'finishreason'      => 'target',
            'inprogressuniq'    => 0,
            'currentslot'       => 0,
            'pendingjson'       => null,
            'preview'           => 0,
            'masterycurrent'    => $mastery,
            'skillssnapshot'    => 'differentiate,integrate',
            'targetsnapshot'    => json_encode(array_combine(bkt::SKILLS, array_fill(0, 8, 0.95))),
            'budget'            => 10,
            'questionsdone'     => 0,
            'reachedtarget'     => 1,
            'stepstotarget'     => null,
            'timetargetreached' => null,
            'masteryfinal'      => $mastery,
            'policyversion'     => 'shipped-abc123def456',
            'bktmodelversion'   => 'bkt-1:default',
            'timeexported'      => 0,
            'timestart'         => 1000,
            'timefinish'        => 2000,
            'timemodified'      => 2000,
        ], $overrides);
        // The C3 unique index (stackmasteryid, userid, inprogressuniq): only the single OPEN
        // attempt of a user may sit at 0, so a terminal row must never even INSERT at 0 (the
        // same user may already hold an open attempt). Insert terminal rows under a placeholder
        // that cannot collide, then settle them at their own id, as production does at close.
        $terminal = $record->state !== 'inprogress';
        if ($terminal && (int) $record->inprogressuniq === 0) {
            $record->inprogressuniq = 1000000000 + (++self::$uniqseq);
        }
        $record->id = $DB->insert_record('stackmastery_attempts', $record);
        if ($terminal) {
            $record->inprogressuniq = (int) $record->id;
            $DB->set_field('stackmastery_attempts', 'inprogressuniq', $record->id, ['id' => $record->id]);
        }
        return $record;
    }

    /**
     * Seed one step row.
     *
     * @param int $attemptid The attempt.
     * @param int $seq The step order.
     * @param array $overrides Column overrides.
     * @return \stdClass The step record with id.
     */
    private function seed_step(int $attemptid, int $seq, array $overrides = []): \stdClass {
        global $DB;
        $before = array_combine(bkt::SKILLS, array_fill(0, 8, 0.2));
        $after = $before;
        $after['differentiate'] = 0.6;
        $record = (object) array_merge([
            'attemptid'             => $attemptid,
            'seq'                   => $seq,
            'slot'                  => $seq,
            'questionid'            => 0,
            'questionbankentryid'   => 1,
            'questionversion'       => 1,
            'variant'               => 1,
            'stackseed'             => null,
            'recommendedskill'      => 'differentiate',
            'recommendeddifficulty' => 'easy',
            'servedskill'           => 'differentiate',
            'serveddifficulty'      => 'easy',
            'actionsource'          => 'policy',
            'propensity'            => 1.0,
            'masterybefore'         => json_encode($before),
            'correct'               => 1,
            'fraction'              => 1.0,
            'masteryafter'          => json_encode($after),
            'policyversion'         => 'shipped-abc123def456',
            'bktmodelversion'       => 'bkt-1:default',
            'stateencodingversion'  => 'enc-1',
            'rewardversion'         => 'reward-1',
            'timeanswered'          => 1500,
        ], $overrides);
        $record->id = $DB->insert_record('stackmastery_steps', $record);
        return $record;
    }

    /**
     * Funnel counts distinct users per stage, not attempts.
     *
     * @return void
     */
    public function test_funnel_counts_distinct_users(): void {
        $generator = $this->getDataGenerator();
        $usera = $generator->create_user();
        $userb = $generator->create_user();

        // User A: three attempts across every stage (must count ONCE per stage).
        $a1 = $this->seed_attempt((int) $usera->id, ['attemptnumber' => 1]);
        $this->seed_step((int) $a1->id, 1);
        $a2 = $this->seed_attempt((int) $usera->id, [
            'attemptnumber' => 2, 'reachedtarget' => 0, 'finishreason' => 'budget',
        ]);
        $this->seed_step((int) $a2->id, 1);
        $this->seed_attempt((int) $usera->id, [
            'attemptnumber' => 3, 'state' => 'inprogress', 'finishreason' => null,
            'reachedtarget' => 0, 'timefinish' => 0, 'masteryfinal' => null,
        ]);
        // User B: started only, no steps, still open.
        $this->seed_attempt((int) $userb->id, [
            'state' => 'inprogress', 'finishreason' => null,
            'reachedtarget' => 0, 'timefinish' => 0, 'masteryfinal' => null,
        ]);

        $funnel = report_data::funnel((int) $this->instance->id);
        $this->assertSame(2, $funnel->started);
        $this->assertSame(1, $funnel->answered);
        $this->assertSame(1, $funnel->completed);
        $this->assertSame(1, $funnel->reached);
    }

    /**
     * An instance without attempts yields zero counts and null aggregates, never a division
     * error.
     *
     * @return void
     */
    public function test_funnel_and_stats_empty_instance(): void {
        $funnel = report_data::funnel((int) $this->instance->id);
        $this->assertSame(0, $funnel->started);
        $this->assertSame(0, $funnel->answered);
        $this->assertSame(0, $funnel->completed);
        $this->assertSame(0, $funnel->reached);

        $stats = report_data::stats((int) $this->instance->id);
        $this->assertSame(0, $stats->inprogress);
        $this->assertSame(0, $stats->abandoned);
        $this->assertNull($stats->medianstepstotarget);
        $this->assertNull($stats->mediantimetotarget);
        $this->assertNull($stats->exploreshare);

        $this->assertSame([], report_data::overview_rows($this->instance));
    }

    /**
     * Medians are taken over target-reaching attempts only, and the state counters see
     * in-progress and abandoned attempts.
     *
     * @return void
     */
    public function test_stats_medians_and_state_counts(): void {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $this->seed_attempt((int) $user->id, [
            'attemptnumber' => 1, 'stepstotarget' => 3, 'timetargetreached' => 1300,
        ]);
        $this->seed_attempt((int) $user->id, [
            'attemptnumber' => 2, 'stepstotarget' => 5, 'timetargetreached' => 1500,
        ]);
        // Not reached: its columns must not enter the medians.
        $this->seed_attempt((int) $user->id, [
            'attemptnumber' => 3, 'reachedtarget' => 0, 'finishreason' => 'budget',
        ]);
        $this->seed_attempt((int) $user->id, [
            'attemptnumber' => 4, 'state' => 'inprogress', 'finishreason' => null,
            'reachedtarget' => 0, 'timefinish' => 0, 'masteryfinal' => null,
        ]);
        $this->seed_attempt((int) $user->id, [
            'attemptnumber' => 5, 'state' => 'abandoned', 'finishreason' => 'abandoned',
            'reachedtarget' => 0,
        ]);

        $stats = report_data::stats((int) $this->instance->id);
        $this->assertSame(1, $stats->inprogress);
        $this->assertSame(1, $stats->abandoned);
        // Even count: mean of the two middles (3, 5) = 4; times (300, 500) = 400.
        $this->assertEqualsWithDelta(4.0, $stats->medianstepstotarget, 1e-9);
        $this->assertEqualsWithDelta(400.0, $stats->mediantimetotarget, 1e-9);
    }

    /**
     * Exploration share: cohort-wide and per attempt, from the actionsource column.
     *
     * @return void
     */
    public function test_exploration_share(): void {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $attempt = $this->seed_attempt((int) $user->id, ['questionsdone' => 4]);
        $this->seed_step((int) $attempt->id, 1, ['actionsource' => 'policy']);
        $this->seed_step((int) $attempt->id, 2, ['actionsource' => 'explore']);
        $this->seed_step((int) $attempt->id, 3, ['actionsource' => 'fallback']);
        $this->seed_step((int) $attempt->id, 4, ['actionsource' => 'exhausted']);

        $stats = report_data::stats((int) $this->instance->id);
        $this->assertEqualsWithDelta(0.25, $stats->exploreshare, 1e-9);

        $rows = report_data::overview_rows($this->instance);
        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]->nsteps);
        $this->assertEqualsWithDelta(0.25, $rows[0]->exploreshare, 1e-9);
    }

    /**
     * Overview rows: one per attempt, user record joined, grade per the gradebook mapping,
     * counts-badge on the highest-graded attempt, mastery decoded, deleted users excluded.
     *
     * @return void
     */
    public function test_overview_rows_shape(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $user = $generator->create_user(['email' => 'sam@example.com']);
        $gone = $generator->create_user();

        $reachedmastery = array_combine(bkt::SKILLS, array_fill(0, 8, 0.2));
        $reachedmastery['differentiate'] = 0.97;
        $this->seed_attempt((int) $user->id, [
            'attemptnumber' => 1, 'reachedtarget' => 0, 'finishreason' => 'budget',
        ]);
        $best = $this->seed_attempt((int) $user->id, [
            'attemptnumber' => 2, 'masteryfinal' => json_encode($reachedmastery),
        ]);
        $this->seed_attempt((int) $gone->id);
        $DB->set_field('user', 'deleted', 1, ['id' => $gone->id]);

        $rows = report_data::overview_rows($this->instance);
        $this->assertCount(2, $rows);

        $first = $rows[0];
        $second = $rows[1];
        $this->assertSame('sam@example.com', $first->user->email);
        // Binary grademode: not-reached grades 0, reached grades 100; the highest is badged.
        $this->assertEqualsWithDelta(0.0, $first->grade, 1e-9);
        $this->assertFalse($first->counted);
        $this->assertEqualsWithDelta(100.0, $second->grade, 1e-9);
        $this->assertTrue($second->counted);
        $this->assertSame((int) $best->id, (int) $second->attempt->id);
        $this->assertEqualsWithDelta(0.97, $second->mastery['differentiate'], 1e-9);
        $this->assertSame(['differentiate', 'integrate'], $second->snapshotskills);
        $this->assertSame(
            ['differentiate', 'integrate'],
            report_data::skill_columns($this->instance, $rows)
        );
    }

    /**
     * The step drilldown joins question names and keeps seq order.
     *
     * @return void
     */
    public function test_attempt_steps_join_question_names(): void {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $q1 = $questiongenerator->create_question('shortanswer', 'frogtoad', [
            'category' => $this->qcat->id, 'name' => 'Frog one',
        ]);
        $q2 = $questiongenerator->create_question('shortanswer', 'frogtoad', [
            'category' => $this->qcat->id, 'name' => 'Frog two',
        ]);

        $attempt = $this->seed_attempt((int) $user->id, ['questionsdone' => 2]);
        $this->seed_step((int) $attempt->id, 2, ['questionid' => $q2->id, 'correct' => 0, 'fraction' => 0.0]);
        $this->seed_step((int) $attempt->id, 1, ['questionid' => $q1->id]);

        $steps = report_data::attempt_steps($attempt);
        $this->assertCount(2, $steps);
        $this->assertSame(1, (int) $steps[0]->seq);
        $this->assertSame('Frog one', $steps[0]->questionname);
        $this->assertSame('Frog two', $steps[1]->questionname);
    }

    /**
     * attempt_record scopes by instance: a foreign attempt id fails loudly.
     *
     * @return void
     */
    public function test_attempt_record_is_instance_scoped(): void {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $attempt = $this->seed_attempt((int) $user->id);
        $loaded = report_data::attempt_record($this->instance, (int) $attempt->id);
        $this->assertSame((int) $attempt->id, (int) $loaded->id);

        $other = $generator->create_module('stackmastery', [
            'course'         => $this->course->id,
            'poolcategoryid' => $this->qcat->id,
        ]);
        $this->expectException(\dml_missing_record_exception::class);
        report_data::attempt_record($other, (int) $attempt->id);
    }

    /**
     * The median helper: empty, odd and even inputs.
     *
     * @return void
     */
    public function test_median(): void {
        $this->assertNull(report_data::median([]));
        $this->assertEqualsWithDelta(5.0, report_data::median([9.0, 5.0, 1.0]), 1e-9);
        $this->assertEqualsWithDelta(4.0, report_data::median([5.0, 1.0, 3.0, 9.0]), 1e-9);
        $this->assertEqualsWithDelta(7.0, report_data::median([7.0]), 1e-9);
    }
}
