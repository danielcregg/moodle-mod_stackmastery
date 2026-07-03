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
 * The result of one submission through the attempt engine.
 *
 * A plain value object: attempt_manager::process_submission() classifies every POST into exactly
 * one result code and fills the fields the pages need to redirect and render (design 03 section 6,
 * amended by master plan C8 with the graded-but-next-slot-pending recovery case).
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Submission outcome DTO: one result code plus the redirect/render payload.
 */
final class submit_outcome {
    /** No fields for this attempt's open slot were present in the POST; nothing happened. */
    public const NOOP = 'noop';

    /** The step was processed but no graded try occurred (a STACK validation press or invalid input). */
    public const VALIDATED = 'validated';

    /** A graded try was logged and the attempt continues (next slot open, or pending recovery). */
    public const GRADED = 'graded';

    /** A graded try was logged and the attempt reached a terminal state. */
    public const FINISHED = 'finished';

    /** An out-of-sequence or stale-slot replay (double click, back button, second tab); no change. */
    public const DUPLICATE = 'duplicate';

    /** The per-user attempt lock could not be acquired; another request is mid-processing. */
    public const BUSY = 'busy';

    /** A CAS or grading failure; nothing was persisted and the student can retry the same Check. */
    public const ERROR = 'error';

    /** @var string One of the result constants above. */
    public string $result;

    /** @var bool|null Whether the graded answer was correct (GRADED/FINISHED only). */
    public ?bool $correct = null;

    /** @var float|null Raw graded fraction (GRADED/FINISHED only; null on the E26 guard path). */
    public ?float $fraction = null;

    /** @var int|null Seq of the sealed question; drives the review panel redirect. */
    public ?int $lastseq = null;

    /** @var int|null Usage slot that was sealed by this submission. */
    public ?int $gradedslot = null;

    /** @var int|null The open slot after processing; null when finished or provisioning is pending. */
    public ?int $nextslot = null;

    /** @var bool True when the answer was graded but the next slot could not be provisioned yet. */
    public bool $nextpending = false;

    /** @var string|null The finishreason enum value (FINISHED only). */
    public ?string $finishreason = null;

    /** @var string|null Skill code of the graded step (mastery-delta display). */
    public ?string $servedskill = null;

    /** @var array<string, float> Mastery before the update, keyed by skill code (graded results). */
    public array $masterybefore = [];

    /** @var array<string, float> Mastery after the update, keyed by skill code (graded results). */
    public array $masteryafter = [];

    /** @var string|null Lang key describing the problem (ERROR/BUSY and pending-recovery cases). */
    public ?string $errorstring = null;

    /**
     * Use the named factories.
     *
     * @param string $result One of the result constants.
     */
    private function __construct(string $result) {
        $this->result = $result;
    }

    /**
     * A bare outcome of the given result code.
     *
     * @param string $result One of the result constants.
     * @return self The outcome.
     */
    public static function of(string $result): self {
        return new self($result);
    }

    /**
     * A failure outcome carrying the lang key the page should show.
     *
     * @param string $result ERROR or BUSY.
     * @param string $errorstring Lang key in mod_stackmastery, e.g. errgrading.
     * @return self The outcome.
     */
    public static function failure(string $result, string $errorstring): self {
        $outcome = new self($result);
        $outcome->errorstring = $errorstring;
        return $outcome;
    }

    /**
     * Whether a graded try was recorded (GRADED or FINISHED).
     *
     * @return bool True for graded results.
     */
    public function is_graded(): bool {
        return $this->result === self::GRADED || $this->result === self::FINISHED;
    }

    /**
     * Whether the attempt is now in a terminal state.
     *
     * @return bool True for FINISHED.
     */
    public function is_finished(): bool {
        return $this->result === self::FINISHED;
    }
}
