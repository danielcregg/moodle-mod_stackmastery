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
 * Tests for the pseudonymised JSONL experience export engine.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * Pseudonymisation (no userid, no raw attempt ids, hex seqkeys), watermark idempotence,
 * corrupt-row whole-attempt skips, and exportruns provenance.
 *
 * @covers \mod_stackmastery\local\export
 */
final class export_test extends \advanced_testcase {
    /** @var \stdClass The shared course. */
    private $course;

    /** @var \stdClass The shared instance. */
    private $instance;

    /** @var int Next attempt number per user id. */
    private $attemptno = [];

    /**
     * Common setup.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module(
            'stackmastery',
            ['course' => $this->course->id]
        );
    }

    /**
     * Insert one attempt row directly (WP-4 tests seed rows; no runtime needed).
     *
     * @param string $state The attempt state.
     * @param array $overrides Column overrides.
     * @return \stdClass The attempt record with id.
     */
    private function make_attempt(string $state = 'complete', array $overrides = []): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $number = ($this->attemptno[$user->id] ?? 0) + 1;
        $this->attemptno[$user->id] = $number;
        $mastery = experience::encode_mastery(array_fill(0, 8, 0.2));
        $record = (object) array_merge([
            'stackmasteryid' => $this->instance->id,
            'userid' => $user->id,
            'attemptnumber' => $number,
            'qubaid' => 0,
            'state' => $state,
            'finishreason' => $state === 'inprogress' ? null : 'target',
            'inprogressuniq' => 0,
            'currentslot' => 0,
            'pendingjson' => null,
            'preview' => 0,
            'masterycurrent' => $mastery,
            'skillssnapshot' => 'expand,factor',
            'targetsnapshot' => json_encode(array_combine(bkt::SKILLS, array_fill(0, 8, 0.95))),
            'budget' => 40,
            'questionsdone' => 0,
            'reachedtarget' => $state === 'complete' ? 1 : 0,
            'stepstotarget' => null,
            'timetargetreached' => null,
            'masteryfinal' => $state === 'inprogress' ? null : $mastery,
            'policyversion' => 'shipped-abc123def456',
            'bktmodelversion' => 'bkt-1:default',
            'timeexported' => 0,
            'timestart' => time() - 600,
            'timefinish' => $state === 'inprogress' ? 0 : time() - 60,
            'timemodified' => time(),
        ], $overrides);
        $record->id = $DB->insert_record('stackmastery_attempts', $record);
        if ($state !== 'inprogress') {
            $DB->set_field('stackmastery_attempts', 'inprogressuniq', $record->id, ['id' => $record->id]);
            $record->inprogressuniq = $record->id;
        }
        return $record;
    }

    /**
     * Log one step through the canonical writer.
     *
     * @param \stdClass $attempt The attempt row.
     * @param int $seq The step sequence number.
     * @return int The step id.
     */
    private function add_step(\stdClass $attempt, int $seq): int {
        $before = array_fill(0, 8, 0.2);
        $after = $before;
        $after[3] = 0.2 + 0.05 * $seq;
        return experience::log_step($attempt, $seq, [
            'recommendedskill' => 'factor',
            'recommendeddifficulty' => 'medium',
            'servedskill' => 'factor',
            'serveddifficulty' => 'medium',
            'actionsource' => 'policy',
            'propensity' => 0.95208333,
            'policyversion' => 'shipped-abc123def456',
        ], [
            'questionid' => 100 + $seq,
            'questionbankentryid' => 50 + $seq,
            'questionversion' => 2,
            'slot' => $seq,
            'variant' => 1,
            'stackseed' => null,
        ], [
            'fraction' => 1.0,
            'masterybefore' => $before,
            'masteryafter' => $after,
        ]);
    }

    /**
     * Read an export file into decoded lines.
     *
     * @param string $filename The export filename.
     * @return array Positional [raw string, array of decoded lines].
     */
    private function read_export(string $filename): array {
        $path = export::export_dir() . '/' . $filename;
        $this->assertFileExists($path);
        $raw = file_get_contents($path);
        $lines = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $lines[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
        return [$raw, $lines];
    }

    /**
     * Nothing finished and unexported: no file, no run row, null.
     *
     * @return void
     */
    public function test_run_with_nothing_returns_null(): void {
        global $DB;
        $this->make_attempt('inprogress');
        $this->assertNull(export::run());
        $this->assertSame(0, $DB->count_records('stackmastery_exportruns'));
        $this->assertSame([], glob(export::export_dir() . '/*.jsonl'));
    }

    /**
     * The full export: shape, pseudonymisation, selection, watermark and run provenance.
     *
     * @return void
     */
    public function test_full_export_shape_and_pseudonymisation(): void {
        global $DB;
        $complete = $this->make_attempt('complete');
        $this->add_step($complete, 1);
        $this->add_step($complete, 2);
        $abandoned = $this->make_attempt('abandoned', ['finishreason' => 'abandoned']);
        $this->add_step($abandoned, 1);
        $this->add_step($abandoned, 2);
        $open = $this->make_attempt('inprogress');
        $this->add_step($open, 1);
        $empty = $this->make_attempt('complete');
        $preview = $this->make_attempt('complete', ['preview' => 1]);
        $this->add_step($preview, 1);

        $run = export::run();
        $this->assertNotNull($run);
        $this->assertEquals(2, $run->attempts);
        $this->assertEquals(4, $run->steps);
        $this->assertEquals(0, $run->skippederrors);

        [$raw, $lines] = $this->read_export($run->filename);
        $this->assertSame(hash('sha256', $raw), $run->sha256);

        // Pseudonymisation regression: no userid key, no attemptid key, anywhere.
        $this->assertDoesNotMatchRegularExpression('/"userid"/', $raw);
        $this->assertDoesNotMatchRegularExpression('/"attemptid"/', $raw);

        // Line 1 is the meta record with the pinned encoding.
        $meta = $lines[0];
        $this->assertSame('meta', $meta['_type']);
        $this->assertSame(export::SCHEMA, $meta['schema']);
        $this->assertSame(bkt::SKILLS, $meta['skills']);
        $this->assertSame([0.475, 0.725, 0.95], $meta['bin_edges']);
        $this->assertSame(4, $meta['n_bins']);
        $this->assertTrue($meta['pseudonymised']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $meta['site']);

        // Two attempt lines, each preceding its own steps, keyed by distinct hex-16 seqkeys.
        $attemptlines = array_values(array_filter($lines, function ($line) {
            return $line['_type'] === 'attempt';
        }));
        $this->assertCount(2, $attemptlines);
        $seqkeys = [];
        foreach ($attemptlines as $line) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $line['seqkey']);
            $this->assertSame(['expand', 'factor'], $line['skills_selected']);
            $this->assertSame(2, $line['steps']);
            $this->assertCount(8, $line['target']);
            $seqkeys[] = $line['seqkey'];
        }
        $this->assertNotSame($seqkeys[0], $seqkeys[1]);
        $current = null;
        foreach (array_slice($lines, 1) as $line) {
            if ($line['_type'] === 'attempt') {
                $current = $line['seqkey'];
                continue;
            }
            $this->assertSame('step', $line['_type']);
            $this->assertSame($current, $line['seqkey'], 'steps must follow their attempt line');
        }

        // Spot-check one full step line against the DB row.
        $steprow = $DB->get_record('stackmastery_steps', ['attemptid' => $complete->id, 'seq' => 2]);
        $stepline = null;
        foreach ($lines as $line) {
            if (
                $line['_type'] === 'step' && $line['seqkey'] === $attemptlines[0]['seqkey']
                    && $line['seq'] === 2
            ) {
                $stepline = $line;
            }
        }
        $this->assertNotNull($stepline);
        $this->assertSame((int) $steprow->questionbankentryid, $stepline['qbentry']);
        $this->assertSame((int) $steprow->questionversion, $stepline['qversion']);
        $this->assertSame('factor', $stepline['served_skill']);
        $this->assertSame('policy', $stepline['action_source']);
        $this->assertSame('enc-1', $stepline['state_encoding_version']);
        $this->assertSame('reward-1', $stepline['reward_version']);
        $this->assertEqualsWithDelta(0.3, $stepline['mastery_after'][3], 1e-9);
        $this->assertCount(8, $stepline['mastery_before']);

        // Watermark: finished attempts stamped (including the empty one), others untouched.
        $this->assertGreaterThan(0, $DB->get_field(
            'stackmastery_attempts',
            'timeexported',
            ['id' => $complete->id]
        ));
        $this->assertGreaterThan(0, $DB->get_field(
            'stackmastery_attempts',
            'timeexported',
            ['id' => $abandoned->id]
        ));
        $this->assertGreaterThan(0, $DB->get_field(
            'stackmastery_attempts',
            'timeexported',
            ['id' => $empty->id]
        ));
        $this->assertEquals(0, $DB->get_field(
            'stackmastery_attempts',
            'timeexported',
            ['id' => $open->id]
        ));
        $this->assertEquals(0, $DB->get_field(
            'stackmastery_attempts',
            'timeexported',
            ['id' => $preview->id]
        ));

        // No .tmp left behind; the run row matches the file on disk.
        $this->assertSame([], glob(export::export_dir() . '/*.tmp'));
        $this->assertSame(
            hash_file('sha256', export::export_dir() . '/' . $run->filename),
            $run->sha256
        );
    }

    /**
     * Idempotence: a second run is null; a newly finished attempt then exports alone.
     *
     * @return void
     */
    public function test_watermark_makes_runs_idempotent(): void {
        $first = $this->make_attempt('complete');
        $this->add_step($first, 1);
        $run1 = export::run();
        $this->assertNotNull($run1);
        $this->assertNull(export::run());

        $second = $this->make_attempt('complete');
        $this->add_step($second, 1);
        $this->add_step($second, 2);
        $run2 = export::run();
        $this->assertNotNull($run2);
        $this->assertEquals(1, $run2->attempts);
        $this->assertEquals(2, $run2->steps);
        [, $lines] = $this->read_export($run2->filename);
        $attemptlines = array_values(array_filter($lines, function ($line) {
            return $line['_type'] === 'attempt';
        }));
        $this->assertCount(1, $attemptlines);
    }

    /**
     * A corrupt step skips the WHOLE attempt (unstamped, counted); others still export.
     *
     * @return void
     */
    public function test_corrupt_attempt_skipped_whole_and_unstamped(): void {
        global $DB;
        $good = $this->make_attempt('complete');
        $this->add_step($good, 1);
        $bad = $this->make_attempt('complete');
        $badstep = $this->add_step($bad, 1);
        $DB->set_field('stackmastery_steps', 'masteryafter', '{broken', ['id' => $badstep]);

        $run = export::run();
        $this->assertNotNull($run);
        $this->assertEquals(1, $run->attempts);
        $this->assertEquals(1, $run->steps);
        $this->assertEquals(1, $run->skippederrors);
        $this->assertEquals(0, $DB->get_field(
            'stackmastery_attempts',
            'timeexported',
            ['id' => $bad->id]
        ));
        $this->assertGreaterThan(0, $DB->get_field(
            'stackmastery_attempts',
            'timeexported',
            ['id' => $good->id]
        ));
        [$raw] = $this->read_export($run->filename);
        $this->assertDoesNotMatchRegularExpression('/"userid"/', $raw);
    }

    /**
     * Non-contiguous seq (a retention artefact would be whole-attempt) is treated as corruption.
     *
     * @return void
     */
    public function test_non_contiguous_seq_is_corruption(): void {
        global $DB;
        $attempt = $this->make_attempt('complete');
        $this->add_step($attempt, 1);
        $this->add_step($attempt, 2);
        $DB->delete_records('stackmastery_steps', ['attemptid' => $attempt->id, 'seq' => 1]);
        $this->assertNull(export::run());
        $this->assertEquals(0, $DB->get_field(
            'stackmastery_attempts',
            'timeexported',
            ['id' => $attempt->id]
        ));
    }

    /**
     * Only-empty runs stamp the watermark without producing a file or a run row.
     *
     * @return void
     */
    public function test_empty_attempts_stamp_without_file(): void {
        global $DB;
        $empty = $this->make_attempt('complete');
        $this->assertNull(export::run());
        $this->assertGreaterThan(0, $DB->get_field(
            'stackmastery_attempts',
            'timeexported',
            ['id' => $empty->id]
        ));
        $this->assertSame(0, $DB->count_records('stackmastery_exportruns'));
        $this->assertSame([], glob(export::export_dir() . '/*.jsonl'));
    }
}
