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
 * Grade mapping and aggregation for STACK Mastery attempts.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Pure grade logic: no stored final grade anywhere.
 *
 * Grades are derived on read from reachedtarget, masteryfinal and the attempt's own skills
 * snapshot under the instance's CURRENT grademode, so a teacher flipping the grade mode regrades
 * cleanly with zero data migration.
 */
final class grades {
    /** Binary reached-target grading (the default): 100 when the target was reached, else 0. */
    public const GRADEMODE_REACHEDTARGET = 0;

    /** Mean final mastery over the attempt's selected skills, as a 0 to 100 percentage. */
    public const GRADEMODE_MEANMASTERY = 1;

    /**
     * Grade (0..100) for one finished attempt under the instance's grademode.
     *
     * Uses the ATTEMPT's skills snapshot, not the instance's current csv: the student was tested
     * on the snapshot; the teacher owns how it maps to a grade.
     *
     * @param \stdClass $stackmastery The instance record (grademode is read).
     * @param \stdClass $attempt A stackmastery_attempts record.
     * @return float|null The grade, or null when the attempt cannot be graded (in progress, or
     *         corrupt mastery JSON, which is reported via debugging() and never an exception).
     */
    public static function attempt_grade(\stdClass $stackmastery, \stdClass $attempt): ?float {
        if ($attempt->state === attempt_store::STATE_INPROGRESS) {
            return null;
        }
        if ((int) $stackmastery->grademode === self::GRADEMODE_REACHEDTARGET) {
            return empty($attempt->reachedtarget) ? 0.0 : 100.0;
        }

        $mastery = json_decode((string) $attempt->masteryfinal, true);
        if (!is_array($mastery)) {
            debugging(
                'mod_stackmastery: attempt ' . $attempt->id . ' has unreadable masteryfinal JSON.',
                DEBUG_DEVELOPER
            );
            return null;
        }
        $selected = skills::decode_csv((string) $attempt->skillssnapshot);
        $total = 0.0;
        foreach ($selected as $code) {
            $value = $mastery[$code] ?? 0.0;
            $total += is_numeric($value) ? (float) $value : 0.0;
        }
        return round(100.0 * $total / count($selected), 5);
    }

    /**
     * Best (highest) attempt grade per user, in the shape grade_update() expects.
     *
     * @param \stdClass $stackmastery The instance record.
     * @param int $userid One user, or 0 for every user with a gradable attempt.
     * @return array<int, \stdClass> Map userid => object with userid, rawgrade and dategraded.
     */
    public static function get_user_grades(\stdClass $stackmastery, int $userid = 0): array {
        global $DB;

        $params = ['stackmasteryid' => $stackmastery->id, 'inprogress' => attempt_store::STATE_INPROGRESS];
        $where = 'stackmasteryid = :stackmasteryid AND state <> :inprogress';
        if ($userid) {
            $where .= ' AND userid = :userid';
            $params['userid'] = $userid;
        }
        $attempts = $DB->get_records_select(
            'stackmastery_attempts',
            $where,
            $params,
            'userid, attemptnumber'
        );

        $grades = [];
        foreach ($attempts as $attempt) {
            $grade = self::attempt_grade($stackmastery, $attempt);
            if ($grade === null) {
                continue;
            }
            $uid = (int) $attempt->userid;
            $isbetter = !isset($grades[$uid]) || $grade > $grades[$uid]->rawgrade;
            if ($isbetter) {
                $grades[$uid] = (object) [
                    'userid'     => $uid,
                    'rawgrade'   => $grade,
                    'dategraded' => (int) $attempt->timefinish,
                ];
            }
        }
        return $grades;
    }
}
