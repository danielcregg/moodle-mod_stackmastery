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
 * Per-attempt step drilldown table of the teacher report.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local\report;

use mod_stackmastery\local\skill_manifest;
use mod_stackmastery\local\skills;
use mod_stackmastery\local\topics;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * One row per experience-log step of a single attempt: what was served, why, the grading result
 * and the mastery movement. Downloadable through the core dataformat plugins. Steps are
 * immutable audit data, so there are no row actions.
 */
class steps_table extends \flexible_table {
    /** @var \stdClass The stackmastery instance record. */
    protected \stdClass $instance;

    /** @var skill_manifest The instance manifest, for resolving core and custom skill labels. */
    protected skill_manifest $manifest;

    /**
     * Configure columns, headers and the download surface.
     *
     * @param \stdClass $instance The instance record.
     * @param \cm_info $cm The course module.
     * @param int $attemptid The attempt whose steps are listed (uniqueid and filename input).
     * @param \moodle_url $baseurl The attempt-detail URL.
     * @param string $download Requested dataformat name, or empty for the on-screen table.
     */
    public function __construct(
        \stdClass $instance,
        \cm_info $cm,
        int $attemptid,
        \moodle_url $baseurl,
        string $download
    ) {
        parent::__construct('mod_stackmastery-steps-' . $cm->id . '-' . $attemptid);
        $this->instance = $instance;
        $this->manifest = skill_manifest::from_instance($instance, topics::for_instance((int) $instance->id));

        $this->define_baseurl($baseurl);
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);
        $this->is_downloading(
            $download,
            clean_filename($instance->name . '-attempt-' . $attemptid . '-steps'),
            get_string('attemptdetail', 'mod_stackmastery')
        );

        $this->define_columns([
            'seq', 'time', 'skill', 'difficulty', 'question', 'source', 'correct', 'fraction', 'masterychange',
        ]);
        $this->define_headers([
            get_string('colseq', 'mod_stackmastery'),
            get_string('time'),
            get_string('colskill', 'mod_stackmastery'),
            get_string('coldifficulty', 'mod_stackmastery'),
            get_string('colquestion', 'mod_stackmastery'),
            get_string('colsource', 'mod_stackmastery'),
            get_string('colcorrect', 'mod_stackmastery'),
            get_string('colfraction', 'mod_stackmastery'),
            get_string('colmasterychange', 'mod_stackmastery'),
        ]);
        $this->sortable(false);
        $this->collapsible(false);
        $this->pageable(false);
    }

    /**
     * Render (or stream) the table from report_data::attempt_steps() output.
     *
     * @param \stdClass[] $steps The step records with questionname joined.
     * @return void
     */
    public function build(array $steps): void {
        $this->setup();
        foreach ($steps as $step) {
            $this->add_data($this->format_step_cells($step));
        }
        $this->finish_output();
    }

    /**
     * Format one step row into cells matching the defined columns.
     *
     * @param \stdClass $step The step record.
     * @return string[] The cell values.
     */
    protected function format_step_cells(\stdClass $step): array {
        $timeformat = $this->is_downloading()
            ? '%Y-%m-%d %H:%M'
            : get_string('strftimedatetimeshort', 'langconfig');
        return [
            (string) (int) $step->seq,
            userdate((int) $step->timeanswered, $timeformat),
            $this->manifest->label((string) $step->servedskill),
            skills::difficulty_label((string) $step->serveddifficulty),
            format_string((string) $step->questionname),
            $this->source_cell($step),
            empty($step->correct) ? get_string('no') : get_string('yes'),
            $step->fraction === null ? '-' : format_float((float) $step->fraction, 2),
            $this->mastery_change_cell($step),
        ];
    }

    /**
     * The action-source cell, annotated when the policy recommendation was overridden.
     *
     * @param \stdClass $step The step record.
     * @return string The cell.
     */
    protected function source_cell(\stdClass $step): string {
        $label = get_string('source' . $step->actionsource, 'mod_stackmastery');
        $overridden = $step->recommendedskill !== $step->servedskill
            || $step->recommendeddifficulty !== $step->serveddifficulty;
        if (!$overridden) {
            return $label;
        }
        $detail = get_string('recommendedvsserved', 'mod_stackmastery', (object) [
            'rec'    => $this->manifest->label((string) $step->recommendedskill) . ' / '
                . skills::difficulty_label((string) $step->recommendeddifficulty),
            'served' => $this->manifest->label((string) $step->servedskill) . ' / '
                . skills::difficulty_label((string) $step->serveddifficulty),
        ]);
        if ($this->is_downloading()) {
            return $label . ' - ' . $detail;
        }
        return $label . \html_writer::div($detail, 'small text-muted');
    }

    /**
     * The mastery-movement cell for the served skill: from, to and the signed delta.
     *
     * @param \stdClass $step The step record.
     * @return string The cell.
     */
    protected function mastery_change_cell(\stdClass $step): string {
        $before = json_decode((string) $step->masterybefore, true);
        $after = json_decode((string) $step->masteryafter, true);
        $code = (string) $step->servedskill;
        $frombad = !is_array($before) || !isset($before[$code]) || !is_numeric($before[$code]);
        $tobad = !is_array($after) || !isset($after[$code]) || !is_numeric($after[$code]);
        if ($frombad || $tobad) {
            return '-';
        }
        $from = (int) round(100.0 * (float) $before[$code]);
        $to = (int) round(100.0 * (float) $after[$code]);
        return get_string('masteryfromto', 'mod_stackmastery', (object) [
            'from'  => $from . '%',
            'to'    => $to . '%',
            'delta' => sprintf('%+d%%', $to - $from),
        ]);
    }
}
