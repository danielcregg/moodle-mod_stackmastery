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
 * Shared walkthrough helpers for attempt-engine tests on real question usages.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery;

use mod_stackmastery\local\attempt_manager;
use mod_stackmastery\local\attempt_store;
use mod_stackmastery\local\bkt;
use mod_stackmastery\local\experience;
use mod_stackmastery\local\policy;
use mod_stackmastery\local\skills;

/**
 * Setup, start, answer, drive and step-invariant helpers on real shortanswer QUBAs.
 *
 * The manager is qtype-agnostic by construction: the graded-try detector and every question
 * engine call behave identically for core shortanswer (frogtoad: frog 1.0, toad 0.8, anything
 * else 0.0) under adaptivenopenalty, which is what makes these tests representative of STACK.
 */
trait stackmastery_walkthrough_trait {
    /**
     * Course + tagged pool + instance + enrolled users in one call.
     *
     * Instance override extras: 'adminepsilon' seeds the admin setting BEFORE instance creation
     * (the instance snapshots it); course-module fields such as 'completion' ride in the record.
     *
     * @param array $instanceoverrides Instance record overrides.
     * @param array $poolspec create_pool() spec overrides.
     * @return \stdClass Object with course, student, teacher, instance, cm, context and pool.
     */
    protected function setup_mastery(array $instanceoverrides = [], array $poolspec = []): \stdClass {
        set_config('allowedqtypes', 'shortanswer', 'mod_stackmastery');
        $adminepsilon = $instanceoverrides['adminepsilon'] ?? 0;
        unset($instanceoverrides['adminepsilon']);
        set_config('epsilon', $adminepsilon, 'mod_stackmastery');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        /** @var \mod_stackmastery_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('mod_stackmastery');
        $poolspec += [
            'course'  => $course->id,
            'skills'  => ['differentiate', 'integrate'],
            'percell' => 3,
        ];
        $pool = $plugingenerator->create_pool($poolspec);

        $record = array_merge([
            'course'         => $course->id,
            'poolcategoryid' => $pool->category->id,
            'skills'         => implode(',', $poolspec['skills']),
        ], $instanceoverrides);
        $instance = $generator->create_module('stackmastery', $record);
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);

        return (object) [
            'course'   => $course,
            'student'  => $generator->create_and_enrol($course, 'student'),
            'teacher'  => $generator->create_and_enrol($course, 'editingteacher'),
            'instance' => $instance,
            'cm'       => $cm,
            'context'  => \context_module::instance($cm->id),
            'pool'     => $pool,
        ];
    }

    /**
     * A real manager over the shipped policy, with an optional injected RNG (SEAM-2).
     *
     * @param \stdClass $setup The setup_mastery() bundle.
     * @param callable|null $rng Uniform [0,1) source for deterministic exploration tests.
     * @return attempt_manager The manager.
     */
    protected function make_manager(\stdClass $setup, ?callable $rng = null): attempt_manager {
        $policy = policy::load();
        return new attempt_manager(
            $setup->instance,
            $setup->cm,
            $setup->context,
            $policy,
            $policy->version(),
            null,
            $rng
        );
    }

    /**
     * Simulated engine POST data for the attempt's current slot.
     *
     * @param \stdClass $attempt The attempt row (currentslot must be open).
     * @param array $response Question response, e.g. ['answer' => 'frog', '-submit' => 1].
     * @return array Post data with prefixes, slots and the sequence check filled in.
     */
    protected function submission_post(\stdClass $attempt, array $response): array {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');
        $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->qubaid);
        return $quba->prepare_simulated_post_data([(int) $attempt->currentslot => $response]);
    }

    /**
     * Submit one answer to the current slot and validate the logged step.
     *
     * @param attempt_manager $manager The manager.
     * @param \stdClass $attempt The attempt row (refreshed in place by the manager).
     * @param string $quality One of right (frog, 1.0), partial (toad, 0.8) or wrong (newt, 0.0).
     * @return \stdClass Object with outcome, step (null unless graded) and the fresh attempt row.
     */
    protected function answer_current(attempt_manager $manager, \stdClass $attempt, string $quality): \stdClass {
        global $DB;
        $answers = ['right' => 'frog', 'partial' => 'toad', 'wrong' => 'newt'];
        $this->assertArrayHasKey($quality, $answers, 'unknown answer quality');
        $this->assertGreaterThan(0, (int) $attempt->currentslot, 'answer_current needs an open slot');
        $post = $this->submission_post($attempt, ['answer' => $answers[$quality], '-submit' => 1]);
        $outcome = $manager->process_submission($attempt, $post);
        $step = null;
        if ($outcome->lastseq !== null) {
            $step = $DB->get_record(
                'stackmastery_steps',
                ['attemptid' => $attempt->id, 'seq' => $outcome->lastseq],
                '*',
                MUST_EXIST
            );
            $this->assert_step_invariants($step, $attempt);
        }
        return (object) ['outcome' => $outcome, 'step' => $step, 'attempt' => $attempt];
    }

    /**
     * Answer with the given quality until the attempt leaves inprogress or $max answers given.
     *
     * @param attempt_manager $manager The manager.
     * @param \stdClass $attempt The attempt row.
     * @param string $quality Answer quality for every submission.
     * @param int $max Safety bound on submissions.
     * @return \stdClass The fresh attempt row.
     */
    protected function drive(
        attempt_manager $manager,
        \stdClass $attempt,
        string $quality = 'right',
        int $max = 60
    ): \stdClass {
        global $DB;
        for ($i = 0; $i < $max; $i++) {
            $attempt = $DB->get_record('stackmastery_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
            if ($attempt->state !== attempt_store::STATE_INPROGRESS) {
                return $attempt;
            }
            if ((int) $attempt->currentslot === 0) {
                $manager->current_state($attempt, null, false);
                continue;
            }
            $this->answer_current($manager, $attempt, $quality);
        }
        return $DB->get_record('stackmastery_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
    }

    /**
     * Invariants every logged step must satisfy (run after every answer_current).
     *
     * @param \stdClass $step The stackmastery_steps row.
     * @param \stdClass $attempt The owning attempt row.
     * @return void
     */
    protected function assert_step_invariants(\stdClass $step, \stdClass $attempt): void {
        global $DB;
        $this->assertSame((int) $attempt->id, (int) $step->attemptid);
        $this->assertGreaterThanOrEqual(1, (int) $step->seq);
        $this->assertSame((int) $step->seq, (int) $step->slot, 'seq equals slot in v1');
        $this->assertSame(
            (int) $step->seq,
            $DB->count_records('stackmastery_steps', ['attemptid' => $attempt->id]),
            'seq is contiguous with the step count'
        );
        $selected = skills::decode_csv((string) $attempt->skillssnapshot);
        $this->assertContains((string) $step->servedskill, $selected, 'served skill is a selected skill');
        $this->assertContains((string) $step->recommendedskill, bkt::SKILLS);
        $this->assertContains((string) $step->serveddifficulty, bkt::DIFFICULTIES);
        $this->assertContains((string) $step->recommendeddifficulty, bkt::DIFFICULTIES);
        $this->assertContains((string) $step->actionsource, experience::SOURCES);
        $propensity = (float) $step->propensity;
        $this->assertGreaterThan(0.0, $propensity);
        $this->assertLessThanOrEqual(1.0, $propensity);
        foreach (['policyversion', 'bktmodelversion', 'stateencodingversion', 'rewardversion'] as $field) {
            $this->assertNotSame('', (string) $step->{$field}, "{$field} is stamped");
        }
        foreach (['masterybefore', 'masteryafter'] as $field) {
            $vector = json_decode((string) $step->{$field}, true);
            $this->assertIsArray($vector, "{$field} is valid JSON");
            $this->assertSame(bkt::SKILLS, array_keys($vector), "{$field} carries the 8 canonical keys");
            foreach ($vector as $value) {
                $this->assertGreaterThanOrEqual(0.0, $value);
                $this->assertLessThanOrEqual(1.0, $value);
            }
        }
        if ($step->fraction !== null) {
            $fraction = (float) $step->fraction;
            $this->assertGreaterThanOrEqual(0.0, $fraction);
            $this->assertLessThanOrEqual(1.0, $fraction);
            $this->assertSame(
                (int) ($fraction >= 0.999),
                (int) $step->correct,
                'correct follows the v1 fraction rule'
            );
        }
        $this->assertGreaterThan(0, (int) $step->timeanswered);
    }
}
