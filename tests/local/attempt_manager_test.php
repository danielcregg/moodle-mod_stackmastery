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
 * Tests for the attempt engine on real question usages.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

use mod_stackmastery\stackmastery_walkthrough_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../stackmastery_walkthrough_trait.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * The QUBA-driven attempt loop: lifecycle, duplicates, rollback, recovery, termination,
 * grades, completion, events and provenance (design 03 section 11 + test plan 07 section 3.5).
 *
 * @covers \mod_stackmastery\local\attempt_manager
 */
final class attempt_manager_test extends \advanced_testcase {
    use stackmastery_walkthrough_trait;

    /**
     * Common setup.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Craft the E21 crash shape: a committed attempt row plus pool snapshot, before any
     * provisioning (qubaid 0, SLOTLESS) - exactly what a crash between the start transaction
     * and the first provisioning leaves behind.
     *
     * @param \stdClass $setup The setup_mastery() bundle.
     * @param int $userid The student.
     * @return \stdClass The attempt row.
     */
    private function craft_slotless_attempt(\stdClass $setup, int $userid): \stdClass {
        global $DB;
        $selected = skills::decode_csv((string) $setup->instance->skills);
        $mastery = mastery::init();
        $now = time();
        $attempt = (object) [
            'stackmasteryid'  => (int) $setup->instance->id,
            'userid'          => $userid,
            'attemptnumber'   => 1,
            'qubaid'          => 0,
            'state'           => attempt_store::STATE_INPROGRESS,
            'finishreason'    => null,
            'inprogressuniq'  => 0,
            'currentslot'     => 0,
            'pendingjson'     => null,
            'preview'         => 0,
            'masterycurrent'  => $mastery->to_json(),
            'skillssnapshot'  => skills::encode_csv($selected),
            'targetsnapshot'  => json_encode(array_fill_keys(bkt::SKILLS, 0.95)),
            'budget'          => 0,
            'questionsdone'   => 0,
            'reachedtarget'   => 0,
            'policyversion'   => 'test-policy',
            'bktmodelversion' => bkt::MODEL_VERSION,
            'timeexported'    => 0,
            'timestart'       => $now,
            'timefinish'      => 0,
            'timemodified'    => $now,
        ];
        $attempt->id = $DB->insert_record('stackmastery_attempts', $attempt);
        $snapshot = pool::build_snapshot($setup->instance, (int) $attempt->id, $selected);
        $attempt->budget = min((int) $setup->instance->budget, (int) $snapshot['distinct']);
        $DB->set_field('stackmastery_attempts', 'budget', $attempt->budget, ['id' => $attempt->id]);
        return $attempt;
    }

    /**
     * Rows in question_attempt_steps for a whole usage (the write-detector for duplicate tests).
     *
     * @param int $qubaid The usage id.
     * @return int The step row count.
     */
    private function count_engine_steps(int $qubaid): int {
        global $DB;
        return (int) $DB->count_records_sql(
            'SELECT COUNT(1)
               FROM {question_attempt_steps} qas
               JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
              WHERE qa.questionusageid = :qubaid',
            ['qubaid' => $qubaid]
        );
    }

    /**
     * The gradebook grade for a user on the instance's grade item.
     *
     * @param \stdClass $setup The setup bundle.
     * @param int $userid The user.
     * @return float|null The grade, or null when none.
     */
    private function gradebook_grade(\stdClass $setup, int $userid): ?float {
        $grades = grade_get_grades(
            $setup->course->id,
            'mod',
            'stackmastery',
            $setup->instance->id,
            $userid
        );
        $grade = $grades->items[0]->grades[$userid]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * Start creates the attempt row, the pool snapshot, the usage and the first slot, all
     * persisted, with the pending selection context complete and provenance stamped.
     *
     * @return void
     */
    public function test_start_creates_usage_snapshot_and_first_slot(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);

        $this->assertSame(attempt_store::STATE_INPROGRESS, $attempt->state);
        $this->assertSame(1, (int) $attempt->attemptnumber);
        $this->assertSame(0, (int) $attempt->preview);
        $this->assertGreaterThan(0, (int) $attempt->qubaid);
        $this->assertSame(1, (int) $attempt->currentslot);
        $this->assertSame(0, (int) $attempt->questionsdone);
        $this->assertSame(mastery::init()->to_json(), $attempt->masterycurrent);
        $this->assertSame(policy::load()->version(), $attempt->policyversion);
        $this->assertSame(bkt::MODEL_VERSION, $attempt->bktmodelversion);
        // Two skills, three difficulties, three questions per cell.
        $this->assertSame(18, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => $attempt->id]));
        $this->assertSame(18, (int) $attempt->budget);

        $pending = json_decode((string) $attempt->pendingjson);
        $this->assertIsObject($pending);
        $keys = ['seq', 'slot', 'qbeid', 'questionid', 'version', 'variant', 'stackseed',
                 'recommendedskill', 'recommendeddifficulty', 'servedskill', 'serveddifficulty',
                 'source', 'propensity', 'epsilon', 'eligiblecount', 'policyversion',
                 'masterybefore', 'timecreated'];
        foreach ($keys as $key) {
            $this->assertTrue(property_exists($pending, $key), "pendingjson carries {$key}");
        }
        $this->assertSame(1, (int) $pending->seq);
        $this->assertSame(1, (int) $pending->slot);

        // The usage is persisted and owned by this module in this context.
        $usage = $DB->get_record('question_usages', ['id' => $attempt->qubaid], '*', MUST_EXIST);
        $this->assertSame('mod_stackmastery', $usage->component);
        $this->assertSame((int) $setup->context->id, (int) $usage->contextid);
        $this->assertSame('adaptivenopenalty', $usage->preferredbehaviour);
        $this->assertSame(1, $DB->count_records('question_attempts', ['questionusageid' => $attempt->qubaid]));
        // The served row is burnt in the snapshot.
        $served = $DB->count_records_select(
            'stackmastery_pool_snapshot',
            'attemptid = :attemptid AND timeserved IS NOT NULL',
            ['attemptid' => $attempt->id]
        );
        $this->assertGreaterThanOrEqual(1, $served);
    }

    /**
     * A second start while an attempt is open resumes the SAME attempt without drawing;
     * different users get independent attempts.
     *
     * @return void
     */
    public function test_start_or_resume_returns_existing_open_attempt(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $first = $manager->start_or_resume((int) $setup->student->id);
        $second = $manager->start_or_resume((int) $setup->student->id);
        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(1, $DB->count_records('question_attempts', ['questionusageid' => $first->qubaid]));
        $this->assertSame(1, $DB->count_records('stackmastery_attempts', [
            'stackmasteryid' => $setup->instance->id, 'userid' => $setup->student->id,
        ]));

        $other = $this->getDataGenerator()->create_and_enrol($setup->course, 'student');
        $otherattempt = $manager->start_or_resume((int) $other->id);
        $this->assertNotSame((int) $first->id, (int) $otherattempt->id);
    }

    /**
     * The C3 backstop: a second in-progress row forced past the lock trips the DB unique
     * index (stackmasteryid, userid, inprogressuniq).
     *
     * @return void
     */
    public function test_forced_second_open_attempt_trips_unique_index(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('stackmastery_attempts', (object) [
            'stackmasteryid'  => (int) $setup->instance->id,
            'userid'          => (int) $setup->student->id,
            'attemptnumber'   => 2,
            'state'           => attempt_store::STATE_INPROGRESS,
            'inprogressuniq'  => 0,
            'masterycurrent'  => $attempt->masterycurrent,
            'skillssnapshot'  => $attempt->skillssnapshot,
            'targetsnapshot'  => $attempt->targetsnapshot,
            'policyversion'   => 'test-policy',
            'bktmodelversion' => bkt::MODEL_VERSION,
        ]);
    }

    /**
     * maxattempts counts every state and blocks a new start once spent.
     *
     * @return void
     */
    public function test_maxattempts_enforced(): void {
        $setup = $this->setup_mastery(['maxattempts' => 1]);
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $manager->abandon_attempt($attempt);
        try {
            $manager->start_or_resume((int) $setup->student->id);
            $this->fail('errmaxattempts expected');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmaxattempts', $e->errorcode);
        }
    }

    /**
     * An empty pool aborts the start atomically: the exception surfaces and nothing persists.
     *
     * @return void
     */
    public function test_start_with_empty_pool_throws_and_persists_nothing(): void {
        global $DB;
        $setup = $this->setup_mastery([], ['percell' => 0]);
        $manager = $this->make_manager($setup);
        try {
            $manager->start_or_resume((int) $setup->student->id);
            $this->fail('errpoolempty expected');
        } catch (\moodle_exception $e) {
            $this->assertSame('errpoolempty', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('stackmastery_attempts'));
        $this->assertSame(0, $DB->count_records('stackmastery_pool_snapshot'));
        $this->assertSame(0, $DB->count_records('question_usages', ['component' => 'mod_stackmastery']));
    }

    /**
     * The effective budget caps at the DISTINCT eligible entry count: a question tagged into
     * two cells counts once (spec: min of the instance budget and unique questions).
     *
     * @return void
     */
    public function test_effective_budget_capped_at_distinct_eligible(): void {
        global $DB;
        $setup = $this->setup_mastery(
            ['skills' => 'differentiate,integrate'],
            ['skills' => ['differentiate'], 'percell' => 2]
        );
        // Tag one differentiate question with a second skill: it appears in two cells.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $doubletagged = $setup->pool->questions['differentiate']['easy'][0];
        $questiongenerator->create_question_tag([
            'questionid' => $doubletagged->id,
            'tag'        => skills::skill_tag('integrate'),
        ]);
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        // Six distinct questions across seven snapshot rows.
        $this->assertSame(7, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => $attempt->id]));
        $this->assertSame(6, (int) $attempt->budget);
    }

    /**
     * A save-only press (no submit behaviour var) never grades: the engine step is persisted so
     * the echo survives resume, but no experience row is written and the budget is untouched.
     *
     * @return void
     */
    public function test_validation_press_does_not_advance(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $enginestepsbefore = $this->count_engine_steps((int) $attempt->qubaid);

        $post = $this->submission_post($attempt, ['answer' => 'frog']);
        $outcome = $manager->process_submission($attempt, $post);

        $this->assertSame(submit_outcome::VALIDATED, $outcome->result);
        $this->assertSame(1, (int) $attempt->currentslot);
        $this->assertSame(0, (int) $attempt->questionsdone);
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $attempt->id]));
        $this->assertGreaterThan($enginestepsbefore, $this->count_engine_steps((int) $attempt->qubaid));
    }

    /**
     * A graded correct answer seals the slot, logs exactly one step, moves only the served
     * skill's mastery (recomputed independently), and provisions the next slot.
     *
     * @return void
     */
    public function test_graded_correct_advances_and_logs(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $pending = json_decode((string) $attempt->pendingjson);
        $masterybefore = (string) $attempt->masterycurrent;

        $result = $this->answer_current($manager, $attempt, 'right');
        $outcome = $result->outcome;
        $step = $result->step;

        $this->assertSame(submit_outcome::GRADED, $outcome->result);
        $this->assertTrue($outcome->correct);
        $this->assertEqualsWithDelta(1.0, $outcome->fraction, 1e-9);
        $this->assertSame(1, $outcome->lastseq);
        $this->assertSame(1, $outcome->gradedslot);
        $this->assertSame(2, $outcome->nextslot);

        $this->assertSame(1, (int) $step->seq);
        $this->assertSame(1, (int) $step->slot);
        $this->assertSame(1, (int) $step->correct);
        $this->assertEqualsWithDelta(1.0, (float) $step->fraction, 1e-9);
        $this->assertSame((string) $pending->servedskill, $step->servedskill);
        $this->assertSame((string) $pending->source, $step->actionsource);

        // Integration equality: the logged mastery step equals an independent recomputation.
        $skillindex = array_search($step->servedskill, bkt::SKILLS, true);
        $diffindex = array_search($step->serveddifficulty, bkt::DIFFICULTIES, true);
        $recompute = mastery::from_json($masterybefore);
        $expected = $recompute->apply_result((int) $skillindex, (int) $diffindex, 1.0);
        $after = json_decode((string) $step->masteryafter, true);
        $before = json_decode((string) $step->masterybefore, true);
        $this->assertEqualsWithDelta($expected['after'], $after[$step->servedskill], 1e-9);
        foreach (bkt::SKILLS as $code) {
            if ($code === $step->servedskill) {
                $this->assertNotEquals($before[$code], $after[$code]);
            } else {
                $this->assertEqualsWithDelta($before[$code], $after[$code], 1e-12);
            }
        }

        // The previous slot is sealed graded-right; the attempt advanced.
        $this->assertSame(1, (int) $attempt->questionsdone);
        $this->assertSame(2, (int) $attempt->currentslot);
        $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->qubaid);
        $qa = $quba->get_question_attempt(1);
        $this->assertSame(\question_state::$gradedright, $qa->get_state());
        $newpending = json_decode((string) $attempt->pendingjson);
        $this->assertSame(2, (int) $newpending->seq);
    }

    /**
     * Partial credit logs the raw fraction with correct 0 (the v1 rule needs at least 0.999);
     * a wrong answer logs 0.0.
     *
     * @return void
     */
    public function test_partial_and_wrong_fractions_logged_raw(): void {
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);

        $partial = $this->answer_current($manager, $attempt, 'partial');
        $this->assertEqualsWithDelta(0.8, (float) $partial->step->fraction, 1e-6);
        $this->assertSame(0, (int) $partial->step->correct);
        $this->assertFalse($partial->outcome->correct);

        $wrong = $this->answer_current($manager, $attempt, 'wrong');
        $this->assertEqualsWithDelta(0.0, (float) $wrong->step->fraction, 1e-9);
        $this->assertSame(0, (int) $wrong->step->correct);
    }

    /**
     * The double-click loser on the SAME open slot: the engine's sequence check throws and the
     * manager classifies DUPLICATE with zero database delta (E1's tripwire never fires).
     *
     * @return void
     */
    public function test_duplicate_same_slot_replay_is_duplicate(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);

        // A validation press bumps the slot's sequence; replaying the identical post is stale.
        $post = $this->submission_post($attempt, ['answer' => 'frog']);
        $first = $manager->process_submission($attempt, $post);
        $this->assertSame(submit_outcome::VALIDATED, $first->result);
        $enginesteps = $this->count_engine_steps((int) $attempt->qubaid);

        $replay = $manager->process_submission($attempt, $post);
        $this->assertSame(submit_outcome::DUPLICATE, $replay->result);
        $this->assertSame($enginesteps, $this->count_engine_steps((int) $attempt->qubaid));
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $attempt->id]));
        $this->assertSame(1, (int) $attempt->currentslot);
    }

    /**
     * A stale form for an already-sealed slot (back button, second tab) is DUPLICATE with zero
     * state change; the finished slot stays finished and no second step row appears.
     *
     * @return void
     */
    public function test_stale_slot_post_is_duplicate_without_change(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $post = $this->submission_post($attempt, ['answer' => 'frog', '-submit' => 1]);
        $first = $manager->process_submission($attempt, $post);
        $this->assertSame(submit_outcome::GRADED, $first->result);
        $enginesteps = $this->count_engine_steps((int) $attempt->qubaid);

        $replay = $manager->process_submission($attempt, $post);
        $this->assertSame(submit_outcome::DUPLICATE, $replay->result);
        $this->assertSame(1, $DB->count_records('stackmastery_steps', ['attemptid' => $attempt->id]));
        $this->assertSame(1, (int) $attempt->questionsdone);
        $this->assertSame(2, (int) $attempt->currentslot);
        $this->assertSame($enginesteps, $this->count_engine_steps((int) $attempt->qubaid));
        $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->qubaid);
        $this->assertTrue($quba->get_question_attempt(1)->get_state()->is_finished());
    }

    /**
     * A POST with no question-engine fields at all is a NOOP.
     *
     * @return void
     */
    public function test_post_without_engine_fields_is_noop(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $outcome = $manager->process_submission($attempt, ['action' => 'submit', 'sesskey' => 'x']);
        $this->assertSame(submit_outcome::NOOP, $outcome->result);
        $this->assertSame(1, (int) $attempt->currentslot);
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $attempt->id]));
    }

    /**
     * T1 atomicity: when the experience write fails inside the transaction, the QUBA save, the
     * mastery update and the attempt update all roll back together, and the same Check can be
     * retried successfully afterwards.
     *
     * @return void
     */
    public function test_t1_rollback_leaves_no_partial_state(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $masterybefore = (string) $attempt->masterycurrent;

        // Poison T1: a conflicting (attemptid, seq 1) row makes experience::log_step() throw
        // inside the transaction (DB-level seam; log_step is static by design).
        $poisonid = $DB->insert_record('stackmastery_steps', (object) [
            'attemptid'             => $attempt->id,
            'seq'                   => 1,
            'slot'                  => 999,
            'questionid'            => 1,
            'questionbankentryid'   => 1,
            'questionversion'       => 1,
            'variant'               => 1,
            'recommendedskill'      => 'differentiate',
            'recommendeddifficulty' => 'easy',
            'servedskill'           => 'differentiate',
            'serveddifficulty'      => 'easy',
            'actionsource'          => 'policy',
            'propensity'            => 1,
            'masterybefore'         => $masterybefore,
            'correct'               => 0,
            'masteryafter'          => $masterybefore,
            'policyversion'         => 'poison',
            'bktmodelversion'       => 'poison',
            'stateencodingversion'  => 'poison',
            'rewardversion'         => 'poison',
            'timeanswered'          => time(),
        ]);

        $post = $this->submission_post($attempt, ['answer' => 'frog', '-submit' => 1]);
        try {
            $manager->process_submission($attempt, $post);
            $this->fail('the poisoned experience write must abort T1');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('duplicate experience row', $e->getMessage());
        }

        // Zero partial state: attempt row, mastery and the persisted QUBA are all pre-submit.
        $fresh = $DB->get_record('stackmastery_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
        $this->assertSame(attempt_store::STATE_INPROGRESS, $fresh->state);
        $this->assertSame(1, (int) $fresh->currentslot);
        $this->assertSame(0, (int) $fresh->questionsdone);
        $this->assertSame($masterybefore, $fresh->masterycurrent);
        $this->assertSame(1, $DB->count_records('stackmastery_steps', ['attemptid' => $attempt->id]));
        $quba = \question_engine::load_questions_usage_by_activity((int) $fresh->qubaid);
        $qa = $quba->get_question_attempt(1);
        $this->assertFalse($qa->get_state()->is_finished());
        $this->assertSame(0, (int) $qa->get_last_behaviour_var('_try', 0));

        // Remove the poison: the identical post now grades (the sequence check never advanced).
        $DB->delete_records('stackmastery_steps', ['id' => $poisonid]);
        $retry = $manager->process_submission($fresh, $post);
        $this->assertSame(submit_outcome::GRADED, $retry->result);
    }

    /**
     * Resume re-renders the open slot as-is: repeated reads never draw, never add engine rows
     * and never log steps (Codex-07 HIGH-2's core assertion).
     *
     * @return void
     */
    public function test_resume_rerenders_never_draws(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $attempt = $this->make_manager($setup)->start_or_resume((int) $setup->student->id);
        $qacount = $DB->count_records('question_attempts', ['questionusageid' => $attempt->qubaid]);

        // Fresh manager per read, as separate requests would be.
        $stateone = $this->make_manager($setup)->current_state($attempt);
        $statetwo = $this->make_manager($setup)->current_state($attempt);

        $this->assertSame(1, $stateone->slot);
        $this->assertSame(1, $statetwo->slot);
        $this->assertNotNull($stateone->questionhtml);
        $this->assertNotNull($stateone->headhtml);
        $this->assertNull($stateone->notice);
        $this->assertSame(
            $qacount,
            $DB->count_records('question_attempts', ['questionusageid' => $attempt->qubaid])
        );
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $attempt->id]));
    }

    /**
     * SLOTLESS recovery (the E21 crash shape) provisions exactly one slot under the lock, and a
     * second read provisions nothing further.
     *
     * @return void
     */
    public function test_slotless_recovery_provisions_exactly_once(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $this->craft_slotless_attempt($setup, (int) $setup->student->id);

        $state = $manager->current_state($attempt, null, false);
        $this->assertSame(attempt_state::NOTICE_RECOVERED, $state->notice);
        $this->assertSame(1, $state->slot);
        $this->assertGreaterThan(0, (int) $attempt->qubaid);
        $this->assertSame(1, $DB->count_records('question_attempts', ['questionusageid' => $attempt->qubaid]));

        $again = $this->make_manager($setup)->current_state($attempt, null, false);
        $this->assertSame(1, $again->slot);
        $this->assertNull($again->notice);
        $this->assertSame(1, $DB->count_records('question_attempts', ['questionusageid' => $attempt->qubaid]));
    }

    /**
     * A stale POST racing recovery (SLOTLESS at submit time) provisions the next question and
     * reports DUPLICATE so the page re-renders it.
     *
     * @return void
     */
    public function test_slotless_submit_provisions_and_reports_duplicate(): void {
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $this->craft_slotless_attempt($setup, (int) $setup->student->id);
        $outcome = $manager->process_submission($attempt, ['stale' => 'form']);
        $this->assertSame(submit_outcome::DUPLICATE, $outcome->result);
        $this->assertSame(1, $outcome->nextslot);
        $this->assertSame(1, (int) $attempt->currentslot);
    }

    /**
     * Target termination: the finishing answer stamps reachedtarget/stepstotarget/
     * timetargetreached atomically with the terminal state, pushes grade 100 (binary mode) and
     * flips the custom completion rule.
     *
     * @return void
     */
    public function test_target_termination_stamps_grade_and_completion(): void {
        global $DB;
        $setup = $this->setup_mastery(
            ['skills' => 'differentiate', 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionreachedtarget' => 1],
            ['skills' => ['differentiate']]
        );
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $final = $this->drive($manager, $attempt, 'right');

        $this->assertSame(attempt_store::STATE_COMPLETE, $final->state);
        $this->assertSame(attempt_manager::REASON_TARGET, $final->finishreason);
        $this->assertSame(1, (int) $final->reachedtarget);
        $this->assertSame((int) $final->questionsdone, (int) $final->stepstotarget);
        $this->assertGreaterThan(0, (int) $final->timetargetreached);
        $this->assertGreaterThan(0, (int) $final->timefinish);
        $this->assertSame((int) $final->id, (int) $final->inprogressuniq);
        $mastery = json_decode((string) $final->masteryfinal, true);
        $this->assertGreaterThanOrEqual(0.95, $mastery['differentiate']);
        $this->assertSame(0, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => $final->id]));

        $this->assertEqualsWithDelta(100.0, $this->gradebook_grade($setup, (int) $setup->student->id), 1e-6);
        $completion = new \completion_info($setup->course);
        $data = $completion->get_data($setup->cm, false, (int) $setup->student->id);
        $this->assertSame(COMPLETION_COMPLETE, (int) $data->completionstate);
    }

    /**
     * Mean-mastery grade mode maps the final vector's selected-skill mean onto 0..100.
     *
     * @return void
     */
    public function test_mean_mastery_grademode(): void {
        $setup = $this->setup_mastery(
            ['skills' => 'differentiate', 'grademode' => grades::GRADEMODE_MEANMASTERY],
            ['skills' => ['differentiate']]
        );
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $final = $this->drive($manager, $attempt, 'right');

        $mastery = json_decode((string) $final->masteryfinal, true);
        $expected = round(100.0 * (float) $mastery['differentiate'], 5);
        $this->assertEqualsWithDelta($expected, grades::attempt_grade($setup->instance, $final), 1e-6);
        $this->assertEqualsWithDelta($expected, $this->gradebook_grade($setup, (int) $setup->student->id), 0.01);
    }

    /**
     * Budget termination: all-wrong answers spend the (capped) budget; grade 0 in binary mode.
     *
     * @return void
     */
    public function test_budget_termination(): void {
        $setup = $this->setup_mastery(
            ['skills' => 'differentiate'],
            ['skills' => ['differentiate'], 'percell' => 1]
        );
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $this->assertSame(3, (int) $attempt->budget);
        $final = $this->drive($manager, $attempt, 'wrong');

        $this->assertSame(attempt_store::STATE_COMPLETE, $final->state);
        $this->assertSame(attempt_manager::REASON_BUDGET, $final->finishreason);
        $this->assertSame(3, (int) $final->questionsdone);
        $this->assertSame(0, (int) $final->reachedtarget);
        $this->assertEqualsWithDelta(0.0, $this->gradebook_grade($setup, (int) $setup->student->id), 1e-6);
    }

    /**
     * A question version deleted mid-attempt is skipped (marked invalid, redrawn) and never
     * blocks the loop; when the drained cells leave nothing eligible the attempt finishes
     * exhausted BEFORE the budget (E9 + E14).
     *
     * @return void
     */
    public function test_deleted_version_skipped_and_pool_exhausts(): void {
        global $DB;
        $setup = $this->setup_mastery(
            ['skills' => 'differentiate'],
            ['skills' => ['differentiate'], 'difficulties' => ['easy'], 'percell' => 3]
        );
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $this->assertSame(3, (int) $attempt->budget);

        // Delete one UNSERVED question's row: its snapshot entry becomes undrawable.
        $unserved = $DB->get_records_select(
            'stackmastery_pool_snapshot',
            'attemptid = :attemptid AND timeserved IS NULL',
            ['attemptid' => $attempt->id]
        );
        $this->assertCount(2, $unserved);
        $victim = reset($unserved);
        $DB->delete_records('question', ['id' => $victim->questionid]);

        $final = $this->drive($manager, $attempt, 'wrong');
        $this->assertSame(attempt_store::STATE_COMPLETE, $final->state);
        $this->assertSame(attempt_manager::REASON_EXHAUSTED, $final->finishreason);
        // Both surviving questions were answered; exhaustion preceded the budget of 3.
        $this->assertSame(2, (int) $final->questionsdone);
        $steps = attempt_store::get_steps((int) $final->id);
        foreach ($steps as $step) {
            $this->assertNotEquals((int) $victim->questionid, (int) $step->questionid);
        }
    }

    /**
     * The early-complete edge (E15): every selected skill starts at or above a low target, so
     * the attempt completes at start with zero questions and full marks.
     *
     * @return void
     */
    public function test_early_complete_on_high_priors(): void {
        global $DB;
        $setup = $this->setup_mastery(['targetmastery' => 0.05]);
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);

        $this->assertSame(attempt_store::STATE_COMPLETE, $attempt->state);
        $this->assertSame(attempt_manager::REASON_TARGET, $attempt->finishreason);
        $this->assertSame(0, (int) $attempt->questionsdone);
        $this->assertSame(0, (int) $attempt->qubaid);
        $this->assertSame(1, (int) $attempt->reachedtarget);
        $this->assertSame(0, (int) $attempt->stepstotarget);
        $this->assertNotEmpty($attempt->masteryfinal);
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $attempt->id]));
        $this->assertSame(0, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => $attempt->id]));
        $this->assertEqualsWithDelta(100.0, $this->gradebook_grade($setup, (int) $setup->student->id), 1e-6);
    }

    /**
     * The C27 public finish: an open, unfinished slot is sealed via the abandon path (gaveup),
     * NO step row is written for it, and the attempt grades as-is. Idempotent; invalid reasons
     * are a coding error.
     *
     * @return void
     */
    public function test_finish_user_seals_open_slot_without_step(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $this->answer_current($manager, $attempt, 'right');
        $this->assertSame(2, (int) $attempt->currentslot);

        $manager->finish_attempt($attempt, attempt_manager::REASON_USER);

        $this->assertSame(attempt_store::STATE_COMPLETE, $attempt->state);
        $this->assertSame(attempt_manager::REASON_USER, $attempt->finishreason);
        $this->assertSame(0, (int) $attempt->currentslot);
        $this->assertNull($attempt->pendingjson);
        $this->assertSame($attempt->masterycurrent, $attempt->masteryfinal);
        $this->assertSame(1, $DB->count_records('stackmastery_steps', ['attemptid' => $attempt->id]));
        $this->assertSame(0, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => $attempt->id]));
        $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->qubaid);
        $this->assertSame(\question_state::$gaveup, $quba->get_question_attempt(2)->get_state());
        $this->assertNotNull($this->gradebook_grade($setup, (int) $setup->student->id));

        // Idempotent: a second finish changes nothing.
        $timefinish = (int) $attempt->timefinish;
        $manager->finish_attempt($attempt, attempt_manager::REASON_USER, time() + 100);
        $this->assertSame($timefinish, (int) $attempt->timefinish);
        // A finished attempt swallows further submissions.
        $outcome = $manager->process_submission($attempt, ['x' => 'y']);
        $this->assertSame(submit_outcome::NOOP, $outcome->result);
        // The abandon reason is not a finish reason.
        $this->expectException(\coding_exception::class);
        $manager->finish_attempt($attempt, attempt_manager::REASON_ABANDONED);
    }

    /**
     * Abandon grades as-is, seals the open slot to gaveup, and is idempotent (no second
     * attempt_completed event).
     *
     * @return void
     */
    public function test_abandon_idempotent_and_grades_as_is(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $this->answer_current($manager, $attempt, 'right');
        $masterylive = (string) $attempt->masterycurrent;

        $manager->abandon_attempt($attempt);
        $this->assertSame(attempt_store::STATE_ABANDONED, $attempt->state);
        $this->assertSame(attempt_manager::REASON_ABANDONED, $attempt->finishreason);
        $this->assertSame($masterylive, $attempt->masteryfinal);
        $this->assertSame(0, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => $attempt->id]));
        $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->qubaid);
        $this->assertSame(\question_state::$gaveup, $quba->get_question_attempt(2)->get_state());

        $sink = $this->redirectEvents();
        $manager->abandon_attempt($attempt);
        $completedagain = array_filter($sink->get_events(), function ($event) {
            return $event instanceof \mod_stackmastery\event\attempt_completed;
        });
        $sink->close();
        $this->assertCount(0, $completedagain, 'a second abandon is a no-op');
    }

    /**
     * The cleanup task's abandon phase drives THIS manager for real (the WP-3 no-op guard has
     * activated): a stale in-progress attempt is finalised, fresh attempts are untouched.
     *
     * @return void
     */
    public function test_cleanup_task_abandons_stale_attempt(): void {
        global $DB;
        $this->expectOutputRegex('/abandoned 1 stale attempts/');
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $stale = $manager->start_or_resume((int) $setup->student->id);
        $other = $this->getDataGenerator()->create_and_enrol($setup->course, 'student');
        $freshattempt = $manager->start_or_resume((int) $other->id);

        set_config('abandonafter', 7 * DAYSECS, 'mod_stackmastery');
        $DB->set_field('stackmastery_attempts', 'timemodified', time() - 8 * DAYSECS, ['id' => $stale->id]);
        (new \mod_stackmastery\task\cleanup_task())->execute();

        $staledb = $DB->get_record('stackmastery_attempts', ['id' => $stale->id], '*', MUST_EXIST);
        $this->assertSame(attempt_store::STATE_ABANDONED, $staledb->state);
        $freshdb = $DB->get_record('stackmastery_attempts', ['id' => $freshattempt->id], '*', MUST_EXIST);
        $this->assertSame(attempt_store::STATE_INPROGRESS, $freshdb->state);
    }

    /**
     * With epsilon 0 the served action is exactly policy::choose()'s deterministic composite,
     * and every provenance field is stamped end to end (attempt row, pendingjson, step row).
     *
     * @return void
     */
    public function test_epsilon_zero_matches_policy_choose_and_stamps_provenance(): void {
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $pending = json_decode((string) $attempt->pendingjson);

        // Recompute the expected first selection independently.
        $policy = policy::load();
        $mastery = mastery::init();
        $selected = [];
        foreach (bkt::SKILLS as $code) {
            $selected[] = in_array($code, ['differentiate', 'integrate'], true);
        }
        $targets = array_fill(0, 8, 0.95);
        $masked = policy::mask_mastered($mastery->vector(), $selected, $targets);
        $eligible = [0, 1, 2, 3, 4, 5];
        $expect = $policy->choose($masked, $eligible, 0.0);

        $this->assertSame(bkt::SKILLS[$expect['skill']], (string) $pending->servedskill);
        $this->assertSame(bkt::DIFFICULTIES[$expect['difficulty']], (string) $pending->serveddifficulty);
        $this->assertSame($expect['source'], (string) $pending->source);
        $this->assertEqualsWithDelta($expect['propensity'], (float) $pending->propensity, 1e-9);
        $this->assertEqualsWithDelta(0.0, (float) $pending->epsilon, 1e-12);
        $this->assertSame(6, (int) $pending->eligiblecount);
        $this->assertSame($policy->version(), (string) $pending->policyversion);

        $result = $this->answer_current($manager, $attempt, 'right');
        $step = $result->step;
        $this->assertSame($policy->version(), $step->policyversion);
        $this->assertSame(bkt::MODEL_VERSION, $step->bktmodelversion);
        $this->assertSame(policy::ENCODING_VERSION, $step->stateencodingversion);
        $this->assertSame(experience::REWARD_VERSION, $step->rewardversion);
        $this->assertSame($attempt->policyversion, $step->policyversion);
    }

    /**
     * An injected RNG makes exploration deterministic: the first draw enters the epsilon branch,
     * the second picks the eligible index, and the logged propensity is the exact mixture value.
     *
     * @return void
     */
    public function test_explore_with_injected_rng_logs_exact_propensity(): void {
        $setup = $this->setup_mastery(['adminepsilon' => 0.2]);
        $this->assertEqualsWithDelta(0.2, (float) $setup->instance->epsilon, 1e-9);
        $draws = [0.0, 0.99];
        $i = 0;
        $rng = function () use ($draws, &$i) {
            return $draws[$i++] ?? 0.5;
        };
        $manager = $this->make_manager($setup, $rng);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $pending = json_decode((string) $attempt->pendingjson);

        // Index draw 0.99 over the six canonical-order eligible actions picks action 5.
        $this->assertSame('explore', (string) $pending->source);
        $this->assertSame('integrate', (string) $pending->servedskill);
        $this->assertSame('hard', (string) $pending->serveddifficulty);
        $this->assertEqualsWithDelta(0.2, (float) $pending->epsilon, 1e-12);

        $policy = policy::load();
        $mastery = mastery::init();
        $selected = [];
        foreach (bkt::SKILLS as $code) {
            $selected[] = in_array($code, ['differentiate', 'integrate'], true);
        }
        $masked = policy::mask_mastered($mastery->vector(), $selected, array_fill(0, 8, 0.95));
        $det = $policy->choose($masked, [0, 1, 2, 3, 4, 5], 0.0)['servedaction'];
        $expected = $det === 5 ? (0.8 + 0.2 / 6) : (0.2 / 6);
        $this->assertEqualsWithDelta($expected, (float) $pending->propensity, 1e-9);
    }

    /**
     * Masking end to end: only selected skills are ever served, and a skill stops being served
     * once its target is reached (every step's pre-update mastery is below target).
     *
     * @return void
     */
    public function test_reached_or_unselected_skills_never_served(): void {
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $final = $this->drive($manager, $attempt, 'right');
        $this->assertSame(attempt_manager::REASON_TARGET, $final->finishreason);

        $targets = json_decode((string) $final->targetsnapshot, true);
        $steps = attempt_store::get_steps((int) $final->id);
        $this->assertNotEmpty($steps);
        foreach ($steps as $step) {
            $this->assertContains($step->servedskill, ['differentiate', 'integrate']);
            $before = json_decode((string) $step->masterybefore, true);
            $this->assertLessThan(
                (float) $targets[$step->servedskill],
                (float) $before[$step->servedskill],
                'a skill at target is never served again'
            );
        }
    }

    /**
     * Gradebook aggregation is HIGHEST: a later, worse attempt never lowers the grade.
     *
     * @return void
     */
    public function test_grade_aggregation_highest(): void {
        $setup = $this->setup_mastery(['skills' => 'differentiate'], ['skills' => ['differentiate']]);
        $manager = $this->make_manager($setup);

        $first = $manager->start_or_resume((int) $setup->student->id);
        $first = $this->drive($manager, $first, 'right');
        $this->assertSame(attempt_manager::REASON_TARGET, $first->finishreason);
        $this->assertEqualsWithDelta(100.0, $this->gradebook_grade($setup, (int) $setup->student->id), 1e-6);

        $second = $manager->start_or_resume((int) $setup->student->id);
        $this->assertNotSame((int) $first->id, (int) $second->id);
        $second = $this->drive($manager, $second, 'wrong');
        $this->assertSame(attempt_manager::REASON_BUDGET, $second->finishreason);
        $this->assertEqualsWithDelta(100.0, $this->gradebook_grade($setup, (int) $setup->student->id), 1e-6);
    }

    /**
     * The three lifecycle events fire with their required payloads.
     *
     * @return void
     */
    public function test_events_emitted(): void {
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $sink = $this->redirectEvents();

        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $this->answer_current($manager, $attempt, 'right');
        $manager->finish_attempt($attempt, attempt_manager::REASON_USER);

        $events = $sink->get_events();
        $sink->close();
        $started = null;
        $stepped = null;
        $completed = null;
        foreach ($events as $event) {
            if ($event instanceof \mod_stackmastery\event\attempt_started) {
                $started = $event;
            }
            if ($event instanceof \mod_stackmastery\event\step_submitted) {
                $stepped = $stepped ?? $event;
            }
            if ($event instanceof \mod_stackmastery\event\attempt_completed) {
                $completed = $event;
            }
        }
        $this->assertNotNull($started);
        $this->assertSame((int) $attempt->id, (int) $started->objectid);
        $this->assertSame((int) $setup->student->id, (int) $started->relateduserid);
        $this->assertSame((int) $setup->instance->id, (int) $started->other['stackmasteryid']);
        $this->assertSame((int) $setup->context->id, (int) $started->get_context()->id);

        $this->assertNotNull($stepped);
        $this->assertSame((int) $attempt->id, (int) $stepped->other['attemptid']);
        $this->assertSame(1, (int) $stepped->other['seq']);
        foreach (['skill', 'difficulty', 'actionsource', 'correct'] as $key) {
            $this->assertArrayHasKey($key, $stepped->other);
        }

        $this->assertNotNull($completed);
        $this->assertSame(attempt_manager::REASON_USER, $completed->other['reason']);
        $this->assertSame(0, (int) $completed->other['reachedtarget']);
    }

    /**
     * Deleting an attempt removes its usage, steps, snapshot and row.
     *
     * @return void
     */
    public function test_delete_attempt_removes_usage_and_rows(): void {
        global $DB;
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $this->answer_current($manager, $attempt, 'right');
        $qubaid = (int) $attempt->qubaid;

        $manager->delete_attempt($attempt);
        $this->assertSame(0, $DB->count_records('stackmastery_attempts', ['id' => $attempt->id]));
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $attempt->id]));
        $this->assertSame(0, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => $attempt->id]));
        $this->assertSame(0, $DB->count_records('question_usages', ['id' => $qubaid]));
        $this->assertSame(0, $DB->count_records('question_attempts', ['questionusageid' => $qubaid]));
    }

    /**
     * The behaviour-state probe (fail-first canary): under adaptivenopenalty a graded shortanswer
     * submission that the manager seals lands in a finished, graded state with a non-null
     * fraction. If a core upgrade changes this contract, THIS test fails with a clear message
     * instead of the loop silently never advancing.
     *
     * @return void
     */
    public function test_behaviour_state_probe(): void {
        $setup = $this->setup_mastery();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $this->answer_current($manager, $attempt, 'right');

        $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->qubaid);
        $qa = $quba->get_question_attempt(1);
        $this->assertTrue($qa->get_state()->is_finished(), 'graded submissions must seal the slot');
        $this->assertTrue($qa->get_state()->is_graded(), 'sealed slots must be graded');
        $this->assertNotNull($qa->get_fraction(), 'the sealed slot must carry a fraction');
        $this->assertSame(1, (int) $qa->get_last_behaviour_var('_try', 0), 'adaptive sets _try once per graded try');
    }
}
