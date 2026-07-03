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
 * Gradebook click-through target: teachers land on the report, students on the activity.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'stackmastery');
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/stackmastery:view', $context);

if (has_capability('mod/stackmastery:viewreports', $context)) {
    redirect(new moodle_url('/mod/stackmastery/report.php', ['id' => $cm->id]));
}
redirect(new moodle_url('/mod/stackmastery/view.php', ['id' => $cm->id]));
