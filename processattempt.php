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
 * POST-only mutation endpoint for attempts: start, submit and finish, each sesskey-checked and
 * redirect-after-post (master plan C9).
 *
 * Attempts are addressed by course module id and $USER only. The submit action passes the raw
 * POST to the engine, which filters it to the open slot itself; the finish action shows a
 * confirm page first (the js-free confirm pattern), and only the confirmed POST mutates.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_stackmastery\local\attempt_manager;
use mod_stackmastery\local\submit_outcome;
use mod_stackmastery\output\attempt_page;

$id = required_param('id', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'stackmastery');
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/stackmastery:attempt', $context);
$instance = $DB->get_record('stackmastery', ['id' => $cm->instance], '*', MUST_EXIST);

$viewurl = new moodle_url('/mod/stackmastery/view.php', ['id' => $cm->id]);
$attempturl = new moodle_url('/mod/stackmastery/attempt.php', ['id' => $cm->id]);

// Mutations only: a stray GET is bounced without side effects.
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect($viewurl);
}
require_sesskey();

try {
    $manager = attempt_manager::create($instance, $cm, $context);
} catch (moodle_exception $e) {
    redirect($viewurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
}

if ($action === 'start') {
    // Idempotent: an existing open attempt is simply resumed (two-tab double start is safe).
    try {
        $manager->start_or_resume((int) $USER->id);
    } catch (moodle_exception $e) {
        redirect($viewurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($attempturl);
}

// Both remaining actions operate on the user's own open attempt.
$attempt = $manager->get_open_attempt((int) $USER->id);
if ($attempt === null) {
    redirect(
        $viewurl,
        get_string('attemptalreadyfinished', 'mod_stackmastery'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

if ($action === 'finish') {
    if (!$confirm) {
        // The js-free confirm page; only the confirmed POST below mutates anything.
        $PAGE->set_url('/mod/stackmastery/processattempt.php', ['id' => $cm->id, 'action' => 'finish']);
        $PAGE->set_title(get_string('endattempt', 'mod_stackmastery'));
        $PAGE->set_heading(format_string($course->fullname));
        echo $OUTPUT->header();
        $continueurl = new moodle_url('/mod/stackmastery/processattempt.php', [
            'id'      => $cm->id,
            'action'  => 'finish',
            'confirm' => 1,
            'sesskey' => sesskey(),
        ]);
        $continue = new single_button($continueurl, get_string('endattempt', 'mod_stackmastery'), 'post');
        echo $OUTPUT->confirm(
            get_string('endattemptconfirm', 'mod_stackmastery'),
            $continue,
            $attempturl
        );
        echo $OUTPUT->footer();
        exit;
    }
    $manager->finish_attempt($attempt, attempt_manager::REASON_USER);
    redirect(
        $viewurl,
        get_string('attemptended', 'mod_stackmastery'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

if ($action === 'submit') {
    // The engine filters the raw POST to the open slot itself; the posted slot field is
    // informational only and deliberately not trusted (master plan C9).
    $outcome = $manager->process_submission($attempt, $_POST);

    if ($outcome->is_finished()) {
        [$message, $type] = attempt_page::finish_notice($outcome->finishreason, $attempt);
        redirect($viewurl, $message, null, $type);
    }
    if ($outcome->result === submit_outcome::GRADED) {
        $target = new moodle_url('/mod/stackmastery/attempt.php', [
            'id'      => $cm->id,
            'lastseq' => (int) $outcome->lastseq,
        ]);
        if ($outcome->nextpending) {
            // Graded, but the next question is still pending; the next GET recovers it.
            redirect(
                $target,
                get_string($outcome->errorstring ?? 'errnextquestion', 'mod_stackmastery'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        redirect($target);
    }
    if ($outcome->result === submit_outcome::VALIDATED) {
        // Not graded (a validation press or invalid input): same slot re-renders with the
        // engine's validation feedback inline.
        redirect(
            $attempturl,
            get_string('invalidnotchecked', 'mod_stackmastery'),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }
    if ($outcome->result === submit_outcome::BUSY || $outcome->result === submit_outcome::ERROR) {
        redirect(
            $attempturl,
            get_string($outcome->errorstring ?? 'errgrading', 'mod_stackmastery'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    // DUPLICATE and NOOP: idempotent redirect, the current state simply re-renders.
    redirect($attempturl);
}

// Unknown action: nothing mutated.
redirect($viewurl);
