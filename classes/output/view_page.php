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
 * Renderable for the landing page.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\output;

use cm_info;
use mod_stackmastery\local\attempt_store;
use mod_stackmastery\local\grades;
use mod_stackmastery\local\pool;
use mod_stackmastery\local\skills;
use mod_stackmastery\local\view_helper;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Builds the view.php template context: the state-machine card, mastery bars, attempt history,
 * the grade line and (for teachers) pool decay warnings.
 */
class view_page implements renderable, templatable {
    /** @var cm_info The course module. */
    protected cm_info $cm;

    /** @var stdClass The stackmastery instance record. */
    protected stdClass $instance;

    /**
     * Constructor.
     *
     * @param cm_info $cm The course module.
     * @param stdClass $instance The instance record.
     */
    public function __construct(cm_info $cm, stdClass $instance) {
        $this->cm = $cm;
        $this->instance = $instance;
    }

    /**
     * Export the template context.
     *
     * @param renderer_base $output The renderer.
     * @return stdClass Context for templates/view_page.mustache.
     */
    public function export_for_template(renderer_base $output): stdClass {
        global $USER;

        $context = \context_module::instance($this->cm->id);
        $canattempt = has_capability('mod/stackmastery:attempt', $context);
        $canviewreports = has_capability('mod/stackmastery:viewreports', $context);
        $attempts = attempt_store::get_attempts($this->instance->id, (int) $USER->id);
        $open = attempt_store::get_open_attempt($this->instance->id, (int) $USER->id);
        $state = view_helper::get_view_state($this->instance, $attempts, $open, time(), $canattempt);

        $data = (object) [
            'state'          => $state,
            'cmid'           => $this->cm->id,
            'sesskey'        => sesskey(),
            'actionurl'      => (new moodle_url('/mod/stackmastery/processattempt.php'))->out(false),
            'showstart'      => false,
            'startlabel'     => '',
            'showresume'     => false,
            'resumeurl'      => (new moodle_url(
                '/mod/stackmastery/attempt.php',
                ['id' => $this->cm->id]
            ))->out(false),
            'statemessage'   => '',
            'teacherplaceholder' => false,
            'facts'          => $this->export_facts(),
            'hasprogress'    => false,
            'progress'       => null,
            'hasattempts'    => false,
            'attempts'       => [],
            'gradeline'      => '',
            'canviewreports' => $canviewreports,
            'reporturl'      => (new moodle_url(
                '/mod/stackmastery/report.php',
                ['id' => $this->cm->id]
            ))->out(false),
            'poolwarnings'   => [],
            'haspoolwarnings' => false,
        ];

        $this->export_state($data, $state);
        $this->export_progress($data, $attempts, $open, $output);
        $this->export_history($data, $attempts);
        $this->export_gradeline($data, (int) $USER->id);
        if ($canviewreports) {
            $this->export_pool_warnings($data);
        }
        return $data;
    }

    /**
     * Fill the state-specific card fields.
     *
     * @param stdClass $data The context being built.
     * @param string $state The view_helper state.
     * @return void
     */
    protected function export_state(stdClass $data, string $state): void {
        switch ($state) {
            case view_helper::STATE_NOATTEMPTCAP:
                $data->teacherplaceholder = true;
                break;
            case view_helper::STATE_NOTOPEN:
                $data->statemessage = get_string(
                    'opensat',
                    'mod_stackmastery',
                    userdate((int) $this->instance->timeopen)
                );
                break;
            case view_helper::STATE_INPROGRESS:
                $data->showresume = true;
                break;
            case view_helper::STATE_CLOSED:
                $data->statemessage = get_string(
                    'closedat',
                    'mod_stackmastery',
                    userdate((int) $this->instance->timeclose)
                );
                break;
            case view_helper::STATE_CANSTART:
                $data->showstart = true;
                $data->startlabel = get_string('startattempt', 'mod_stackmastery');
                break;
            case view_helper::STATE_CANRETRY:
                $data->showstart = true;
                $data->startlabel = get_string('reattempt', 'mod_stackmastery');
                break;
            case view_helper::STATE_NOMOREATTEMPTS:
                $data->statemessage = get_string(
                    'noattemptsleft',
                    'mod_stackmastery',
                    (int) $this->instance->maxattempts
                );
                break;
        }
    }

    /**
     * The intro facts card: skills chips, target, budget, attempts allowed.
     *
     * @return stdClass Facts sub-context.
     */
    protected function export_facts(): stdClass {
        $chips = [];
        foreach (skills::decode_csv((string) $this->instance->skills) as $code) {
            $chips[] = ['name' => skills::label($code)];
        }
        $maxattempts = (int) $this->instance->maxattempts;
        return (object) [
            'skills'           => $chips,
            'targetpercent'    => (int) round(100 * (float) $this->instance->targetmastery),
            'budget'           => (int) $this->instance->budget,
            'maxattemptslabel' => $maxattempts === 0
                ? get_string('unlimited', 'mod_stackmastery') : (string) $maxattempts,
        ];
    }

    /**
     * The mastery bars: the open attempt's live mastery, else the best finished attempt's final.
     *
     * @param stdClass $data The context being built.
     * @param stdClass[] $attempts The user's attempts.
     * @param stdClass|null $open The open attempt.
     * @param renderer_base $output The renderer.
     * @return void
     */
    protected function export_progress(
        stdClass $data,
        array $attempts,
        ?stdClass $open,
        renderer_base $output
    ): void {
        if (empty($this->instance->showprogress) || $attempts === []) {
            return;
        }
        $source = null;
        $heading = '';
        if ($open !== null) {
            $source = json_decode((string) $open->masterycurrent, true);
            $heading = get_string('yourmastery', 'mod_stackmastery');
            $selected = skills::decode_csv((string) $open->skillssnapshot);
        } else {
            $best = null;
            $bestgrade = null;
            foreach ($attempts as $attempt) {
                $grade = grades::attempt_grade($this->instance, $attempt);
                if ($grade !== null && ($bestgrade === null || $grade > $bestgrade)) {
                    $best = $attempt;
                    $bestgrade = $grade;
                }
            }
            if ($best === null) {
                return;
            }
            $source = json_decode((string) $best->masteryfinal, true);
            $heading = get_string('yourbestresult', 'mod_stackmastery');
            $selected = skills::decode_csv((string) $best->skillssnapshot);
        }
        if (!is_array($source)) {
            return;
        }
        $bars = new progress_bars(
            $source,
            $selected,
            (float) $this->instance->targetmastery,
            true,
            $heading
        );
        $data->hasprogress = true;
        $data->progress = $bars->export_for_template($output);
    }

    /**
     * The attempt history table rows (the grade-contributing attempt is badged).
     *
     * @param stdClass $data The context being built.
     * @param stdClass[] $attempts The user's attempts.
     * @return void
     */
    protected function export_history(stdClass $data, array $attempts): void {
        if ($attempts === []) {
            return;
        }
        $bestid = null;
        $bestgrade = null;
        foreach ($attempts as $attempt) {
            $grade = grades::attempt_grade($this->instance, $attempt);
            if ($grade !== null && ($bestgrade === null || $grade > $bestgrade)) {
                $bestid = $attempt->id;
                $bestgrade = $grade;
            }
        }
        $rows = [];
        foreach ($attempts as $attempt) {
            $grade = grades::attempt_grade($this->instance, $attempt);
            $rows[] = [
                'number'     => (int) $attempt->attemptnumber,
                'started'    => userdate((int) $attempt->timestart),
                'finished'   => $attempt->timefinish ? userdate((int) $attempt->timefinish) : '-',
                'questions'  => (int) $attempt->questionsdone,
                'reached'    => !empty($attempt->reachedtarget),
                'grade'      => $grade === null ? '-' : format_float($grade, 2),
                'statelabel' => get_string('state' . $attempt->state, 'mod_stackmastery'),
                'counted'    => $attempt->id === $bestid,
            ];
        }
        $data->hasattempts = true;
        $data->attempts = $rows;
    }

    /**
     * The gradebook grade line, suppressed when the item or grade is hidden.
     *
     * @param stdClass $data The context being built.
     * @param int $userid The user.
     * @return void
     */
    protected function export_gradeline(stdClass $data, int $userid): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $grading = grade_get_grades(
            $this->cm->course,
            'mod',
            'stackmastery',
            $this->instance->id,
            $userid
        );
        if (empty($grading->items)) {
            return;
        }
        $item = $grading->items[0];
        if (!empty($item->hidden) || !isset($item->grades[$userid])) {
            return;
        }
        $grade = $item->grades[$userid];
        if ($grade->grade === null || !empty($grade->hidden)) {
            return;
        }
        $data->gradeline = get_string(
            'gradeline',
            'mod_stackmastery',
            $grade->str_grade . ' / ' . format_float((float) $item->grademax, 0)
        );
    }

    /**
     * Teacher-only pool decay warnings (deleted questions, un-tagged new versions): re-derived
     * live because the mod_form hard-block cannot catch drift after creation.
     *
     * @param stdClass $data The context being built.
     * @return void
     */
    protected function export_pool_warnings(stdClass $data): void {
        $selected = skills::decode_csv((string) $this->instance->skills);
        $result = pool::validate_selection((int) $this->instance->poolcategoryid, $selected);
        $messages = array_values($result['errors']);
        foreach ($result['warnings'] as $warning) {
            $messages[] = $warning;
        }
        $data->poolwarnings = array_map(fn($message) => ['message' => $message], $messages);
        $data->haspoolwarnings = $messages !== [];
    }
}
