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
 * Imports the shipped sample question bank into an instance's pool category, tagged.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_stackmastery\local;

/**
 * The starter pack: 14 oracle-validated STACK questions shipped in the module's questionbank
 * directory (forge exports regenerated from templates, covering all 8 skills).
 *
 * import() brings them into the instance's pool category with Moodle's standard XML question
 * import, then tags each question with the skill and difficulty tags the pool queries expect.
 * The tag plan derives from the file names: the prefix is the forge template type (mapped to a
 * canonical skill via skills::FORGE_TYPE_MAP) and the index picks the difficulty (_1 easy,
 * _2 medium, _3 hard, _4 medium); a type with a single file is tagged with all three
 * difficulties so every one of its cells is covered. A file whose question name already exists
 * in the category is skipped, so the import is safe to repeat.
 */
final class starter_pack {
    /** @var array<int, string> Difficulty tag per file index for multi-file types. */
    const INDEX_DIFFICULTIES = [1 => 'easy', 2 => 'medium', 3 => 'hard', 4 => 'medium'];

    /**
     * The absolute path of the shipped question bank directory.
     *
     * @return string The directory path.
     */
    public static function bank_directory(): string {
        return dirname(__DIR__, 2) . '/questionbank';
    }

    /**
     * The shipped XML file names, sorted.
     *
     * @return string[] Base names, e.g. differentiate_1.xml.
     */
    public static function bank_files(): array {
        $paths = glob(self::bank_directory() . '/*.xml') ?: [];
        $names = array_map('basename', $paths);
        sort($names);
        return $names;
    }

    /**
     * Pure tagging plan for a set of shipped file names.
     *
     * @param string[] $filenames Base names shaped <forgetype>_<index>.xml.
     * @return array<string, array{skill: string, difficulties: string[]}> Plan keyed by file name;
     *         files that do not parse or whose type is unknown are omitted.
     */
    public static function tag_plan(array $filenames): array {
        $bytype = [];
        foreach ($filenames as $filename) {
            if (!preg_match('/^([a-z_]+)_(\d+)\.xml$/', $filename, $matches)) {
                continue;
            }
            if (!isset(skills::FORGE_TYPE_MAP[$matches[1]])) {
                continue;
            }
            $bytype[$matches[1]][$filename] = (int) $matches[2];
        }

        $plan = [];
        foreach ($bytype as $type => $files) {
            $skill = skills::FORGE_TYPE_MAP[$type];
            foreach ($files as $filename => $index) {
                if (count($files) === 1) {
                    // A single file must cover the whole skill: tag it for every difficulty.
                    $difficulties = skills::DIFFICULTIES;
                } else {
                    $difficulties = [self::INDEX_DIFFICULTIES[$index] ?? 'medium'];
                }
                $plan[$filename] = ['skill' => $skill, 'difficulties' => $difficulties];
            }
        }
        ksort($plan);
        return $plan;
    }

    /**
     * Import the starter pack into the instance's pool category and tag every imported question.
     *
     * @param \stdClass $instance The stackmastery instance record (poolcategoryid and course are read).
     * @param \context $context The pool category's context (import target and tag context).
     * @return array{imported: int, skipped: int, failed: int} Per-file outcome counts.
     */
    public static function import(\stdClass $instance, \context $context): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $category = $DB->get_record(
            'question_categories',
            ['id' => (int) $instance->poolcategoryid],
            '*',
            MUST_EXIST
        );
        $course = get_course((int) $instance->course);
        $plan = self::tag_plan(self::bank_files());

        $counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($plan as $filename => $tags) {
            $path = self::bank_directory() . '/' . $filename;
            $name = self::question_name($path);
            if ($name !== '' && self::name_exists((int) $category->id, $name)) {
                $counts['skipped']++;
                continue;
            }

            $qids = self::import_file($path, $filename, $category, $context, $course);
            if ($qids === []) {
                $counts['failed']++;
                continue;
            }

            $tagnames = [skills::skill_tag($tags['skill'])];
            foreach ($tags['difficulties'] as $difficulty) {
                $tagnames[] = skills::diff_tag($difficulty);
            }
            foreach ($qids as $qid) {
                \core_tag_tag::set_item_tags('core_question', 'question', $qid, $context, $tagnames);
            }
            $counts['imported']++;
        }
        return $counts;
    }

    /**
     * Import one shipped XML file with Moodle's standard XML question import.
     *
     * The pattern matches the repo's proven importers (scripts/moodle_seed.php and
     * local_stackforge's import_one): category pinned, no category/context from the file, stop on
     * error, progress HTML swallowed; the imported ids come from the importer's own list.
     *
     * @param string $path Absolute path of the XML file.
     * @param string $filename The real file name, for import diagnostics.
     * @param \stdClass $category The target question category.
     * @param \context $context The category's context.
     * @param \stdClass $course The instance's course.
     * @return int[] The imported question ids (empty on failure).
     */
    private static function import_file(
        string $path,
        string $filename,
        \stdClass $category,
        \context $context,
        \stdClass $course
    ): array {
        $qformat = new \qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);
        $qformat->setCourse($course);
        $qformat->setFilename($path);
        $qformat->setRealfilename($filename);
        $qformat->setMatchgrades('error');
        $qformat->setCatfromfile(false);
        $qformat->setContextfromfile(false);
        $qformat->setStoponerror(true);

        ob_start(); // The importprocess() call echoes progress HTML; swallow it.
        try {
            $ok = $qformat->importpreprocess()
                && $qformat->importprocess()
                && $qformat->importpostprocess();
        } catch (\Throwable $e) {
            $ok = false;
            debugging('mod_stackmastery starter pack: import of ' . $filename . ' failed: '
                . $e->getMessage(), DEBUG_DEVELOPER);
        }
        ob_end_clean();
        if (!$ok) {
            return [];
        }
        return array_map('intval', $qformat->questionids ?? []);
    }

    /**
     * The (first) question name inside a shipped quiz XML file.
     *
     * @param string $path Absolute path of the XML file.
     * @return string The question name, or '' when it cannot be read.
     */
    public static function question_name(string $path): string {
        $xml = @simplexml_load_file($path);
        if ($xml === false) {
            return '';
        }
        foreach ($xml->question as $question) {
            if ((string) $question['type'] === 'category') {
                continue;
            }
            return trim((string) $question->name->text);
        }
        return '';
    }

    /**
     * Whether a question of this name already exists in the category (any version).
     *
     * @param int $categoryid The question category id.
     * @param string $name The question name.
     * @return bool True when a same-named question exists.
     */
    private static function name_exists(int $categoryid, string $name): bool {
        global $DB;
        $sql = "SELECT 1
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 WHERE qbe.questioncategoryid = :categoryid AND q.name = :name";
        return $DB->record_exists_sql($sql, ['categoryid' => $categoryid, 'name' => $name]);
    }
}
