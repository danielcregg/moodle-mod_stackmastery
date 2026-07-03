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
 * Renderable for the attempt page.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\output;

use cm_info;
use mod_stackmastery\local\attempt_state;
use mod_stackmastery\local\skills;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Builds the attempt.php template context: notices, the review panel of the just-graded step,
 * the mastery progress region, the open question inside its single mutation form, and the
 * End-attempt-now escape hatch.
 */
class attempt_page implements renderable, templatable {
    /** @var cm_info The course module. */
    protected cm_info $cm;

    /** @var stdClass The stackmastery instance record. */
    protected stdClass $instance;

    /** @var attempt_state The render-ready state from attempt_manager::current_state(). */
    protected attempt_state $state;

    /**
     * Constructor.
     *
     * @param cm_info $cm The course module.
     * @param stdClass $instance The instance record.
     * @param attempt_state $state The attempt state DTO.
     */
    public function __construct(cm_info $cm, stdClass $instance, attempt_state $state) {
        $this->cm = $cm;
        $this->instance = $instance;
        $this->state = $state;
    }

    /**
     * Map an attempt_state notice code to a user-facing message and alert style.
     *
     * @param string $code One of the attempt_state::NOTICE_* codes.
     * @return string[] Pair of message and bootstrap alert type.
     */
    public static function notice_for(string $code): array {
        switch ($code) {
            case attempt_state::NOTICE_RECOVERED:
                return [get_string('noticerecovered', 'mod_stackmastery'), 'info'];
            case attempt_state::NOTICE_BUSY:
                return [get_string('errattemptbusy', 'mod_stackmastery'), 'warning'];
            case attempt_state::NOTICE_PROVISIONFAILED:
                return [get_string('errnextquestion', 'mod_stackmastery'), 'danger'];
            default:
                return [get_string('errquestionrender', 'mod_stackmastery'), 'danger'];
        }
    }

    /**
     * The redirect notification for a finished attempt, keyed by its finish reason.
     *
     * @param string|null $reason The finishreason enum value, or null.
     * @param stdClass $attempt The attempt record (budget is read).
     * @return string[] Pair of message and \core\output\notification type.
     */
    public static function finish_notice(?string $reason, stdClass $attempt): array {
        switch ($reason) {
            case 'target':
                return [
                    get_string('attemptfinishedtarget', 'mod_stackmastery'),
                    \core\output\notification::NOTIFY_SUCCESS,
                ];
            case 'budget':
                return [
                    get_string('attemptfinishedbudget', 'mod_stackmastery', (int) $attempt->budget),
                    \core\output\notification::NOTIFY_INFO,
                ];
            case 'exhausted':
                return [
                    get_string('attemptfinishedexhausted', 'mod_stackmastery'),
                    \core\output\notification::NOTIFY_INFO,
                ];
            case 'timeclose':
                return [
                    get_string('activityclosedgraded', 'mod_stackmastery'),
                    \core\output\notification::NOTIFY_INFO,
                ];
            default:
                return [
                    get_string('attemptended', 'mod_stackmastery'),
                    \core\output\notification::NOTIFY_INFO,
                ];
        }
    }

    /**
     * Export the template context.
     *
     * @param renderer_base $output The renderer.
     * @return stdClass Context for templates/attempt_page.mustache.
     */
    public function export_for_template(renderer_base $output): stdClass {
        $state = $this->state;
        $showprogress = !empty($this->instance->showprogress);

        $data = (object) [
            'notices'          => [],
            'hasreview'        => false,
            'reviewheading'    => '',
            'reviewbannertype' => '',
            'reviewbanner'     => '',
            'reviewhtml'       => '',
            'hasprogress'      => false,
            'progress'         => null,
            'skillsattarget'   => '',
            'questionprogress' => '',
            'hasquestion'      => false,
            'questionheading'  => '',
            'skillchip'        => '',
            'questionhtml'     => '',
            'noquestion'       => false,
            'continueurl'      => (new moodle_url(
                '/mod/stackmastery/attempt.php',
                ['id' => $this->cm->id]
            ))->out(false),
            'actionurl'        => (new moodle_url('/mod/stackmastery/processattempt.php'))->out(false),
            'cmid'             => $this->cm->id,
            'slot'             => (int) ($state->slot ?? 0),
            'sesskey'          => sesskey(),
        ];

        if ($state->notice !== null) {
            [$message, $type] = self::notice_for($state->notice);
            $data->notices[] = ['message' => $message, 'type' => $type];
        }

        $this->export_review($data, $showprogress);
        $this->export_progress($data, $output, $showprogress);

        if ($state->has_open_question()) {
            $seq = (int) $state->seq;
            $data->questionprogress = get_string('questionprogress', 'mod_stackmastery', (object) [
                'n'      => $seq,
                'budget' => (int) $state->budget,
            ]);
            $data->hasquestion = true;
            $data->questionheading = get_string('questionx', 'mod_stackmastery', $seq);
            $data->skillchip = $this->open_skill_label();
            $data->questionhtml = (string) $state->questionhtml;
        } else {
            $data->noquestion = true;
        }
        return $data;
    }

    /**
     * Fill the review-panel fields from the reviewed step, when one is resolved.
     *
     * @param stdClass $data The context being built.
     * @param bool $showprogress Whether mastery is student-visible (gates the moved wording).
     * @return void
     */
    protected function export_review(stdClass $data, bool $showprogress): void {
        global $DB;
        $state = $this->state;
        if (!$state->has_review()) {
            return;
        }
        $data->hasreview = true;
        $data->reviewheading = get_string('questionx', 'mod_stackmastery', (int) $state->reviewseq);
        $data->reviewhtml = (string) $state->reviewhtml;
        $step = $DB->get_record('stackmastery_steps', [
            'attemptid' => (int) $state->attempt->id,
            'seq'       => (int) $state->reviewseq,
        ]);
        if (!$step) {
            return;
        }
        $correct = !empty($step->correct);
        $data->reviewbannertype = $correct ? 'success' : 'info';
        $banner = $correct
            ? get_string('correct', 'mod_stackmastery')
            : get_string('notquite', 'mod_stackmastery');
        if ($showprogress) {
            $moved = $this->mastery_moved($step);
            if ($moved !== null) {
                $banner .= ' ' . $moved;
            }
        }
        $data->reviewbanner = $banner;
    }

    /**
     * The "your mastery moved from x to y" sentence for a step, or null on unreadable JSON.
     *
     * @param stdClass $step The step record.
     * @return string|null The sentence.
     */
    protected function mastery_moved(stdClass $step): ?string {
        $before = json_decode((string) $step->masterybefore, true);
        $after = json_decode((string) $step->masteryafter, true);
        $code = (string) $step->servedskill;
        $frombad = !is_array($before) || !isset($before[$code]) || !is_numeric($before[$code]);
        $tobad = !is_array($after) || !isset($after[$code]) || !is_numeric($after[$code]);
        if ($frombad || $tobad) {
            return null;
        }
        return get_string('masterymoved', 'mod_stackmastery', (object) [
            'skill' => skills::label($code),
            'from'  => ((int) round(100.0 * (float) $before[$code])) . '%',
            'to'    => ((int) round(100.0 * (float) $after[$code])) . '%',
        ]);
    }

    /**
     * Fill the mastery progress region (bars plus the skills-at-target line).
     *
     * @param stdClass $data The context being built.
     * @param renderer_base $output The renderer.
     * @param bool $showprogress Whether mastery is student-visible.
     * @return void
     */
    protected function export_progress(stdClass $data, renderer_base $output, bool $showprogress): void {
        $state = $this->state;
        if (!$showprogress || $state->selectedskills === []) {
            return;
        }
        $bars = new progress_bars(
            $state->mastery,
            $state->selectedskills,
            (float) $this->instance->targetmastery,
            true,
            get_string('yourmastery', 'mod_stackmastery')
        );
        $data->hasprogress = true;
        $data->progress = $bars->export_for_template($output);

        $reached = 0;
        foreach ($state->selectedskills as $code) {
            $value = (float) ($state->mastery[$code] ?? 0.0);
            $target = (float) ($state->targets[$code] ?? (float) $this->instance->targetmastery);
            if ($value >= $target - 1e-9) {
                $reached++;
            }
        }
        $data->skillsattarget = get_string('skillsattarget', 'mod_stackmastery', (object) [
            'k'     => $reached,
            'total' => count($state->selectedskills),
        ]);
    }

    /**
     * The skill label of the open question, from the attempt's frozen selection context.
     *
     * @return string The label, or empty when unavailable.
     */
    protected function open_skill_label(): string {
        $pending = json_decode((string) ($this->state->attempt->pendingjson ?? ''));
        if (!is_object($pending) || empty($pending->servedskill)) {
            return '';
        }
        $code = (string) $pending->servedskill;
        if (!skills::is_skill($code)) {
            return '';
        }
        return get_string('skillslabel', 'mod_stackmastery', skills::label($code));
    }
}
