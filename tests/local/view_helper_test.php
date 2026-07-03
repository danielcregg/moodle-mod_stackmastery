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
 * Tests for the pure landing-page state machine.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * The seven-state table, including the documented ordering conflicts.
 *
 * @covers \mod_stackmastery\local\view_helper
 */
final class view_helper_test extends \advanced_testcase {
    /** @var int A reference "now". */
    private const NOW = 1000000;

    /**
     * Build an instance record.
     *
     * @param array $overrides Field overrides.
     * @return \stdClass The instance.
     */
    private function instance(array $overrides = []): \stdClass {
        return (object) array_merge([
            'timeopen'    => 0,
            'timeclose'   => 0,
            'maxattempts' => 0,
        ], $overrides);
    }

    /**
     * Build an attempt record stub.
     *
     * @param string $state The state.
     * @return \stdClass The attempt.
     */
    private function attempt(string $state): \stdClass {
        return (object) ['state' => $state];
    }

    /**
     * State table provider.
     *
     * @return array The cases.
     */
    public static function states_provider(): array {
        $done = (object) ['state' => attempt_store::STATE_COMPLETE];
        $open = (object) ['state' => attempt_store::STATE_INPROGRESS];
        return [
            'no capability' => [
                [], [], null, false, view_helper::STATE_NOATTEMPTCAP,
            ],
            'not open yet' => [
                ['timeopen' => self::NOW + 100], [], null, true, view_helper::STATE_NOTOPEN,
            ],
            'open attempt' => [
                [], [$open], $open, true, view_helper::STATE_INPROGRESS,
            ],
            'closed' => [
                ['timeclose' => self::NOW - 100], [$done], null, true, view_helper::STATE_CLOSED,
            ],
            'fresh' => [
                [], [], null, true, view_helper::STATE_CANSTART,
            ],
            'retry unlimited' => [
                ['maxattempts' => 0], [$done, $done], null, true, view_helper::STATE_CANRETRY,
            ],
            'retry below cap' => [
                ['maxattempts' => 3], [$done, $done], null, true, view_helper::STATE_CANRETRY,
            ],
            'cap used' => [
                ['maxattempts' => 2], [$done, $done], null, true, view_helper::STATE_NOMOREATTEMPTS,
            ],
            // Ordering conflicts, pinned deliberately.
            'open outranks closed (resume-to-finish path)' => [
                ['timeclose' => self::NOW - 100], [$open], $open, true, view_helper::STATE_INPROGRESS,
            ],
            'notopen outranks history' => [
                ['timeopen' => self::NOW + 100], [$done], null, true, view_helper::STATE_NOTOPEN,
            ],
            'no capability outranks everything' => [
                ['timeopen' => self::NOW + 100], [$open], $open, false, view_helper::STATE_NOATTEMPTCAP,
            ],
            'closed with no attempts still closed' => [
                ['timeclose' => self::NOW - 100], [], null, true, view_helper::STATE_CLOSED,
            ],
        ];
    }

    /**
     * Each row of the state table maps to its state.
     *
     * @dataProvider states_provider
     * @param array $instanceoverrides Instance field overrides.
     * @param array $attempts The user's attempts.
     * @param \stdClass|null $open The open attempt.
     * @param bool $canattempt Capability flag.
     * @param string $expected Expected state constant.
     * @return void
     */
    public function test_states(
        array $instanceoverrides,
        array $attempts,
        ?\stdClass $open,
        bool $canattempt,
        string $expected
    ): void {
        $this->assertSame($expected, view_helper::get_view_state(
            $this->instance($instanceoverrides),
            $attempts,
            $open,
            self::NOW,
            $canattempt
        ));
    }

    /**
     * Only finished attempts count toward the attempt cap.
     *
     * @return void
     */
    public function test_open_attempts_do_not_consume_the_cap(): void {
        // One finished, cap 2, and (hypothetically stale) open rows in the history list: the
        // open attempt row is not counted as used, so the user could retry.
        $attempts = [$this->attempt(attempt_store::STATE_COMPLETE),
            $this->attempt(attempt_store::STATE_INPROGRESS)];
        $state = view_helper::get_view_state(
            $this->instance(['maxattempts' => 2]),
            $attempts,
            null,
            self::NOW,
            true
        );
        $this->assertSame(view_helper::STATE_CANRETRY, $state);
    }
}
