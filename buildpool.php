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
 * POST-only pool builder: fill an instance's question pool with one click.
 *
 * Two actions, both redirect-after-post to view.php: 'generate' queues one STACK Question Forge
 * job (masterytag on) per thin (skill, difficulty) cell, so the questions are drafted, oracle
 * validated and tagged in the background; 'starter' imports the shipped sample bank into the pool
 * category, tagged and idempotent.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_stackmastery\local\pool;
use mod_stackmastery\local\skills;
use mod_stackmastery\local\starter_pack;

$id = required_param('id', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'stackmastery');
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/stackmastery:manageinstance', $context);
$instance = $DB->get_record('stackmastery', ['id' => $cm->instance], '*', MUST_EXIST);

$viewurl = new moodle_url('/mod/stackmastery/view.php', ['id' => $cm->id]);

// Mutations only: a stray GET is bounced without side effects.
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect($viewurl);
}
require_sesskey();

// Both actions put questions into the pool category, so it must exist.
$categoryid = (int) $instance->poolcategoryid;
$category = $categoryid > 0 ? $DB->get_record('question_categories', ['id' => $categoryid]) : false;
if (!$category) {
    redirect(
        $viewurl,
        get_string('buildpoolneedcategory', 'mod_stackmastery'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}
// Adding questions to a bank category is a core-capability action in the category's context,
// exactly as the forge's own generate page requires (defence in depth beside manageinstance).
$categorycontext = context::instance_by_id((int) $category->contextid);
require_capability('moodle/question:add', $categorycontext);

if ($action === 'generate') {
    // The builder rides on the forge's public job API; without it there is nothing to queue.
    $forgeready = class_exists('\\local_stackforge\\generator')
        && method_exists('\\local_stackforge\\generator', 'queue_generation');
    if (!$forgeready) {
        redirect(
            $viewurl,
            get_string('buildpoolneedforge', 'mod_stackmastery'),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }

    $target = min(20, max(1, optional_param('target', 3, PARAM_INT)));
    $selected = skills::decode_csv((string) $instance->skills);
    $gaps = pool::cell_gaps(pool::cell_counts($categoryid, $selected), $target);

    $jobs = 0;
    $questions = 0;
    foreach ($gaps as $skill => $row) {
        $forgetype = skills::forge_type($skill);
        if ($forgetype === null) {
            continue;
        }
        foreach ($row as $difficulty => $missing) {
            $count = min(10, (int) $missing);
            \local_stackforge\generator::queue_generation(
                (int) $course->id,
                (int) $USER->id,
                $categoryid,
                $forgetype,
                $difficulty,
                $count,
                true
            );
            $jobs++;
            $questions += $count;
        }
    }

    if ($jobs === 0) {
        redirect(
            $viewurl,
            get_string('buildpoolnothing', 'mod_stackmastery', $target),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }
    $forgeurl = new moodle_url('/local/stackforge/index.php', ['courseid' => $course->id]);
    $message = get_string(
        'buildpoolqueued',
        'mod_stackmastery',
        (object) ['jobs' => $jobs, 'questions' => $questions]
    );
    $message .= ' ' . html_writer::link($forgeurl, get_string('buildpoolviewforge', 'mod_stackmastery'));
    redirect($viewurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'starter') {
    $counts = starter_pack::import($instance, $categorycontext);
    $message = get_string('starterpackdone', 'mod_stackmastery', $counts['imported']);
    if ($counts['skipped'] > 0) {
        $message .= ' ' . get_string('starterpackskipped', 'mod_stackmastery', $counts['skipped']);
    }
    $type = \core\output\notification::NOTIFY_SUCCESS;
    if ($counts['failed'] > 0) {
        $message .= ' ' . get_string('starterpackfailed', 'mod_stackmastery', $counts['failed']);
        $type = \core\output\notification::NOTIFY_WARNING;
    }
    redirect($viewurl, $message, null, $type);
}

// Unknown action: nothing mutated.
redirect($viewurl);
