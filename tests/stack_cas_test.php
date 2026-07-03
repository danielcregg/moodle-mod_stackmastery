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
 * Real-STACK integration of the attempt engine (CAS-gated, skip-guarded).
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery;

use mod_stackmastery\local\attempt_manager;
use mod_stackmastery\local\attempt_store;
use mod_stackmastery\local\submit_outcome;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/stackmastery_walkthrough_trait.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Drives a short adaptive run on real STACK questions through real Maxima.
 *
 * Skips cleanly when qtype_stack or a QTYPE_STACK_TEST_CONFIG_* Maxima configuration is absent
 * (the plain CI matrix job installs qtype_stack but no CAS, so these are green-by-skip there);
 * the advisory CAS job and the demo VM (goemaxima, platform server) execute them for real.
 * Everything qtype-agnostic about the loop is covered by attempt_manager_test on shortanswer;
 * this suite pins the STACK-specific behaviours: the validate-then-submit dance, CAS grading of
 * right/wrong/invalid input, and deployed-variant/seed provenance.
 *
 * @covers \mod_stackmastery\local\attempt_manager
 * @group stackmastery_cas
 */
final class stack_cas_test extends \advanced_testcase {
    use stackmastery_walkthrough_trait;

    /**
     * Skip without qtype_stack or a Maxima test configuration; connect otherwise.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        if (!is_readable($CFG->dirroot . '/question/type/stack/tests/fixtures/test_base.php')) {
            $this->markTestSkipped('qtype_stack is not installed.');
        }
        require_once($CFG->dirroot . '/question/type/stack/tests/fixtures/test_base.php');
        if (!\qtype_stack_test_config::is_test_config_available()) {
            $this->markTestSkipped('No QTYPE_STACK_TEST_CONFIG_* Maxima configuration in config.php.');
        }
        \qtype_stack_test_config::setup_test_maxima_connection($this);
        $this->resetAfterTest();
    }

    /**
     * Course + a one-cell STACK pool (integrate/easy, three test1 questions) + instance.
     *
     * @return \stdClass The setup bundle (course, student, instance, cm, context, pool).
     */
    private function make_stack_setup(): \stdClass {
        set_config('allowedqtypes', 'stack', 'mod_stackmastery');
        set_config('epsilon', 0, 'mod_stackmastery');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        /** @var \mod_stackmastery_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('mod_stackmastery');
        $pool = $plugingenerator->create_pool([
            'course'       => $course->id,
            'skills'       => ['integrate'],
            'difficulties' => ['easy'],
            'percell'      => 3,
            'qtype'        => 'stack',
            'which'        => 'test1',
        ]);
        $instance = $generator->create_module('stackmastery', [
            'course'         => $course->id,
            'poolcategoryid' => $pool->category->id,
            'skills'         => 'integrate',
        ]);
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        return (object) [
            'course'   => $course,
            'student'  => $generator->create_and_enrol($course, 'student'),
            'instance' => $instance,
            'cm'       => $cm,
            'context'  => \context_module::instance($cm->id),
            'pool'     => $pool,
        ];
    }

    /**
     * The started STACK question of the attempt's open slot.
     *
     * @param \stdClass $attempt The attempt row.
     * @return \question_definition The question.
     */
    private function open_question(\stdClass $attempt): \question_definition {
        $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->qubaid);
        return $quba->get_question_attempt((int) $attempt->currentslot)->get_question();
    }

    /**
     * The STACK validate-then-submit dance: the first Check (no submit var) is a validation
     * round-trip that consumes nothing; the second, matching submission grades to full marks,
     * logs one step and moves mastery.
     *
     * @return void
     */
    public function test_stack_pool_attempt_walkthrough(): void {
        global $DB;
        $setup = $this->make_stack_setup();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $this->assertSame(attempt_store::STATE_INPROGRESS, $attempt->state);
        $this->assertSame(1, (int) $attempt->currentslot);
        $this->assertSame(3, (int) $attempt->budget);

        $question = $this->open_question($attempt);
        // Never hardcode the teacher answer: test1's correct response comes from the question.
        $correct = $question->get_correct_response();
        $this->assertArrayHasKey('ans1', $correct);

        // Phase 1: validation press (ans1 only, no matching validation echo yet, no submit).
        $post = $this->submission_post($attempt, ['ans1' => $correct['ans1']]);
        $outcome = $manager->process_submission($attempt, $post);
        $this->assertSame(submit_outcome::VALIDATED, $outcome->result);
        $this->assertSame(1, (int) $attempt->currentslot);
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $attempt->id]));

        // Phase 2: the full correct response (including the validation echo) with submit.
        $post = $this->submission_post($attempt, $correct + ['-submit' => 1]);
        $outcome = $manager->process_submission($attempt, $post);
        $this->assertSame(submit_outcome::GRADED, $outcome->result);
        $this->assertTrue($outcome->correct);
        $this->assertEqualsWithDelta(1.0, $outcome->fraction, 1e-9);
        $step = $DB->get_record(
            'stackmastery_steps',
            ['attemptid' => $attempt->id, 'seq' => 1],
            '*',
            MUST_EXIST
        );
        $this->assert_step_invariants($step, $attempt);
        $this->assertSame('integrate', $step->servedskill);
        $before = json_decode((string) $step->masterybefore, true);
        $after = json_decode((string) $step->masteryafter, true);
        $this->assertGreaterThan($before['integrate'], $after['integrate'], 'a correct answer moves mastery up');
        $this->assertSame(2, (int) $attempt->currentslot);
    }

    /**
     * A syntactically valid but wrong answer grades to 0.0 through the CAS.
     *
     * @return void
     */
    public function test_stack_wrong_answer(): void {
        global $DB;
        $setup = $this->make_stack_setup();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);

        $post = $this->submission_post($attempt, ['ans1' => 'x', 'ans1_val' => 'x', '-submit' => 1]);
        $outcome = $manager->process_submission($attempt, $post);
        $this->assertSame(submit_outcome::GRADED, $outcome->result);
        $this->assertFalse($outcome->correct);
        $this->assertEqualsWithDelta(0.0, $outcome->fraction, 1e-9);
        $step = $DB->get_record(
            'stackmastery_steps',
            ['attemptid' => $attempt->id, 'seq' => 1],
            '*',
            MUST_EXIST
        );
        $this->assertSame(0, (int) $step->correct);
    }

    /**
     * Syntactically invalid input never grades: STACK marks the input invalid, no step row is
     * written, the budget is untouched and the student retries the same question.
     *
     * @return void
     */
    public function test_stack_invalid_input_consumes_nothing(): void {
        global $DB;
        $setup = $this->make_stack_setup();
        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);

        $post = $this->submission_post($attempt, ['ans1' => 'sin(x', 'ans1_val' => 'sin(x', '-submit' => 1]);
        $outcome = $manager->process_submission($attempt, $post);
        $this->assertSame(submit_outcome::VALIDATED, $outcome->result);
        $this->assertSame(1, (int) $attempt->currentslot);
        $this->assertSame(0, (int) $attempt->questionsdone);
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $attempt->id]));
    }

    /**
     * Deployed-variant provenance: with seeds deployed on every pool question, the served
     * variant and the resolved STACK seed are logged on the step row and match the live usage.
     *
     * @return void
     */
    public function test_variant_seed_recorded(): void {
        global $DB;
        $setup = $this->make_stack_setup();
        $stackgenerator = $this->getDataGenerator()->get_plugin_generator('qtype_stack');
        if (!method_exists($stackgenerator, 'create_deployed_variant')) {
            $this->markTestSkipped('qtype_stack generator has no create_deployed_variant.');
        }
        $seeds = [5, 86];
        foreach ($setup->pool->questions['integrate']['easy'] as $question) {
            foreach ($seeds as $seed) {
                $stackgenerator->create_deployed_variant([
                    'questionid' => $question->id,
                    'seed'       => $seed,
                ]);
            }
        }

        $manager = $this->make_manager($setup);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->qubaid);
        $qa = $quba->get_question_attempt((int) $attempt->currentslot);
        $variant = (int) $qa->get_variant();
        $this->assertGreaterThanOrEqual(1, $variant);
        $this->assertLessThanOrEqual(count($seeds), $variant);

        $correct = $this->open_question($attempt)->get_correct_response();
        $post = $this->submission_post($attempt, $correct + ['-submit' => 1]);
        $outcome = $manager->process_submission($attempt, $post);
        $this->assertTrue($outcome->is_graded());

        $step = $DB->get_record(
            'stackmastery_steps',
            ['attemptid' => $attempt->id, 'seq' => 1],
            '*',
            MUST_EXIST
        );
        $this->assertSame($variant, (int) $step->variant);
        $this->assertNotNull($step->stackseed);
        $this->assertContains((int) $step->stackseed, $seeds);
    }
}
