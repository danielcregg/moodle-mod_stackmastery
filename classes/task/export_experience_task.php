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
 * The weekly pseudonymised experience export task (inert unless enabled).
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\task;

/**
 * Thin scheduled-task wrapper around \mod_stackmastery\local\export::run().
 */
final class export_experience_task extends \core\task\scheduled_task {
    /**
     * Localised task name for the scheduled-tasks admin screen.
     *
     * @return string The name.
     */
    public function get_name() {
        return get_string('exporttask', 'mod_stackmastery');
    }

    /**
     * Run the export when the admin has opted in; otherwise do nothing at all.
     *
     * @return void
     */
    public function execute() {
        if (!get_config('mod_stackmastery', 'experienceexport')) {
            mtrace('mod_stackmastery: experience export disabled; skipping.');
            return;
        }
        $run = \mod_stackmastery\local\export::run(new \text_progress_trace());
        if ($run) {
            mtrace("mod_stackmastery: exported {$run->attempts} attempts / {$run->steps} steps " .
                "to {$run->filename}");
        } else {
            mtrace('mod_stackmastery: nothing new to export.');
        }
    }
}
