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
 * Instance settings form for mod_stackmastery.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_stackmastery\local\grades;
use mod_stackmastery\local\pool;
use mod_stackmastery\local\skills;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * The mod_form: skills, target, pool category (with hard-block coverage validation), budget,
 * attempts, grading mode, progress visibility, open/close times and the completion rule.
 *
 * Exploration (epsilon) is deliberately absent: it is admin-level and snapshotted onto the
 * instance invisibly at creation.
 */
class mod_stackmastery_mod_form extends moodleform_mod {
    /**
     * Define the form elements.
     *
     * @return void
     */
    public function definition() {
        global $DB, $OUTPUT;
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        $mform->addElement('header', 'masterysettings', get_string('masterysettings', 'mod_stackmastery'));
        $mform->setExpanded('masterysettings');

        // One flat advcheckbox per canonical skill (groups plus nested arrays fight moodleform_mod).
        $boxes = [];
        foreach (skills::CODES as $code) {
            $boxes[] = $mform->createElement('advcheckbox', 'skill_' . $code, '', skills::label($code));
        }
        $mform->addGroup($boxes, 'skillsgroup', get_string('skills', 'mod_stackmastery'), '<br>', false);
        $mform->addHelpButton('skillsgroup', 'skills', 'mod_stackmastery');
        if (empty($this->_instance)) {
            foreach (skills::CODES as $code) {
                $mform->setDefault('skill_' . $code, 1);
            }
        }

        // String keys deliberately: floats as form select keys round-trip badly.
        $mform->addElement('select', 'targetmastery', get_string('targetmastery', 'mod_stackmastery'), [
            '0.95' => get_string('targetmastery_confident', 'mod_stackmastery'),
            '0.85' => get_string('targetmastery_working', 'mod_stackmastery'),
        ]);
        $mform->setType('targetmastery', PARAM_RAW);
        $mform->setDefault('targetmastery', '0.95');
        $mform->addHelpButton('targetmastery', 'targetmastery', 'mod_stackmastery');

        $coursecontext = context_course::instance($this->get_course()->id);
        $menu = [0 => get_string('choosedots')] + pool::category_menu($coursecontext);
        $mform->addElement('select', 'poolcategoryid', get_string('poolcategory', 'mod_stackmastery'), $menu);
        $mform->addHelpButton('poolcategoryid', 'poolcategory', 'mod_stackmastery');

        // Coverage table so a teacher sees the tagged-question counts before saving.
        if (!empty($this->_instance)) {
            $instance = $DB->get_record('stackmastery', ['id' => $this->_instance]);
            $coverage = '';
            if ($instance && $instance->poolcategoryid) {
                $selected = skills::decode_csv($instance->skills);
                $counts = pool::cell_counts((int) $instance->poolcategoryid, $selected);
                $coverage = $OUTPUT->render_from_template(
                    'mod_stackmastery/pool_coverage',
                    self::coverage_context($counts)
                );
            }
            $mform->addElement(
                'static',
                'poolcoverage',
                get_string('poolcoverage', 'mod_stackmastery'),
                $coverage
            );
        } else {
            $mform->addElement(
                'static',
                'poolcoverage',
                get_string('poolcoverage', 'mod_stackmastery'),
                get_string('poolcoverage_addhint', 'mod_stackmastery')
            );
        }

        $mform->addElement('text', 'budget', get_string('budget', 'mod_stackmastery'), ['size' => 4]);
        $mform->setType('budget', PARAM_INT);
        $mform->setDefault('budget', 40);
        $mform->addHelpButton('budget', 'budget', 'mod_stackmastery');

        $attemptoptions = [0 => get_string('unlimited', 'mod_stackmastery')];
        for ($i = 1; $i <= 10; $i++) {
            $attemptoptions[$i] = $i;
        }
        $mform->addElement(
            'select',
            'maxattempts',
            get_string('maxattempts', 'mod_stackmastery'),
            $attemptoptions
        );
        $mform->setDefault('maxattempts', 0);

        $mform->addElement('select', 'grademode', get_string('grademode', 'mod_stackmastery'), [
            grades::GRADEMODE_REACHEDTARGET => get_string('grademode_reachedtarget', 'mod_stackmastery'),
            grades::GRADEMODE_MEANMASTERY   => get_string('grademode_meanmastery', 'mod_stackmastery'),
        ]);
        $mform->setDefault('grademode', grades::GRADEMODE_REACHEDTARGET);
        $mform->addHelpButton('grademode', 'grademode', 'mod_stackmastery');

        $mform->addElement('advcheckbox', 'showprogress', get_string('showprogress', 'mod_stackmastery'));
        $mform->setDefault('showprogress', 1);
        $mform->addHelpButton('showprogress', 'showprogress', 'mod_stackmastery');

        $mform->addElement(
            'date_time_selector',
            'timeopen',
            get_string('timeopen', 'mod_stackmastery'),
            ['optional' => true]
        );
        $mform->addElement(
            'date_time_selector',
            'timeclose',
            get_string('timeclose', 'mod_stackmastery'),
            ['optional' => true]
        );

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Build the pool_coverage template context from a cell-count matrix.
     *
     * @param array<string, array<string, int>> $counts Map skill => difficulty => count.
     * @return stdClass The template context.
     */
    public static function coverage_context(array $counts): stdClass {
        $difficulties = [];
        foreach (skills::DIFFICULTIES as $difficulty) {
            $difficulties[] = ['label' => skills::difficulty_label($difficulty)];
        }
        $rows = [];
        foreach ($counts as $skill => $cells) {
            $row = ['name' => skills::label($skill), 'cells' => []];
            foreach (skills::DIFFICULTIES as $difficulty) {
                $count = $cells[$difficulty] ?? 0;
                $row['cells'][] = [
                    'count' => $count,
                    'empty' => $count === 0,
                    'thin'  => $count > 0 && $count < 3,
                ];
            }
            $rows[] = $row;
        }
        return (object) ['difficulties' => $difficulties, 'skills' => $rows];
    }

    /**
     * Prepare instance data for the form: csv to checkboxes, target float to its select key.
     *
     * @param array $defaultvalues Values loaded from the instance record, by reference.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        if (isset($defaultvalues['skills'])) {
            $selected = skills::decode_csv((string) $defaultvalues['skills']);
            foreach (skills::CODES as $code) {
                $defaultvalues['skill_' . $code] = in_array($code, $selected, true) ? 1 : 0;
            }
        }
        if (isset($defaultvalues['targetmastery'])) {
            $target = (float) $defaultvalues['targetmastery'];
            $defaultvalues['targetmastery'] = (abs($target - 0.85) < 1e-9) ? '0.85' : '0.95';
        }
    }

    /**
     * Post-process submitted data: checkboxes to csv, derive the target vector, completion guard.
     *
     * @param stdClass $data The submitted data (passed to add/update_instance afterwards).
     * @return void
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);

        $selected = [];
        foreach (skills::CODES as $code) {
            if (!empty($data->{'skill_' . $code})) {
                $selected[] = $code;
            }
        }
        $data->skills = skills::encode_csv($selected);
        $data->targetmastery = (float) $data->targetmastery;
        $data->targetvector = json_encode(array_fill_keys(skills::CODES, $data->targetmastery));

        // A hidden completion rule must not stay latched when completion is off (quiz pattern).
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $completion = $data->{'completion' . $suffix} ?? null;
            $autocompletion = !empty($completion) && $completion == COMPLETION_TRACKING_AUTOMATIC;
            if (!$autocompletion) {
                $data->{'completionreachedtarget' . $suffix} = 0;
            }
        }
    }

    /**
     * Server-side validation: skills non-empty, target whitelisted, budget in range, and the
     * pool coverage rule (hard-block empty cells, warn below 3 per cell).
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $selected = array_values(array_filter(
            skills::CODES,
            fn($code) => !empty($data['skill_' . $code])
        ));
        if ($selected === []) {
            $errors['skillsgroup'] = get_string('errnoskills', 'mod_stackmastery');
        }
        if (!in_array((string) $data['targetmastery'], ['0.85', '0.95'], true)) {
            $errors['targetmastery'] = get_string('errtargetmastery', 'mod_stackmastery');
        }
        $budget = (int) ($data['budget'] ?? 0);
        if ($budget < 1 || $budget > 500) {
            $errors['budget'] = get_string('errbudgetrange', 'mod_stackmastery');
        }
        if (empty($data['poolcategoryid'])) {
            $errors['poolcategoryid'] = get_string('errpoolcategorymissing', 'mod_stackmastery');
        }

        if ($selected !== [] && empty($errors['poolcategoryid'])) {
            $result = pool::validate_selection((int) $data['poolcategoryid'], $selected);
            $errors += $result['errors'];
            if (empty($result['errors'])) {
                // Thin cells warn without blocking: the session notification renders post-redirect.
                foreach ($result['warnings'] as $warning) {
                    \core\notification::add($warning, \core\output\notification::NOTIFY_WARNING);
                }
            }
        }
        return $errors;
    }

    /**
     * Add the custom completion rule element (Moodle 4.3+ suffix API).
     *
     * @return string[] The element names added.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();
        $element = 'completionreachedtarget' . $suffix;
        $mform->addElement(
            'advcheckbox',
            $element,
            '',
            get_string('completionreachedtarget', 'mod_stackmastery')
        );
        $mform->setDefault($element, 0);
        return [$element];
    }

    /**
     * Whether the custom completion rule is enabled in the submitted data.
     *
     * @param array $data Form data.
     * @return bool True when the rule is ticked.
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionreachedtarget' . $this->get_suffix()]);
    }
}
