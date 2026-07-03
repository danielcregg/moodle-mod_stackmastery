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
 * Active/pending policy artifact storage, validation and the promote/rollback flow.
 *
 * The runtime obtains its serving policy ONLY through get_active() (master plan C13): a valid
 * promoted artifact at <moodledata>/stackmastery/policy/active.json wins, else the shipped
 * data/policy.json. A candidate produced by the offline retrain is dropped at
 * <moodledata>/stackmastery/pending/policy_pending.json, strictly validated (schema, pinned
 * encoding, table bounds, gate-passed flag, id rules, size), and only a site administrator can
 * promote it; every swap archives the previous active (newest 10 kept), purges the MUC cache and
 * fires the policy_promoted event (a rollback is the same event with source 'rollback').
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * File-backed policy artifact store with MUC caching and admin-gated swaps.
 */
final class policy_store {
    /** @var string The policy artifact schema id required in artifact.schema. */
    public const ARTIFACT_SCHEMA = 'stackmastery-policy/v1';

    /** @var int Maximum accepted artifact size (32 MB; a 65536-state map fits far below). */
    public const MAX_ARTIFACT_BYTES = 33554432;

    /** @var int How many archived previous artifacts to keep. */
    public const PREVIOUS_KEEP = 10;

    /** @var string[] Baseline names the gate block must report (displayed, not re-checked). */
    public const GATE_BASELINES = ['random', 'round_robin', 'easiest_first', 'hardest_first'];

    /**
     * The policy directory inside moodledata, created on demand.
     *
     * @return string The directory path.
     */
    public static function policy_dir(): string {
        global $CFG;
        return make_writable_directory($CFG->dataroot . '/stackmastery/policy');
    }

    /**
     * The promoted-artifact path (absent until the first promote).
     *
     * @return string The active.json path.
     */
    public static function active_path(): string {
        return self::policy_dir() . '/active.json';
    }

    /**
     * The pending-candidate path (the retrain drops the artifact here).
     *
     * @return string The policy_pending.json path.
     */
    public static function pending_path(): string {
        global $CFG;
        return make_writable_directory($CFG->dataroot . '/stackmastery/pending') . '/policy_pending.json';
    }

    /**
     * The shipped default policy file inside the plugin.
     *
     * @return string The data/policy.json path.
     */
    public static function shipped_path(): string {
        return __DIR__ . '/../../data/policy.json';
    }

    /**
     * The active policy: a valid promoted active.json, else the shipped default. Cached in the
     * activepolicy MUC cache; promote/rollback purge it. A present-but-invalid active file falls
     * back to shipped with a debugging notice (fail-safe; the per-step policyversion stamp makes
     * the fallback visible in data).
     *
     * @return \stdClass Object with path, source ('shipped' or 'promoted'), policyid and meta.
     */
    public static function get_active(): \stdClass {
        $cache = \cache::make('mod_stackmastery', 'activepolicy');
        $cached = $cache->get('active');
        if (is_array($cached) && isset($cached['policyid'])) {
            return (object) $cached;
        }
        $resolved = self::resolve_active();
        $cache->set('active', $resolved);
        return (object) $resolved;
    }

    /**
     * The pending candidate, if present, with its full validation report.
     *
     * @return \stdClass|null Object with path, json, report, meta and timemodified, or null.
     */
    public static function get_pending(): ?\stdClass {
        $path = self::pending_path();
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }
        $report = self::validate_artifact($json, self::get_active()->policyid);
        return (object) [
            'path' => $path,
            'json' => $json,
            'report' => $report,
            'meta' => $report['meta'],
            'timemodified' => (int) filemtime($path),
        ];
    }

    /**
     * Full validation of a candidate artifact (design 05 section 8.3). Never throws on bad
     * input - the report is admin-reviewable output; each failed rule contributes a distinct
     * localised error line.
     *
     * @param string $json The raw candidate file contents.
     * @param string|null $activepolicyid When given, the incumbent id (promoting it is an error);
     *     null skips that rule (used when validating the active file itself).
     * @return array ['ok' => bool, 'errors' => string[], 'meta' => array|null].
     */
    public static function validate_artifact(string $json, ?string $activepolicyid = null): array {
        if (strlen($json) > self::MAX_ARTIFACT_BYTES) {
            return ['ok' => false, 'errors' => [self::error('size')], 'meta' => null];
        }
        try {
            $meta = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'errors' => [self::error('json')], 'meta' => null];
        }
        if (!is_array($meta)) {
            return ['ok' => false, 'errors' => [self::error('json')], 'meta' => null];
        }
        $errors = [];

        // Pinned-encoding equality, reusing policy.php as the single source of the pins. The
        // required keys and the non-empty table are pre-checked so the only validate_meta failure
        // left possible is a pin mismatch (its final rule is the table's existence).
        $required = ['skills', 'difficulties', 'bin_edges', 'n_bins', 'threshold',
                     'n_states', 'n_actions', 'n_skills', 'n_diff'];
        $missing = false;
        foreach ($required as $key) {
            if (!array_key_exists($key, $meta)) {
                $missing = true;
            }
        }
        $table = $meta['policy'] ?? null;
        $tableok = is_array($table) && $table !== [] && count($table) <= policy::N_STATES;
        if ($missing || (int) ($meta['n_actions'] ?? -1) !== policy::N_ACTIONS) {
            $errors[] = self::error('encoding');
        } else if ($tableok) {
            try {
                policy::validate_meta($meta);
            } catch (\InvalidArgumentException $e) {
                $errors[] = self::error('encoding');
            }
        }

        // Table bounds: canonical integer state keys in [0, N_STATES), integer actions in
        // [0, N_ACTIONS) - the same bounds policy::load() enforces.
        if (!$tableok) {
            $errors[] = self::error('actions');
        } else {
            foreach ($table as $key => $value) {
                $state = (int) $key;
                $badkey = (string) $state !== (string) $key || $state < 0 || $state >= policy::N_STATES;
                $badvalue = !is_int($value) || $value < 0 || $value >= policy::N_ACTIONS;
                if ($badkey || $badvalue) {
                    $errors[] = self::error('actions');
                    break;
                }
            }
        }

        // The artifact block: schema, versions, id rules, gate.
        $artifact = $meta['artifact'] ?? null;
        if (!is_array($artifact) || ($artifact['schema'] ?? null) !== self::ARTIFACT_SCHEMA) {
            $errors[] = self::error('schema');
        } else {
            $encok = ($artifact['state_encoding_version'] ?? null) === policy::ENCODING_VERSION
                && ($artifact['reward_version'] ?? null) === experience::REWARD_VERSION;
            if (!$encok) {
                $errors[] = self::error('encoding');
            }
            $policyid = $artifact['policy_id'] ?? null;
            $idok = is_string($policyid) && $policyid !== '' && strlen($policyid) <= 64
                && preg_match('/^[A-Za-z0-9._-]+$/', $policyid) === 1;
            if (!$idok) {
                $errors[] = self::error('policyid');
            }
            $gate = $artifact['gate'] ?? null;
            $gateok = is_array($gate) && ($gate['passed'] ?? null) === true && is_array($gate['baselines'] ?? null);
            if ($gateok) {
                foreach (self::GATE_BASELINES as $name) {
                    if (!array_key_exists($name, $gate['baselines'])) {
                        $gateok = false;
                    }
                }
            }
            if (!$gateok) {
                $errors[] = self::error('gate');
            }
            if ($idok && $activepolicyid !== null && $policyid === $activepolicyid) {
                $errors[] = self::error('same');
            }
        }
        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'meta' => $meta];
    }

    /**
     * Promote the pending candidate to active. Serialised under the policyswap lock: re-validate,
     * archive the current active (if any), write active.json atomically, delete pending, prune
     * the archive, record the config, purge the MUC cache and fire policy_promoted.
     *
     * @param int $actinguserid The administrator performing the swap.
     * @return \stdClass The new active descriptor (get_active()).
     * @throws \moodle_exception On validation failure or IO error (nothing swapped).
     */
    public static function promote(int $actinguserid): \stdClass {
        $lock = self::get_swap_lock();
        try {
            $pendingpath = self::pending_path();
            $json = is_file($pendingpath) ? file_get_contents($pendingpath) : false;
            if ($json === false) {
                throw new \moodle_exception('policyswapfailed', 'mod_stackmastery');
            }
            $old = self::get_active();
            $report = self::validate_artifact($json, $old->policyid);
            if (!$report['ok']) {
                throw new \moodle_exception('artifactinvalid', 'mod_stackmastery', '', null,
                    implode(' ', $report['errors']));
            }
            $newid = $report['meta']['artifact']['policy_id'];
            self::archive_active();
            self::write_active($json);
            unlink($pendingpath);
            self::prune_previous();
            self::record_swap($newid);
            $newactive = self::get_active();
            self::fire_event($actinguserid, $old->policyid, $newid, 'promote', $report['meta']);
            return $newactive;
        } finally {
            $lock->release();
        }
    }

    /**
     * Roll back to an archived previous artifact (the newest by default, or a named archive
     * file). With an empty archive the shipped default counts as the implicit previous: the
     * current active is archived (so rollback of a rollback works) and active.json is removed.
     * Fires policy_promoted with source 'rollback'.
     *
     * @param int $actinguserid The administrator performing the swap.
     * @param string|null $filename Optional archive basename to restore; null = newest.
     * @return \stdClass The new active descriptor (get_active()).
     * @throws \moodle_exception When there is nothing to roll back to, or on IO error.
     */
    public static function rollback(int $actinguserid, ?string $filename = null): \stdClass {
        $lock = self::get_swap_lock();
        try {
            $previous = self::list_previous();
            $old = self::get_active();
            if ($previous === []) {
                if ($old->source !== 'promoted' || !is_file(self::active_path())) {
                    throw new \moodle_exception('norollback', 'mod_stackmastery');
                }
                self::archive_active();
                self::record_swap(null);
                $newactive = self::get_active();
                self::record_swap($newactive->policyid);
                self::fire_event($actinguserid, $old->policyid, $newactive->policyid, 'rollback', null);
                return $newactive;
            }
            $target = $previous[0];
            if ($filename !== null) {
                $target = null;
                foreach ($previous as $entry) {
                    if ($entry->filename === $filename) {
                        $target = $entry;
                    }
                }
                if ($target === null) {
                    throw new \moodle_exception('norollback', 'mod_stackmastery');
                }
            }
            $json = file_get_contents($target->path);
            if ($json === false) {
                throw new \moodle_exception('policyswapfailed', 'mod_stackmastery');
            }
            $report = self::validate_artifact($json);
            if (!$report['ok']) {
                throw new \moodle_exception('artifactinvalid', 'mod_stackmastery', '', null,
                    implode(' ', $report['errors']));
            }
            $newid = $report['meta']['artifact']['policy_id'];
            self::archive_active();
            self::write_active($json);
            unlink($target->path);
            self::prune_previous();
            self::record_swap($newid);
            $newactive = self::get_active();
            self::fire_event($actinguserid, $old->policyid, $newid, 'rollback', $report['meta']);
            return $newactive;
        } finally {
            $lock->release();
        }
    }

    /**
     * Reject (delete) the pending candidate. No event: nothing served ever changed.
     *
     * @return void
     */
    public static function reject_pending(): void {
        $path = self::pending_path();
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * The archived previous artifacts, newest first.
     *
     * @return \stdClass[] Objects with filename, path, policyid and time.
     */
    public static function list_previous(): array {
        $dir = self::policy_dir() . '/previous';
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.json') as $path) {
            $name = basename($path);
            $policyid = $name;
            $time = (int) filemtime($path);
            if (preg_match('/^(.+)_(\d+)\.json$/', $name, $matches)) {
                $policyid = $matches[1];
                $time = (int) $matches[2];
            }
            $out[] = (object) ['filename' => $name, 'path' => $path, 'policyid' => $policyid, 'time' => $time];
        }
        usort($out, function ($a, $b) {
            if ($a->time !== $b->time) {
                return $b->time <=> $a->time;
            }
            return strcmp($b->filename, $a->filename);
        });
        return $out;
    }

    /**
     * Resolve the active policy without the cache.
     *
     * @return array The descriptor array (path, source, policyid, meta).
     */
    private static function resolve_active(): array {
        $activepath = self::active_path();
        if (is_file($activepath)) {
            $json = file_get_contents($activepath);
            if ($json !== false) {
                $report = self::validate_artifact($json);
                if ($report['ok']) {
                    return [
                        'path' => $activepath,
                        'source' => 'promoted',
                        'policyid' => $report['meta']['artifact']['policy_id'],
                        'meta' => $report['meta'],
                    ];
                }
                debugging('mod_stackmastery: active policy file failed validation; serving the shipped ' .
                    'policy. ' . implode(' ', $report['errors']), DEBUG_DEVELOPER);
            }
        }
        $shipped = self::shipped_path();
        $policy = policy::load($shipped);
        return [
            'path' => $shipped,
            'source' => 'shipped',
            'policyid' => $policy->version(),
            'meta' => $policy->meta(),
        ];
    }

    /**
     * Acquire the promote/rollback serialisation lock.
     *
     * @return \core\lock\lock The held lock.
     * @throws \moodle_exception When the lock cannot be obtained in time.
     */
    private static function get_swap_lock(): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('mod_stackmastery');
        $lock = $factory->get_lock('policyswap', 10);
        if (!$lock) {
            throw new \moodle_exception('policyswapfailed', 'mod_stackmastery');
        }
        return $lock;
    }

    /**
     * Archive the current active.json (if any) into previous/, named by its policy id and time.
     *
     * @return void
     */
    private static function archive_active(): void {
        $activepath = self::active_path();
        if (!is_file($activepath)) {
            return;
        }
        $policyid = 'unknown';
        $json = file_get_contents($activepath);
        if ($json !== false) {
            $meta = json_decode($json, true);
            if (is_array($meta) && is_string($meta['artifact']['policy_id'] ?? null)) {
                $policyid = $meta['artifact']['policy_id'];
            }
        }
        $safeid = preg_replace('/[^A-Za-z0-9._-]/', '_', $policyid);
        $dir = make_writable_directory(self::policy_dir() . '/previous');
        $base = $dir . '/' . $safeid . '_' . time();
        $dest = $base . '.json';
        $n = 0;
        while (file_exists($dest)) {
            $n++;
            $dest = $base . '-' . $n . '.json';
        }
        if (!rename($activepath, $dest)) {
            throw new \moodle_exception('policyswapfailed', 'mod_stackmastery');
        }
    }

    /**
     * Write active.json atomically (tmp + rename).
     *
     * @param string $json The validated artifact contents.
     * @return void
     */
    private static function write_active(string $json): void {
        $activepath = self::active_path();
        $tmp = $activepath . '.tmp';
        $written = file_put_contents($tmp, $json);
        if ($written !== strlen($json) || !rename($tmp, $activepath)) {
            throw new \moodle_exception('policyswapfailed', 'mod_stackmastery');
        }
    }

    /**
     * Prune the previous/ archive to the newest PREVIOUS_KEEP entries.
     *
     * @return void
     */
    private static function prune_previous(): void {
        $previous = self::list_previous();
        foreach (array_slice($previous, self::PREVIOUS_KEEP) as $entry) {
            unlink($entry->path);
        }
    }

    /**
     * Record the swap in config and purge the MUC cache.
     *
     * @param string|null $policyid The new active id, or null to clear before re-resolving.
     * @return void
     */
    private static function record_swap(?string $policyid): void {
        if ($policyid === null) {
            unset_config('activepolicyid', 'mod_stackmastery');
        } else {
            set_config('activepolicyid', $policyid, 'mod_stackmastery');
        }
        \cache::make('mod_stackmastery', 'activepolicy')->purge();
    }

    /**
     * Fire the policy_promoted event (master plan C21 payload) after the swap.
     *
     * @param int $userid The acting administrator.
     * @param string $oldid The previous active policy id.
     * @param string $newid The new active policy id.
     * @param string $source Either 'promote' or 'rollback'.
     * @param array|null $meta The promoted artifact meta (null when reverting to shipped).
     * @return void
     */
    private static function fire_event(int $userid, string $oldid, string $newid, string $source,
            ?array $meta): void {
        $gateavg = $meta['artifact']['gate']['avg_questions'] ?? null;
        $event = \mod_stackmastery\event\policy_promoted::create([
            'context' => \context_system::instance(),
            'userid' => $userid,
            'other' => [
                'oldpolicyid' => $oldid,
                'newpolicyid' => $newid,
                'source' => $source,
                'datasetsha' => $meta['artifact']['dataset']['sha256'] ?? null,
                'gateavgquestions' => $gateavg === null ? null : (float) $gateavg,
            ],
        ]);
        $event->trigger();
    }

    /**
     * A localised artifact-validation error line.
     *
     * @param string $rule The artifacterror_* suffix.
     * @return string The localised message.
     */
    private static function error(string $rule): string {
        return get_string('artifacterror_' . $rule, 'mod_stackmastery');
    }
}
