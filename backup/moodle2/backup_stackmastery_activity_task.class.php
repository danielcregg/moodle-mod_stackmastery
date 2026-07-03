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
 * Backup task for the stackmastery activity module.
 *
 * @package    mod_stackmastery
 * @category   backup
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/stackmastery/backup/moodle2/backup_stackmastery_stepslib.php');

/**
 * Provides the steps to perform one complete backup of a STACK Mastery instance.
 *
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_stackmastery_activity_task extends backup_activity_task {
    /**
     * No specific settings for this activity.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Defines the backup steps: the structure step plus the two question steps
     * required by every activity that owns question usages (the mod_quiz pattern).
     *
     * @return void
     */
    protected function define_my_steps() {
        // Generate the stackmastery.xml file with the instance data, the attempts
        // (with their question usages) and annotate every used question.
        $this->add_step(new backup_stackmastery_activity_structure_step('stackmastery_structure', 'stackmastery.xml'));

        // Process all the annotated questions to calculate the question categories
        // needing to be included in the backup for this activity, plus the categories
        // belonging to the activity context itself.
        $this->add_step(new backup_calculate_question_categories('activity_question_categories'));

        // Clean the backup_temp_ids table of question annotations; they have already
        // been used to detect the categories and are not needed any more.
        $this->add_step(new backup_delete_temp_questions('clean_temp_questions'));
    }

    /**
     * Encodes URLs to the index.php, view.php and report.php scripts.
     *
     * @param string $content some HTML text that eventually contains URLs to the activity instance scripts
     * @return string the content with the URLs encoded
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to the list of stackmastery instances in a course.
        $search = "/({$base}\/mod\/stackmastery\/index.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@STACKMASTERYINDEX*$2@$', $content);

        // Link to a stackmastery view by course module id.
        $search = "/({$base}\/mod\/stackmastery\/view.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@STACKMASTERYVIEWBYID*$2@$', $content);

        // Link to a stackmastery report by course module id.
        $search = "/({$base}\/mod\/stackmastery\/report.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@STACKMASTERYREPORTBYID*$2@$', $content);

        return $content;
    }
}
