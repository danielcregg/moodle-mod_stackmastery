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
 * Landing page for a STACK Mastery activity: state machine, mastery, history, grade line.
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
$instance = $DB->get_record('stackmastery', ['id' => $cm->instance], '*', MUST_EXIST);

stackmastery_view($instance, $course, $cm, $context);

$PAGE->set_url('/mod/stackmastery/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_activity_record($instance);

echo $OUTPUT->header();
echo $OUTPUT->render(new \mod_stackmastery\output\view_page($cm, $instance));
echo $OUTPUT->footer();
