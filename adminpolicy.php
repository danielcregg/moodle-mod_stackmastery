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
 * Site administration page: review, promote and roll back the mastery selection policy.
 *
 * Shows the active policy, the pending candidate (with its full validation report and gate
 * metrics), the rollback archive and the recent experience export runs, so the operator sees the
 * whole retrain loop on one page. All mutations are sesskey-protected POSTs with a confirm step;
 * the page reads only fixed moodledata paths. Labels shown in code style are artifact schema
 * field names, rendered verbatim by design.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_stackmastery\local\policy_store;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('modstackmasterypolicy');

$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$file = optional_param('file', '', PARAM_FILE);

$pageurl = new moodle_url('/mod/stackmastery/adminpolicy.php');

if ($action !== '' && $confirm) {
    require_sesskey();
    if ($action === 'promote') {
        $old = policy_store::get_active()->policyid;
        $new = policy_store::promote((int) $USER->id);
        \core\notification::success(get_string('policypromoted', 'mod_stackmastery',
            (object) ['old' => $old, 'new' => $new->policyid]));
    } else if ($action === 'rollback') {
        $old = policy_store::get_active()->policyid;
        $new = policy_store::rollback((int) $USER->id, $file !== '' ? $file : null);
        \core\notification::success(get_string('policyrolledback', 'mod_stackmastery',
            (object) ['old' => $old, 'new' => $new->policyid]));
    } else if ($action === 'reject') {
        policy_store::reject_pending();
        \core\notification::success(get_string('changessaved'));
    }
    redirect($pageurl);
}

echo $OUTPUT->header();

if ($action !== '') {
    // Confirm step for every mutation.
    require_sesskey();
    $messages = [
        'promote' => [get_string('promoteconfirm', 'mod_stackmastery'), get_string('promote', 'mod_stackmastery')],
        'rollback' => [get_string('rollbackconfirm', 'mod_stackmastery'), get_string('rollback', 'mod_stackmastery')],
        'reject' => [get_string('areyousure'), get_string('delete')],
    ];
    if (!isset($messages[$action])) {
        redirect($pageurl);
    }
    $params = ['action' => $action, 'confirm' => 1, 'sesskey' => sesskey()];
    if ($file !== '') {
        $params['file'] = $file;
    }
    $yesurl = new moodle_url($pageurl, $params);
    echo $OUTPUT->confirm($messages[$action][0], new single_button($yesurl, $messages[$action][1], 'post'),
        $pageurl);
    echo $OUTPUT->footer();
    die;
}

// Renders the artifact block's dataset and gate numbers as a small verbatim-field table.
$artifacttable = function (array $artifact): string {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable w-auto';
    $table->data = [];
    $gate = is_array($artifact['gate'] ?? null) ? $artifact['gate'] : [];
    $passed = ($gate['passed'] ?? null) === true;
    $table->data[] = [html_writer::tag('code', 'gate.passed'), $passed ? '&#10003;' : '&#10007;'];
    if (isset($gate['avg_questions'])) {
        $table->data[] = [html_writer::tag('code', 'gate.avg_questions'), s((string) $gate['avg_questions'])];
    }
    foreach (($gate['baselines'] ?? []) as $name => $avg) {
        $cell = html_writer::tag('code', 'gate.baselines.' . s((string) $name));
        $table->data[] = [$cell, s((string) $avg)];
    }
    $dataset = is_array($artifact['dataset'] ?? null) ? $artifact['dataset'] : [];
    if (isset($dataset['sha256'])) {
        $table->data[] = [html_writer::tag('code', 'dataset.sha256'),
            html_writer::tag('code', s(substr((string) $dataset['sha256'], 0, 12)) . '&#8230;')];
    }
    if (isset($dataset['transitions'])) {
        $table->data[] = [html_writer::tag('code', 'dataset.transitions'), s((string) $dataset['transitions'])];
    }
    return html_writer::table($table);
};

// The active policy card.
$active = policy_store::get_active();
echo $OUTPUT->heading(get_string('activepolicy', 'mod_stackmastery'), 3);
$table = new html_table();
$table->attributes['class'] = 'generaltable w-auto';
$table->data = [];
$table->data[] = [html_writer::tag('code', 'policy_id'), html_writer::tag('strong', s($active->policyid))];
$table->data[] = [html_writer::tag('code', 'source'), s($active->source)];
if ($active->source === 'promoted' && is_file($active->path)) {
    $table->data[] = [html_writer::tag('code', 'promoted_at'), userdate((int) filemtime($active->path))];
}
if (isset($active->meta['states_visited'])) {
    $table->data[] = [html_writer::tag('code', 'states_visited'), s((string) (int) $active->meta['states_visited'])];
}
echo html_writer::table($table);
$activeartifact = $active->meta['artifact'] ?? null;
if (is_array($activeartifact)) {
    echo $artifacttable($activeartifact);
}

// The pending candidate card.
echo $OUTPUT->heading(get_string('pendingpolicy', 'mod_stackmastery'), 3);
$pending = policy_store::get_pending();
if ($pending === null) {
    echo html_writer::div(get_string('nopendingpolicy', 'mod_stackmastery'));
} else {
    echo html_writer::div(html_writer::tag('code', s($pending->path)) . ' &#183; ' .
        userdate($pending->timemodified));
    if (!$pending->report['ok']) {
        echo $OUTPUT->notification(get_string('artifactinvalid', 'mod_stackmastery'),
            \core\output\notification::NOTIFY_ERROR);
        echo html_writer::alist(array_map('s', $pending->report['errors']));
    } else {
        $artifact = $pending->meta['artifact'];
        echo html_writer::div(html_writer::tag('code', 'policy_id') . ' ' .
            html_writer::tag('strong', s($artifact['policy_id'])));
        echo $artifacttable($artifact);
        echo $OUTPUT->single_button(new moodle_url($pageurl, ['action' => 'promote', 'sesskey' => sesskey()]),
            get_string('promote', 'mod_stackmastery'), 'post');
    }
    echo $OUTPUT->single_button(new moodle_url($pageurl, ['action' => 'reject', 'sesskey' => sesskey()]),
        get_string('delete'), 'post');
}

// The rollback card.
echo $OUTPUT->heading(get_string('rollback', 'mod_stackmastery'), 3);
$previous = policy_store::list_previous();
if ($previous !== []) {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable w-auto';
    $table->data = [];
    foreach ($previous as $entry) {
        $rollbackurl = new moodle_url($pageurl,
            ['action' => 'rollback', 'file' => $entry->filename, 'sesskey' => sesskey()]);
        $table->data[] = [
            html_writer::tag('code', s($entry->policyid)),
            userdate($entry->time),
            $OUTPUT->single_button($rollbackurl, get_string('rollback', 'mod_stackmastery'), 'post'),
        ];
    }
    echo html_writer::table($table);
} else if ($active->source === 'promoted') {
    // Empty archive: rolling back reverts to the shipped default policy.
    echo $OUTPUT->single_button(new moodle_url($pageurl, ['action' => 'rollback', 'sesskey' => sesskey()]),
        get_string('rollback', 'mod_stackmastery'), 'post');
} else {
    echo html_writer::div(get_string('norollback', 'mod_stackmastery'));
}

// The recent export runs: the operator sees the whole retrain loop on one page.
echo $OUTPUT->heading(get_string('exportruns', 'mod_stackmastery'), 3);
$runs = $DB->get_records('stackmastery_exportruns', null, 'timecreated DESC', '*', 0, 10);
if ($runs === []) {
    echo html_writer::div(get_string('none'));
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable w-auto';
    $table->data = [];
    foreach ($runs as $run) {
        $counts = get_string('exportrun_counts', 'mod_stackmastery', (object) [
            'attempts' => (int) $run->attempts,
            'steps' => (int) $run->steps,
            'skipped' => (int) $run->skippederrors,
        ]);
        $table->data[] = [
            html_writer::tag('code', s($run->filename)),
            html_writer::tag('code', s(substr($run->sha256, 0, 12)) . '&#8230;'),
            $counts,
            userdate((int) $run->timecreated),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
