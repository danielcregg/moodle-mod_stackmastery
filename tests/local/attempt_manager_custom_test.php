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
 * Tests for the attempt engine on custom-topics instances (heuristic-driven).
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

/**
 * Custom-topics attempts: policy-free creation, heuristic selection, enc-custom-1 provenance,
 * N-key mastery persistence and the preserved fail-closed core path.
 *
 * @covers \mod_stackmastery\local\attempt_manager
 * @covers \mod_stackmastery\local\heuristic_selector
 */
final class attempt_manager_custom_test extends \advanced_testcase {
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
     * A setup bundle whose instance tracks custom topics.
     *
     * The pool is tagged for the core codes AND the topic slugs; the topic rows are synced
     * after module creation and the instance skills column is then pinned DIRECTLY (module
     * creation runs before the topics exist, so lib.php's empty-csv normalisation would
     * otherwise back-fill all 8).
     *
     * @param string $coreskills The instance's core skills csv ('' for custom-only).
     * @param array $poolskills Every code (core and slug) the pool must cover.
     * @param array $topicitems topics::sync() items.
     * @param array $instanceoverrides Extra instance overrides.
     * @return \stdClass The setup bundle with a refreshed instance record.
     */
    private function setup_custom(
        string $coreskills,
        array $poolskills,
        array $topicitems,
        array $instanceoverrides = []
    ): \stdClass {
        global $DB;
        $setup = $this->setup_mastery(
            array_merge(['skills' => $coreskills], $instanceoverrides),
            ['skills' => $poolskills]
        );
        topics::sync((int) $setup->instance->id, $topicitems);
        $DB->set_field('stackmastery', 'skills', $coreskills, ['id' => $setup->instance->id]);
        $cmid = $setup->instance->cmid;
        $setup->instance = $DB->get_record('stackmastery', ['id' => $setup->instance->id], '*', MUST_EXIST);
        $setup->instance->cmid = $cmid;
        return $setup;
    }

    /**
     * Point the policy store's cache at a nonexistent artifact, simulating a missing or
     * corrupt policy.json for everything that consults the store.
     *
     * @return void
     */
    private function poison_policy_store(): void {
        \cache::make('mod_stackmastery', 'activepolicy')->set('active', [
            'path' => '/nonexistent/stackmastery-policy.json',
            'source' => 'promoted',
            'policyid' => 'ghost-policy',
            'meta' => [],
        ]);
    }

    /**
     * THE D4 ordering rule: a custom-topics instance never consults the policy store, so a
     * missing policy artifact cannot block attempt creation or the serving loop; the same
     * broken store still fails CLOSED for a core-only instance.
     *
     * @return void
     */
    public function test_custom_instance_needs_no_policy_file(): void {
        $this->poison_policy_store();

        // Core-only instance: fail-closed create, exactly as before custom topics.
        $coresetup = $this->setup_mastery();
        try {
            attempt_manager::create($coresetup->instance, $coresetup->cm, $coresetup->context);
            $this->fail('errpolicyunavailable expected for the core instance');
        } catch (\moodle_exception $e) {
            $this->assertSame('errpolicyunavailable', $e->errorcode);
        }
        $this->assertDebuggingCalled();

        // Custom instance: creation and a full serving round succeed with the store broken.
        $setup = $this->setup_custom(
            '',
            ['settheory'],
            [['slug' => null, 'label' => 'Set theory', 'templatetype' => 'set_theory']]
        );
        $manager = attempt_manager::create($setup->instance, $setup->cm, $setup->context);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $this->assertSame(attempt_store::STATE_INPROGRESS, $attempt->state);
        $this->assertSame(1, (int) $attempt->currentslot);
        $result = $this->answer_current($manager, $attempt, 'right');
        $this->assertSame(submit_outcome::GRADED, $result->outcome->result);
        $this->assertDebuggingNotCalled();
    }

    /**
     * A custom-only attempt (zero core skills) runs the whole loop on the heuristic engine:
     * snapshot csv is the slug, mastery JSONs carry 9 keys, every step is stamped
     * heuristic-1/enc-custom-1 with a heuristic-family action source, and the attempt
     * terminates and grades.
     *
     * @return void
     */
    public function test_custom_only_attempt_full_loop(): void {
        $setup = $this->setup_custom(
            '',
            ['settheory'],
            [['slug' => null, 'label' => 'Set theory', 'templatetype' => 'set_theory']]
        );
        $manager = attempt_manager::create($setup->instance, $setup->cm, $setup->context);
        $attempt = $manager->start_or_resume((int) $setup->student->id);

        $this->assertSame('settheory', (string) $attempt->skillssnapshot);
        $this->assertSame(heuristic_selector::POLICY_VERSION, (string) $attempt->policyversion);
        $this->assertSame(bkt::MODEL_VERSION, (string) $attempt->bktmodelversion);
        $mastery = json_decode((string) $attempt->masterycurrent, true);
        $this->assertSame(array_merge(bkt::SKILLS, ['settheory']), array_keys($mastery));
        $this->assertEqualsWithDelta(bkt::DEFAULT_CUSTOM_PARAMS['p_init'], $mastery['settheory'], 1e-12);
        $targets = json_decode((string) $attempt->targetsnapshot, true);
        $this->assertArrayHasKey('settheory', $targets);

        $pending = json_decode((string) $attempt->pendingjson);
        $this->assertSame('settheory', (string) $pending->servedskill);
        $this->assertSame('easy', (string) $pending->serveddifficulty, 'p_init 0.15 sits in the easy band');
        $this->assertSame('heuristic', (string) $pending->source);
        $this->assertSame(heuristic_selector::POLICY_VERSION, (string) $pending->policyversion);

        $final = $this->drive($manager, $attempt, 'right');
        $this->assertNotSame(attempt_store::STATE_INPROGRESS, $final->state);
        $steps = attempt_store::get_steps((int) $final->id);
        $this->assertNotEmpty($steps);
        foreach ($steps as $step) {
            $this->assertSame('settheory', (string) $step->servedskill);
            $this->assertContains((string) $step->actionsource, ['heuristic', 'explore', 'exhausted']);
            $this->assertSame(heuristic_selector::POLICY_VERSION, (string) $step->policyversion);
            $this->assertSame(heuristic_selector::ENCODING_VERSION, (string) $step->stateencodingversion);
            $this->assertSame(experience::REWARD_VERSION, (string) $step->rewardversion);
            $before = json_decode((string) $step->masterybefore, true);
            $this->assertSame(array_merge(bkt::SKILLS, ['settheory']), array_keys($before));
        }

        // The independent BKT recomputation for the first step, under the documented defaults.
        $first = reset($steps);
        $after = json_decode((string) $first->masteryafter, true);
        $expected = bkt::update_belief(
            bkt::DEFAULT_CUSTOM_PARAMS['p_init'],
            array_search((string) $first->serveddifficulty, bkt::DIFFICULTIES, true),
            true,
            bkt::DEFAULT_CUSTOM_PARAMS
        );
        $this->assertEqualsWithDelta($expected, $after['settheory'], 1e-9);

        // Mean-mastery grading over the manifest: the slug counts, the unmoved core codes do not.
        $instance = clone $setup->instance;
        $instance->grademode = grades::GRADEMODE_MEANMASTERY;
        $finalmastery = json_decode((string) $final->masteryfinal, true);
        $this->assertEqualsWithDelta(
            round(100.0 * (float) $finalmastery['settheory'], 5),
            grades::attempt_grade($instance, $final),
            1e-6
        );
    }

    /**
     * A mixed instance (core skills plus a topic) is heuristic-driven as a whole: both code
     * families are served and tracked in one 9-key vector, and the snapshot csv is core codes
     * then slugs.
     *
     * @return void
     */
    public function test_mixed_core_and_custom_attempt(): void {
        $setup = $this->setup_custom(
            'differentiate',
            ['differentiate', 'settheory'],
            [['slug' => null, 'label' => 'Set theory', 'templatetype' => 'set_theory']]
        );
        $manager = attempt_manager::create($setup->instance, $setup->cm, $setup->context);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $this->assertSame('differentiate,settheory', (string) $attempt->skillssnapshot);

        $final = $this->drive($manager, $attempt, 'right');
        $this->assertSame(attempt_store::STATE_COMPLETE, $final->state);
        $this->assertSame(attempt_manager::REASON_TARGET, $final->finishreason);
        $steps = attempt_store::get_steps((int) $final->id);
        $served = array_unique(array_column($steps, 'servedskill'));
        sort($served);
        $this->assertSame(['differentiate', 'settheory'], $served, 'both families are taught');
        foreach ($steps as $step) {
            $this->assertSame(heuristic_selector::ENCODING_VERSION, (string) $step->stateencodingversion);
        }
        $finalmastery = json_decode((string) $final->masteryfinal, true);
        $this->assertGreaterThanOrEqual(0.95, $finalmastery['differentiate']);
        $this->assertGreaterThanOrEqual(0.95, $finalmastery['settheory']);
    }

    /**
     * Heuristic-path exploration mirrors the policy path exactly: the injected RNG enters the
     * epsilon branch, the index draw picks over the manifest-ordered eligible actions, and the
     * logged propensity is the exact mixture value.
     *
     * @return void
     */
    public function test_heuristic_explore_logs_exact_propensity(): void {
        global $DB;
        $setup = $this->setup_custom(
            'differentiate',
            ['differentiate', 'settheory'],
            [['slug' => null, 'label' => 'Set theory', 'templatetype' => 'set_theory']],
            ['adminepsilon' => 0.2]
        );
        $this->assertEqualsWithDelta(0.2, (float) $setup->instance->epsilon, 1e-9);
        $draws = [0.0, 0.99];
        $i = 0;
        $rng = function () use ($draws, &$i) {
            return $draws[$i++] ?? 0.5;
        };
        $manager = new attempt_manager(
            $setup->instance,
            $setup->cm,
            $setup->context,
            null,
            heuristic_selector::POLICY_VERSION,
            null,
            $rng
        );
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $pending = json_decode((string) $attempt->pendingjson);

        // Eligible actions in manifest order: differentiate easy/medium/hard (0,1,2) then
        // settheory easy/medium/hard (24,25,26); index draw 0.99 * 6 -> the last: settheory/hard.
        $this->assertSame('explore', (string) $pending->source);
        $this->assertSame('settheory', (string) $pending->servedskill);
        $this->assertSame('hard', (string) $pending->serveddifficulty);
        $this->assertSame(6, (int) $pending->eligiblecount);
        // The deterministic composite is settheory/easy (0.15 < 0.2), so the draw explored away.
        $this->assertEqualsWithDelta(0.2 / 6, (float) $pending->propensity, 1e-9);

        $result = $this->answer_current($manager, $attempt, 'right');
        $this->assertSame('explore', (string) $result->step->actionsource);
        $this->assertEqualsWithDelta(0.2 / 6, (float) $result->step->propensity, 1e-9);
        $this->assertSame(0, $DB->count_records('stackmastery_steps', [
            'attemptid' => $attempt->id,
            'stateencodingversion' => policy::ENCODING_VERSION,
        ]), 'no enc-1 rows on a custom attempt');
    }

    /**
     * Deleting the topic rows mid-attempt does not break an open attempt: the frozen snapshot
     * keeps the slug tracked (slug-as-label degradation) and the loop still serves and grades.
     *
     * @return void
     */
    public function test_topic_rows_deleted_mid_attempt(): void {
        global $DB;
        $setup = $this->setup_custom(
            'differentiate',
            ['differentiate', 'settheory'],
            [['slug' => null, 'label' => 'Set theory', 'templatetype' => 'set_theory']]
        );
        $manager = attempt_manager::create($setup->instance, $setup->cm, $setup->context);
        $attempt = $manager->start_or_resume((int) $setup->student->id);
        $this->answer_current($manager, $attempt, 'right');

        $DB->delete_records('stackmastery_topics', ['stackmasteryid' => $setup->instance->id]);
        // A FRESH manager (new request): the attempt manifest still derives the slug from the
        // frozen snapshot even though the live rows are gone.
        $manager = attempt_manager::create($setup->instance, $setup->cm, $setup->context);
        $fresh = $DB->get_record('stackmastery_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
        $state = $manager->current_state($fresh, null, false);
        $this->assertContains('settheory', $state->selectedskills);
        $result = $this->answer_current($manager, $fresh, 'right');
        $this->assertSame(submit_outcome::GRADED, $result->outcome->result);
        $this->assertSame(heuristic_selector::ENCODING_VERSION, (string) $result->step->stateencodingversion);
    }
}
