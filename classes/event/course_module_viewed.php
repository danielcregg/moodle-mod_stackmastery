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
 * The stackmastery course module viewed event.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\event;

/**
 * The conventional course module viewed event, fired by view.php (completion view tracking).
 */
final class course_module_viewed extends \core\event\course_module_viewed {
    /**
     * Initialise the event shape.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'stackmastery';
        parent::init();
    }

    /**
     * Backup mapping of the objectid.
     *
     * @return array The mapping definition.
     */
    public static function get_objectid_mapping() {
        return ['db' => 'stackmastery', 'restore' => 'stackmastery'];
    }

    /**
     * Backup mapping of the other fields: nothing custom is carried.
     *
     * @return bool Always false.
     */
    public static function get_other_mapping() {
        return false;
    }
}
