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
 * Shared per-skill mastery bars exporter.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\output;

use mod_stackmastery\local\skills;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Exports the accessible progress-bars region (the a11y contract lives in the template).
 *
 * Used by the landing page, the attempt page and the report user detail so the bars always
 * render identically.
 */
class progress_bars implements renderable, templatable {
    /** @var array<string, float> Full mastery vector keyed by skill code. */
    protected array $mastery;

    /** @var string[] The selected skill codes to display. */
    protected array $selectedskills;

    /** @var float The target mastery in [0, 1]. */
    protected float $target;

    /** @var bool Whether to draw the target line and note. */
    protected bool $showtarget;

    /** @var string Lang-resolved heading, e.g. "Your mastery". */
    protected string $heading;

    /**
     * Constructor.
     *
     * @param array $mastery Full mastery vector keyed by skill code.
     * @param array $selectedskills Selected skill codes; only these are exported, canonical order.
     * @param float $target Target mastery in [0, 1].
     * @param bool $showtarget Whether to draw the target line and note.
     * @param string $heading Lang-resolved heading.
     */
    public function __construct(
        array $mastery,
        array $selectedskills,
        float $target,
        bool $showtarget,
        string $heading
    ) {
        $this->mastery = $mastery;
        $this->selectedskills = $selectedskills;
        $this->target = $target;
        $this->showtarget = $showtarget;
        $this->heading = $heading;
    }

    /**
     * Export the template context.
     *
     * @param renderer_base $output The renderer.
     * @return stdClass Context for templates/progress_bars.mustache.
     */
    public function export_for_template(renderer_base $output): stdClass {
        $rows = [];
        foreach (skills::CODES as $code) {
            if (!in_array($code, $this->selectedskills, true)) {
                continue;
            }
            $value = $this->mastery[$code] ?? 0.0;
            $value = is_numeric($value) ? (float) $value : 0.0;
            $percent = (int) round(100 * $value);
            $rows[] = [
                'code'         => $code,
                'name'         => skills::label($code),
                'percent'      => $percent,
                'percentlabel' => $percent . '%',
                // Same epsilon the runtime termination check uses.
                'reached'      => $value >= $this->target - 1e-9,
            ];
        }
        return (object) [
            'heading'       => $this->heading,
            'showtarget'    => $this->showtarget,
            'targetpercent' => (int) round(100 * $this->target),
            'skills'        => $rows,
        ];
    }
}
