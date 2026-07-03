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
 * List every STACK Mastery instance in a course.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_course_login($course);

$event = \mod_stackmastery\event\course_module_instance_list_viewed::create([
    'context' => context_course::instance($course->id),
]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/stackmastery/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_stackmastery'));

$instances = get_all_instances_in_course('stackmastery', $course);
if (!$instances) {
    notice(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_stackmastery')),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
}

$table = new html_table();
$usesections = course_format_uses_sections($course->format);
if ($usesections) {
    $table->head = [get_string('sectionname', 'format_' . $course->format), get_string('name')];
    $table->align = ['center', 'left'];
} else {
    $table->head = [get_string('name')];
    $table->align = ['left'];
}

foreach ($instances as $instance) {
    $class = $instance->visible ? null : ['class' => 'dimmed'];
    $link = html_writer::link(
        new moodle_url('/mod/stackmastery/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name, true),
        $class
    );
    if ($usesections) {
        $table->data[] = [get_section_name($course, $instance->section), $link];
    } else {
        $table->data[] = [$link];
    }
}

echo html_writer::table($table);
echo $OUTPUT->footer();
