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
 * Tests for the policy artifact store.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * validate_artifact rule table, the promote/rollback flows with MUC invalidation and the
 * policy_promoted event payload, and the shipped fallback chain.
 *
 * @covers \mod_stackmastery\local\policy_store
 */
final class policy_store_test extends \advanced_testcase {
    /**
     * Common setup.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A fully valid promotable artifact built on the shipped table.
     *
     * @param string $policyid The artifact policy id.
     * @return array The decoded artifact.
     */
    private function valid_artifact(string $policyid = 'fqi-20260712-9a1b2c3d'): array {
        $meta = json_decode(file_get_contents(policy_store::shipped_path()), true);
        $meta['skills_source'] = 'offline:moodle';
        $meta['artifact'] = [
            'schema' => policy_store::ARTIFACT_SCHEMA,
            'policy_id' => $policyid,
            'created_at' => 1752910500,
            'trained_by' => 'phase3/offline.py fit',
            'state_encoding_version' => policy::ENCODING_VERSION,
            'reward_version' => experience::REWARD_VERSION,
            'dataset' => [
                'file' => 'transitions.json',
                'sha256' => str_repeat('ab', 32),
                'transitions' => 1234,
                'attempts' => 98,
                'sites' => ['1c9f2e7a8b3d4c5e'],
                'exported_range' => [1752300000, 1752900000],
                'action_source_counts' => ['policy' => 1100, 'explore' => 58,
                    'fallback' => 40, 'exhausted' => 36],
            ],
            'gate' => [
                'passed' => true,
                'avg_questions' => 143.2,
                'solve_rate' => 1.0,
                'baselines' => ['random' => 300.0, 'round_robin' => 278.1,
                    'easiest_first' => 299.4, 'hardest_first' => 297.9],
                'eval_seeds' => [100000, 102000],
                'skills_source' => 'default',
                'evaluated_at' => 1752910500,
            ],
        ];
        return $meta;
    }

    /**
     * Drop an artifact at the pending path.
     *
     * @param array $artifact The artifact to encode.
     * @return void
     */
    private function stage_pending(array $artifact): void {
        file_put_contents(policy_store::pending_path(), json_encode($artifact));
    }

    /**
     * With no promoted file the shipped default serves, under its content-addressed id.
     *
     * @return void
     */
    public function test_get_active_shipped_default(): void {
        $active = policy_store::get_active();
        $this->assertSame('shipped', $active->source);
        $raw = file_get_contents(policy_store::shipped_path());
        $this->assertSame('shipped-' . substr(sha1($raw), 0, 12), $active->policyid);
        $this->assertIsArray($active->meta['policy']);
        $this->assertSame(bkt::SKILLS, array_values($active->meta['skills']));
    }

    /**
     * The rule table: the golden artifact passes; each single-field mutation trips exactly its
     * own localised error.
     *
     * @return void
     */
    public function test_validate_artifact_rules(): void {
        $good = $this->valid_artifact();
        $report = policy_store::validate_artifact(json_encode($good));
        $this->assertTrue($report['ok'], implode(' ', $report['errors']));
        $this->assertSame([], $report['errors']);

        $cases = [];
        $mutated = $good;
        $mutated['bin_edges'] = [0.5, 0.725, 0.95];
        $cases['wrong bin edge'] = [$mutated, 'artifacterror_encoding'];
        $mutated = $good;
        $mutated['skills'] = array_reverse($mutated['skills']);
        $cases['reordered skills'] = [$mutated, 'artifacterror_encoding'];
        $mutated = $good;
        $mutated['n_actions'] = 25;
        $cases['wrong n_actions'] = [$mutated, 'artifacterror_encoding'];
        $mutated = $good;
        $mutated['artifact']['state_encoding_version'] = 'enc-2';
        $cases['wrong encoding version'] = [$mutated, 'artifacterror_encoding'];
        $mutated = $good;
        $mutated['policy']['0'] = 24;
        $cases['action out of range'] = [$mutated, 'artifacterror_actions'];
        $mutated = $good;
        $mutated['policy']['65536'] = 0;
        $cases['state out of range'] = [$mutated, 'artifacterror_actions'];
        $mutated = $good;
        $mutated['policy'] = [];
        $cases['empty table'] = [$mutated, 'artifacterror_actions'];
        $mutated = $good;
        unset($mutated['artifact']['policy_id']);
        $cases['missing policy id'] = [$mutated, 'artifacterror_policyid'];
        $mutated = $good;
        $mutated['artifact']['policy_id'] = 'bad id with spaces';
        $cases['malformed policy id'] = [$mutated, 'artifacterror_policyid'];
        $mutated = $good;
        $mutated['artifact']['gate']['passed'] = false;
        $cases['gate not passed'] = [$mutated, 'artifacterror_gate'];
        $mutated = $good;
        unset($mutated['artifact']['gate']['baselines']['round_robin']);
        $cases['missing baseline'] = [$mutated, 'artifacterror_gate'];
        $mutated = $good;
        $mutated['artifact']['schema'] = 'stackmastery-policy/v0';
        $cases['wrong schema'] = [$mutated, 'artifacterror_schema'];
        $mutated = $good;
        unset($mutated['artifact']);
        $cases['missing artifact block'] = [$mutated, 'artifacterror_schema'];

        foreach ($cases as $label => [$artifact, $errorkey]) {
            $report = policy_store::validate_artifact(json_encode($artifact));
            $this->assertFalse($report['ok'], $label);
            $this->assertContains(get_string($errorkey, 'mod_stackmastery'), $report['errors'], $label);
        }

        // Malformed JSON and oversize inputs.
        $report = policy_store::validate_artifact('{"truncated":');
        $this->assertFalse($report['ok']);
        $this->assertContains(get_string('artifacterror_json', 'mod_stackmastery'), $report['errors']);
        $report = policy_store::validate_artifact(str_repeat('x', policy_store::MAX_ARTIFACT_BYTES + 1));
        $this->assertFalse($report['ok']);
        $this->assertContains(get_string('artifacterror_size', 'mod_stackmastery'), $report['errors']);

        // Promoting the incumbent id is an error only when an active id is supplied.
        $report = policy_store::validate_artifact(json_encode($good), 'fqi-20260712-9a1b2c3d');
        $this->assertFalse($report['ok']);
        $this->assertContains(get_string('artifacterror_same', 'mod_stackmastery'), $report['errors']);
    }

    /**
     * The promote flow: files swapped atomically, config recorded, MUC invalidated without a
     * manual purge, pending removed, and the C21 event payload exact.
     *
     * @return void
     */
    public function test_promote_flow(): void {
        global $USER;
        $shippedid = policy_store::get_active()->policyid;   // Warm the cache deliberately.
        $this->stage_pending($this->valid_artifact());
        $pending = policy_store::get_pending();
        $this->assertNotNull($pending);
        $this->assertTrue($pending->report['ok']);

        $sink = $this->redirectEvents();
        $active = policy_store::promote((int) $USER->id);
        $events = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof \mod_stackmastery\event\policy_promoted;
        }));
        $sink->close();

        $this->assertSame('promoted', $active->source);
        $this->assertSame('fqi-20260712-9a1b2c3d', $active->policyid);
        $this->assertFileExists(policy_store::active_path());
        $this->assertFileDoesNotExist(policy_store::active_path() . '.tmp');
        $this->assertFileDoesNotExist(policy_store::pending_path());
        $this->assertSame('fqi-20260712-9a1b2c3d', get_config('mod_stackmastery', 'activepolicyid'));
        // MUC invalidation: the cached shipped entry must be gone without a manual purge.
        $this->assertSame('fqi-20260712-9a1b2c3d', policy_store::get_active()->policyid);

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertEquals(\context_system::instance()->id, $event->contextid);
        $this->assertSame($shippedid, $event->other['oldpolicyid']);
        $this->assertSame('fqi-20260712-9a1b2c3d', $event->other['newpolicyid']);
        $this->assertSame('promote', $event->other['source']);
        $this->assertSame(str_repeat('ab', 32), $event->other['datasetsha']);
        $this->assertEqualsWithDelta(143.2, $event->other['gateavgquestions'], 1e-9);
    }

    /**
     * Promote with no pending candidate fails cleanly.
     *
     * @return void
     */
    public function test_promote_requires_pending(): void {
        global $USER;
        $this->expectException(\moodle_exception::class);
        policy_store::promote((int) $USER->id);
    }

    /**
     * Promote re-validates: a gate-failed artifact is rejected and nothing is swapped.
     *
     * @return void
     */
    public function test_promote_rejects_invalid_artifact(): void {
        global $USER;
        $artifact = $this->valid_artifact();
        $artifact['artifact']['gate']['passed'] = false;
        $this->stage_pending($artifact);
        try {
            policy_store::promote((int) $USER->id);
            $this->fail('expected moodle_exception for a gate-failed artifact');
        } catch (\moodle_exception $e) {
            $this->assertFileDoesNotExist(policy_store::active_path());
            $this->assertFileExists(policy_store::pending_path());
            $this->assertSame('shipped', policy_store::get_active()->source);
        }
    }

    /**
     * Rollback restores the newest archived artifact and fires source=rollback.
     *
     * @return void
     */
    public function test_rollback_restores_previous(): void {
        global $USER;
        $this->stage_pending($this->valid_artifact('fqi-20260712-aaaaaaaa'));
        policy_store::promote((int) $USER->id);
        $this->stage_pending($this->valid_artifact('fqi-20260719-bbbbbbbb'));
        policy_store::promote((int) $USER->id);
        $this->assertSame('fqi-20260719-bbbbbbbb', policy_store::get_active()->policyid);
        $this->assertCount(1, policy_store::list_previous());

        $sink = $this->redirectEvents();
        $active = policy_store::rollback((int) $USER->id);
        $events = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof \mod_stackmastery\event\policy_promoted;
        }));
        $sink->close();

        $this->assertSame('fqi-20260712-aaaaaaaa', $active->policyid);
        $this->assertSame('fqi-20260712-aaaaaaaa', get_config('mod_stackmastery', 'activepolicyid'));
        $this->assertCount(1, $events);
        $this->assertSame('rollback', $events[0]->other['source']);
        $this->assertSame('fqi-20260719-bbbbbbbb', $events[0]->other['oldpolicyid']);
        $this->assertSame('fqi-20260712-aaaaaaaa', $events[0]->other['newpolicyid']);
        // The replaced active was archived, so a rollback of the rollback works.
        $previous = policy_store::list_previous();
        $this->assertCount(1, $previous);
        $this->assertSame('fqi-20260719-bbbbbbbb', $previous[0]->policyid);
    }

    /**
     * Rollback with an empty archive reverts to shipped; a second rollback restores the
     * archived promotion (archive of the archive).
     *
     * @return void
     */
    public function test_rollback_empty_archive_reverts_to_shipped(): void {
        global $USER;
        $this->stage_pending($this->valid_artifact('fqi-20260712-cccccccc'));
        policy_store::promote((int) $USER->id);
        $this->assertSame([], policy_store::list_previous());

        $active = policy_store::rollback((int) $USER->id);
        $this->assertSame('shipped', $active->source);
        $this->assertFileDoesNotExist(policy_store::active_path());
        $this->assertStringStartsWith('shipped-', $active->policyid);

        // Double rollback: the archived promotion comes back.
        $active = policy_store::rollback((int) $USER->id);
        $this->assertSame('fqi-20260712-cccccccc', $active->policyid);
    }

    /**
     * Rollback with nothing promoted and nothing archived is a clean error.
     *
     * @return void
     */
    public function test_rollback_with_nothing_fails(): void {
        global $USER;
        $this->expectException(\moodle_exception::class);
        policy_store::rollback((int) $USER->id);
    }

    /**
     * The fallback chain: a corrupt active.json serves shipped with a debugging notice.
     *
     * @return void
     */
    public function test_corrupt_active_falls_back_to_shipped(): void {
        file_put_contents(policy_store::active_path(), '{not json');
        $active = policy_store::get_active();
        $this->assertDebuggingCalled();
        $this->assertSame('shipped', $active->source);
        $this->assertStringStartsWith('shipped-', $active->policyid);
    }

    /**
     * Reject deletes the pending candidate and nothing else.
     *
     * @return void
     */
    public function test_reject_pending(): void {
        $this->stage_pending($this->valid_artifact());
        policy_store::reject_pending();
        $this->assertFileDoesNotExist(policy_store::pending_path());
        $this->assertNull(policy_store::get_pending());
        // Idempotent.
        policy_store::reject_pending();
    }

    /**
     * The previous/ archive is pruned to the newest PREVIOUS_KEEP entries on promote.
     *
     * @return void
     */
    public function test_previous_archive_pruned(): void {
        global $USER;
        $dir = make_writable_directory(policy_store::policy_dir() . '/previous');
        for ($i = 1; $i <= 11; $i++) {
            file_put_contents($dir . sprintf('/fqi-0000000%s-seed_%d.json', $i, 1000000 + $i), '{}');
        }
        $this->stage_pending($this->valid_artifact());
        policy_store::promote((int) $USER->id);
        $previous = policy_store::list_previous();
        $this->assertCount(policy_store::PREVIOUS_KEEP, $previous);
        // The oldest entry was the one pruned.
        $times = array_map(function ($entry) {
            return $entry->time;
        }, $previous);
        $this->assertNotContains(1000001, $times);
    }
}
