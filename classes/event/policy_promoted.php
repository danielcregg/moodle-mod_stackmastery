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
 * The stackmastery policy promoted event.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\event;

/**
 * Fired when an administrator swaps the active question selection policy.
 *
 * Always created in the SYSTEM context (the policy is site-wide). A rollback fires the same
 * event with other['source'] = 'rollback'. Required other keys: oldpolicyid (string),
 * newpolicyid (string), source ('promote' or 'rollback'); optional (nullable): datasetsha,
 * gateavgquestions.
 */
final class policy_promoted extends \core\event\base {
    /**
     * Initialise the event shape.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string The name.
     */
    public static function get_name() {
        return get_string('eventpolicypromoted', 'mod_stackmastery');
    }

    /**
     * Non-localised description for the log.
     *
     * @return string The description.
     */
    public function get_description() {
        return "The user with id '{$this->userid}' switched the active mastery policy from " .
            "'{$this->other['oldpolicyid']}' to '{$this->other['newpolicyid']}' " .
            "({$this->other['source']}).";
    }

    /**
     * URL a log viewer follows for detail.
     *
     * @return \moodle_url The policy admin page.
     */
    public function get_url() {
        return new \moodle_url('/mod/stackmastery/adminpolicy.php');
    }

    /**
     * Validate the required custom fields.
     *
     * @return void
     * @throws \coding_exception When a required field is missing.
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['oldpolicyid', 'newpolicyid', 'source'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * Backup mapping of the objectid: the object is a file artifact, never inside a backup.
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
        return [
            'oldpolicyid'      => ['db' => self::NOT_MAPPED, 'restore' => self::NOT_MAPPED],
            'newpolicyid'      => ['db' => self::NOT_MAPPED, 'restore' => self::NOT_MAPPED],
            'source'           => ['db' => self::NOT_MAPPED, 'restore' => self::NOT_MAPPED],
            'datasetsha'       => ['db' => self::NOT_MAPPED, 'restore' => self::NOT_MAPPED],
            'gateavgquestions' => ['db' => self::NOT_MAPPED, 'restore' => self::NOT_MAPPED],
        ];
    }
}
