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
 * The render-ready view of one attempt, produced by attempt_manager::current_state().
 *
 * Pure data plus tiny accessors; page logic lives in the WP-6 pages. The question payload follows
 * master plan C28: headhtml carries render_question_head_html() output for every slot rendered
 * below (the page must echo it before the header, then question_engine::initialise_js(), then the
 * body HTML inside its single form). Notices are machine codes, not lang keys: the pages own the
 * mapping to user-facing strings.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Attempt render DTO: open-slot payload, review payload, mastery bars data, notices and the
 * finished summary fields.
 */
final class attempt_state {
    /** Notice: the attempt was found SLOTLESS and recovery provisioned the next question. */
    public const NOTICE_RECOVERED = 'recovered';

    /** Notice: another request holds the attempt lock; the state shown may be mid-transition. */
    public const NOTICE_BUSY = 'busy';

    /** Notice: SLOTLESS recovery could not provision a question (map to errnextquestion). */
    public const NOTICE_PROVISIONFAILED = 'provisionfailed';

    /** Notice: the slot exists but its question HTML could not be rendered. */
    public const NOTICE_RENDERFAILED = 'renderfailed';

    /** @var \stdClass The stackmastery_attempts row, fresh after any recovery. */
    public \stdClass $attempt;

    /** @var bool Whether the attempt is in a terminal state. */
    public bool $finished = false;

    /** @var string|null The finishreason enum value, when finished. */
    public ?string $finishreason = null;

    /** @var bool Whether every selected skill reached its target during this attempt. */
    public bool $reachedtarget = false;

    /** @var int|null The open usage slot, or null when finished or awaiting recovery. */
    public ?int $slot = null;

    /** @var int|null 1-based display number of the open question (questionsdone plus one). */
    public ?int $seq = null;

    /** @var string|null Rendered HTML of the open question (render=true and a slot is open). */
    public ?string $questionhtml = null;

    /** @var string|null Head contributions for every rendered slot; echo before the page header. */
    public ?string $headhtml = null;

    /** @var int|null Sealed usage slot rendered read-only as the review panel. */
    public ?int $reviewslot = null;

    /** @var int|null Seq of the reviewed step. */
    public ?int $reviewseq = null;

    /** @var string|null Read-only render of the reviewed slot (worked solution visible). */
    public ?string $reviewhtml = null;

    /** @var array<string, float> Mastery keyed by skill code: live, or final when finished. */
    public array $mastery = [];

    /** @var array<string, float> Per-skill targets from the attempt snapshot, keyed by code. */
    public array $targets = [];

    /** @var string[] Selected skill codes from the attempt snapshot, canonical order. */
    public array $selectedskills = [];

    /** @var int Graded answers so far (equals the step count). */
    public int $questionsdone = 0;

    /** @var int Effective question budget of this attempt. */
    public int $budget = 0;

    /** @var float|null Attempt grade on the 0 to 100 scale, when finished; null in progress. */
    public ?float $grade = null;

    /** @var string|null One of the NOTICE_* machine codes, or null. */
    public ?string $notice = null;

    /**
     * Whether an open question is available to render.
     *
     * @return bool True when a slot is open.
     */
    public function has_open_question(): bool {
        return $this->slot !== null;
    }

    /**
     * Whether a review panel is available to render.
     *
     * @return bool True when a sealed slot was resolved for review.
     */
    public function has_review(): bool {
        return $this->reviewslot !== null;
    }
}
