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
 * Pure view-state machine for the landing page.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Decides which of the seven landing states view.php renders. Pure and unit-tested.
 */
final class view_helper {
    /** The user cannot attempt (teacher, observer): placeholder card, no button. */
    public const STATE_NOATTEMPTCAP = 'noattemptcap';

    /** timeopen is in the future: disabled button plus the opening date. */
    public const STATE_NOTOPEN = 'notopen';

    /** An open attempt exists: Resume is the primary action. */
    public const STATE_INPROGRESS = 'inprogress';

    /** timeclose has passed and nothing is open: history only. */
    public const STATE_CLOSED = 'closed';

    /** No attempts yet: Start is the primary action. */
    public const STATE_CANSTART = 'canstart';

    /** Finished attempts exist and more are allowed: summary plus Start another attempt. */
    public const STATE_CANRETRY = 'canretry';

    /** The attempt cap is used up: summary plus a muted note. */
    public const STATE_NOMOREATTEMPTS = 'nomoreattempts';

    /**
     * Compute the view state. Conditions are evaluated strictly in this order.
     *
     * INPROGRESS deliberately outranks CLOSED: resuming a closed activity routes to the attempt
     * page, which finishes it grade-as-is, so already-answered work is never stranded.
     *
     * @param \stdClass $instance The stackmastery record (timeopen, timeclose, maxattempts read).
     * @param \stdClass[] $attempts Every attempt of this user, any state.
     * @param \stdClass|null $open The user's single open attempt, or null.
     * @param int $now The current time.
     * @param bool $canattempt Whether the user holds mod/stackmastery:attempt.
     * @return string One of the STATE_* constants.
     */
    public static function get_view_state(
        \stdClass $instance,
        array $attempts,
        ?\stdClass $open,
        int $now,
        bool $canattempt
    ): string {
        if (!$canattempt) {
            return self::STATE_NOATTEMPTCAP;
        }
        if (!empty($instance->timeopen) && $now < (int) $instance->timeopen) {
            return self::STATE_NOTOPEN;
        }
        if ($open !== null) {
            return self::STATE_INPROGRESS;
        }
        if (!empty($instance->timeclose) && $now > (int) $instance->timeclose) {
            return self::STATE_CLOSED;
        }
        if ($attempts === []) {
            return self::STATE_CANSTART;
        }
        $finished = 0;
        foreach ($attempts as $attempt) {
            if ($attempt->state !== attempt_store::STATE_INPROGRESS) {
                $finished++;
            }
        }
        $maxattempts = (int) $instance->maxattempts;
        if ($maxattempts === 0 || $finished < $maxattempts) {
            return self::STATE_CANRETRY;
        }
        return self::STATE_NOMOREATTEMPTS;
    }
}
