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
 * Custom completion rule: the student reached the mastery target.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\completion;

use core_completion\activity_custom_completion;

/**
 * The completionreachedtarget rule.
 *
 * reachedtarget is only ever set at target-termination, atomically with the terminal state, so
 * an O(1) indexed lookup on it is safe in the completion hot path (course page render loops).
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetch the completion state of one rule for the configured user.
     *
     * @param string $rule The rule name; only completionreachedtarget is defined.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        $reached = $DB->record_exists('stackmastery_attempts', [
            'stackmasteryid' => $this->cm->instance,
            'userid'         => $this->userid,
            'reachedtarget'  => 1,
        ]);
        return $reached ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * The custom rules this module defines.
     *
     * @return string[] Rule names.
     */
    public static function get_defined_custom_rules(): array {
        return ['completionreachedtarget'];
    }

    /**
     * Human descriptions of the custom rules, for the activity header.
     *
     * @return array<string, string> Map rule => description.
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionreachedtarget' => get_string('completiondetail:reachedtarget', 'mod_stackmastery'),
        ];
    }

    /**
     * Display order of completion rules for this module.
     *
     * @return string[] Rule names in display order.
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionreachedtarget', 'completionusegrade'];
    }
}
