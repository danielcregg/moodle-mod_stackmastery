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
 * Tests for the pool eligibility queries and the attempt-start snapshot.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Pool tests. All questions are shortanswer via the allowedqtypes test seam, so no CAS is needed.
 *
 * @covers \mod_stackmastery\local\pool
 */
final class pool_test extends \advanced_testcase {
    /**
     * Use shortanswer questions through the admin seam.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('allowedqtypes', 'shortanswer', 'mod_stackmastery');
    }

    /**
     * Create a course + tagged pool.
     *
     * @param string[] $skills Skill codes.
     * @param int $percell Questions per cell.
     * @return \stdClass Object with course and pool.
     */
    private function make_pool(array $skills, int $percell): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $pool = $this->getDataGenerator()->get_plugin_generator('mod_stackmastery')->create_pool([
            'course'  => $course->id,
            'skills'  => $skills,
            'percell' => $percell,
        ]);
        return (object) ['course' => $course, 'pool' => $pool];
    }

    /**
     * Insert a minimal attempt row so snapshot functions have a real attemptid to hang off.
     *
     * @param int $instanceid The stackmastery id.
     * @param int $userid The user id.
     * @return int The attempt id.
     */
    private function insert_attempt(int $instanceid, int $userid): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('stackmastery_attempts', (object) [
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
            'policyversion'   => 'test-policy',
            'bktmodelversion' => 'test-bkt',
            'timeexported'    => 0,
            'timestart'       => $now,
            'timefinish'      => 0,
            'timemodified'    => $now,
        ]);
    }

    /**
     * The canonical Moodle-side skill codes are the math core's, index for index.
     *
     * @return void
     */
    public function test_skills_codes_match_math_core(): void {
        $this->assertSame(bkt::SKILLS, skills::CODES);
        $this->assertSame(8, count(skills::CODES));
    }

    /**
     * Every production tag name survives core tag normalisation unchanged.
     *
     * @return void
     */
    public function test_tag_names_survive_normalisation(): void {
        $names = [];
        foreach (skills::CODES as $code) {
            $names[] = skills::skill_tag($code);
        }
        foreach (skills::DIFFICULTIES as $code) {
            $names[] = skills::diff_tag($code);
        }
        foreach ($names as $name) {
            $normalised = \core_tag_tag::normalize([$name], false);
            $this->assertSame($name, reset($normalised));
        }
    }

    /**
     * The cell-count matrix is exact and zero-filled; partial tagging, subcategories, other
     * question types and drafts are excluded.
     *
     * @return void
     */
    public function test_cell_counts(): void {
        global $DB;
        $made = $this->make_pool(['differentiate', 'integrate'], 2);
        $categoryid = (int) $made->pool->category->id;
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        // A question with only a skill tag is not counted.
        $skillonly = $questiongenerator->create_question(
            'shortanswer',
            'frogtoad',
            ['category' => $categoryid]
        );
        $questiongenerator->create_question_tag([
            'questionid' => $skillonly->id, 'tag' => skills::skill_tag('differentiate'),
        ]);

        // A fully tagged question in a subcategory is not counted (no subcategories in v1).
        $subcategory = $questiongenerator->create_question_category([
            'contextid' => \context_course::instance($made->course->id)->id,
            'parent'    => $categoryid,
        ]);
        $inchild = $questiongenerator->create_question(
            'shortanswer',
            'frogtoad',
            ['category' => $subcategory->id]
        );
        $questiongenerator->create_question_tag([
            'questionid' => $inchild->id, 'tag' => skills::skill_tag('differentiate'),
        ]);
        $questiongenerator->create_question_tag([
            'questionid' => $inchild->id, 'tag' => skills::diff_tag('easy'),
        ]);

        // A fully tagged question of a non-allowed type is not counted.
        $essay = $questiongenerator->create_question('essay', 'editor', ['category' => $categoryid]);
        $questiongenerator->create_question_tag([
            'questionid' => $essay->id, 'tag' => skills::skill_tag('differentiate'),
        ]);
        $questiongenerator->create_question_tag([
            'questionid' => $essay->id, 'tag' => skills::diff_tag('easy'),
        ]);

        // A draft-only question is not counted.
        $draft = $questiongenerator->create_question(
            'shortanswer',
            'frogtoad',
            ['category' => $categoryid]
        );
        $questiongenerator->create_question_tag([
            'questionid' => $draft->id, 'tag' => skills::skill_tag('differentiate'),
        ]);
        $questiongenerator->create_question_tag([
            'questionid' => $draft->id, 'tag' => skills::diff_tag('easy'),
        ]);
        $DB->set_field(
            'question_versions',
            'status',
            \core_question\local\bank\question_version_status::QUESTION_STATUS_DRAFT,
            ['questionid' => $draft->id]
        );

        $counts = pool::cell_counts($categoryid, ['differentiate', 'integrate', 'factor']);
        foreach (['differentiate', 'integrate'] as $skill) {
            foreach (skills::DIFFICULTIES as $difficulty) {
                $this->assertSame(2, $counts[$skill][$difficulty], "$skill/$difficulty");
            }
        }
        // The un-pooled skill row exists and is zero-filled.
        $this->assertSame(['easy' => 0, 'medium' => 0, 'hard' => 0], $counts['factor']);
    }

    /**
     * Tag matching is exact: a tag that merely extends a production tag name never matches.
     *
     * @return void
     */
    public function test_tag_match_is_exact(): void {
        $made = $this->make_pool(['integrate'], 1);
        $categoryid = (int) $made->pool->category->id;
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $decoy = $questiongenerator->create_question(
            'shortanswer',
            'frogtoad',
            ['category' => $categoryid]
        );
        $questiongenerator->create_question_tag([
            'questionid' => $decoy->id, 'tag' => skills::skill_tag('differentiate') . 'xtra',
        ]);
        $questiongenerator->create_question_tag([
            'questionid' => $decoy->id, 'tag' => skills::diff_tag('easy'),
        ]);

        $counts = pool::cell_counts($categoryid, ['differentiate']);
        $this->assertSame(0, $counts['differentiate']['easy']);
    }

    /**
     * validate_selection: empty cells hard-block naming every empty cell; thin cells warn
     * without erroring; a missing category is its own error.
     *
     * @return void
     */
    public function test_validate_selection(): void {
        $made = $this->make_pool(['differentiate'], 2);
        $categoryid = (int) $made->pool->category->id;

        // Fully covered but thin (2 per cell): warnings only.
        $result = pool::validate_selection($categoryid, ['differentiate']);
        $this->assertSame([], $result['errors']);
        $this->assertCount(3, $result['warnings']);

        // An un-pooled skill produces one error naming all three empty cells.
        $result = pool::validate_selection($categoryid, ['differentiate', 'factor']);
        $this->assertArrayHasKey('poolcategoryid', $result['errors']);
        $this->assertStringContainsString('factor/easy', $result['errors']['poolcategoryid']);
        $this->assertStringContainsString('factor/medium', $result['errors']['poolcategoryid']);
        $this->assertStringContainsString('factor/hard', $result['errors']['poolcategoryid']);

        // A vanished category is its own error.
        $result = pool::validate_selection(-1, ['differentiate']);
        $this->assertSame(
            get_string('errpoolcategorymissing', 'mod_stackmastery'),
            $result['errors']['poolcategoryid']
        );
    }

    /**
     * The snapshot pins versions at freeze time: later new questions and new versions never
     * enter a running attempt; draws come only from the snapshot; served entries stop being
     * eligible; terminal deletion clears the rows.
     *
     * @return void
     */
    public function test_snapshot_freezes_versions(): void {
        global $DB;
        $made = $this->make_pool(['differentiate'], 2);
        $categoryid = (int) $made->pool->category->id;
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $instance = $this->getDataGenerator()->create_module('stackmastery', [
            'course'         => $made->course->id,
            'poolcategoryid' => $categoryid,
            'skills'         => 'differentiate',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $attemptid = $this->insert_attempt((int) $instance->id, (int) $user->id);

        $summary = pool::build_snapshot($instance, $attemptid, ['differentiate']);
        $this->assertSame(2, $summary['cells']['differentiate']['easy']);
        $this->assertSame(6, $summary['distinct']);

        // Freeze reference data, then mutate the live pool.
        $frozen = $DB->get_records('stackmastery_pool_snapshot', ['attemptid' => $attemptid]);
        $this->assertCount(6, $frozen);
        $original = $made->pool->questions['differentiate']['easy'][0];
        // Note update_question creates a new version WITHOUT copying tags (the editing UI
        // copies them via the form). Re-tag the new version explicitly: eligibility is strictly
        // "latest READY version carries both tags" (master plan R6), so an untagged new
        // version would - correctly - drop the entry from the pool.
        $newversion = $questiongenerator->update_question($original, null, ['name' => 'New version']);
        $questiongenerator->create_question_tag([
            'questionid' => $newversion->id, 'tag' => skills::skill_tag('differentiate'),
        ]);
        $questiongenerator->create_question_tag([
            'questionid' => $newversion->id, 'tag' => skills::diff_tag('easy'),
        ]);
        $extra = $questiongenerator->create_question(
            'shortanswer',
            'frogtoad',
            ['category' => $categoryid]
        );
        $questiongenerator->create_question_tag([
            'questionid' => $extra->id, 'tag' => skills::skill_tag('differentiate'),
        ]);
        $questiongenerator->create_question_tag([
            'questionid' => $extra->id, 'tag' => skills::diff_tag('easy'),
        ]);

        // The snapshot is unchanged: same rows, original version ids.
        $after = $DB->get_records('stackmastery_pool_snapshot', ['attemptid' => $attemptid]);
        $this->assertEquals($frozen, $after);
        $versions = array_map('intval', array_column($after, 'questionid'));
        $this->assertContains((int) $original->id, $versions);

        // Draws return only snapshot rows of the requested cell.
        $eligible = pool::eligible_cells($attemptid);
        $this->assertSame(2, $eligible['differentiate']['easy']);
        $draw = pool::draw($attemptid, 'differentiate', 'easy');
        $this->assertNotNull($draw);
        $this->assertNotEquals((int) $extra->id, (int) $draw->questionid);

        // Serving an entry removes it from every cell it appears in.
        pool::mark_served($attemptid, (int) $draw->questionbankentryid, time());
        $eligible = pool::eligible_cells($attemptid);
        $this->assertSame(1, $eligible['differentiate']['easy']);
        $this->assertSame(5, pool::distinct_entry_count($attemptid));

        // Exhausting a cell yields null draws.
        $second = pool::draw($attemptid, 'differentiate', 'easy');
        pool::mark_served($attemptid, (int) $second->questionbankentryid, time());
        $this->assertNull(pool::draw($attemptid, 'differentiate', 'easy'));

        // A second attempt's snapshot sees the new question and the new version.
        $attempt2 = $this->insert_attempt((int) $instance->id, (int) $user->id + 1);
        $summary2 = pool::build_snapshot($instance, $attempt2, ['differentiate']);
        $this->assertSame(3, $summary2['cells']['differentiate']['easy']);
        $this->assertSame(7, $summary2['distinct']);

        pool::delete_snapshot($attemptid);
        $this->assertSame(0, $DB->count_records(
            'stackmastery_pool_snapshot',
            ['attemptid' => $attemptid]
        ));
        $this->assertSame(7, $DB->count_records(
            'stackmastery_pool_snapshot',
            ['attemptid' => $attempt2]
        ));
    }

    /**
     * mark_invalid removes a row from eligibility without deleting the audit row.
     *
     * @return void
     */
    public function test_mark_invalid(): void {
        global $DB;
        $made = $this->make_pool(['integrate'], 1);
        $instance = $this->getDataGenerator()->create_module('stackmastery', [
            'course'         => $made->course->id,
            'poolcategoryid' => $made->pool->category->id,
            'skills'         => 'integrate',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $attemptid = $this->insert_attempt((int) $instance->id, (int) $user->id);
        pool::build_snapshot($instance, $attemptid, ['integrate']);

        $row = pool::draw($attemptid, 'integrate', 'medium');
        pool::mark_invalid((int) $row->id);
        $this->assertNull(pool::draw($attemptid, 'integrate', 'medium'));
        $this->assertSame(1, $DB->count_records('stackmastery_pool_snapshot', [
            'attemptid' => $attemptid, 'skill' => 'integrate', 'difficulty' => 'medium',
        ]));
    }

    /**
     * cell_gaps is pure gap math on a counts map: only cells below the target appear, each with
     * exactly the missing count (the shared thin-cell definition of the pool builder and the
     * nightly refill task).
     *
     * @return void
     */
    public function test_cell_gaps(): void {
        $counts = [
            'differentiate' => ['easy' => 0, 'medium' => 2, 'hard' => 3],
            'integrate'     => ['easy' => 3, 'medium' => 4, 'hard' => 1],
        ];

        $this->assertSame([
            'differentiate' => ['easy' => 3, 'medium' => 1],
            'integrate'     => ['hard' => 2],
        ], pool::cell_gaps($counts, 3));

        // A target of 1 flags only empty cells.
        $this->assertSame([
            'differentiate' => ['easy' => 1],
        ], pool::cell_gaps($counts, 1));

        // A fully stocked map yields no gaps at all.
        $this->assertSame([], pool::cell_gaps($counts, 0));
        $this->assertSame([], pool::cell_gaps([], 3));
    }
}
