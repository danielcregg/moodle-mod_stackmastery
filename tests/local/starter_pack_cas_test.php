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
 * Real import of the shipped starter pack (CAS-gated, skip-guarded).
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Imports the shipped bank for real (qtype_stack required) and verifies tags, pool eligibility
 * and idempotency.
 *
 * Skips cleanly when qtype_stack or a QTYPE_STACK_TEST_CONFIG_* Maxima configuration is absent,
 * exactly like stack_cas_test (the plain CI matrix job installs qtype_stack but no CAS); the
 * advisory CAS job and the demo VM execute it for real. The tag plan itself is covered without
 * STACK by starter_pack_test.
 *
 * @covers \mod_stackmastery\local\starter_pack
 * @group stackmastery_cas
 */
final class starter_pack_cas_test extends \advanced_testcase {
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
     * The full starter import: 14 questions land tagged, fill every pool cell, and re-running
     * skips them all.
     *
     * @return void
     */
    public function test_import_tags_fills_every_cell_and_is_idempotent(): void {
        global $DB;
        set_config('allowedqtypes', 'stack', 'mod_stackmastery');

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $category = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question_category(['contextid' => $context->id]);
        $instance = $this->getDataGenerator()->create_module('stackmastery', [
            'course'         => $course->id,
            'poolcategoryid' => $category->id,
        ]);

        $counts = starter_pack::import($instance, $context);
        $this->assertSame(['imported' => 14, 'skipped' => 0, 'failed' => 0], $counts);

        // Every (skill, difficulty) cell of the pool is now stocked with at least one question.
        $cells = pool::cell_counts((int) $category->id, skills::CODES);
        foreach (skills::CODES as $skill) {
            foreach (skills::DIFFICULTIES as $difficulty) {
                $this->assertGreaterThanOrEqual(
                    1,
                    $cells[$skill][$difficulty],
                    "Starter pack left cell {$skill}/{$difficulty} empty."
                );
            }
        }

        // Tag spot checks: a multi-file type carries exactly its index difficulty...
        $tags = $this->tags_of((int) $category->id, 'Differentiate (sample 2, medium)');
        $this->assertContains(skills::skill_tag('differentiate'), $tags);
        $this->assertContains(skills::diff_tag('medium'), $tags);
        $this->assertNotContains(skills::diff_tag('easy'), $tags);
        $this->assertNotContains(skills::diff_tag('hard'), $tags);

        // ...a single-file type carries all three, and simplify crosses the vocabulary boundary.
        $tags = $this->tags_of((int) $category->id, 'Simplify to lowest terms (sample)');
        $this->assertContains(skills::skill_tag('simplify'), $tags);
        foreach (skills::DIFFICULTIES as $difficulty) {
            $this->assertContains(skills::diff_tag($difficulty), $tags);
        }

        // Idempotency: the same-name check skips every already-imported file.
        $again = starter_pack::import($instance, $context);
        $this->assertSame(['imported' => 0, 'skipped' => 14, 'failed' => 0], $again);
        $this->assertSame(14, $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {question_bank_entries} qbe
              WHERE qbe.questioncategoryid = :categoryid",
            ['categoryid' => $category->id]
        ));
    }

    /**
     * The tag names of the question with a given name in a category.
     *
     * @param int $categoryid The question category id.
     * @param string $name The question name.
     * @return string[] The raw tag names.
     */
    private function tags_of(int $categoryid, string $name): array {
        global $DB;
        $sql = "SELECT q.id
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 WHERE qbe.questioncategoryid = :categoryid AND q.name = :name";
        $qid = (int) $DB->get_field_sql($sql, ['categoryid' => $categoryid, 'name' => $name], MUST_EXIST);
        $tags = \core_tag_tag::get_item_tags('core_question', 'question', $qid);
        return array_values(array_map(fn($tag) => $tag->get_display_name(false), $tags));
    }
}
