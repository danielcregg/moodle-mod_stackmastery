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
 * Tests for lib.php: supports matrix, CRUD, grades, reset.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery;

use mod_stackmastery\local\attempt_store;
use mod_stackmastery\local\grades;
use mod_stackmastery\local\skills;

/**
 * lib.php hook tests.
 *
 * @covers ::stackmastery_supports
 * @covers ::stackmastery_add_instance
 * @covers ::stackmastery_update_instance
 * @covers ::stackmastery_delete_instance
 * @covers ::stackmastery_update_grades
 * @covers ::stackmastery_reset_userdata
 * @covers ::stackmastery_get_coursemodule_info
 */
final class lib_test extends \advanced_testcase {
    /**
     * Common setup.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/stackmastery/lib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');
        require_once($CFG->libdir . '/gradelib.php');
    }

    /**
     * Course + pool + instance fixture.
     *
     * @param array $overrides Instance overrides.
     * @return \stdClass Object with course, pool, instance (fresh record), cm, context.
     */
    private function make(array $overrides = []): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $pool = $this->getDataGenerator()->get_plugin_generator('mod_stackmastery')->create_pool([
            'course' => $course->id, 'skills' => ['differentiate'], 'percell' => 1,
        ]);
        $module = $this->getDataGenerator()->create_module('stackmastery', array_merge([
            'course' => $course->id, 'poolcategoryid' => $pool->category->id,
        ], $overrides));
        $cm = get_coursemodule_from_instance('stackmastery', $module->id);
        $instance = $DB->get_record('stackmastery', ['id' => $module->id], '*', MUST_EXIST);
        return (object) [
            'course'   => $course,
            'pool'     => $pool,
            'instance' => $instance,
            'cmid'     => (int) $cm->id,
            'context'  => \context_module::instance($cm->id),
        ];
    }

    /**
     * Insert a finished attempt with a real question usage.
     *
     * @param \stdClass $made The fixture.
     * @param int $userid The user.
     * @param array $overrides Field overrides.
     * @return int The attempt id.
     */
    private function insert_attempt(\stdClass $made, int $userid, array $overrides = []): int {
        global $DB;
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question(
            'shortanswer',
            'frogtoad',
            ['category' => $made->pool->category->id]
        );
        $quba = \question_engine::make_questions_usage_by_activity('mod_stackmastery', $made->context);
        $quba->set_preferred_behaviour('deferredfeedback');
        $quba->add_question(\question_bank::load_question($question->id));
        $quba->start_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);

        $now = time();
        $attemptid = (int) $DB->insert_record('stackmastery_attempts', (object) array_merge([
            'stackmasteryid'  => $made->instance->id,
            'userid'          => $userid,
            'attemptnumber'   => 1,
            'qubaid'          => $quba->get_id(),
            'state'           => attempt_store::STATE_COMPLETE,
            'inprogressuniq'  => 1,
            'currentslot'     => 0,
            'preview'         => 0,
            'masterycurrent'  => json_encode(array_fill_keys(skills::CODES, 0.5)),
            'skillssnapshot'  => 'differentiate',
            'targetsnapshot'  => json_encode(array_fill_keys(skills::CODES, 0.95)),
            'budget'          => 40,
            'questionsdone'   => 1,
            'reachedtarget'   => 0,
            'masteryfinal'    => json_encode(array_fill_keys(skills::CODES, 0.5)),
            'policyversion'   => 'p',
            'bktmodelversion' => 'b',
            'timeexported'    => 0,
            'timestart'       => $now - 60,
            'timefinish'      => $now,
            'timemodified'    => $now,
        ], $overrides));
        $DB->insert_record('stackmastery_steps', (object) [
            'attemptid'             => $attemptid,
            'seq'                   => 1,
            'slot'                  => 1,
            'questionid'            => $question->id,
            'questionbankentryid'   => 1,
            'questionversion'       => 1,
            'variant'               => 1,
            'recommendedskill'      => 'differentiate',
            'recommendeddifficulty' => 'easy',
            'servedskill'           => 'differentiate',
            'serveddifficulty'      => 'easy',
            'actionsource'          => 'policy',
            'propensity'            => 1,
            'masterybefore'         => json_encode(array_fill_keys(skills::CODES, 0.2)),
            'correct'               => 1,
            'fraction'              => 1.0,
            'masteryafter'          => json_encode(array_fill_keys(skills::CODES, 0.5)),
            'policyversion'         => 'p',
            'bktmodelversion'       => 'b',
            'stateencodingversion'  => 'enc-1',
            'rewardversion'         => 'reward-1',
            'timeanswered'          => $now,
        ]);
        $DB->insert_record('stackmastery_pool_snapshot', (object) [
            'attemptid' => $attemptid, 'skill' => 'differentiate', 'difficulty' => 'easy',
            'questionbankentryid' => 1, 'questionid' => $question->id, 'questionversion' => 1,
            'timeserved' => $now, 'invalid' => 0, 'timecreated' => $now - 60,
        ]);
        return $attemptid;
    }

    /**
     * Every decided supports() value is pinned against drift.
     *
     * @return void
     */
    public function test_supports_matrix(): void {
        $this->assertFalse(stackmastery_supports(FEATURE_GROUPS));
        $this->assertFalse(stackmastery_supports(FEATURE_GROUPINGS));
        $this->assertTrue(stackmastery_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(stackmastery_supports(FEATURE_SHOW_DESCRIPTION));
        $this->assertTrue(stackmastery_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertTrue(stackmastery_supports(FEATURE_COMPLETION_HAS_RULES));
        $this->assertTrue(stackmastery_supports(FEATURE_MODEDIT_DEFAULT_COMPLETION));
        $this->assertTrue(stackmastery_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertFalse(stackmastery_supports(FEATURE_GRADE_OUTCOMES));
        $this->assertFalse(stackmastery_supports(FEATURE_CONTROLS_GRADE_VISIBILITY));
        $this->assertFalse(stackmastery_supports(FEATURE_ADVANCED_GRADING));
        $this->assertTrue(stackmastery_supports(FEATURE_USES_QUESTIONS));
        $this->assertFalse(stackmastery_supports(FEATURE_PLAGIARISM));
        $this->assertTrue(stackmastery_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertSame(MOD_PURPOSE_ASSESSMENT, stackmastery_supports(FEATURE_MOD_PURPOSE));
        $this->assertNull(stackmastery_supports('nonexistentfeature'));
    }

    /**
     * add_instance snapshots the admin epsilon (clamped); later admin edits never touch it.
     *
     * @return void
     */
    public function test_add_instance_snapshots_epsilon(): void {
        global $DB;
        set_config('epsilon', '0.11', 'mod_stackmastery');
        $made = $this->make();
        $this->assertEqualsWithDelta(0.11, (float) $made->instance->epsilon, 1e-6);

        set_config('epsilon', '0.2', 'mod_stackmastery');
        $fresh = $DB->get_record('stackmastery', ['id' => $made->instance->id]);
        $this->assertEqualsWithDelta(0.11, (float) $fresh->epsilon, 1e-6);

        // Out-of-range admin values are clamped into [0, 0.2] at snapshot time.
        set_config('epsilon', '0.9', 'mod_stackmastery');
        $clamped = $this->make();
        $this->assertEqualsWithDelta(0.2, (float) $clamped->instance->epsilon, 1e-6);

        // An update never re-snapshots epsilon.
        set_config('epsilon', '0.05', 'mod_stackmastery');
        $data = clone $clamped->instance;
        $data->instance = $data->id;
        $data->coursemodule = $clamped->cmid;
        stackmastery_update_instance($data);
        $fresh = $DB->get_record('stackmastery', ['id' => $clamped->instance->id]);
        $this->assertEqualsWithDelta(0.2, (float) $fresh->epsilon, 1e-6);
    }

    /**
     * Flipping grademode regrades existing attempts from the stored raw components.
     *
     * @return void
     */
    public function test_update_instance_regrades_on_grademode_change(): void {
        global $DB;
        $made = $this->make(['grademode' => grades::GRADEMODE_REACHEDTARGET]);
        $student = $this->getDataGenerator()->create_and_enrol($made->course, 'student');
        $this->insert_attempt($made, (int) $student->id);
        stackmastery_update_grades($made->instance, (int) $student->id);

        $grading = grade_get_grades(
            $made->course->id,
            'mod',
            'stackmastery',
            $made->instance->id,
            $student->id
        );
        $this->assertEqualsWithDelta(0.0, (float) $grading->items[0]->grades[$student->id]->grade, 1e-6);

        $data = clone $made->instance;
        $data->instance = $data->id;
        $data->coursemodule = $made->cmid;
        $data->grademode = grades::GRADEMODE_MEANMASTERY;
        stackmastery_update_instance($data);

        $grading = grade_get_grades(
            $made->course->id,
            'mod',
            'stackmastery',
            $made->instance->id,
            $student->id
        );
        $this->assertEqualsWithDelta(50.0, (float) $grading->items[0]->grades[$student->id]->grade, 1e-6);
    }

    /**
     * delete_instance cascades: module tables empty, question usages gone, grade item deleted.
     *
     * @return void
     */
    public function test_delete_instance_cascades(): void {
        global $DB;
        $made = $this->make();
        $student = $this->getDataGenerator()->create_and_enrol($made->course, 'student');
        $attemptid = $this->insert_attempt($made, (int) $student->id);
        $qubaid = (int) $DB->get_field('stackmastery_attempts', 'qubaid', ['id' => $attemptid]);
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $qubaid]));

        // The recycle bin backs the module up before deletion, which needs the backup task
        // classes that land with the backup/restore work package. Deletion semantics under
        // test here are stackmastery_delete_instance's, not the bin's.
        set_config('coursebinenable', 0, 'tool_recyclebin');

        course_delete_module($made->cmid);

        $this->assertFalse($DB->record_exists('stackmastery', ['id' => $made->instance->id]));
        $this->assertSame(0, $DB->count_records(
            'stackmastery_attempts',
            ['stackmasteryid' => $made->instance->id]
        ));
        $this->assertSame(0, $DB->count_records('stackmastery_steps', ['attemptid' => $attemptid]));
        $this->assertSame(0, $DB->count_records(
            'stackmastery_pool_snapshot',
            ['attemptid' => $attemptid]
        ));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $qubaid]));
        $this->assertEmpty(grade_get_grades(
            $made->course->id,
            'mod',
            'stackmastery',
            $made->instance->id
        )->items);
    }

    /**
     * Course reset deletes attempts (usages included) and resets the gradebook, in this course only.
     *
     * @return void
     */
    public function test_reset_userdata(): void {
        global $DB;
        $made = $this->make();
        $other = $this->make();
        $student = $this->getDataGenerator()->create_and_enrol($made->course, 'student');
        $attemptid = $this->insert_attempt($made, (int) $student->id);
        $otherattempt = $this->insert_attempt($other, (int) $student->id);
        stackmastery_update_grades($made->instance, (int) $student->id);

        $data = (object) [
            'courseid'                    => $made->course->id,
            'reset_stackmastery_attempts' => 1,
        ];
        $status = stackmastery_reset_userdata($data);
        $this->assertNotEmpty($status);
        $this->assertFalse($status[0]['error']);

        $this->assertSame(0, $DB->count_records(
            'stackmastery_attempts',
            ['stackmasteryid' => $made->instance->id]
        ));
        // The other course's instance is untouched.
        $this->assertTrue($DB->record_exists('stackmastery_attempts', ['id' => $otherattempt]));
        $this->assertFalse($DB->record_exists('stackmastery_attempts', ['id' => $attemptid]));
    }

    /**
     * get_coursemodule_info exposes the completion rule value only under automatic completion.
     *
     * @return void
     */
    public function test_get_coursemodule_info(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $pool = $this->getDataGenerator()->get_plugin_generator('mod_stackmastery')->create_pool([
            'course' => $course->id, 'skills' => ['differentiate'], 'percell' => 1,
        ]);
        $module = $this->getDataGenerator()->create_module('stackmastery', [
            'course'                  => $course->id,
            'poolcategoryid'          => $pool->category->id,
            'completion'              => COMPLETION_TRACKING_AUTOMATIC,
            'completionreachedtarget' => 1,
        ]);
        $cm = $DB->get_record('course_modules', ['id' => $module->cmid], '*', MUST_EXIST);
        $cm->instance = $module->id;
        $cm->showdescription = 0;

        $info = stackmastery_get_coursemodule_info($cm);
        $this->assertSame(
            1,
            (int) $info->customdata['customcompletionrules']['completionreachedtarget']
        );

        $cm->completion = COMPLETION_TRACKING_NONE;
        $info = stackmastery_get_coursemodule_info($cm);
        $this->assertTrue(empty($info->customdata['customcompletionrules']));
    }
}
