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
 * Overview table of the teacher report.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local\report;

use mod_stackmastery\local\attempt_store;
use mod_stackmastery\local\skill_manifest;
use mod_stackmastery\local\topics;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * One row per attempt (every attempt, the grade-contributing one badged), downloadable through
 * the core dataformat plugins. Rows are built in PHP by report_data (per-row JSON mastery
 * unpacking), so this extends flexible_table, not table_sql.
 */
class overview_table extends \flexible_table {
    /** @var \stdClass The stackmastery instance record. */
    protected \stdClass $instance;

    /** @var \cm_info The course module. */
    protected \cm_info $cm;

    /** @var string[] Mastery column skill codes, canonical order. */
    protected array $skillcolumns;

    /** @var bool Whether the viewer may delete attempts (adds the actions column on screen). */
    protected bool $candelete;

    /**
     * Configure columns, headers and the download surface.
     *
     * @param \stdClass $instance The instance record.
     * @param \cm_info $cm The course module.
     * @param string[] $skillcolumns Mastery column skill codes.
     * @param \moodle_url $baseurl The report overview URL.
     * @param string $download Requested dataformat name, or empty for the on-screen table.
     * @param bool $candelete Whether the viewer holds mod/stackmastery:deleteattempts.
     */
    public function __construct(
        \stdClass $instance,
        \cm_info $cm,
        array $skillcolumns,
        \moodle_url $baseurl,
        string $download,
        bool $candelete
    ) {
        parent::__construct('mod_stackmastery-report-' . $cm->id);
        $this->instance = $instance;
        $this->cm = $cm;
        $this->skillcolumns = $skillcolumns;
        $this->candelete = $candelete;

        $this->define_baseurl($baseurl);
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);
        $this->is_downloading(
            $download,
            clean_filename($instance->name . '-report'),
            get_string('reportoverview', 'mod_stackmastery')
        );

        $columns = ['fullname'];
        $headers = [get_string('fullname')];
        if ($this->is_downloading()) {
            $columns[] = 'userid';
            $headers[] = get_string('coluserid', 'mod_stackmastery');
            $columns[] = 'email';
            $headers[] = get_string('email');
        }
        $columns = array_merge($columns, [
            'attempt', 'state', 'questions', 'duration', 'reached', 'stepstotarget', 'timetotarget',
        ]);
        $headers = array_merge($headers, [
            get_string('colattempt', 'mod_stackmastery'),
            get_string('colstate', 'mod_stackmastery'),
            get_string('colquestions', 'mod_stackmastery'),
            get_string('colduration', 'mod_stackmastery'),
            get_string('colreached', 'mod_stackmastery'),
            get_string('colstepstotarget', 'mod_stackmastery'),
            get_string('coltimetotarget', 'mod_stackmastery'),
        ]);
        $manifest = skill_manifest::from_instance($instance, topics::for_instance((int) $instance->id));
        foreach ($this->skillcolumns as $code) {
            $columns[] = 'mastery_' . $code;
            $headers[] = get_string('colmastery', 'mod_stackmastery', $manifest->label($code));
        }
        $columns[] = 'explore';
        $headers[] = get_string('colexplore', 'mod_stackmastery');
        $columns[] = 'grade';
        $headers[] = get_string('colgrade', 'mod_stackmastery');
        if (!$this->is_downloading() && $this->candelete) {
            $columns[] = 'actions';
            $headers[] = get_string('actions');
        }
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->sortable(false);
        $this->collapsible(false);
        $this->pageable(false);
    }

    /**
     * Render (or stream) the table from report_data::overview_rows() output.
     *
     * @param \stdClass[] $rows The overview rows.
     * @return void
     */
    public function build(array $rows): void {
        $this->setup();
        foreach ($rows as $row) {
            $this->add_data($this->format_row_cells($row));
        }
        $this->finish_output();
    }

    /**
     * Format one overview row into cells matching the defined columns.
     *
     * @param \stdClass $row A report_data::overview_rows() row.
     * @return string[] The cell values.
     */
    protected function format_row_cells(\stdClass $row): array {
        $attempt = $row->attempt;
        $cells = [$this->fullname_cell($row)];
        if ($this->is_downloading()) {
            $cells[] = (string) $attempt->userid;
            $cells[] = (string) $row->user->email;
        }
        $cells[] = $this->attempt_cell($row);
        $cells[] = $this->state_cell($attempt);
        $cells[] = (string) (int) $attempt->questionsdone;
        $cells[] = $attempt->timefinish > 0
            ? format_time((int) $attempt->timefinish - (int) $attempt->timestart)
            : get_string('stateinprogress', 'mod_stackmastery');
        $cells[] = empty($attempt->reachedtarget) ? get_string('no') : get_string('yes');
        $cells[] = $attempt->stepstotarget === null ? '-' : (string) (int) $attempt->stepstotarget;
        $cells[] = $attempt->timetargetreached === null
            ? '-'
            : format_time((int) $attempt->timetargetreached - (int) $attempt->timestart);
        foreach ($this->skillcolumns as $code) {
            $cells[] = $this->mastery_cell($row, $code);
        }
        $cells[] = $this->share_cell($row->exploreshare);
        $cells[] = $row->grade === null ? '-' : format_float($row->grade, 2);
        if (!$this->is_downloading() && $this->candelete) {
            $cells[] = $this->actions_cell($attempt);
        }
        return $cells;
    }

    /**
     * The name cell: linked to the attempt detail on screen, plain when downloading.
     *
     * @param \stdClass $row The overview row.
     * @return string The cell.
     */
    protected function fullname_cell(\stdClass $row): string {
        $name = fullname($row->user);
        if ($this->is_downloading()) {
            return $name;
        }
        $url = new \moodle_url('/mod/stackmastery/report.php', [
            'id'      => $this->cm->id,
            'mode'    => 'user',
            'userid'  => (int) $row->attempt->userid,
            'attempt' => (int) $row->attempt->id,
        ]);
        return \html_writer::link($url, $name);
    }

    /**
     * The attempt-number cell, badged on the grade-contributing attempt.
     *
     * @param \stdClass $row The overview row.
     * @return string The cell.
     */
    protected function attempt_cell(\stdClass $row): string {
        $cell = (string) (int) $row->attempt->attemptnumber;
        if ($row->counted && !$this->is_downloading()) {
            $badge = \html_writer::span(
                get_string('countstowardgrade', 'mod_stackmastery'),
                'badge badge-info bg-info ml-1'
            );
            $cell .= ' ' . $badge;
        }
        return $cell;
    }

    /**
     * The state cell: state label with the finish reason as a suffix.
     *
     * @param \stdClass $attempt The attempt record.
     * @return string The cell.
     */
    protected function state_cell(\stdClass $attempt): string {
        $label = get_string('state' . $attempt->state, 'mod_stackmastery');
        if ($attempt->state !== attempt_store::STATE_INPROGRESS && !empty($attempt->finishreason)) {
            $label .= ' (' . get_string('finishreason_' . $attempt->finishreason, 'mod_stackmastery') . ')';
        }
        return $label;
    }

    /**
     * One mastery cell: whole percent on screen, one decimal place when downloading.
     *
     * @param \stdClass $row The overview row.
     * @param string $code The skill code.
     * @return string The cell.
     */
    protected function mastery_cell(\stdClass $row, string $code): string {
        if ($row->mastery === null || !isset($row->mastery[$code]) || !is_numeric($row->mastery[$code])) {
            return '-';
        }
        $value = (float) $row->mastery[$code];
        if ($this->is_downloading()) {
            return format_float(100.0 * $value, 1);
        }
        return ((int) round(100.0 * $value)) . '%';
    }

    /**
     * The exploration-share cell.
     *
     * @param float|null $share Share in [0,1], or null when the attempt has no steps.
     * @return string The cell.
     */
    protected function share_cell(?float $share): string {
        if ($share === null) {
            return '-';
        }
        if ($this->is_downloading()) {
            return format_float(100.0 * $share, 1);
        }
        return ((int) round(100.0 * $share)) . '%';
    }

    /**
     * The actions cell: the delete-attempt link (confirm page follows).
     *
     * @param \stdClass $attempt The attempt record.
     * @return string The cell.
     */
    protected function actions_cell(\stdClass $attempt): string {
        $url = new \moodle_url('/mod/stackmastery/report.php', [
            'id'      => $this->cm->id,
            'action'  => 'delete',
            'attempt' => (int) $attempt->id,
        ]);
        return \html_writer::link($url, get_string('deleteattempt', 'mod_stackmastery'));
    }
}
