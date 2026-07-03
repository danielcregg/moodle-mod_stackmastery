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
 * The stackmastery attempt started event.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\event;

/**
 * Fired when a student starts a mastery attempt.
 *
 * Required data: objectid (attempt id), relateduserid (the student),
 * other['stackmasteryid'] (the instance id).
 */
final class attempt_started extends \core\event\base {
    /**
     * Initialise the event shape.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'stackmastery_attempts';
    }

    /**
     * Localised event name.
     *
     * @return string The name.
     */
    public static function get_name() {
        return get_string('eventattemptstarted', 'mod_stackmastery');
    }

    /**
     * Non-localised description for the log.
     *
     * @return string The description.
     */
    public function get_description() {
        return "The user with id '{$this->relateduserid}' started attempt '{$this->objectid}' " .
            "on the STACK Mastery activity '{$this->other['stackmasteryid']}'.";
    }

    /**
     * URL a log viewer follows for detail.
     *
     * @return \moodle_url The report page for this attempt.
     */
    public function get_url() {
        return new \moodle_url(
            '/mod/stackmastery/report.php',
            ['id' => $this->contextinstanceid, 'attempt' => $this->objectid]
        );
    }

    /**
     * Validate the required custom fields.
     *
     * @return void
     * @throws \coding_exception When a required field is missing.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        if (!isset($this->other['stackmasteryid'])) {
            throw new \coding_exception('The \'stackmasteryid\' value must be set in other.');
        }
    }

    /**
     * Backup mapping of the objectid.
     *
     * @return array The mapping definition.
     */
    public static function get_objectid_mapping() {
        return ['db' => 'stackmastery_attempts', 'restore' => 'stackmastery_attempt'];
    }

    /**
     * Backup mapping of the other fields.
     *
     * @return array The mapping definitions.
     */
    public static function get_other_mapping() {
        return ['stackmasteryid' => ['db' => 'stackmastery', 'restore' => 'stackmastery']];
    }
}
