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
 * Restore task for the stackmastery activity module.
 *
 * @package    mod_stackmastery
 * @category   backup
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/stackmastery/backup/moodle2/restore_stackmastery_stepslib.php');

/**
 * Provides the settings and steps to perform one complete restore of a STACK Mastery instance.
 *
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_stackmastery_activity_task extends restore_activity_task {
    /**
     * No specific settings for this activity.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Defines the restore steps: stackmastery only has one structure step.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_stackmastery_activity_structure_step('stackmastery_structure', 'stackmastery.xml'));
    }

    /**
     * Defines the contents in the activity that must be processed by the link decoder.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        $contents = [];

        $contents[] = new restore_decode_content('stackmastery', ['intro'], 'stackmastery');

        return $contents;
    }

    /**
     * Defines the decoding rules for links belonging to the activity, mirroring
     * the encoders in backup_stackmastery_activity_task::encode_content_links().
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        $rules = [];

        $rules[] = new restore_decode_rule(
            'STACKMASTERYVIEWBYID',
            '/mod/stackmastery/view.php?id=$1',
            'course_module'
        );
        $rules[] = new restore_decode_rule(
            'STACKMASTERYREPORTBYID',
            '/mod/stackmastery/report.php?id=$1',
            'course_module'
        );
        $rules[] = new restore_decode_rule(
            'STACKMASTERYINDEX',
            '/mod/stackmastery/index.php?id=$1',
            'course'
        );

        return $rules;
    }

    /**
     * Defines the restore log rules applied by the restore_logs_processor when
     * restoring stackmastery logs. Minimal set: this module has no legacy logs.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules() {
        $rules = [];

        $rules[] = new restore_log_rule(
            'stackmastery',
            'add',
            'view.php?id={course_module}',
            '{stackmastery}'
        );
        $rules[] = new restore_log_rule(
            'stackmastery',
            'update',
            'view.php?id={course_module}',
            '{stackmastery}'
        );
        $rules[] = new restore_log_rule(
            'stackmastery',
            'view',
            'view.php?id={course_module}',
            '{stackmastery}'
        );
        $rules[] = new restore_log_rule(
            'stackmastery',
            'report',
            'report.php?id={course_module}',
            '{stackmastery}'
        );

        return $rules;
    }

    /**
     * Defines the restore log rules applied by the restore_logs_processor when
     * restoring course logs. These are rules not linked to any module instance (cmid = 0).
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules_for_course() {
        $rules = [];

        $rules[] = new restore_log_rule('stackmastery', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}
