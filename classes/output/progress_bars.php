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

use mod_stackmastery\local\skill_manifest;
use mod_stackmastery\local\skills;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Exports the accessible progress-bars region (the a11y contract lives in the template).
 *
 * Used by the landing page, the attempt page and the report user detail so the bars always
 * render identically. Callers on custom-topics surfaces pass an ordered label map (usually
 * from {@see progress_bars::manifest_labels()}) so per-instance topic labels resolve; without
 * one the bars fall back to the canonical core-8 vocabulary.
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

    /** @var array<string, string>|null Ordered code-to-label map; null = core-8 vocabulary. */
    protected ?array $labels;

    /**
     * Constructor.
     *
     * @param array $mastery Full mastery vector keyed by skill code.
     * @param array $selectedskills Selected skill codes; only these are exported.
     * @param float $target Target mastery in [0, 1].
     * @param bool $showtarget Whether to draw the target line and note.
     * @param string $heading Lang-resolved heading.
     * @param array|null $labels Ordered code-to-label map carrying both the display order and
     *        the labels (manifest vocabulary); null keeps the canonical core-8 behaviour.
     */
    public function __construct(
        array $mastery,
        array $selectedskills,
        float $target,
        bool $showtarget,
        string $heading,
        ?array $labels = null
    ) {
        $this->mastery = $mastery;
        $this->selectedskills = $selectedskills;
        $this->target = $target;
        $this->showtarget = $showtarget;
        $this->heading = $heading;
        $this->labels = $labels;
    }

    /**
     * The ordered code-to-label map of a skill manifest, for the labels constructor argument.
     *
     * Shared by every surface that renders manifest vocabulary (bars, coverage, chips) so the
     * label resolution rule lives exactly once on the output layer.
     *
     * @param skill_manifest $manifest The instance- or attempt-scoped manifest.
     * @return array<string, string> Map of code to label in manifest order.
     */
    public static function manifest_labels(skill_manifest $manifest): array {
        $labels = [];
        foreach ($manifest->codes() as $code) {
            $labels[$code] = $manifest->label($code);
        }
        return $labels;
    }

    /**
     * Export the template context.
     *
     * @param renderer_base $output The renderer.
     * @return stdClass Context for templates/progress_bars.mustache.
     */
    public function export_for_template(renderer_base $output): stdClass {
        $order = $this->labels === null ? skills::CODES : array_keys($this->labels);
        $rows = [];
        foreach ($order as $code) {
            if (!in_array($code, $this->selectedskills, true)) {
                continue;
            }
            $value = $this->mastery[$code] ?? 0.0;
            $value = is_numeric($value) ? (float) $value : 0.0;
            $percent = (int) round(100 * $value);
            $rows[] = [
                'code'         => $code,
                'name'         => $this->labels === null ? skills::label($code) : $this->labels[$code],
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
