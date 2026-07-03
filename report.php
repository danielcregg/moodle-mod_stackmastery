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
 * Teacher report: cohort funnel and overview table, per-attempt step drilldown, dataformat
 * downloads and single-attempt deletion.
 *
 * Modes: the default overview; mode=user with userid and attempt for the drilldown; download=
 * any dataformat name streams the current table; action=delete with an attempt id shows a
 * confirm page whose confirmed POST deletes and regrades.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_stackmastery\local\attempt_manager;
use mod_stackmastery\local\report\overview_table;
use mod_stackmastery\local\report\report_data;
use mod_stackmastery\local\report\steps_table;
use mod_stackmastery\output\attempt_summary;
use mod_stackmastery\output\progress_bars;

$id = required_param('id', PARAM_INT);
$mode = optional_param('mode', 'overview', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$attemptid = optional_param('attempt', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'stackmastery');
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/stackmastery:viewreports', $context);
$instance = $DB->get_record('stackmastery', ['id' => $cm->instance], '*', MUST_EXIST);

$overviewurl = new moodle_url('/mod/stackmastery/report.php', ['id' => $cm->id]);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_activity_record($instance);
$PAGE->set_secondary_active_tab('stackmasteryreport');

// Single-attempt deletion: confirm page on GET, mutation only on the confirmed POST.
if ($action === 'delete' && $attemptid > 0) {
    require_capability('mod/stackmastery:deleteattempts', $context);
    $attempt = report_data::attempt_record($instance, $attemptid);
    if ($confirm && data_submitted() && confirm_sesskey()) {
        try {
            $manager = attempt_manager::create($instance, $cm, $context);
            $manager->delete_attempt($attempt);
        } catch (moodle_exception $e) {
            redirect($overviewurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
        redirect(
            $overviewurl,
            get_string('attemptdeleted', 'mod_stackmastery'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $PAGE->set_url('/mod/stackmastery/report.php', [
        'id'      => $cm->id,
        'action'  => 'delete',
        'attempt' => $attemptid,
    ]);
    $PAGE->set_title(get_string('deleteattempt', 'mod_stackmastery'));
    echo $OUTPUT->header();
    $continueurl = new moodle_url('/mod/stackmastery/report.php', [
        'id'      => $cm->id,
        'action'  => 'delete',
        'attempt' => $attemptid,
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]);
    $continue = new single_button($continueurl, get_string('deleteattempt', 'mod_stackmastery'), 'post');
    echo $OUTPUT->confirm(get_string('deleteattemptconfirm', 'mod_stackmastery'), $continue, $overviewurl);
    echo $OUTPUT->footer();
    exit;
}

if ($mode === 'user') {
    // Per-attempt drilldown. The attempt id is instance-scoped; the userid parameter is part of
    // the URL contract and cross-checked against the attempt row.
    if ($attemptid <= 0) {
        redirect($overviewurl);
    }
    $attempt = report_data::attempt_record($instance, $attemptid);
    if ($userid > 0 && $userid !== (int) $attempt->userid) {
        throw new moodle_exception('invalidaccessparameter');
    }
    $user = core_user::get_user((int) $attempt->userid, '*', MUST_EXIST);
    $detailurl = new moodle_url('/mod/stackmastery/report.php', [
        'id'      => $cm->id,
        'mode'    => 'user',
        'userid'  => (int) $attempt->userid,
        'attempt' => (int) $attempt->id,
    ]);
    $table = new steps_table($instance, $cm, (int) $attempt->id, $detailurl, $download);
    if (!$table->is_downloading()) {
        \mod_stackmastery\event\report_viewed::create([
            'context'       => $context,
            'relateduserid' => (int) $attempt->userid,
            'other'         => ['mode' => 'user'],
        ])->trigger();

        $PAGE->set_url($detailurl);
        $PAGE->set_title(
            get_string('attemptdetail', 'mod_stackmastery') . ' - ' . format_string($instance->name)
        );
        echo $OUTPUT->header();
        echo $OUTPUT->heading(
            get_string('attemptdetail', 'mod_stackmastery') . ': ' . fullname($user),
            2
        );
        echo $OUTPUT->render(new attempt_summary($instance, $attempt));

        $masteryjson = empty($attempt->masteryfinal)
            ? (string) $attempt->masterycurrent
            : (string) $attempt->masteryfinal;
        $mastery = json_decode($masteryjson, true);
        if (is_array($mastery)) {
            // Teachers always see the bars, whatever the student-facing toggle says.
            echo $OUTPUT->render(new progress_bars(
                $mastery,
                \mod_stackmastery\local\skills::decode_csv((string) $attempt->skillssnapshot),
                (float) $instance->targetmastery,
                true,
                get_string('colmastery', 'mod_stackmastery', fullname($user))
            ));
        }
    }
    $table->build(report_data::attempt_steps($attempt));
    if (!$table->is_downloading()) {
        echo html_writer::div(
            html_writer::link($overviewurl, get_string('reportoverview', 'mod_stackmastery')),
            'mt-3'
        );
        echo html_writer::div(
            get_string('nogroupsnote', 'mod_stackmastery'),
            'text-muted small mt-3'
        );
        echo $OUTPUT->footer();
    }
    exit;
}

// Overview mode.
$rows = report_data::overview_rows($instance);
$skillcolumns = report_data::skill_columns($instance, $rows);
$candelete = has_capability('mod/stackmastery:deleteattempts', $context);
$table = new overview_table($instance, $cm, $skillcolumns, $overviewurl, $download, $candelete);

if (!$table->is_downloading()) {
    \mod_stackmastery\event\report_viewed::create([
        'context' => $context,
        'other'   => ['mode' => 'overview'],
    ])->trigger();

    $PAGE->set_url($overviewurl);
    $PAGE->set_title(get_string('report', 'mod_stackmastery') . ' - ' . format_string($instance->name));
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('reportoverview', 'mod_stackmastery'), 2);

    $funnel = report_data::funnel((int) $instance->id);
    $stats = report_data::stats((int) $instance->id);
    $share = function (int $count) use ($funnel): string {
        if ($funnel->started === 0) {
            return '';
        }
        $percent = (int) round(100.0 * $count / $funnel->started);
        return get_string('funnelshare', 'mod_stackmastery', $percent);
    };
    $cards = [
        [
            'label' => get_string('funnelstarted', 'mod_stackmastery'),
            'value' => (string) $funnel->started,
            'sub'   => '',
        ],
        [
            'label' => get_string('funnelanswered', 'mod_stackmastery'),
            'value' => (string) $funnel->answered,
            'sub'   => $share($funnel->answered),
        ],
        [
            'label' => get_string('funnelcompleted', 'mod_stackmastery'),
            'value' => (string) $funnel->completed,
            'sub'   => $share($funnel->completed),
        ],
        [
            'label' => get_string('funnelreached', 'mod_stackmastery'),
            'value' => (string) $funnel->reached,
            'sub'   => $share($funnel->reached),
        ],
        [
            'label' => get_string('medianquestionstotarget', 'mod_stackmastery'),
            'value' => $stats->medianstepstotarget === null
                ? '-' : format_float($stats->medianstepstotarget, 1),
            'sub'   => '',
        ],
        [
            'label' => get_string('mediantimetotarget', 'mod_stackmastery'),
            'value' => $stats->mediantimetotarget === null
                ? '-' : format_time((int) round($stats->mediantimetotarget)),
            'sub'   => '',
        ],
        [
            'label' => get_string('explorationshare', 'mod_stackmastery'),
            'value' => $stats->exploreshare === null
                ? '-' : ((int) round(100.0 * $stats->exploreshare)) . '%',
            'sub'   => '',
        ],
        [
            'label' => get_string('inprogressattempts', 'mod_stackmastery'),
            'value' => (string) $stats->inprogress,
            'sub'   => '',
        ],
        [
            'label' => get_string('abandonedattempts', 'mod_stackmastery'),
            'value' => (string) $stats->abandoned,
            'sub'   => '',
        ],
    ];
    echo $OUTPUT->render_from_template('mod_stackmastery/report_funnel', ['cards' => $cards]);
}

$table->build($rows);

if (!$table->is_downloading()) {
    echo html_writer::div(
        get_string('nogroupsnote', 'mod_stackmastery'),
        'text-muted small mt-3'
    );
    echo $OUTPUT->footer();
}
