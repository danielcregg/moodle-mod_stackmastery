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
 * Data generator for mod_stackmastery.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_stackmastery\local\skills;

/**
 * mod_stackmastery module generator: instances (with named-pool resolution for Behat) and
 * tagged question pools.
 */
class mod_stackmastery_generator extends testing_module_generator {
    /**
     * Create a stackmastery instance.
     *
     * The record must carry either poolcategoryid (int) or poolcategory (a question category
     * NAME, resolved so Behat tables can reference categories by name).
     *
     * @param array|stdClass|null $record Instance fields.
     * @param array|null $options Course module options.
     * @return stdClass The instance record with cmid.
     */
    public function create_instance($record = null, ?array $options = null) {
        global $DB;
        $record = (object) (array) $record;

        if (!empty($record->poolcategory)) {
            $record->poolcategoryid = $DB->get_field(
                'question_categories',
                'id',
                ['name' => $record->poolcategory],
                MUST_EXIST
            );
            unset($record->poolcategory);
        }
        if (empty($record->poolcategoryid)) {
            throw new coding_exception(
                'mod_stackmastery generator requires poolcategoryid or poolcategory (a category name).'
            );
        }

        $defaults = [
            'skills'                  => implode(',', skills::CODES),
            'targetmastery'           => 0.95,
            'budget'                  => 40,
            'maxattempts'             => 0,
            'grademode'               => 0,
            'showprogress'            => 1,
            'timeopen'                => 0,
            'timeclose'               => 0,
            'completionreachedtarget' => 0,
        ];
        foreach ($defaults as $field => $value) {
            if (!isset($record->{$field})) {
                $record->{$field} = $value;
            }
        }
        return parent::create_instance($record, (array) $options);
    }

    /**
     * Build a tagged question pool: a category plus percell questions for every requested
     * (skill, difficulty) cell, carrying the two production tags.
     *
     * Spec keys (all optional except course/contextid): course (course id) or contextid,
     * skills (default all 8 canonical codes), difficulties (default easy/medium/hard),
     * percell (default 3), qtype (default shortanswer), which (default frogtoad),
     * categoryname (optional category name).
     *
     * @param array $spec The pool specification.
     * @return stdClass Object with ->category and ->questions[skill][difficulty] = question[].
     */
    public function create_pool(array $spec): stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');

        if (isset($spec['contextid'])) {
            $contextid = (int) $spec['contextid'];
        } else if (isset($spec['course'])) {
            $contextid = context_course::instance((int) $spec['course'])->id;
        } else {
            throw new coding_exception('create_pool requires course or contextid.');
        }

        $qtype = $spec['qtype'] ?? 'shortanswer';
        if (!question_bank::is_qtype_installed($qtype)) {
            throw new coding_exception("create_pool needs question type '{$qtype}' installed.");
        }
        $which = $spec['which'] ?? 'frogtoad';
        $percell = $spec['percell'] ?? 3;
        $skillcodes = $spec['skills'] ?? skills::CODES;
        $difficulties = $spec['difficulties'] ?? skills::DIFFICULTIES;

        /** @var core_question_generator $questiongenerator */
        $questiongenerator = $this->datagenerator->get_plugin_generator('core_question');
        $categoryrecord = ['contextid' => $contextid];
        if (isset($spec['categoryname'])) {
            $categoryrecord['name'] = $spec['categoryname'];
        }
        $category = $questiongenerator->create_question_category($categoryrecord);

        $questions = [];
        foreach ($skillcodes as $skill) {
            foreach ($difficulties as $difficulty) {
                $questions[$skill][$difficulty] = [];
                for ($i = 0; $i < $percell; $i++) {
                    $question = $questiongenerator->create_question(
                        $qtype,
                        $which,
                        ['category' => $category->id]
                    );
                    $questiongenerator->create_question_tag([
                        'questionid' => $question->id,
                        'tag'        => skills::skill_tag($skill),
                    ]);
                    $questiongenerator->create_question_tag([
                        'questionid' => $question->id,
                        'tag'        => skills::diff_tag($difficulty),
                    ]);
                    $version = $DB->get_record(
                        'question_versions',
                        ['questionid' => $question->id],
                        '*',
                        MUST_EXIST
                    );
                    $question->questionbankentryid = (int) $version->questionbankentryid;
                    $question->version = (int) $version->version;
                    $questions[$skill][$difficulty][] = $question;
                }
            }
        }
        return (object) ['category' => $category, 'questions' => $questions];
    }
}
