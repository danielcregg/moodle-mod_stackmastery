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
 * Renderable result card for one finished attempt.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\output;

use mod_stackmastery\local\attempt_store;
use mod_stackmastery\local\grades;
use mod_stackmastery\local\skill_manifest;
use mod_stackmastery\local\topics;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Self-contained summary of a finished (or abandoned) attempt: outcome, counts, timings, the
 * attempt grade and the final per-skill mastery. Used by the report attempt detail; view.php may
 * adopt it for its history section later.
 */
class attempt_summary implements renderable, templatable {
    /** @var stdClass The stackmastery instance record. */
    protected stdClass $instance;

    /** @var stdClass The stackmastery_attempts record. */
    protected stdClass $attempt;

    /**
     * Constructor.
     *
     * @param stdClass $instance The instance record.
     * @param stdClass $attempt The attempt record.
     */
    public function __construct(stdClass $instance, stdClass $attempt) {
        $this->instance = $instance;
        $this->attempt = $attempt;
    }

    /**
     * Export the template context.
     *
     * @param renderer_base $output The renderer.
     * @return stdClass Context for templates/attempt_summary.mustache.
     */
    public function export_for_template(renderer_base $output): stdClass {
        $attempt = $this->attempt;
        $statelabel = get_string('state' . $attempt->state, 'mod_stackmastery');
        $finished = $attempt->state !== attempt_store::STATE_INPROGRESS;
        if ($finished && !empty($attempt->finishreason)) {
            $statelabel .= ' (' . get_string('finishreason_' . $attempt->finishreason, 'mod_stackmastery') . ')';
        }
        $grade = grades::attempt_grade($this->instance, $attempt);
        $skills = $this->export_skills();
        return (object) [
            'heading'       => get_string('attemptsummary', 'mod_stackmastery'),
            'number'        => (int) $attempt->attemptnumber,
            'statelabel'    => $statelabel,
            'reached'       => !empty($attempt->reachedtarget),
            'questions'     => (int) $attempt->questionsdone,
            'stepstotarget' => $attempt->stepstotarget === null ? '-' : (string) (int) $attempt->stepstotarget,
            'timetotarget'  => $attempt->timetargetreached === null
                ? '-'
                : format_time((int) $attempt->timetargetreached - (int) $attempt->timestart),
            'started'       => userdate((int) $attempt->timestart),
            'finished'      => $attempt->timefinish > 0 ? userdate((int) $attempt->timefinish) : '-',
            'grade'         => $grade === null ? '-' : format_float($grade, 2),
            'hasskills'     => $skills !== [],
            'skills'        => $skills,
        ];
    }

    /**
     * The per-skill final mastery rows for the snapshot skills.
     *
     * @return array[] Rows of name, percentlabel and reached.
     */
    protected function export_skills(): array {
        $attempt = $this->attempt;
        $masteryjson = empty($attempt->masteryfinal)
            ? (string) $attempt->masterycurrent
            : (string) $attempt->masteryfinal;
        $mastery = json_decode($masteryjson, true);
        if (!is_array($mastery)) {
            return [];
        }
        $targets = json_decode((string) ($attempt->targetsnapshot ?? ''), true);
        $fallback = (float) ($this->instance->targetmastery ?? 0.95);
        $manifest = skill_manifest::from_attempt(
            $this->instance,
            $attempt,
            topics::for_instance((int) $this->instance->id)
        );
        $rows = [];
        foreach ($manifest->selected() as $code) {
            if (!isset($mastery[$code]) || !is_numeric($mastery[$code])) {
                continue;
            }
            $value = (float) $mastery[$code];
            $target = is_array($targets) && isset($targets[$code]) && is_numeric($targets[$code])
                ? (float) $targets[$code]
                : $fallback;
            $rows[] = [
                'name'         => $manifest->label($code),
                'percentlabel' => ((int) round(100.0 * $value)) . '%',
                'reached'      => $value >= $target - 1e-9,
            ];
        }
        return $rows;
    }
}
