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
 * GET-only render of the viewer's own open attempt: the open question, or the review panel plus
 * the next question after a graded answer.
 *
 * Keyed by course module id and $USER only; attempt ids never appear in URLs (master plan C9).
 * All mutations POST to processattempt.php. The only state change a GET can cause is the
 * manager's SLOTLESS provisioning recovery, which is idempotent and owner-only.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

use mod_stackmastery\local\attempt_manager;
use mod_stackmastery\local\attempt_store;
use mod_stackmastery\output\attempt_page;

$id = required_param('id', PARAM_INT);
$lastseq = optional_param('lastseq', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'stackmastery');
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/stackmastery:attempt', $context);
$instance = $DB->get_record('stackmastery', ['id' => $cm->instance], '*', MUST_EXIST);

$viewurl = new moodle_url('/mod/stackmastery/view.php', ['id' => $cm->id]);

// The viewer's own open attempt only: no attempt id is read from the request.
$attempt = attempt_store::get_open_attempt((int) $instance->id, (int) $USER->id);
if ($attempt === null) {
    if ($lastseq > 0) {
        // The two-tab race: the other tab finished the attempt; the summary lives on view.php.
        redirect(
            $viewurl,
            get_string('attemptalreadyfinished', 'mod_stackmastery'),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }
    redirect($viewurl);
}

try {
    $manager = attempt_manager::create($instance, $cm, $context);
} catch (moodle_exception $e) {
    redirect($viewurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
}

// The activity closed while the attempt was open: finish grade-as-is (design 06 D14).
if (!empty($instance->timeclose) && time() > (int) $instance->timeclose) {
    $manager->finish_attempt($attempt, attempt_manager::REASON_TIMECLOSE);
    redirect(
        $viewurl,
        get_string('activityclosedgraded', 'mod_stackmastery'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$pageparams = ['id' => $cm->id];
if ($lastseq > 0) {
    $pageparams['lastseq'] = $lastseq;
}
$PAGE->set_url('/mod/stackmastery/attempt.php', $pageparams);
$PAGE->set_activity_record($instance);

// Render the state BEFORE any output: current_state() collects the question head contributions
// (render_question_head_html) so the qtype CSS/JS requirements land in the page head (C28).
$state = $manager->current_state($attempt, $lastseq > 0 ? $lastseq : null, true, (int) $USER->id);
if ($state->finished) {
    [$message, $type] = attempt_page::finish_notice($state->finishreason, $attempt);
    redirect($viewurl, $message, null, $type);
}

$seq = $state->seq ?? ((int) $state->questionsdone + 1);
$PAGE->set_title(
    get_string('questionx', 'mod_stackmastery', $seq) . ' - ' . format_string($instance->name)
);
$PAGE->set_heading(format_string($course->fullname));

// C28 order: head contributions, then the engine JS, then the page header; the question body
// renders inside the template's single form.
echo $state->headhtml;
echo question_engine::initialise_js();
echo $OUTPUT->header();
echo $OUTPUT->render(new attempt_page($cm, $instance, $state));
echo $OUTPUT->footer();
