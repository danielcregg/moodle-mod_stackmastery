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
 * Tests for the privacy provider.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_stackmastery\local\attempt_store;
use mod_stackmastery\local\skills;

/**
 * Metadata completeness, context/user discovery (including the question-usage linkage), export
 * shape and the three deletion paths.
 *
 * @covers \mod_stackmastery\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
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
        set_config('allowedqtypes', 'shortanswer', 'mod_stackmastery');
        // The writer singleton is not part of the standard test reset; context ids repeat
        // across tests after DB resets, so stale writer data could cross-talk.
        writer::reset();
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
     * Create a real saved question usage owned by mod_stackmastery, answered by the given user.
     *
     * @param \context_module $context The module context.
     * @param \stdClass $user The user whose activity the usage records.
     * @return int The usage id.
     */
    private function make_usage(\context_module $context, \stdClass $user): int {
        $this->setUser($user);
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
        $quba->process_action(1, ['answer' => 'frog']);
        $quba->finish_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);
        $this->setUser(null);
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
            'budget'          => 12,
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
     * @param int $seq Step sequence (also used as the slot).
     * @param array $overrides Field overrides.
     * @return int The step id.
     */
    private function insert_step(int $attemptid, int $seq, array $overrides = []): int {
        global $DB;
        return (int) $DB->insert_record('stackmastery_steps', (object) array_merge([
            'attemptid'             => $attemptid,
            'seq'                   => $seq,
            'slot'                  => $seq,
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
        ], $overrides));
    }

    /**
     * Insert a pool snapshot row.
     *
     * @param int $attemptid Attempt id.
     * @return int The snapshot row id.
     */
    private function insert_snapshot(int $attemptid): int {
        global $DB;
        return (int) $DB->insert_record('stackmastery_pool_snapshot', (object) [
            'attemptid' => $attemptid, 'skill' => 'differentiate', 'difficulty' => 'easy',
            'questionbankentryid' => 1, 'questionid' => 1, 'questionversion' => 1,
            'timeserved' => null, 'invalid' => 0, 'timecreated' => time(),
        ]);
    }

    /**
     * Full fixture: a saved usage plus attempt, two steps and one snapshot row for a user.
     *
     * @param \stdClass $made The make_instance() bundle.
     * @param \stdClass $user The attempt owner.
     * @param array $overrides Attempt field overrides.
     * @return \stdClass Object with attemptid and qubaid.
     */
    private function make_fixture(\stdClass $made, \stdClass $user, array $overrides = []): \stdClass {
        $qubaid = $this->make_usage($made->context, $user);
        $attemptid = $this->insert_attempt(
            (int) $made->instance->id,
            (int) $user->id,
            $overrides + ['qubaid' => $qubaid]
        );
        $this->insert_step($attemptid, 1);
        $this->insert_step($attemptid, 2, ['correct' => 0, 'fraction' => 0.0]);
        $this->insert_snapshot($attemptid);
        return (object) ['attemptid' => $attemptid, 'qubaid' => $qubaid];
    }

    /**
     * Record activity by a user inside an existing usage: a manual question_attempt_steps row.
     *
     * @param int $qubaid The usage id.
     * @param int $userid The acting user.
     * @return void
     */
    private function add_quba_step(int $qubaid, int $userid): void {
        global $DB;
        $qa = $DB->get_record('question_attempts', ['questionusageid' => $qubaid], '*', MUST_EXIST);
        $maxseq = (int) $DB->get_field(
            'question_attempt_steps',
            'MAX(sequencenumber)',
            ['questionattemptid' => $qa->id]
        );
        $DB->insert_record('question_attempt_steps', (object) [
            'questionattemptid' => $qa->id,
            'sequencenumber'    => $maxseq + 1,
            'state'             => 'complete',
            'fraction'          => null,
            'timecreated'       => time(),
            'userid'            => $userid,
        ]);
    }

    /**
     * The metadata declares the three tables, the core_question link and the export disclosure.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('mod_stackmastery'));
        $items = $collection->get_collection();
        $this->assertCount(5, $items);

        $tables = [];
        $subsystems = [];
        $external = [];
        foreach ($items as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                $tables[$item->get_name()] = $item;
            } else if ($item instanceof \core_privacy\local\metadata\types\subsystem_link) {
                $subsystems[] = $item->get_name();
            } else if ($item instanceof \core_privacy\local\metadata\types\external_location) {
                $external[] = $item->get_name();
            }
        }
        $this->assertEqualsCanonicalizing(
            ['stackmastery_attempts', 'stackmastery_steps', 'stackmastery_pool_snapshot'],
            array_keys($tables)
        );
        $this->assertSame(['core_question'], $subsystems);
        $this->assertSame(['exportfiles'], $external);

        // Spot-check declared fields against the schema's user-data columns.
        $this->assertArrayHasKey('userid', $tables['stackmastery_attempts']->get_privacy_fields());
        $this->assertArrayHasKey('masteryfinal', $tables['stackmastery_attempts']->get_privacy_fields());
        $this->assertArrayHasKey('masterybefore', $tables['stackmastery_steps']->get_privacy_fields());
        $this->assertArrayHasKey('recommendeddifficulty', $tables['stackmastery_steps']->get_privacy_fields());
        $this->assertArrayHasKey('questionbankentryid', $tables['stackmastery_pool_snapshot']->get_privacy_fields());
    }

    /**
     * Attempt owners are found by context discovery; users without data are not.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        $made = $this->make_instance();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $this->make_fixture($made, $usera);

        $contextlist = provider::get_contexts_for_userid((int) $usera->id);
        $this->assertCount(1, $contextlist);
        $this->assertContains((int) $made->context->id, array_map('intval', $contextlist->get_contextids()));

        $this->assertEmpty(provider::get_contexts_for_userid((int) $userb->id)->get_contextids());
    }

    /**
     * Regression: a user whose ONLY trace is a question_attempt_steps row inside one of our
     * usages (they own no attempt) is still located.
     *
     * @return void
     */
    public function test_get_contexts_for_userid_via_question_usage(): void {
        $made = $this->make_instance();
        $owner = $this->getDataGenerator()->create_user();
        $marker = $this->getDataGenerator()->create_user();
        $fixture = $this->make_fixture($made, $owner);
        $this->add_quba_step($fixture->qubaid, (int) $marker->id);

        $contextlist = provider::get_contexts_for_userid((int) $marker->id);
        $this->assertContains((int) $made->context->id, array_map('intval', $contextlist->get_contextids()));
    }

    /**
     * The userlist contains attempt owners and question-usage actors, and nobody else.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $one = $this->make_instance();
        $two = $this->make_instance();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $marker = $this->getDataGenerator()->create_user();
        $fixture = $this->make_fixture($one, $usera);
        $this->add_quba_step($fixture->qubaid, (int) $marker->id);
        $this->make_fixture($two, $userb);

        $userlist = new userlist($one->context, 'mod_stackmastery');
        provider::get_users_in_context($userlist);
        $ids = array_map('intval', $userlist->get_userids());
        $this->assertContains((int) $usera->id, $ids);
        $this->assertContains((int) $marker->id, $ids);
        $this->assertNotContains((int) $userb->id, $ids);
    }

    /**
     * The export contains the attempt record, its steps, its pool snapshot and, via
     * core_question, the actual question the user answered.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        $made = $this->make_instance();
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        $this->make_fixture($made, $user, [
            'state'          => attempt_store::STATE_COMPLETE,
            'inprogressuniq' => 1,
            'finishreason'   => 'target',
            'reachedtarget'  => 1,
            'questionsdone'  => 2,
            'masteryfinal'   => json_encode(array_fill_keys(skills::CODES, 0.96)),
            'timefinish'     => $now,
        ]);

        provider::export_user_data(new approved_contextlist($user, 'mod_stackmastery', [$made->context->id]));

        $writer = writer::with_context($made->context);
        $this->assertTrue($writer->has_any_data());

        $subcontext = [get_string('attempts', 'mod_stackmastery'), '1'];
        $data = $writer->get_data($subcontext);
        $this->assertNotEmpty((array) $data);
        $this->assertSame(1, $data->attemptnumber);
        $this->assertSame(attempt_store::STATE_COMPLETE, $data->state);
        $this->assertSame('target', $data->finishreason);
        $this->assertSame(transform::yesno(1), $data->reachedtarget);
        $this->assertSame(12, $data->budget);
        $this->assertNotNull($data->timefinish);

        // Steps: both rows, in order, with decoded 8-key mastery objects.
        $this->assertCount(2, $data->steps);
        $this->assertSame(1, $data->steps[0]->seq);
        $this->assertSame(2, $data->steps[1]->seq);
        $this->assertSame(transform::yesno(1), $data->steps[0]->correct);
        $this->assertSame(transform::yesno(0), $data->steps[1]->correct);
        $this->assertCount(8, (array) $data->steps[0]->masterybefore);
        $this->assertEqualsWithDelta(0.2, ((array) $data->steps[0]->masterybefore)['differentiate'], 1e-9);

        // The final mastery vector decoded from the attempt row.
        $this->assertCount(8, (array) $data->finalmastery);

        // The pool snapshot rows.
        $this->assertCount(1, $data->poolsnapshot);
        $this->assertSame('differentiate', $data->poolsnapshot[0]->skill);

        // The question usage was exported through core_question under the same subcontext.
        $questiondata = $writer->get_data(
            array_merge($subcontext, [get_string('questions', 'core_question'), 1])
        );
        $this->assertNotEmpty((array) $questiondata);
    }

    /**
     * A user who only acted inside someone else's usage does not receive the owner's attempt
     * record; the export still completes.
     *
     * @return void
     */
    public function test_export_related_user_gets_no_attempt_data(): void {
        $made = $this->make_instance();
        $owner = $this->getDataGenerator()->create_user();
        $marker = $this->getDataGenerator()->create_user();
        $fixture = $this->make_fixture($made, $owner);
        $this->add_quba_step($fixture->qubaid, (int) $marker->id);

        provider::export_user_data(new approved_contextlist($marker, 'mod_stackmastery', [$made->context->id]));

        $writer = writer::with_context($made->context);
        $data = $writer->get_data([get_string('attempts', 'mod_stackmastery'), '1']);
        $this->assertEmpty((array) $data);
    }

    /**
     * A corrupt JSON column can never fatal a subject access request: it is exported raw.
     *
     * @return void
     */
    public function test_export_tolerates_corrupt_json(): void {
        $made = $this->make_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->make_fixture($made, $user, ['masteryfinal' => '{oops']);

        provider::export_user_data(new approved_contextlist($user, 'mod_stackmastery', [$made->context->id]));

        $data = writer::with_context($made->context)->get_data([get_string('attempts', 'mod_stackmastery'), '1']);
        $this->assertSame('{oops', $data->finalmastery);
    }

    /**
     * Context-wide deletion removes every user's rows and usages in that module only; a
     * non-module context is a no-op.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $one = $this->make_instance();
        $two = $this->make_instance();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $fixturea = $this->make_fixture($one, $usera);
        $fixtureb = $this->make_fixture($one, $userb);
        $other = $this->make_fixture($two, $usera);

        // A non-module context is ignored.
        provider::delete_data_for_all_users_in_context(\context_course::instance($one->course->id));
        $this->assertSame(2, $DB->count_records('stackmastery_attempts', ['stackmasteryid' => $one->instance->id]));

        provider::delete_data_for_all_users_in_context($one->context);

        $this->assertSame(0, $DB->count_records('stackmastery_attempts', ['stackmasteryid' => $one->instance->id]));
        foreach ([$fixturea, $fixtureb] as $fixture) {
            $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $fixture->attemptid]));
            $this->assertSame(0, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => $fixture->attemptid]));
            $this->assertFalse($DB->record_exists('question_usages', ['id' => $fixture->qubaid]));
        }

        // The other instance is untouched.
        $this->assertTrue($DB->record_exists('stackmastery_attempts', ['id' => $other->attemptid]));
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $other->qubaid]));
    }

    /**
     * Per-user deletion removes exactly that user's data, usages included; others keep theirs.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $made = $this->make_instance();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $fixturea = $this->make_fixture($made, $usera);
        $fixtureb = $this->make_fixture($made, $userb);

        provider::delete_data_for_user(new approved_contextlist($usera, 'mod_stackmastery', [$made->context->id]));

        $this->assertFalse($DB->record_exists('stackmastery_attempts', ['id' => $fixturea->attemptid]));
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $fixturea->attemptid]));
        $this->assertSame(0, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => $fixturea->attemptid]));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $fixturea->qubaid]));

        $this->assertTrue($DB->record_exists('stackmastery_attempts', ['id' => $fixtureb->attemptid]));
        $this->assertSame(2, $DB->count_records('stackmastery_steps', ['attemptid' => $fixtureb->attemptid]));
        $this->assertSame(1, $DB->count_records('stackmastery_pool_snapshot', ['attemptid' => $fixtureb->attemptid]));
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $fixtureb->qubaid]));
    }

    /**
     * Userlist deletion removes exactly the approved users' data in the context.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $made = $this->make_instance();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $fixturea = $this->make_fixture($made, $usera);
        $fixtureb = $this->make_fixture($made, $userb);

        provider::delete_data_for_users(
            new approved_userlist($made->context, 'mod_stackmastery', [(int) $usera->id])
        );

        $this->assertFalse($DB->record_exists('stackmastery_attempts', ['id' => $fixturea->attemptid]));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $fixturea->qubaid]));
        $this->assertTrue($DB->record_exists('stackmastery_attempts', ['id' => $fixtureb->attemptid]));
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $fixtureb->qubaid]));
    }
}
