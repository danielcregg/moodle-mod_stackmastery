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
 * The stackmastery report viewed event.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\event;

/**
 * Fired when a teacher views the report.
 *
 * Required data: other['mode'] (overview or user). relateduserid is set on the per-student
 * drill-down only.
 */
final class report_viewed extends \core\event\base {
    /**
     * Initialise the event shape.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string The name.
     */
    public static function get_name() {
        return get_string('eventreportviewed', 'mod_stackmastery');
    }

    /**
     * Non-localised description for the log.
     *
     * @return string The description.
     */
    public function get_description() {
        return "The user with id '{$this->userid}' viewed the '{$this->other['mode']}' report " .
            "of the STACK Mastery activity with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * URL a log viewer follows for detail.
     *
     * @return \moodle_url The report page.
     */
    public function get_url() {
        return new \moodle_url('/mod/stackmastery/report.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validate the required custom fields.
     *
     * @return void
     * @throws \coding_exception When a required field is missing.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['mode'])) {
            throw new \coding_exception('The \'mode\' value must be set in other.');
        }
    }

    /**
     * Backup mapping of the objectid: there is none.
     *
     * @return bool Always false.
     */
    public static function get_objectid_mapping() {
        return false;
    }

    /**
     * Backup mapping of the other fields: nothing to map.
     *
     * @return array The mapping definitions.
     */
    public static function get_other_mapping() {
        return ['mode' => ['db' => self::NOT_MAPPED, 'restore' => self::NOT_MAPPED]];
    }
}
