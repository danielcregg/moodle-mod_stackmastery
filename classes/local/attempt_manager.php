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
 * The QUBA-driven adaptive attempt orchestrator.
 *
 * Owns every question_engine call, transaction, lock, event and grade/completion push of the
 * attempt lifecycle (design 03 as amended by the master plan): start/resume, the T1/T2 submit
 * split (T1 = QUBA save + experience row + mastery/attempt update + terminal check, atomic;
 * T2 = provision the next slot, whose CAS failure can never roll back a graded answer),
 * first-class SLOTLESS recovery, early finish that seals an open slot without a step row (C27),
 * the abandon sweep contract, and delete primitives. Selection is delegated entirely to
 * policy::choose() (C15); step rows are written only through experience::log_step() (C16);
 * epsilon comes from the instance snapshot clamped to [0, 0.2] (C18).
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Adaptive attempt engine: lifecycle, locking, transactions, recovery and side effects.
 */
class attempt_manager {
    /** Terminal reason: every selected skill reached its target. */
    public const REASON_TARGET = 'target';

    /** Terminal reason: the effective question budget was spent. */
    public const REASON_BUDGET = 'budget';

    /** Terminal reason: no eligible question remained (cells drained unevenly). */
    public const REASON_EXHAUSTED = 'exhausted';

    /** Terminal reason: the student pressed "End attempt now". */
    public const REASON_USER = 'user';

    /** Terminal reason: the activity close date passed. */
    public const REASON_TIMECLOSE = 'timeclose';

    /** Terminal reason: the cleanup sweep closed a stale attempt. */
    public const REASON_ABANDONED = 'abandoned';

    /** @var int Max CAS-level start failures per provisioning call before giving up recoverably. */
    public const MAX_START_RETRIES = 3;

    /** @var int Bound on redraws of CAS-skipped rows within one provisioning call. */
    public const MAX_DRAW_TRIES = 25;

    /** @var int Lock acquire timeout for submissions, in seconds. */
    public const LOCK_TIMEOUT_SUBMIT = 10;

    /** @var int Lock acquire timeout for start and recovery, in seconds. */
    public const LOCK_TIMEOUT_START = 10;

    /** @var int Lock acquire timeout for the cleanup sweep, in seconds (skip and retry next run). */
    public const LOCK_TIMEOUT_CLEANUP = 1;

    /** @var \stdClass The stackmastery instance record. */
    protected \stdClass $instance;

    /** @var \cm_info The course module. */
    protected \cm_info $cm;

    /** @var \context_module The module context (QUBAs are owned by it). */
    protected \context_module $context;

    /** @var policy The loaded serving policy (the single selection brain). */
    protected policy $policy;

    /** @var string The active policy id stamped on attempts and steps. */
    protected string $policyid;

    /** @var \core\lock\lock_factory The lock factory for per-user attempt locks. */
    protected \core\lock\lock_factory $lockfactory;

    /** @var callable|null Injectable uniform [0,1) source for policy::choose() (test seam). */
    protected $rng;

    /**
     * DI constructor (unit tests inject a policy, a lock factory and a deterministic RNG).
     *
     * @param \stdClass $instance The stackmastery instance record.
     * @param \cm_info $cm The course module.
     * @param \context_module $context The module context.
     * @param policy $policy The loaded serving policy.
     * @param string $policyid The active policy id to stamp on new attempts and steps.
     * @param \core\lock\lock_factory|null $lockfactory Lock factory; null resolves the default.
     * @param callable|null $rng Uniform [0,1) source passed to policy::choose(); null for random.
     */
    public function __construct(
        \stdClass $instance,
        \cm_info $cm,
        \context_module $context,
        policy $policy,
        string $policyid,
        ?\core\lock\lock_factory $lockfactory = null,
        ?callable $rng = null
    ) {
        $this->instance = $instance;
        $this->cm = $cm;
        $this->context = $context;
        $this->policy = $policy;
        $this->policyid = $policyid;
        $this->lockfactory = $lockfactory ?? \core\lock\lock_config::get_lock_factory('mod_stackmastery');
        $this->rng = $rng;
    }

    /**
     * Factory used by pages and tasks; wires the real collaborators. Fails CLOSED: a missing or
     * corrupt policy artifact must never silently degrade selection, so any load failure becomes
     * an admin-actionable errpolicyunavailable.
     *
     * @param \stdClass $instance The stackmastery instance record.
     * @param \cm_info $cm The course module.
     * @param \context_module $context The module context.
     * @return self The manager.
     * @throws \moodle_exception When the policy artifact cannot be loaded and validated.
     */
    public static function create(\stdClass $instance, \cm_info $cm, \context_module $context): self {
        try {
            $active = policy_store::get_active();
            $policy = policy::load($active->path);
            $policyid = (string) $active->policyid;
        } catch (\Throwable $e) {
            debugging('mod_stackmastery: policy load failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            throw new \moodle_exception('errpolicyunavailable', 'mod_stackmastery');
        }
        return new self($instance, $cm, $context, $policy, $policyid);
    }

    /**
     * The user's open attempt at this instance, if any.
     *
     * @param int $userid The user.
     * @return \stdClass|null The open attempt record, or null.
     */
    public function get_open_attempt(int $userid): ?\stdClass {
        return attempt_store::get_open_attempt((int) $this->instance->id, $userid);
    }

    /**
     * How many attempts (any state) the user has at this instance, for the maxattempts gate.
     *
     * @param int $userid The user.
     * @return int The attempt count.
     */
    public function count_user_attempts(int $userid): int {
        global $DB;
        return $DB->count_records('stackmastery_attempts', [
            'stackmasteryid' => (int) $this->instance->id,
            'userid'         => $userid,
        ]);
    }

    /**
     * Return the user's open attempt, or create one: snapshot the pool, cap the effective budget
     * at the distinct eligible entry count, then provision the first slot. Resuming NEVER draws.
     * A first-provisioning failure leaves the new attempt in the recoverable SLOTLESS state
     * (current_state() retries) rather than failing the start.
     *
     * @param int $userid The student.
     * @param int|null $timenow Injectable clock; null for time().
     * @return \stdClass The attempt record (possibly already terminal on the early-complete edge).
     * @throws \moodle_exception errattemptbusy, errmaxattempts or errpoolempty.
     */
    public function start_or_resume(int $userid, ?int $timenow = null): \stdClass {
        global $DB;
        $timenow = $timenow ?? time();
        $lock = $this->get_user_lock($userid, self::LOCK_TIMEOUT_START);
        if (!$lock) {
            throw new \moodle_exception('errattemptbusy', 'mod_stackmastery');
        }
        try {
            // The one-open-attempt recheck inside the lock (C3); the unique
            // (stackmasteryid, userid, inprogressuniq) index is the DB backstop.
            $open = $this->get_open_attempt($userid);
            if ($open !== null) {
                return $open;
            }
            $maxattempts = (int) $this->instance->maxattempts;
            if ($maxattempts > 0 && $this->count_user_attempts($userid) >= $maxattempts) {
                throw new \moodle_exception('errmaxattempts', 'mod_stackmastery');
            }

            $mastery = mastery::init();
            $selectedcodes = skills::decode_csv((string) ($this->instance->skills ?? ''));
            $targetjson = $this->instance_target_json();

            $attempt = (object) [
                'stackmasteryid'  => (int) $this->instance->id,
                'userid'          => $userid,
                'attemptnumber'   => 0,
                'qubaid'          => 0,
                'state'           => attempt_store::STATE_INPROGRESS,
                'finishreason'    => null,
                'inprogressuniq'  => 0,
                'currentslot'     => 0,
                'pendingjson'     => null,
                'preview'         => 0,
                'masterycurrent'  => $mastery->to_json(),
                'skillssnapshot'  => skills::encode_csv($selectedcodes),
                'targetsnapshot'  => $targetjson,
                'budget'          => 0,
                'questionsdone'   => 0,
                'reachedtarget'   => 0,
                'stepstotarget'   => null,
                'timetargetreached' => null,
                'masteryfinal'    => null,
                'policyversion'   => $this->policyid,
                'bktmodelversion' => $mastery->model_version(),
                'timeexported'    => 0,
                'timestart'       => $timenow,
                'timefinish'      => 0,
                'timemodified'    => $timenow,
            ];

            $transaction = $DB->start_delegated_transaction();
            try {
                $sql = 'SELECT COALESCE(MAX(attemptnumber), 0)
                          FROM {stackmastery_attempts}
                         WHERE stackmasteryid = :stackmasteryid AND userid = :userid';
                $params = ['stackmasteryid' => (int) $this->instance->id, 'userid' => $userid];
                $attempt->attemptnumber = 1 + (int) $DB->get_field_sql($sql, $params);
                $attempt->id = $DB->insert_record('stackmastery_attempts', $attempt);
                $snapshot = pool::build_snapshot($this->instance, (int) $attempt->id, $selectedcodes);
                if ((int) $snapshot['distinct'] === 0) {
                    throw new \moodle_exception(
                        'errpoolempty',
                        'mod_stackmastery',
                        '',
                        (object) ['cells' => $this->empty_cells_label($snapshot['cells'])]
                    );
                }
                $attempt->budget = min((int) $this->instance->budget, (int) $snapshot['distinct']);
                $DB->set_field('stackmastery_attempts', 'budget', $attempt->budget, ['id' => $attempt->id]);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                if (!$transaction->is_disposed()) {
                    $transaction->rollback($e);
                }
                throw $e;
            }

            \mod_stackmastery\event\attempt_started::create([
                'objectid'      => $attempt->id,
                'context'       => $this->context,
                'relateduserid' => $userid,
                'other'         => ['stackmasteryid' => (int) $this->instance->id],
            ])->trigger();

            // Early-complete edge (E15): fitted priors can start at or above target.
            $selected = $this->selected_flags($attempt);
            $targets = $this->target_vector($attempt);
            if ($mastery->all_reached($selected, $targets)) {
                $this->terminalise($attempt, attempt_store::STATE_COMPLETE, self::REASON_TARGET, $timenow);
                return $attempt;
            }

            try {
                $this->provision_next_slot($attempt, $timenow);
            } catch (\moodle_exception $e) {
                // The attempt survives in SLOTLESS state; current_state() recovery retries (E8/E21).
                debugging('mod_stackmastery: first provisioning failed for attempt ' . $attempt->id .
                    ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            return $attempt;
        } finally {
            $lock->release();
        }
    }

    /**
     * Resume-safe read model. When a slot is open it is re-rendered as-is (resume never draws);
     * the SLOTLESS in-progress state triggers provisioning recovery under the lock, for the owner
     * only. Never throws for recoverable provisioning failures: the notice codes tell the page.
     *
     * @param \stdClass $attempt The attempt row (refreshed in place after any recovery).
     * @param int|null $lastseq Seq of a sealed step to render as the review panel, or null.
     * @param bool $render Whether to produce the HTML payloads (false for programmatic callers).
     * @param int|null $vieweruserid The viewing user; recovery is skipped for non-owners (E28).
     * @return attempt_state The render-ready state.
     */
    public function current_state(
        \stdClass $attempt,
        ?int $lastseq = null,
        bool $render = true,
        ?int $vieweruserid = null
    ): attempt_state {
        global $DB;
        $this->require_same_instance($attempt);
        $notice = null;
        $isowner = $vieweruserid === null || (int) $vieweruserid === (int) $attempt->userid;
        $needsrecovery = $attempt->state === attempt_store::STATE_INPROGRESS
            && (int) $attempt->currentslot === 0;
        if ($needsrecovery && $isowner) {
            $lock = $this->get_user_lock((int) $attempt->userid, self::LOCK_TIMEOUT_START);
            if (!$lock) {
                $notice = attempt_state::NOTICE_BUSY;
            } else {
                try {
                    $fresh = $DB->get_record('stackmastery_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
                    $this->sync($attempt, $fresh);
                    $stillslotless = $attempt->state === attempt_store::STATE_INPROGRESS
                        && (int) $attempt->currentslot === 0;
                    if ($stillslotless) {
                        try {
                            $this->provision_next_slot($attempt, time());
                            $notice = attempt_state::NOTICE_RECOVERED;
                        } catch (\moodle_exception $e) {
                            debugging('mod_stackmastery: recovery provisioning failed for attempt ' .
                                $attempt->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                            $notice = attempt_state::NOTICE_PROVISIONFAILED;
                        }
                    }
                } finally {
                    $lock->release();
                }
            }
        }
        return $this->build_state($attempt, $lastseq, $render, $notice);
    }

    /**
     * Process one POST against the open slot. Sequence-checked, locked, transactional: T1 makes
     * the QUBA save, the experience row and the mastery/attempt update (including any terminal
     * transition) atomic; T2 provisions the next slot in its own transaction so a CAS failure on
     * the NEXT question can never roll back this graded answer. Duplicate and stale POSTs are
     * classified without any state change.
     *
     * @param \stdClass $attempt The attempt row (refreshed in place).
     * @param array $postdata The raw POST (the caller passes $_POST; tests pass simulated data).
     * @param int|null $timenow Injectable clock; null for time().
     * @return submit_outcome The classified outcome.
     */
    public function process_submission(\stdClass $attempt, array $postdata, ?int $timenow = null): submit_outcome {
        global $DB;
        $timenow = $timenow ?? time();
        $this->require_same_instance($attempt);
        if ($attempt->state !== attempt_store::STATE_INPROGRESS) {
            return submit_outcome::of(submit_outcome::NOOP);
        }
        $lock = $this->get_user_lock((int) $attempt->userid, self::LOCK_TIMEOUT_SUBMIT);
        if (!$lock) {
            return submit_outcome::failure(submit_outcome::BUSY, 'errattemptbusy');
        }
        try {
            $fresh = $DB->get_record('stackmastery_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
            $this->sync($attempt, $fresh);
            if ($attempt->state !== attempt_store::STATE_INPROGRESS) {
                return submit_outcome::of(submit_outcome::NOOP);
            }
            if ((int) $attempt->currentslot === 0) {
                return $this->recover_then_duplicate($attempt, $timenow);
            }
            return $this->process_open_slot($attempt, $postdata, $timenow);
        } finally {
            $lock->release();
        }
    }

    /**
     * Terminalise as complete for the public early-finish paths. Per master plan C27 an open,
     * unfinished slot is sealed first (finish_question then save, grade-as-is) and NO step row is
     * written: an unsubmitted answer never enters mastery or the experience log. Idempotent.
     *
     * @param \stdClass $attempt The attempt row (refreshed in place).
     * @param string $reason One of the non-abandon REASON_* values ('user' and 'timeclose' are
     *     the public callers; the internal terminal paths pass through terminalise() directly).
     * @param int|null $timenow Injectable clock; null for time().
     * @return void
     * @throws \moodle_exception errattemptbusy when the lock cannot be acquired.
     */
    public function finish_attempt(\stdClass $attempt, string $reason, ?int $timenow = null): void {
        global $DB;
        $timenow = $timenow ?? time();
        $this->require_same_instance($attempt);
        $valid = [self::REASON_TARGET, self::REASON_BUDGET, self::REASON_EXHAUSTED,
                  self::REASON_USER, self::REASON_TIMECLOSE];
        if (!in_array($reason, $valid, true)) {
            throw new \coding_exception("invalid finish reason '{$reason}'");
        }
        $lock = $this->get_user_lock((int) $attempt->userid, self::LOCK_TIMEOUT_SUBMIT);
        if (!$lock) {
            throw new \moodle_exception('errattemptbusy', 'mod_stackmastery');
        }
        try {
            $fresh = $DB->get_record('stackmastery_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
            $this->sync($attempt, $fresh);
            if ($attempt->state !== attempt_store::STATE_INPROGRESS) {
                return;
            }
            $this->seal_open_slot($attempt, $timenow);
            $this->terminalise($attempt, attempt_store::STATE_COMPLETE, $reason, $timenow);
        } finally {
            $lock->release();
        }
    }

    /**
     * Terminalise as abandoned (cleanup sweep, privacy/teacher paths). Grades as-is; seals any
     * open slot to gaveup; idempotent. A busy lock is silently skipped: the sweep retries on its
     * next run rather than blocking behind a live student request.
     *
     * @param \stdClass $attempt The attempt row (refreshed in place).
     * @param int|null $timenow Injectable clock; null for time().
     * @return void
     */
    public function abandon_attempt(\stdClass $attempt, ?int $timenow = null): void {
        global $DB;
        $timenow = $timenow ?? time();
        $this->require_same_instance($attempt);
        $lock = $this->get_user_lock((int) $attempt->userid, self::LOCK_TIMEOUT_CLEANUP);
        if (!$lock) {
            return;
        }
        try {
            $fresh = $DB->get_record('stackmastery_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
            $this->sync($attempt, $fresh);
            if ($attempt->state !== attempt_store::STATE_INPROGRESS) {
                return;
            }
            $this->seal_open_slot($attempt, $timenow);
            $this->terminalise($attempt, attempt_store::STATE_ABANDONED, self::REASON_ABANDONED, $timenow);
        } finally {
            $lock->release();
        }
    }

    /**
     * Hard delete one attempt: question usage, steps, snapshot rows and the attempt row, in one
     * transaction (the attempt_store ordering, scoped to a single row), then regrade the user.
     *
     * @param \stdClass $attempt The attempt row.
     * @return void
     */
    public function delete_attempt(\stdClass $attempt): void {
        global $CFG, $DB;
        $this->require_same_instance($attempt);
        require_once($CFG->dirroot . '/question/engine/lib.php');
        $transaction = $DB->start_delegated_transaction();
        try {
            if ((int) $attempt->qubaid > 0) {
                try {
                    \question_engine::delete_questions_usage_by_activity((int) $attempt->qubaid);
                } catch (\Throwable $e) {
                    // Row deletion must win over a corrupt usage.
                    debugging('mod_stackmastery: usage delete failed for attempt ' . $attempt->id .
                        ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            $DB->delete_records('stackmastery_steps', ['attemptid' => $attempt->id]);
            $DB->delete_records('stackmastery_pool_snapshot', ['attemptid' => $attempt->id]);
            $DB->delete_records('stackmastery_attempts', ['id' => $attempt->id]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        }
        $this->push_grade_and_completion((int) $attempt->userid);
    }

    /**
     * Hard delete every attempt of this instance (used by lib.php delete_instance and reset).
     * Delegates to the single deletion primitive, then regrades every affected user.
     *
     * @return void
     */
    public function delete_all_for_instance(): void {
        $userids = attempt_store::delete_attempts((int) $this->instance->id);
        foreach ($userids as $userid) {
            $this->push_grade_and_completion((int) $userid);
        }
    }

    // Submission internals.

    /**
     * A POST arrived while the attempt is SLOTLESS (a stale form racing recovery): provision the
     * next question and report DUPLICATE so the page re-renders it (design 03 section 5 step 2).
     *
     * @param \stdClass $attempt The fresh attempt row (lock held).
     * @param int $timenow The clock.
     * @return submit_outcome DUPLICATE (or NOOP when recovery terminalised the attempt).
     */
    protected function recover_then_duplicate(\stdClass $attempt, int $timenow): submit_outcome {
        try {
            $this->provision_next_slot($attempt, $timenow);
        } catch (\moodle_exception $e) {
            debugging('mod_stackmastery: provisioning during submit recovery failed: ' .
                $e->getMessage(), DEBUG_DEVELOPER);
            return submit_outcome::failure(submit_outcome::ERROR, 'errnextquestion');
        }
        if ($attempt->state !== attempt_store::STATE_INPROGRESS) {
            return submit_outcome::of(submit_outcome::NOOP);
        }
        $outcome = submit_outcome::of(submit_outcome::DUPLICATE);
        $outcome->nextslot = (int) $attempt->currentslot;
        return $outcome;
    }

    /**
     * The money sequence for an open slot: filter, process, classify, then T1 and T2.
     *
     * @param \stdClass $attempt The fresh attempt row (lock held, refreshed in place).
     * @param array $postdata The raw POST.
     * @param int $timenow The clock.
     * @return submit_outcome The classified outcome.
     */
    protected function process_open_slot(\stdClass $attempt, array $postdata, int $timenow): submit_outcome {
        global $DB;
        $slot = (int) $attempt->currentslot;
        if ((int) $attempt->qubaid <= 0) {
            throw new \coding_exception('stackmastery attempt has an open slot but no question usage');
        }
        $this->require_engine();
        $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->qubaid);
        $qa = $quba->get_question_attempt($slot);

        // Filter the POST to the open slot (defence in depth: forged fields for other slots are
        // discarded before the engine ever sees them; the server, never the client, names the slot).
        $prefix = $quba->get_field_prefix($slot);
        $filtered = ['slots' => (string) $slot];
        foreach ($postdata as $key => $value) {
            if (strpos((string) $key, $prefix) === 0) {
                $filtered[$key] = $value;
            }
        }
        if (!isset($filtered[$prefix . ':sequencecheck'])) {
            return $this->classify_foreign_post($attempt, $postdata);
        }

        $trybefore = (int) $qa->get_last_behaviour_var('_try', 0);
        try {
            $quba->process_all_actions($timenow, $filtered);
        } catch (\question_out_of_sequence_exception $e) {
            // Double-click loser or a stale tab: the engine's sequence check is authoritative.
            $outcome = submit_outcome::of(submit_outcome::DUPLICATE);
            $outcome->nextslot = $slot;
            return $outcome;
        } catch (\Throwable $e) {
            debugging('mod_stackmastery: grading failed for attempt ' . $attempt->id . ' slot ' .
                $slot . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return submit_outcome::failure(submit_outcome::ERROR, 'errgrading');
        }
        $tryafter = (int) $qa->get_last_behaviour_var('_try', 0);

        if ($tryafter === $trybefore) {
            // A validation press or invalid input: persist the QE step so the validation echo
            // survives resume; no step row, budget untouched (E5/E6).
            $transaction = $DB->start_delegated_transaction();
            try {
                \question_engine::save_questions_usage_by_activity($quba);
                $DB->set_field('stackmastery_attempts', 'timemodified', $timenow, ['id' => $attempt->id]);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                if (!$transaction->is_disposed()) {
                    $transaction->rollback($e);
                }
                throw $e;
            }
            $attempt->timemodified = $timenow;
            $outcome = submit_outcome::of(submit_outcome::VALIDATED);
            $outcome->nextslot = $slot;
            return $outcome;
        }

        // A graded try: seal the question immediately (one question = one BKT observation, D5).
        try {
            $quba->finish_question($slot, $timenow);
        } catch (\Throwable $e) {
            debugging('mod_stackmastery: finish_question failed for attempt ' . $attempt->id .
                ' slot ' . $slot . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return submit_outcome::failure(submit_outcome::ERROR, 'errgrading');
        }
        $fraction = $qa->get_fraction();
        if ($fraction === null) {
            $raw = $qa->get_last_behaviour_var('_rawfraction', null);
            $fraction = $raw === null ? null : (float) $raw;
        } else {
            $fraction = (float) $fraction;
        }

        $pending = json_decode((string) $attempt->pendingjson);
        if (!is_object($pending) || (int) ($pending->slot ?? 0) !== $slot) {
            throw new \coding_exception('stackmastery pendingjson does not match the open slot');
        }
        $skillindex = array_search((string) $pending->servedskill, bkt::SKILLS, true);
        $diffindex = array_search((string) $pending->serveddifficulty, bkt::DIFFICULTIES, true);
        if ($skillindex === false || $diffindex === false) {
            throw new \coding_exception('stackmastery pendingjson carries an unknown action');
        }

        $mastery = mastery::from_json((string) $attempt->masterycurrent);
        $beforejson = $mastery->to_json();
        if ($fraction === null) {
            // E26 guard: an autograded type should never yield a null fraction post-finish.
            debugging('mod_stackmastery: null fraction after finish_question on attempt ' .
                $attempt->id . ' slot ' . $slot, DEBUG_DEVELOPER);
            $mastery->apply_result($skillindex, $diffindex, 0.0);
            $correct = false;
        } else {
            $result = $mastery->apply_result($skillindex, $diffindex, $fraction);
            $correct = (bool) $result['correct'];
        }
        $afterjson = $mastery->to_json();

        $selected = $this->selected_flags($attempt);
        $targets = $this->target_vector($attempt);
        $reached = $mastery->all_reached($selected, $targets);
        $seq = (int) $pending->seq;
        $reason = null;
        if ($reached) {
            $reason = self::REASON_TARGET;
        } else if ($seq >= (int) $attempt->budget) {
            $reason = self::REASON_BUDGET;
        }

        // T1: QUBA save + experience row + mastery/attempt update + terminal transition, atomic.
        $stepid = 0;
        $transaction = $DB->start_delegated_transaction();
        try {
            $check = $DB->get_record('stackmastery_attempts', ['id' => $attempt->id], '*', MUST_EXIST);
            $unchanged = (int) $check->currentslot === $slot
                && $check->state === attempt_store::STATE_INPROGRESS;
            if (!$unchanged) {
                throw new \coding_exception('stackmastery attempt mutated under lock');
            }
            \question_engine::save_questions_usage_by_activity($quba);
            $stepid = experience::log_step(
                $check,
                $seq,
                [
                    'recommendedskill'      => (string) $pending->recommendedskill,
                    'recommendeddifficulty' => (string) $pending->recommendeddifficulty,
                    'servedskill'           => (string) $pending->servedskill,
                    'serveddifficulty'      => (string) $pending->serveddifficulty,
                    'actionsource'          => (string) $pending->source,
                    'propensity'            => (float) $pending->propensity,
                    'policyversion'         => (string) ($pending->policyversion ?? $check->policyversion),
                ],
                [
                    'questionid'          => (int) $pending->questionid,
                    'questionbankentryid' => (int) $pending->qbeid,
                    'questionversion'     => (int) $pending->version,
                    'slot'                => $slot,
                    'variant'             => (int) ($pending->variant ?? 1),
                    'stackseed'           => $pending->stackseed ?? null,
                ],
                $this->step_outcome($fraction, $correct, $beforejson, $afterjson)
            );
            $update = [
                'id'             => $attempt->id,
                'masterycurrent' => $afterjson,
                'questionsdone'  => $seq,
                'currentslot'    => 0,
                'pendingjson'    => null,
                'timemodified'   => $timenow,
            ];
            if ($reached && empty($check->reachedtarget)) {
                $update['reachedtarget'] = 1;
                $update['stepstotarget'] = $seq;
                $update['timetargetreached'] = $timenow;
            }
            if ($reason !== null) {
                $update['state'] = attempt_store::STATE_COMPLETE;
                $update['finishreason'] = $reason;
                $update['inprogressuniq'] = (int) $attempt->id;
                $update['masteryfinal'] = $afterjson;
                $update['timefinish'] = $timenow;
            }
            $DB->update_record('stackmastery_attempts', (object) $update);
            if ($reason !== null) {
                pool::delete_snapshot((int) $attempt->id);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        }
        $this->sync($attempt, $DB->get_record('stackmastery_attempts', ['id' => $attempt->id], '*', MUST_EXIST));

        // Post-commit side effects, still under the lock; all idempotent.
        \mod_stackmastery\event\step_submitted::create([
            'objectid'      => $stepid,
            'context'       => $this->context,
            'relateduserid' => (int) $attempt->userid,
            'other'         => [
                'attemptid'    => (int) $attempt->id,
                'seq'          => $seq,
                'skill'        => (string) $pending->servedskill,
                'difficulty'   => (string) $pending->serveddifficulty,
                'actionsource' => (string) $pending->source,
                'correct'      => (int) $correct,
            ],
        ])->trigger();

        $outcome = submit_outcome::of(submit_outcome::GRADED);
        $outcome->correct = $correct;
        $outcome->fraction = $fraction;
        $outcome->lastseq = $seq;
        $outcome->gradedslot = $slot;
        $outcome->servedskill = (string) $pending->servedskill;
        $outcome->masterybefore = (array) json_decode($beforejson, true);
        $outcome->masteryafter = (array) json_decode($afterjson, true);

        if ($reason !== null) {
            $this->finish_side_effects($attempt, $reason);
            $outcome->result = submit_outcome::FINISHED;
            $outcome->finishreason = $reason;
            return $outcome;
        }

        // T2: provision the next slot; its failure can never roll back T1 (SLOTLESS is durable).
        try {
            $this->provision_next_slot($attempt, $timenow);
        } catch (\moodle_exception $e) {
            debugging('mod_stackmastery: next-slot provisioning failed for attempt ' . $attempt->id .
                ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            $outcome->nextpending = true;
            $outcome->errorstring = 'errnextquestion';
            return $outcome;
        }
        if ($attempt->state !== attempt_store::STATE_INPROGRESS) {
            // Provisioning terminalised the attempt (pool exhausted mid-attempt).
            $outcome->result = submit_outcome::FINISHED;
            $outcome->finishreason = (string) $attempt->finishreason;
            return $outcome;
        }
        $outcome->nextslot = (int) $attempt->currentslot;
        return $outcome;
    }

    /**
     * Classify a POST that carries no fields for the open slot: a sequencecheck for ANOTHER slot
     * of this usage marks a stale/replayed form (DUPLICATE, zero state change); no question
     * fields at all is a NOOP.
     *
     * @param \stdClass $attempt The fresh attempt row.
     * @param array $postdata The raw POST.
     * @return submit_outcome DUPLICATE or NOOP.
     */
    protected function classify_foreign_post(\stdClass $attempt, array $postdata): submit_outcome {
        $usageprefix = 'q' . (int) $attempt->qubaid . ':';
        foreach (array_keys($postdata) as $key) {
            $key = (string) $key;
            $isusagefield = strpos($key, $usageprefix) === 0;
            $issequence = substr($key, -14) === ':sequencecheck';
            if ($isusagefield && $issequence) {
                $outcome = submit_outcome::of(submit_outcome::DUPLICATE);
                $outcome->nextslot = (int) $attempt->currentslot;
                return $outcome;
            }
        }
        return submit_outcome::of(submit_outcome::NOOP);
    }

    /**
     * Assemble the log_step outcome struct (fraction rules per the experience writer contract).
     *
     * @param float|null $fraction The graded fraction, or null on the E26 guard path.
     * @param bool $correct The correctness flag (only sent explicitly when fraction is null).
     * @param string $beforejson Mastery JSON before the update.
     * @param string $afterjson Mastery JSON after the update.
     * @return array The outcome struct for experience::log_step().
     */
    protected function step_outcome(?float $fraction, bool $correct, string $beforejson, string $afterjson): array {
        $outcome = [
            'fraction'      => $fraction,
            'masterybefore' => (array) json_decode($beforejson, true),
            'masteryafter'  => (array) json_decode($afterjson, true),
        ];
        if ($fraction === null) {
            $outcome['correct'] = $correct;
        }
        return $outcome;
    }

    // Provisioning.

    /**
     * One routine for start, post-submit and recovery: terminal pre-checks, selection through
     * policy::choose(), draw-and-start with bounded CAS retries, then the T2 transaction that
     * persists the new slot together with its frozen selection context (pendingjson).
     *
     * PRE: the attempt lock is held; state is inprogress; currentslot is 0.
     * POST: a new slot is open and persisted, or the attempt was terminalised, or
     * errnextquestion was thrown with the attempt still SLOTLESS, durable and recoverable.
     *
     * @param \stdClass $attempt The fresh attempt row (refreshed in place).
     * @param int $timenow The clock.
     * @return void
     * @throws \moodle_exception errnextquestion after repeated CAS start failures.
     */
    protected function provision_next_slot(\stdClass $attempt, int $timenow): void {
        global $DB;
        $mastery = mastery::from_json((string) $attempt->masterycurrent);
        $selected = $this->selected_flags($attempt);
        $targets = $this->target_vector($attempt);

        // Terminal pre-checks: the recovery path must reach the same conclusions as submit T1.
        if ($mastery->all_reached($selected, $targets)) {
            $this->terminalise($attempt, attempt_store::STATE_COMPLETE, self::REASON_TARGET, $timenow);
            return;
        }
        if ((int) $attempt->questionsdone >= (int) $attempt->budget) {
            $this->terminalise($attempt, attempt_store::STATE_COMPLETE, self::REASON_BUDGET, $timenow);
            return;
        }

        $this->require_engine();
        $skiprowids = [];
        $casfailures = 0;
        $guard = 0;
        $decision = null;
        $eligiblecount = 0;
        $started = null;
        while ($started === null) {
            if (++$guard > 100) {
                throw new \moodle_exception('errnextquestion', 'mod_stackmastery');
            }
            $eligible = $this->eligible_actions((int) $attempt->id, $mastery, $selected, $targets);
            $masked = policy::mask_mastered($mastery->vector(), $selected, $targets);
            $decision = $this->policy->choose($masked, $eligible, $this->epsilon(), $this->rng);
            if ($decision['servedaction'] === null) {
                // No question to draw and NO step row: 'complete' can only mean the target is
                // reached (the trained threshold never exceeds the teacher target range), and an
                // empty eligible set is the exhausted termination (C15 fallback semantics).
                $reason = $decision['source'] === 'complete' ? self::REASON_TARGET : self::REASON_EXHAUSTED;
                $this->terminalise($attempt, attempt_store::STATE_COMPLETE, $reason, $timenow);
                return;
            }
            $eligiblecount = count($eligible);
            $skillcode = bkt::SKILLS[(int) $decision['skill']];
            $diffcode = bkt::DIFFICULTIES[(int) $decision['difficulty']];
            // Null means the cell drained during this call (invalid marks); re-select with the
            // refreshed eligibility map. Invalid marks are persisted, so the loop terminates.
            $started = $this->draw_and_start($attempt, $skillcode, $diffcode, $skiprowids, $casfailures);
        }
        [$quba, $slot, $row, $variant, $stackseed] = $started;

        [$recskillindex, $recdiffindex] = policy::decode_action((int) $decision['recommendedaction']);
        $pending = [
            'seq'                   => (int) $attempt->questionsdone + 1,
            'slot'                  => $slot,
            'qbeid'                 => (int) $row->questionbankentryid,
            'questionid'            => (int) $row->questionid,
            'version'               => (int) $row->questionversion,
            'variant'               => $variant,
            'stackseed'             => $stackseed,
            'recommendedskill'      => bkt::SKILLS[$recskillindex],
            'recommendeddifficulty' => bkt::DIFFICULTIES[$recdiffindex],
            'servedskill'           => bkt::SKILLS[(int) $decision['skill']],
            'serveddifficulty'      => bkt::DIFFICULTIES[(int) $decision['difficulty']],
            'source'                => (string) $decision['source'],
            'propensity'            => (float) $decision['propensity'],
            'epsilon'               => $this->epsilon(),
            'eligiblecount'         => $eligiblecount,
            'policyversion'         => $this->policyid,
            'masterybefore'         => json_decode($mastery->to_json(), true),
            'timecreated'           => $timenow,
        ];

        $transaction = $DB->start_delegated_transaction();
        try {
            \question_engine::save_questions_usage_by_activity($quba);
            pool::mark_served((int) $attempt->id, (int) $row->questionbankentryid, $timenow);
            $DB->update_record('stackmastery_attempts', (object) [
                'id'           => $attempt->id,
                'qubaid'       => (int) $quba->get_id(),
                'currentslot'  => $slot,
                'pendingjson'  => json_encode($pending),
                'timemodified' => $timenow,
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        }
        $this->sync($attempt, $DB->get_record('stackmastery_attempts', ['id' => $attempt->id], '*', MUST_EXIST));
    }

    /**
     * Draw an unseen question of the cell and start it on a FRESH in-memory QUBA. Version-deleted
     * rows are marked invalid (persisted) and redrawn; CAS start failures are skipped in-memory
     * only (bounded by MAX_START_RETRIES per provisioning call) so a transient Maxima outage
     * never permanently shrinks a frozen pool (D7).
     *
     * @param \stdClass $attempt The attempt row.
     * @param string $skillcode Cell skill code.
     * @param string $diffcode Cell difficulty code.
     * @param int[] $skiprowids In-memory CAS-failed snapshot row ids (accumulates across calls).
     * @param int $casfailures CAS failure counter (accumulates across calls).
     * @return array|null [quba, slot, snapshotrow, variant, stackseed], or null when the cell is
     *     drained and the caller must re-select.
     * @throws \moodle_exception errnextquestion when the CAS retry budget is spent.
     */
    protected function draw_and_start(
        \stdClass $attempt,
        string $skillcode,
        string $diffcode,
        array &$skiprowids,
        int &$casfailures
    ): ?array {
        $drawtries = 0;
        while (true) {
            $row = pool::draw((int) $attempt->id, $skillcode, $diffcode);
            if ($row === null) {
                return null;
            }
            if (in_array((int) $row->id, $skiprowids, true)) {
                // The committed pool API has no exclusion parameter: skip-in-memory is a bounded
                // redraw. Tripping the bound means only CAS-failed rows remain drawable here.
                if (++$drawtries > self::MAX_DRAW_TRIES) {
                    throw new \moodle_exception('errnextquestion', 'mod_stackmastery');
                }
                continue;
            }
            try {
                $question = \question_bank::load_question((int) $row->questionid);
            } catch (\dml_missing_record_exception $e) {
                // The pinned version vanished between freeze and draw: permanent, persisted (E9).
                pool::mark_invalid((int) $row->id);
                continue;
            }
            // A fresh QUBA every iteration: a failed add/start poisons the in-memory object and
            // the engine has no remove_question, so discard-and-reload is the only clean retry.
            if ((int) $attempt->qubaid > 0) {
                $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->qubaid);
            } else {
                $quba = \question_engine::make_questions_usage_by_activity('mod_stackmastery', $this->context);
                $quba->set_preferred_behaviour('adaptivenopenalty');
            }
            try {
                $slot = $quba->add_question($question, 1.0);
                $quba->start_question($slot, null);
            } catch (\Throwable $e) {
                debugging('mod_stackmastery: question start failed (question ' . $row->questionid .
                    '): ' . $e->getMessage(), DEBUG_DEVELOPER);
                $skiprowids[] = (int) $row->id;
                if (++$casfailures >= self::MAX_START_RETRIES) {
                    throw new \moodle_exception('errnextquestion', 'mod_stackmastery');
                }
                continue;
            }
            $qa = $quba->get_question_attempt($slot);
            return [$quba, $slot, $row, (int) $qa->get_variant(), $this->probe_stack_seed($qa)];
        }
    }

    /**
     * Build the eligible action-id set for policy::choose(): selected skills still below their
     * target, in cells with unseen valid questions. Canonical ascending order keeps the explore
     * index draw deterministic under an injected RNG.
     *
     * @param int $attemptid The attempt id.
     * @param mastery $mastery The live belief vector.
     * @param bool[] $selected Positional selected flags.
     * @param float[] $targets Positional per-skill targets.
     * @return int[] Eligible action ids.
     */
    protected function eligible_actions(int $attemptid, mastery $mastery, array $selected, array $targets): array {
        $cells = pool::eligible_cells($attemptid);
        $reached = $mastery->reached($targets);
        $eligible = [];
        foreach (bkt::SKILLS as $skillindex => $skillcode) {
            if (empty($selected[$skillindex]) || $reached[$skillindex]) {
                continue;
            }
            foreach (bkt::DIFFICULTIES as $diffindex => $diffcode) {
                if (!empty($cells[$skillcode][$diffcode])) {
                    $eligible[] = policy::encode_action($skillindex, $diffindex);
                }
            }
        }
        return $eligible;
    }

    /**
     * Best-effort STACK deployed-seed probe, feature-detected so this class never hard-couples
     * to qtype_stack (the stackhinter grounding precedent).
     *
     * @param \question_attempt $qa The started question attempt.
     * @return int|null The resolved seed, or null for non-randomised or non-STACK questions.
     */
    protected function probe_stack_seed(\question_attempt $qa): ?int {
        try {
            $question = $qa->get_question();
        } catch (\Throwable $e) {
            return null;
        }
        $isstack = strpos(get_class($question), 'qtype_stack') === 0;
        if ($isstack && property_exists($question, 'seed') && $question->seed !== null) {
            return (int) $question->seed;
        }
        return null;
    }

    // Terminalisation.

    /**
     * Close the attempt outside the submit path: terminal columns in one transaction (state,
     * finishreason, the inprogressuniq release, masteryfinal grade-as-is, slot cleared, snapshot
     * deleted), then the grade/completion/event side effects. Callers hold the lock; any open
     * slot must already be sealed (seal_open_slot).
     *
     * @param \stdClass $attempt The fresh attempt row (refreshed in place).
     * @param string $state STATE_COMPLETE or STATE_ABANDONED.
     * @param string $reason The finishreason enum value.
     * @param int $timenow The clock.
     * @return void
     */
    protected function terminalise(\stdClass $attempt, string $state, string $reason, int $timenow): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $update = [
                'id'             => $attempt->id,
                'state'          => $state,
                'finishreason'   => $reason,
                'inprogressuniq' => (int) $attempt->id,
                'currentslot'    => 0,
                'pendingjson'    => null,
                'masteryfinal'   => $attempt->masterycurrent,
                'timefinish'     => $timenow,
                'timemodified'   => $timenow,
            ];
            if ($reason === self::REASON_TARGET && empty($attempt->reachedtarget)) {
                // Target discovered outside submit T1 (early-complete start, recovery pre-check):
                // stamp the crossing here; zero steps means the target held before any question.
                $update['reachedtarget'] = 1;
                $update['stepstotarget'] = (int) $attempt->questionsdone;
                $update['timetargetreached'] = $timenow;
            }
            $DB->update_record('stackmastery_attempts', (object) $update);
            pool::delete_snapshot((int) $attempt->id);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        }
        $this->sync($attempt, $DB->get_record('stackmastery_attempts', ['id' => $attempt->id], '*', MUST_EXIST));
        $this->finish_side_effects($attempt, $reason);
    }

    /**
     * Seal an open, unfinished slot before a public finish or abandon (master plan C27): an
     * unanswered adaptive question finishes to gaveup and NO step row is ever written for it.
     * Failures are logged, never propagated - finalisation must not be blockable by a corrupt
     * usage or a CAS outage (the mastery grade is independent of the QUBA).
     *
     * @param \stdClass $attempt The fresh attempt row.
     * @param int $timenow The clock.
     * @return void
     */
    protected function seal_open_slot(\stdClass $attempt, int $timenow): void {
        $slot = (int) $attempt->currentslot;
        if ($slot <= 0 || (int) $attempt->qubaid <= 0) {
            return;
        }
        $this->require_engine();
        try {
            $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->qubaid);
            $qa = $quba->get_question_attempt($slot);
            if (!$qa->get_state()->is_finished()) {
                $quba->finish_question($slot, $timenow);
                \question_engine::save_questions_usage_by_activity($quba);
            }
        } catch (\Throwable $e) {
            debugging('mod_stackmastery: sealing slot ' . $slot . ' of attempt ' . $attempt->id .
                ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Post-commit side effects of any terminal transition: gradebook push, completion state
     * update and the attempt_completed event. All idempotent.
     *
     * @param \stdClass $attempt The terminal attempt row.
     * @param string $reason The finishreason enum value.
     * @return void
     */
    protected function finish_side_effects(\stdClass $attempt, string $reason): void {
        $this->push_grade_and_completion((int) $attempt->userid);
        \mod_stackmastery\event\attempt_completed::create([
            'objectid'      => $attempt->id,
            'context'       => $this->context,
            'relateduserid' => (int) $attempt->userid,
            'other'         => [
                'stackmasteryid' => (int) $this->instance->id,
                'reason'         => $reason,
                'reachedtarget'  => (int) !empty($attempt->reachedtarget),
            ],
        ])->trigger();
    }

    /**
     * Push the user's grade and recalculate the completion state (used on finish, abandon and
     * delete - deletion can lower a grade or revoke a reached-target completion).
     *
     * @param int $userid The user.
     * @return void
     */
    protected function push_grade_and_completion(int $userid): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/stackmastery/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        stackmastery_update_grades($this->instance, $userid);
        $completion = new \completion_info($this->cm->get_course());
        if ($completion->is_enabled($this->cm)) {
            $completion->update_state($this->cm, COMPLETION_UNKNOWN, $userid);
        }
    }

    // Read model.

    /**
     * Assemble the attempt_state DTO, rendering the open slot and the optional review panel.
     *
     * @param \stdClass $attempt The attempt row.
     * @param int|null $lastseq Seq of the step to review, or null.
     * @param bool $render Whether to produce HTML payloads.
     * @param string|null $notice A notice code from recovery, or null.
     * @return attempt_state The DTO.
     */
    protected function build_state(\stdClass $attempt, ?int $lastseq, bool $render, ?string $notice): attempt_state {
        global $DB;
        $state = new attempt_state();
        $state->attempt = $attempt;
        $state->finished = $attempt->state !== attempt_store::STATE_INPROGRESS;
        $state->finishreason = empty($attempt->finishreason) ? null : (string) $attempt->finishreason;
        $state->reachedtarget = !empty($attempt->reachedtarget);
        $state->questionsdone = (int) $attempt->questionsdone;
        $state->budget = (int) $attempt->budget;
        $state->selectedskills = skills::decode_csv((string) $attempt->skillssnapshot);
        $masteryjson = $state->finished && !empty($attempt->masteryfinal)
            ? (string) $attempt->masteryfinal
            : (string) $attempt->masterycurrent;
        $state->mastery = $this->decode_skill_map($masteryjson);
        $state->targets = $this->decode_skill_map((string) $attempt->targetsnapshot);
        $state->grade = $state->finished ? grades::attempt_grade($this->instance, $attempt) : null;
        $state->notice = $notice;

        $slot = (int) $attempt->currentslot;
        if (!$state->finished && $slot > 0) {
            $state->slot = $slot;
            $state->seq = (int) $attempt->questionsdone + 1;
        }
        if ($lastseq !== null && $lastseq > 0) {
            $step = $DB->get_record('stackmastery_steps', ['attemptid' => $attempt->id, 'seq' => $lastseq]);
            if ($step) {
                $state->reviewslot = (int) $step->slot;
                $state->reviewseq = (int) $step->seq;
            }
        }
        if (!$render) {
            return $state;
        }

        $renderable = $state->slot !== null || $state->reviewslot !== null;
        if ($renderable && (int) $attempt->qubaid > 0) {
            $this->require_engine();
            try {
                $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->qubaid);
                $head = '';
                if ($state->reviewslot !== null) {
                    $head .= $quba->render_question_head_html($state->reviewslot);
                    $state->reviewhtml = $quba->render_question(
                        $state->reviewslot,
                        $this->display_options(true),
                        (string) $state->reviewseq
                    );
                }
                if ($state->slot !== null) {
                    $head .= $quba->render_question_head_html($state->slot);
                    $state->questionhtml = $quba->render_question(
                        $state->slot,
                        $this->display_options(false),
                        (string) $state->seq
                    );
                }
                $state->headhtml = $head === '' ? null : $head;
            } catch (\Throwable $e) {
                debugging('mod_stackmastery: question render failed for attempt ' . $attempt->id .
                    ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                $state->notice = $state->notice ?? attempt_state::NOTICE_RENDERFAILED;
            }
        }
        return $state;
    }

    /**
     * Display options for the open question and the sealed review panel (design 03 section 7):
     * marks are never the currency, the right answer is never shown, and STACK's worked solution
     * (general feedback) appears only after the seal.
     *
     * @param bool $review True for the read-only review render.
     * @return \question_display_options The options.
     */
    protected function display_options(bool $review): \question_display_options {
        $options = new \question_display_options();
        $options->readonly = $review;
        $options->flags = \question_display_options::HIDDEN;
        $options->marks = \question_display_options::HIDDEN;
        $options->feedback = \question_display_options::VISIBLE;
        $options->numpartscorrect = \question_display_options::VISIBLE;
        $options->correctness = \question_display_options::VISIBLE;
        $options->generalfeedback = $review
            ? \question_display_options::VISIBLE
            : \question_display_options::HIDDEN;
        $options->rightanswer = \question_display_options::HIDDEN;
        $options->history = \question_display_options::HIDDEN;
        $options->manualcomment = \question_display_options::HIDDEN;
        return $options;
    }

    // Small helpers.

    /**
     * The exploration rate: the INSTANCE snapshot (master plan C18), clamped to [0, 0.2].
     *
     * @return float Epsilon.
     */
    protected function epsilon(): float {
        $epsilon = (float) ($this->instance->epsilon ?? 0.0);
        return min(max($epsilon, 0.0), 0.2);
    }

    /**
     * Acquire the per-user attempt lock (key instanceid:userid - start, submit, recovery, finish
     * and abandon all serialise on it, which is what makes the C3 open-attempt recheck safe).
     *
     * @param int $userid The user.
     * @param int $timeout Acquire timeout in seconds.
     * @return \core\lock\lock|false The held lock, or false.
     */
    protected function get_user_lock(int $userid, int $timeout) {
        return $this->lockfactory->get_lock('attempt:' . (int) $this->instance->id . ':' . $userid, $timeout);
    }

    /**
     * Positional selected-skill flags from the attempt snapshot.
     *
     * @param \stdClass $attempt The attempt row.
     * @return bool[] One flag per canonical skill.
     */
    protected function selected_flags(\stdClass $attempt): array {
        $codes = skills::decode_csv((string) $attempt->skillssnapshot);
        $flags = [];
        foreach (bkt::SKILLS as $code) {
            $flags[] = in_array($code, $codes, true);
        }
        return $flags;
    }

    /**
     * Positional per-skill target vector from the attempt snapshot, tolerant of a missing or
     * corrupt column (falls back to the instance scalar target).
     *
     * @param \stdClass $attempt The attempt row.
     * @return float[] One target per canonical skill.
     */
    protected function target_vector(\stdClass $attempt): array {
        $data = json_decode((string) ($attempt->targetsnapshot ?? ''), true);
        $fallback = (float) ($this->instance->targetmastery ?? 0.95);
        $vector = [];
        foreach (bkt::SKILLS as $code) {
            $value = is_array($data) && isset($data[$code]) && is_numeric($data[$code])
                ? (float) $data[$code]
                : $fallback;
            $vector[] = $value;
        }
        return $vector;
    }

    /**
     * The instance target vector JSON for a new attempt's snapshot: the stored targetvector when
     * valid, else expanded from the scalar targetmastery.
     *
     * @return string JSON object keyed by skill code, all 8 keys.
     */
    protected function instance_target_json(): string {
        $target = (float) ($this->instance->targetmastery ?? 0.95);
        $data = json_decode((string) ($this->instance->targetvector ?? ''), true);
        $out = [];
        foreach (bkt::SKILLS as $code) {
            $value = is_array($data) && isset($data[$code]) && is_numeric($data[$code])
                ? (float) $data[$code]
                : $target;
            $out[$code] = $value;
        }
        return json_encode($out);
    }

    /**
     * Tolerant decode of a mastery/target JSON column to a skill-code map (render path only; the
     * strict codec for computation is the mastery class).
     *
     * @param string $json The stored JSON object.
     * @return array<string, float> Values keyed by skill code, all 8 keys.
     */
    protected function decode_skill_map(string $json): array {
        $data = json_decode($json, true);
        $out = [];
        foreach (bkt::SKILLS as $code) {
            $value = is_array($data) && isset($data[$code]) && is_numeric($data[$code])
                ? (float) $data[$code]
                : 0.0;
            $out[$code] = $value;
        }
        return $out;
    }

    /**
     * Ensure the question engine library is loaded (question_engine and question_display_options
     * are not autoloaded).
     *
     * @return void
     */
    protected function require_engine(): void {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');
    }

    /**
     * Guard against cross-instance attempt rows (a coding error, never user input).
     *
     * @param \stdClass $attempt The attempt row.
     * @return void
     */
    protected function require_same_instance(\stdClass $attempt): void {
        if ((int) $attempt->stackmasteryid !== (int) $this->instance->id) {
            throw new \coding_exception('stackmastery attempt belongs to a different instance');
        }
    }

    /**
     * Copy the fresh row's columns onto the caller's object so every caller observes updates.
     *
     * @param \stdClass $attempt The caller's attempt object.
     * @param \stdClass $fresh The freshly read row.
     * @return void
     */
    protected function sync(\stdClass $attempt, \stdClass $fresh): void {
        foreach ((array) $fresh as $key => $value) {
            $attempt->{$key} = $value;
        }
    }

    /**
     * Human label of the empty cells for the errpoolempty message.
     *
     * @param array $cells The build_snapshot per-cell counts.
     * @return string Comma-separated "skill/difficulty" labels of the empty cells.
     */
    protected function empty_cells_label(array $cells): string {
        $empty = [];
        foreach ($cells as $skill => $row) {
            foreach ($row as $difficulty => $count) {
                if ((int) $count === 0) {
                    $empty[] = $skill . '/' . $difficulty;
                }
            }
        }
        return implode(', ', $empty);
    }
}
