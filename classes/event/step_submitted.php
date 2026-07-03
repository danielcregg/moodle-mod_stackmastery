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
 * The stackmastery step submitted event.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\event;

/**
 * Fired when a graded answer is logged as an experience step.
 *
 * Required data: objectid (step id), relateduserid (the student), other['attemptid'],
 * other['seq'], other['skill'], other['difficulty'], other['actionsource'], other['correct'].
 * Mastery values are deliberately NOT carried in event payloads (identifiers and categorical
 * fields only); the experience data lives in stackmastery_steps.
 */
final class step_submitted extends \core\event\base {
    /**
     * Initialise the event shape.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'stackmastery_steps';
    }

    /**
     * Localised event name.
     *
     * @return string The name.
     */
    public static function get_name() {
        return get_string('eventstepsubmitted', 'mod_stackmastery');
    }

    /**
     * Non-localised description for the log.
     *
     * @return string The description.
     */
    public function get_description() {
        return "The user with id '{$this->relateduserid}' answered step '{$this->other['seq']}' " .
            "of attempt '{$this->other['attemptid']}' (skill '{$this->other['skill']}', " .
            "difficulty '{$this->other['difficulty']}', source '{$this->other['actionsource']}', " .
            "correct '{$this->other['correct']}').";
    }

    /**
     * URL a log viewer follows for detail.
     *
     * @return \moodle_url The report page anchored at this step.
     */
    public function get_url() {
        $url = new \moodle_url(
            '/mod/stackmastery/report.php',
            ['id' => $this->contextinstanceid, 'attempt' => $this->other['attemptid']]
        );
        $url->set_anchor('step' . $this->other['seq']);
        return $url;
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
        foreach (['attemptid', 'seq', 'skill', 'difficulty', 'actionsource', 'correct'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * Backup mapping of the objectid.
     *
     * @return array The mapping definition.
     */
    public static function get_objectid_mapping() {
        return ['db' => 'stackmastery_steps', 'restore' => 'stackmastery_step'];
    }

    /**
     * Backup mapping of the other fields.
     *
     * @return array The mapping definitions.
     */
    public static function get_other_mapping() {
        return [
            'attemptid'    => ['db' => 'stackmastery_attempts', 'restore' => 'stackmastery_attempt'],
            'seq'          => ['db' => self::NOT_MAPPED, 'restore' => self::NOT_MAPPED],
            'skill'        => ['db' => self::NOT_MAPPED, 'restore' => self::NOT_MAPPED],
            'difficulty'   => ['db' => self::NOT_MAPPED, 'restore' => self::NOT_MAPPED],
            'actionsource' => ['db' => self::NOT_MAPPED, 'restore' => self::NOT_MAPPED],
            'correct'      => ['db' => self::NOT_MAPPED, 'restore' => self::NOT_MAPPED],
        ];
    }
}
