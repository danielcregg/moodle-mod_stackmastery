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
 * Tests for the mod_stackmastery settings form.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/stackmastery/mod_form.php');

/**
 * Settings-form tests.
 *
 * @covers \mod_stackmastery_mod_form
 */
final class mod_form_test extends \advanced_testcase {
    /**
     * Build the add-instance form exactly as course/modedit.php does for a new activity.
     *
     * @param \stdClass $course The course to add into.
     * @return \MoodleQuickForm The inner QuickForm.
     */
    private function build_add_form(\stdClass $course): \MoodleQuickForm {
        global $DB;
        $module = $DB->get_record('modules', ['name' => 'stackmastery'], '*', MUST_EXIST);
        $data = (object) [
            'section' => 0,
            'course' => $course->id,
            'module' => $module->id,
            'modulename' => 'stackmastery',
            'add' => 'stackmastery',
            'return' => 0,
            'sr' => 0,
            'update' => 0,
            'instance' => 0,
            'coursemodule' => 0,
        ];
        $form = new \mod_stackmastery_mod_form($data, 0, null, $course);
        return (function () {
            return $this->_form;
        })->call($form);
    }

    /**
     * Regression guard for a live-only fatal (2026-07-03): the custom-topic input must be a
     * TOP-LEVEL form element.
     *
     * definition_after_data() clears the box with setConstant('newtopic', '') after a topic is
     * added, and Moodle's setConstant only supports top-level element names; a grouped member
     * resolves to an HTML_QuickForm_Error whose onQuickFormEvent() call fatals. Adding any custom
     * topic used to crash the form this way. Topic-free Behat never exercised the clear path, so
     * this unit guard pins the element placement instead.
     *
     * @return void
     */
    public function test_newtopic_is_top_level_so_setconstant_survives(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $mform = $this->build_add_form($course);

        $newtopic = $mform->getElement('newtopic');
        $this->assertNotInstanceOf(
            \HTML_QuickForm_Error::class,
            $newtopic,
            'newtopic must be a top-level element so definition_after_data can setConstant it.'
        );
        $checktopic = $mform->getElement('checktopic');
        $this->assertNotInstanceOf(\HTML_QuickForm_Error::class, $checktopic);

        // The exact call that fataled when newtopic was a group member; must be safe now.
        $mform->setConstant('newtopic', '');
        $this->addToAssertionCount(1);
    }
}
