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
 * The pseudonymised JSONL experience export engine (design 05 section 4, as amended by C26).
 *
 * Every finished, not-yet-exported attempt is written as one attempt line plus its step lines.
 * No user ids and no raw attempt ids appear anywhere in the file: each run generates a random
 * salt held only in this process's memory, replaces attempt ids with a 16-hex HMAC seqkey, and
 * discards the salt when the run completes, so the site controller cannot re-link rows after the
 * run. Crash ordering (C26): write .tmp + fsync, THEN stamp the watermark and insert the run row
 * in ONE transaction, THEN rename. A crash before the transaction leaves an orphan .tmp and an
 * untouched watermark (clean re-export, no duplicates); a crash between the transaction and the
 * rename loses those episodes to training, detectably (a run row whose file is missing).
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Streaming JSONL writer with the discarded-salt seqkey, watermark stamping and run provenance.
 */
final class export {
    /** @var string The JSONL schema id carried in every file's meta line. */
    public const SCHEMA = 'stackmastery-experience/v1';

    /**
     * The absolute export directory inside moodledata, created on demand.
     *
     * @return string The directory path.
     */
    public static function export_dir(): string {
        global $CFG;
        return make_writable_directory($CFG->dataroot . '/stackmastery/export');
    }

    /**
     * The opaque site token: merged multi-site datasets cannot collide on seqkeys and the site
     * secret itself is never disclosed.
     *
     * @return string 16 hex characters.
     */
    public static function site_token(): string {
        return substr(hash('sha256', get_site_identifier() . '|stackmastery-export'), 0, 16);
    }

    /**
     * Export every finished (complete or abandoned), not-yet-exported, non-preview attempt and
     * its steps to one new JSONL file. Attempts with zero steps are stamped exported but emit
     * nothing; an attempt with any corrupt row is skipped whole (not stamped) and counted in
     * skippederrors - a partial episode is worse than a missing one for RL.
     *
     * @param \progress_trace|null $trace Optional trace for task output.
     * @return \stdClass|null The stackmastery_exportruns record, or null when no file was made.
     */
    public static function run(?\progress_trace $trace = null): ?\stdClass {
        global $DB;
        $trace = $trace ?? new \null_progress_trace();
        $now = time();
        $dir = self::export_dir();
        $filename = 'stackmastery_experience_' . date('Ymd_His', $now) . '_' . bin2hex(random_bytes(4)) . '.jsonl';
        $finalpath = $dir . '/' . $filename;
        $tmppath = $finalpath . '.tmp';

        // Per-run pseudonymisation salt: memory only, discarded with this frame (C26). Never
        // stored, never logged - post-run, nothing can re-link seqkeys to attempts or users.
        $salt = random_bytes(32);

        $select = "state IN ('complete','abandoned') AND timeexported = 0 AND preview = 0";
        $rs = $DB->get_recordset_select('stackmastery_attempts', $select, [], 'id ASC');
        $fh = null;
        $stampids = [];
        $attemptcount = 0;
        $stepcount = 0;
        $skippederrors = 0;
        try {
            foreach ($rs as $attempt) {
                $steps = $DB->get_records('stackmastery_steps', ['attemptid' => $attempt->id], 'seq ASC');
                if ($steps === []) {
                    $stampids[] = (int) $attempt->id;
                    continue;
                }
                try {
                    $lines = self::attempt_lines($attempt, $steps, $salt);
                } catch (\Exception $e) {
                    $skippederrors++;
                    $trace->output("stackmastery export: skipping corrupt attempt {$attempt->id}: " .
                        $e->getMessage());
                    continue;
                }
                if ($fh === null) {
                    $fh = self::open_tmp($tmppath, $now);
                }
                foreach ($lines as $line) {
                    self::write_line($fh, $tmppath, $line);
                }
                $stampids[] = (int) $attempt->id;
                $attemptcount++;
                $stepcount += count($steps);
            }
        } finally {
            $rs->close();
        }

        if ($fh === null) {
            // Nothing emitted; still advance the watermark over empty attempts.
            if ($stampids !== []) {
                $transaction = $DB->start_delegated_transaction();
                try {
                    self::stamp($stampids, $now);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }
            }
            $trace->output("stackmastery export: nothing to export ({$skippederrors} skipped).");
            return null;
        }

        fflush($fh);
        fsync($fh);
        fclose($fh);
        $sha256 = hash_file('sha256', $tmppath);

        $transaction = $DB->start_delegated_transaction();
        try {
            self::stamp($stampids, $now);
            $runid = $DB->insert_record('stackmastery_exportruns', (object) [
                'filename' => $filename,
                'sha256' => $sha256,
                'attempts' => $attemptcount,
                'steps' => $stepcount,
                'skippederrors' => $skippederrors,
                'timecreated' => $now,
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        if (!rename($tmppath, $finalpath)) {
            // The documented, acceptable crash window: stamped rows + a run row, file stuck at
            // .tmp; those episodes are lost to training and the mismatch is detectable.
            $trace->output("stackmastery export: rename failed; {$filename} stayed .tmp (episodes lost).");
        }
        $trace->output("stackmastery export: {$attemptcount} attempts / {$stepcount} steps / " .
            "{$skippederrors} skipped -> {$filename}");
        return $DB->get_record('stackmastery_exportruns', ['id' => $runid], '*', MUST_EXIST);
    }

    /**
     * Stamp the timeexported watermark on a batch of attempts (inside the caller's transaction).
     *
     * @param array $attemptids The attempt ids to stamp.
     * @param int $time The export timestamp.
     * @return void
     */
    private static function stamp(array $attemptids, int $time): void {
        global $DB;
        foreach (array_chunk($attemptids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'stampid');
            $DB->set_field_select('stackmastery_attempts', 'timeexported', $time, "id {$insql}", $params);
        }
    }

    /**
     * Open the .tmp file and write the meta line.
     *
     * @param string $tmppath The .tmp path.
     * @param int $now The run timestamp.
     * @return resource The open file handle.
     */
    private static function open_tmp(string $tmppath, int $now) {
        $fh = fopen($tmppath, 'wb');
        if ($fh === false) {
            throw new \moodle_exception('cannotwritefile', 'error', '', $tmppath);
        }
        self::write_line($fh, $tmppath, self::meta_line($now));
        return $fh;
    }

    /**
     * Write one JSONL line, failing loudly on a short write (disk full must never produce a
     * silently truncated file that a later rename would bless).
     *
     * @param resource $fh The open handle.
     * @param string $tmppath The path, for the error message.
     * @param string $line The JSON line without the terminator.
     * @return void
     */
    private static function write_line($fh, string $tmppath, string $line): void {
        $data = $line . "\n";
        if (fwrite($fh, $data) !== strlen($data)) {
            throw new \moodle_exception('cannotwritefile', 'error', '', $tmppath);
        }
    }

    /**
     * The first line of every export file: schema, site token and the pinned encoding so the
     * adapter can hard-fail on any drift.
     *
     * @param int $now The run timestamp.
     * @return string The meta JSON line.
     */
    private static function meta_line(int $now): string {
        return json_encode([
            '_type' => 'meta',
            'schema' => self::SCHEMA,
            'site' => self::site_token(),
            'exported_at' => $now,
            'plugin_version' => (int) get_config('mod_stackmastery', 'version'),
            'skills' => bkt::SKILLS,
            'difficulties' => bkt::DIFFICULTIES,
            'bin_edges' => policy::BIN_EDGES,
            'n_bins' => policy::N_BINS,
            'threshold' => policy::THRESHOLD,
            'pseudonymised' => true,
            'dropped_fields' => ['userid'],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Build the attempt line plus its step lines. Any decode/validation failure throws so the
     * caller can skip the WHOLE attempt (partial episodes must never reach training).
     *
     * @param \stdClass $attempt The attempt row.
     * @param array $steps The attempt's step rows ordered by seq.
     * @param string $salt The per-run seqkey salt.
     * @return string[] The JSON lines.
     */
    private static function attempt_lines(\stdClass $attempt, array $steps, string $salt): array {
        $seqkey = substr(hash_hmac('sha256', (string) $attempt->id, $salt), 0, 16);
        $selected = self::selected_skills((string) $attempt->skillssnapshot);
        $target = self::target_vector((string) $attempt->targetsnapshot);
        $expected = 1;
        foreach ($steps as $step) {
            if ((int) $step->seq !== $expected) {
                throw new \RuntimeException("non-contiguous step seq at {$step->seq} (expected {$expected})");
            }
            $expected++;
        }
        $lines = [];
        $lines[] = json_encode([
            '_type' => 'attempt',
            'seqkey' => $seqkey,
            'instance' => (int) $attempt->stackmasteryid,
            'attemptno' => (int) $attempt->attemptnumber,
            'skills_selected' => $selected,
            'target' => $target,
            'budget' => (int) $attempt->budget,
            'state' => (string) $attempt->state,
            'reached_target' => (bool) $attempt->reachedtarget,
            'policy_version' => (string) $attempt->policyversion,
            'bkt_model_version' => (string) $attempt->bktmodelversion,
            'timestart' => (int) $attempt->timestart,
            'timefinish' => (int) $attempt->timefinish,
            'steps' => count($steps),
        ], JSON_THROW_ON_ERROR);
        foreach ($steps as $step) {
            $lines[] = self::step_line($step, $seqkey);
        }
        return $lines;
    }

    /**
     * One step line: the experience row verbatim minus DB ids, nothing derived.
     *
     * @param \stdClass $step The step row.
     * @param string $seqkey The attempt's pseudonymous key.
     * @return string The JSON line.
     */
    private static function step_line(\stdClass $step, string $seqkey): string {
        return json_encode([
            '_type' => 'step',
            'seqkey' => $seqkey,
            'seq' => (int) $step->seq,
            'slot' => (int) $step->slot,
            'qbentry' => (int) $step->questionbankentryid,
            'qversion' => (int) $step->questionversion,
            'variant' => (int) $step->variant,
            'rec_skill' => (string) $step->recommendedskill,
            'rec_difficulty' => (string) $step->recommendeddifficulty,
            'served_skill' => (string) $step->servedskill,
            'served_difficulty' => (string) $step->serveddifficulty,
            'action_source' => (string) $step->actionsource,
            'propensity' => (float) $step->propensity,
            'mastery_before' => mastery::from_json((string) $step->masterybefore)->vector(),
            'mastery_after' => mastery::from_json((string) $step->masteryafter)->vector(),
            'correct' => (int) $step->correct,
            'fraction' => $step->fraction === null ? null : (float) $step->fraction,
            'policy_version' => (string) $step->policyversion,
            'bkt_model_version' => (string) $step->bktmodelversion,
            'state_encoding_version' => (string) $step->stateencodingversion,
            'reward_version' => (string) $step->rewardversion,
            'time' => (int) $step->timeanswered,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Parse and validate the attempt's selected-skills CSV snapshot.
     *
     * @param string $csv The skillssnapshot column.
     * @return string[] The selected canonical skill codes.
     */
    private static function selected_skills(string $csv): array {
        $codes = array_map('trim', explode(',', $csv));
        if ($codes === [] || $codes === ['']) {
            throw new \RuntimeException('empty skillssnapshot');
        }
        foreach ($codes as $code) {
            if (!in_array($code, bkt::SKILLS, true)) {
                throw new \RuntimeException("unknown skill code '{$code}' in skillssnapshot");
            }
        }
        return array_values($codes);
    }

    /**
     * Parse and validate the attempt's target vector snapshot into the canonical positional
     * form the adapter consumes (meta.skills gives the order).
     *
     * @param string $json The targetsnapshot column.
     * @return float[] Positional vector of 8 targets.
     */
    private static function target_vector(string $json): array {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $n = count(bkt::SKILLS);
        if (!is_array($data) || count($data) !== $n) {
            throw new \RuntimeException('targetsnapshot must carry exactly the 8 canonical skills');
        }
        $positional = array_keys($data) === range(0, $n - 1);
        $out = [];
        foreach (bkt::SKILLS as $i => $code) {
            $value = $positional ? $data[$i] : ($data[$code] ?? null);
            if (!is_int($value) && !is_float($value)) {
                throw new \RuntimeException("targetsnapshot value for '{$code}' must be a number");
            }
            $value = (float) $value;
            if (!is_finite($value) || $value < 0.0 || $value > 1.0) {
                throw new \RuntimeException("targetsnapshot value for '{$code}' must be finite and in [0,1]");
            }
            $out[] = $value;
        }
        return $out;
    }
}
