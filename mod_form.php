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
 * Instance settings form for mod_stackmastery.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_stackmastery\local\grades;
use mod_stackmastery\local\pool;
use mod_stackmastery\local\skill_manifest;
use mod_stackmastery\local\skills;
use mod_stackmastery\local\topics;
use mod_stackmastery\output\progress_bars;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * The mod_form: skills, free-text custom topics (JS-free no-submit round trips), target, pool
 * category (with hard-block coverage validation for CORE skills), budget, attempts, grading
 * mode, progress visibility, open/close times and the completion rule.
 *
 * Exploration (epsilon) is deliberately absent: it is admin-level and snapshotted onto the
 * instance invisibly at creation.
 *
 * Custom-topics lifecycle (spec D10): the working topic list is derived from the RAW request
 * (the hidden topicsjson round trip, the clicked no-submit button and the topic box) inside
 * definition(), BEFORE the section is rendered, so the static rows and per-row Remove buttons
 * always reflect the current reload; definition_after_data() only sets element values and
 * errors. Nothing in topicsjson is trusted except the label strings (and the slug purely as a
 * database-row lookup key): slugs and template types are re-derived server-side on every reload
 * and on save.
 */
class mod_stackmastery_mod_form extends moodleform_mod {
    /** @var int Maximum custom topics per instance (defence in depth beside lib.php). */
    private const MAX_TOPICS = 12;

    /** @var array[] Working topic rows for this request: slug, label, templatetype, error. */
    private array $topicsworking = [];

    /** @var stdClass[] Persisted topic rows of the instance, ordered by sortorder. */
    private array $topicrows = [];

    /** @var string|null Core skill code to auto-tick after a core-synonym topic check. */
    private ?string $topiccoretick = null;

    /** @var bool Whether the topic input box is cleared on this reload. */
    private bool $topicclearbox = false;

    /** @var string[] Pending element errors from the no-submit click, element name to message. */
    private array $topicerrors = [];

    /**
     * Define the form elements.
     *
     * @return void
     */
    public function definition() {
        global $DB, $OUTPUT;
        $mform = $this->_form;

        // No-submit lifecycle rule (spec D10, Codex #10): resolve the working topic list from
        // the raw request before anything below renders; definition_after_data() only sets
        // element values and errors.
        $this->prepare_topics_from_request();

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        $mform->addElement('header', 'masterysettings', get_string('masterysettings', 'mod_stackmastery'));
        $mform->setExpanded('masterysettings');

        // One flat advcheckbox per canonical skill (groups plus nested arrays fight moodleform_mod).
        $boxes = [];
        foreach (skills::CODES as $code) {
            $boxes[] = $mform->createElement('advcheckbox', 'skill_' . $code, '', skills::label($code));
        }
        $mform->addGroup($boxes, 'skillsgroup', get_string('skills', 'mod_stackmastery'), '<br>', false);
        $mform->addHelpButton('skillsgroup', 'skills', 'mod_stackmastery');
        if (empty($this->_instance)) {
            foreach (skills::CODES as $code) {
                $mform->setDefault('skill_' . $code, 1);
            }
        }

        // Custom topics live directly under the skills group (spec D10).
        $this->add_topic_elements();

        // String keys deliberately: floats as form select keys round-trip badly.
        $mform->addElement('select', 'targetmastery', get_string('targetmastery', 'mod_stackmastery'), [
            '0.95' => get_string('targetmastery_confident', 'mod_stackmastery'),
            '0.85' => get_string('targetmastery_working', 'mod_stackmastery'),
        ]);
        $mform->setType('targetmastery', PARAM_RAW);
        $mform->setDefault('targetmastery', '0.95');
        $mform->addHelpButton('targetmastery', 'targetmastery', 'mod_stackmastery');

        $coursecontext = context_course::instance($this->get_course()->id);
        $menu = [0 => get_string('choosedots')] + pool::category_menu($coursecontext);
        $mform->addElement('select', 'poolcategoryid', get_string('poolcategory', 'mod_stackmastery'), $menu);
        $mform->addHelpButton('poolcategoryid', 'poolcategory', 'mod_stackmastery');

        // Coverage table so a teacher sees the tagged-question counts before saving.
        if (!empty($this->_instance)) {
            $instance = $DB->get_record('stackmastery', ['id' => $this->_instance]);
            $coverage = '';
            if ($instance && $instance->poolcategoryid) {
                $manifest = skill_manifest::from_instance($instance, $this->topicrows);
                $counts = pool::cell_counts((int) $instance->poolcategoryid, $manifest->selected());
                $coverage = $OUTPUT->render_from_template(
                    'mod_stackmastery/pool_coverage',
                    self::coverage_context($counts, progress_bars::manifest_labels($manifest))
                );
            }
            $mform->addElement(
                'static',
                'poolcoverage',
                get_string('poolcoverage', 'mod_stackmastery'),
                $coverage
            );
        } else {
            $mform->addElement(
                'static',
                'poolcoverage',
                get_string('poolcoverage', 'mod_stackmastery'),
                get_string('poolcoverage_addhint', 'mod_stackmastery')
            );
        }

        $mform->addElement('text', 'budget', get_string('budget', 'mod_stackmastery'), ['size' => 4]);
        $mform->setType('budget', PARAM_INT);
        $mform->setDefault('budget', 40);
        $mform->addHelpButton('budget', 'budget', 'mod_stackmastery');

        $attemptoptions = [0 => get_string('unlimited', 'mod_stackmastery')];
        for ($i = 1; $i <= 10; $i++) {
            $attemptoptions[$i] = $i;
        }
        $mform->addElement(
            'select',
            'maxattempts',
            get_string('maxattempts', 'mod_stackmastery'),
            $attemptoptions
        );
        $mform->setDefault('maxattempts', 0);

        $mform->addElement('select', 'grademode', get_string('grademode', 'mod_stackmastery'), [
            grades::GRADEMODE_REACHEDTARGET => get_string('grademode_reachedtarget', 'mod_stackmastery'),
            grades::GRADEMODE_MEANMASTERY   => get_string('grademode_meanmastery', 'mod_stackmastery'),
        ]);
        $mform->setDefault('grademode', grades::GRADEMODE_REACHEDTARGET);
        $mform->addHelpButton('grademode', 'grademode', 'mod_stackmastery');

        $mform->addElement('advcheckbox', 'showprogress', get_string('showprogress', 'mod_stackmastery'));
        $mform->setDefault('showprogress', 1);
        $mform->addHelpButton('showprogress', 'showprogress', 'mod_stackmastery');

        $mform->addElement(
            'date_time_selector',
            'timeopen',
            get_string('timeopen', 'mod_stackmastery'),
            ['optional' => true]
        );
        $mform->addElement(
            'date_time_selector',
            'timeclose',
            get_string('timeclose', 'mod_stackmastery'),
            ['optional' => true]
        );

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Render the custom-topics section: intro, one static row per working topic (label, matched
     * template, no-submit Remove button), the topic box with its no-submit check button, and the
     * hidden round-trip field.
     *
     * @return void
     */
    protected function add_topic_elements(): void {
        $mform = $this->_form;

        $mform->addElement(
            'static',
            'customtopicsintro',
            get_string('customtopics', 'mod_stackmastery'),
            get_string('customtopicsintro', 'mod_stackmastery')
        );

        foreach ($this->topicsworking as $i => $topic) {
            if ($topic['templatetype'] === null) {
                $typelabel = html_writer::span(
                    get_string('topicrecheck', 'mod_stackmastery'),
                    'text-danger'
                );
            } else {
                $typelabel = html_writer::span(
                    s($this->template_type_label($topic['templatetype'])),
                    'badge badge-secondary bg-secondary'
                );
            }
            $html = html_writer::span(s($topic['label']), 'stackmastery-topic-label') . ' ' . $typelabel;
            $row = [
                $mform->createElement('static', 'topicstatic_' . $i, '', $html),
                $mform->createElement('submit', 'removetopic_' . $i, get_string('removetopic', 'mod_stackmastery')),
            ];
            $mform->addGroup($row, 'topicrow_' . $i, '', ' ', false);
            $mform->registerNoSubmitButton('removetopic_' . $i);
        }

        // The newtopic box and its check button are flat (not grouped): definition_after_data()
        // clears the box with setConstant('newtopic', ''), which Moodle only supports on top-level
        // element names (a grouped member resolves to an HTML_QuickForm_Error and fatals).
        $mform->addElement(
            'text',
            'newtopic',
            get_string('newtopic', 'mod_stackmastery'),
            ['size' => 40, 'maxlength' => 100]
        );
        $mform->setType('newtopic', PARAM_TEXT);
        $mform->addElement('submit', 'checktopic', get_string('checktopic', 'mod_stackmastery'));
        $mform->registerNoSubmitButton('checktopic');

        $mform->addElement('hidden', 'topicsjson');
        $mform->setType('topicsjson', PARAM_RAW);
    }

    /**
     * Derive the working topic list for this request (the no-submit lifecycle rule).
     *
     * On a first render (no topicsjson in the request) the list is seeded from the persisted
     * rows. On our own round trips the list is rebuilt from the raw request: entries are
     * resolved through the forgery defence, a clicked Remove drops its row, and a clicked
     * "Check topic and add" classifies the topic box (the ONLY moment the AI pass may run).
     *
     * @return void
     */
    protected function prepare_topics_from_request(): void {
        global $SESSION, $USER;

        if (!empty($this->_instance)) {
            $this->topicrows = array_values(topics::for_instance((int) $this->_instance));
        }
        $dbbyslug = [];
        foreach ($this->topicrows as $row) {
            $dbbyslug[(string) $row->slug] = $row;
        }

        $raw = optional_param('topicsjson', null, PARAM_RAW);
        if ($raw === null) {
            // First render, not one of our round trips: seed from the persisted rows.
            foreach ($this->topicrows as $row) {
                $this->topicsworking[] = [
                    'slug'         => (string) $row->slug,
                    'label'        => (string) $row->label,
                    'templatetype' => (string) $row->templatetype,
                    'error'        => null,
                ];
            }
            return;
        }

        $entries = json_decode($raw, true);
        $cache = isset($SESSION->mod_stackmastery_topiccache)
            ? (array) $SESSION->mod_stackmastery_topiccache
            : [];
        // Two resolvers. The full classifier (keyword then AI) runs ONLY for a fresh
        // "Check topic and add" click, on one label. Round-trip re-resolution of already-listed
        // labels is keyword-only so a forged or stale topicsjson entry can never trigger an AI
        // call at save time (Codex review: keep the AI pass to the explicit check click).
        $classify = null;
        $keywordonly = static function (string $label): array {
            if (!self::topic_mapper_ready()) {
                return ['type' => null, 'method' => 'none'];
            }
            $matches = \local_stackforge\local\topic_mapper::keyword_matches(
                \local_stackforge\local\topic_mapper::normalise($label)
            );
            $type = count($matches) === 1 ? $matches[0] : null;
            return ['type' => $type, 'method' => $type === null ? 'none' : 'keyword'];
        };
        if (self::topic_mapper_ready()) {
            $context = context_course::instance($this->get_course()->id);
            $userid = (int) $USER->id;
            $classify = static function (string $label) use ($context, $userid): array {
                return \local_stackforge\local\topic_mapper::classify($label, $context, $userid);
            };
        }
        $this->topicsworking = self::resolve_topic_entries(
            is_array($entries) ? $entries : [],
            $dbbyslug,
            $cache,
            $keywordonly
        );

        // A Remove click drops its row (indices are per-render; the list re-renders below).
        foreach (array_keys($this->topicsworking) as $i) {
            if (optional_param('removetopic_' . $i, null, PARAM_RAW) !== null) {
                unset($this->topicsworking[$i]);
            }
        }
        $this->topicsworking = array_values($this->topicsworking);

        if (optional_param('checktopic', null, PARAM_RAW) !== null) {
            $this->handle_check_click($dbbyslug, $classify);
        }
    }

    /**
     * Handle a "Check topic and add" click from the raw request.
     *
     * Classifies the topic box text: a core-synonym match ticks the core skill (D2), a non-core
     * template match appends a working row, no match raises an inline element error. Every
     * successful mapper result is cached in the user's session, which is what later authorises
     * AI-matched labels at save time (the forgery defence).
     *
     * @param array $dbbyslug Persisted topic rows indexed by slug.
     * @param callable|null $classify The mapper closure, or null when the forge is absent.
     * @return void
     */
    protected function handle_check_click(array $dbbyslug, ?callable $classify): void {
        global $SESSION;

        $label = trim(core_text::substr(optional_param('newtopic', '', PARAM_TEXT), 0, 100));
        if ($label === '') {
            return;
        }
        if (count($this->topicsworking) >= self::MAX_TOPICS) {
            $this->topicerrors['newtopic'] = get_string('topicslimit', 'mod_stackmastery', self::MAX_TOPICS);
            return;
        }
        if ($classify === null) {
            $this->topicerrors['newtopic'] = get_string('topicneedsforge', 'mod_stackmastery');
            return;
        }
        $result = $classify($label);
        $type = $result['type'] ?? null;
        if ($type === null) {
            $this->topicerrors['newtopic'] = get_string('topicnomatch', 'mod_stackmastery', s($label));
            return;
        }

        // Cache the successful mapper result for this session: save-time re-resolution accepts
        // AI-matched labels only from this cache (spec D10, Codex #10 HIGH).
        $sessioncache = isset($SESSION->mod_stackmastery_topiccache)
            ? (array) $SESSION->mod_stackmastery_topiccache
            : [];
        $sessioncache[self::normalise_topic($label)] = (string) $type;
        $SESSION->mod_stackmastery_topiccache = $sessioncache;

        $coreskill = skills::FORGE_TYPE_MAP[$type] ?? null;
        if ($coreskill !== null) {
            // Core-skill synonym rule (D2): no topic row; tick the core skill and say so.
            $this->topiccoretick = $coreskill;
            $this->topicclearbox = true;
            \core\notification::add(
                get_string('topicmatchedcore', 'mod_stackmastery', (object) [
                    'topic' => s($label),
                    'skill' => skills::label($coreskill),
                ]),
                \core\output\notification::NOTIFY_INFO
            );
            return;
        }

        $taken = array_merge(array_keys($dbbyslug), array_column($this->topicsworking, 'slug'));
        $this->topicsworking[] = [
            'slug'         => topics::make_slug($label, $taken),
            'label'        => $label,
            'templatetype' => (string) $type,
            'error'        => null,
        ];
        $this->topicclearbox = true;
    }

    /**
     * Resolve raw topicsjson entries into the trusted working list (the forgery defence).
     *
     * Only LABEL strings survive from the hidden field (Codex #10 HIGH): a supplied slug is
     * used purely as a lookup key into the persisted rows, which are trusted by slug identity
     * and never re-classified; everything else has its slug and template type re-derived
     * server-side. A label outside the persisted rows is accepted when the session cache holds
     * a successful mapper result for it, or when the mapper's free deterministic KEYWORD pass
     * resolves it; an AI-only match without a cache entry is flagged for re-checking (never
     * silently saved). In the legitimate flow every label was cached at check-click time, so no
     * AI call ever happens at save time.
     *
     * @param array $entries Decoded topicsjson entries (slug and label read, both untrusted).
     * @param array $dbrows Persisted topic rows indexed by slug.
     * @param array $cache Session cache of successful mapper results, normalised label to type.
     * @param callable|null $classify The mapper closure, or null when the forge is absent.
     * @return array[] Working rows: slug, label, templatetype (null when unresolved), error.
     */
    public static function resolve_topic_entries(
        array $entries,
        array $dbrows,
        array $cache,
        ?callable $classify
    ): array {
        $working = [];
        $useddbslugs = [];
        $taken = array_keys($dbrows);
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $label = trim(core_text::substr(clean_param((string) ($entry['label'] ?? ''), PARAM_TEXT), 0, 100));
            $slug = (string) ($entry['slug'] ?? '');
            if ($slug !== '' && isset($dbrows[$slug])) {
                if (in_array($slug, $useddbslugs, true)) {
                    continue;
                }
                // Persisted rows are trusted by slug identity and never re-classified: the
                // label and the template type come from the database, not from the request.
                $row = $dbrows[$slug];
                $working[] = [
                    'slug'         => $slug,
                    'label'        => (string) $row->label,
                    'templatetype' => (string) $row->templatetype,
                    'error'        => null,
                ];
                $useddbslugs[] = $slug;
                continue;
            }
            if ($label === '') {
                continue;
            }
            $type = $cache[self::normalise_topic($label)] ?? null;
            if ($type === null && $classify !== null) {
                $result = $classify($label);
                // Outside the session cache only the keyword pass is authoritative: an AI
                // match at this point came from a forged or stale round trip and must be
                // re-checked by the teacher instead of silently accepted.
                if (($result['method'] ?? '') === 'keyword') {
                    $type = $result['type'] ?? null;
                }
            }
            if ($type !== null && isset(skills::FORGE_TYPE_MAP[$type])) {
                // Core synonyms are ticked as core skills at check time and can never be
                // topic rows (D2); a forged core-typed entry is simply dropped.
                continue;
            }
            $newslug = topics::make_slug($label, $taken);
            $working[] = [
                'slug'         => $newslug,
                'label'        => $label,
                'templatetype' => $type === null ? null : (string) $type,
                'error'        => $type === null ? 'topicrecheck' : null,
            ];
            $taken[] = $newslug;
        }
        return $working;
    }

    /**
     * Normalise a topic label for session-cache keying (mirrors the mapper's normalisation).
     *
     * @param string $label The raw label.
     * @return string Lower-cased, non-alphanumerics collapsed to single spaces, trimmed.
     */
    public static function normalise_topic(string $label): string {
        $text = core_text::strtolower($label);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        return trim((string) $text);
    }

    /**
     * Whether the forge's topic mapper seam is installed.
     *
     * @return bool True when local_stackforge exposes topic_mapper::classify().
     */
    protected static function topic_mapper_ready(): bool {
        return class_exists('\\local_stackforge\\local\\topic_mapper')
            && method_exists('\\local_stackforge\\local\\topic_mapper', 'classify');
    }

    /**
     * Human label of a forge template type, for the topic-row badge.
     *
     * Display-only nicety: uses the forge's canonical catalog when present and falls back to
     * the raw type code (for example after a forge downgrade removed a type).
     *
     * @param string $type The forge template type code.
     * @return string The catalog label, or the raw code.
     */
    protected function template_type_label(string $type): string {
        if (
            class_exists('\\local_stackforge\\local\\topic_map')
            && method_exists('\\local_stackforge\\local\\topic_map', 'label')
        ) {
            try {
                return (string) \local_stackforge\local\topic_map::label($type);
            } catch (\Throwable $e) {
                return $type;
            }
        }
        return $type;
    }

    /**
     * Apply the derived topic state to the form: the canonical round-trip value, the cleared
     * topic box, the auto-ticked core skill and any pending inline errors.
     *
     * Only values and errors are set here (the lifecycle rule); the list itself was derived
     * and rendered in definition().
     *
     * @return void
     */
    public function definition_after_data() {
        parent::definition_after_data();
        $mform = $this->_form;

        // Only labels round-trip as content; the slug rides along purely as the lookup key
        // for persisted rows. Template types are re-derived on every reload and on save.
        $export = [];
        foreach ($this->topicsworking as $topic) {
            $export[] = ['slug' => $topic['slug'], 'label' => $topic['label']];
        }
        $mform->setConstant('topicsjson', json_encode($export));
        if ($this->topicclearbox) {
            $mform->setConstant('newtopic', '');
        }
        if ($this->topiccoretick !== null) {
            // The skill_* advcheckboxes are members of skillsgroup; setConstant only targets
            // top-level names (a grouped member resolves to an HTML_QuickForm_Error and fatals).
            // Tick the matching group child directly so it renders checked and rides into the
            // save POST (data_postprocessing reads the checkbox state).
            $group = $mform->getElement('skillsgroup');
            if ($group instanceof HTML_QuickForm_group) {
                foreach ($group->getElements() as $child) {
                    if ($child->getName() === 'skill_' . $this->topiccoretick) {
                        $child->setValue(1);
                    }
                }
            }
        }
        foreach ($this->topicerrors as $element => $message) {
            $mform->setElementError($element, $message);
        }
    }

    /**
     * Build the pool_coverage template context from a cell-count matrix.
     *
     * @param array $counts Map of skill code to difficulty code to question count.
     * @param array|null $labels Optional code-to-label map (manifest labels); core lang
     *        strings are used when absent.
     * @return stdClass The template context.
     */
    public static function coverage_context(array $counts, ?array $labels = null): stdClass {
        $difficulties = [];
        foreach (skills::DIFFICULTIES as $difficulty) {
            $difficulties[] = ['label' => skills::difficulty_label($difficulty)];
        }
        $rows = [];
        foreach ($counts as $skill => $cells) {
            $name = $labels[$skill] ?? skills::label($skill);
            $row = ['name' => $name, 'cells' => []];
            foreach (skills::DIFFICULTIES as $difficulty) {
                $count = $cells[$difficulty] ?? 0;
                $row['cells'][] = [
                    'count' => $count,
                    'empty' => $count === 0,
                    'thin'  => $count > 0 && $count < 3,
                ];
            }
            $rows[] = $row;
        }
        return (object) ['difficulties' => $difficulties, 'skills' => $rows];
    }

    /**
     * Prepare instance data for the form: csv to checkboxes, target float to its select key.
     *
     * @param array $defaultvalues Values loaded from the instance record, by reference.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        if (isset($defaultvalues['skills'])) {
            if (trim((string) $defaultvalues['skills']) === '' && $this->topicrows !== []) {
                // A custom-topics instance with an empty csv means zero core skills (spec D3);
                // decode_csv's all-8 backfill must not tick every box here.
                $selected = [];
            } else {
                $selected = skills::decode_csv((string) $defaultvalues['skills']);
            }
            foreach (skills::CODES as $code) {
                $defaultvalues['skill_' . $code] = in_array($code, $selected, true) ? 1 : 0;
            }
        }
        if (isset($defaultvalues['targetmastery'])) {
            $target = (float) $defaultvalues['targetmastery'];
            $defaultvalues['targetmastery'] = (abs($target - 0.85) < 1e-9) ? '0.85' : '0.95';
        }
    }

    /**
     * Post-process submitted data: checkboxes to csv, the validated topic list for lib.php,
     * derive the target vector, completion guard.
     *
     * @param stdClass $data The submitted data (passed to add/update_instance afterwards).
     * @return void
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);

        $selected = [];
        foreach (skills::CODES as $code) {
            if (!empty($data->{'skill_' . $code})) {
                $selected[] = $code;
            }
        }
        $data->skills = skills::encode_csv($selected);
        $data->targetmastery = (float) $data->targetmastery;
        // Provisional core-8 vector; lib.php rebuilds it over the manifest after topic sync.
        $data->targetvector = json_encode(array_fill_keys(skills::CODES, $data->targetmastery));

        // The validated working list rides to add/update_instance for the topic sync.
        $data->customtopics = [];
        foreach ($this->topicsworking as $topic) {
            $data->customtopics[] = [
                'slug'         => $topic['slug'],
                'label'        => $topic['label'],
                'templatetype' => $topic['templatetype'],
            ];
        }

        // A hidden completion rule must not stay latched when completion is off (quiz pattern).
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $completion = $data->{'completion' . $suffix} ?? null;
            $autocompletion = !empty($completion) && $completion == COMPLETION_TRACKING_AUTOMATIC;
            if (!$autocompletion) {
                $data->{'completionreachedtarget' . $suffix} = 0;
            }
        }
    }

    /**
     * Server-side validation: skills-or-topics non-empty, topic list sound, target whitelisted,
     * budget in range, and the pool coverage rule.
     *
     * Pool-validation ordering (spec D10, Codex #10 HIGH): the empty-cell hard block applies to
     * the selected CORE skills only. Custom-topic cells are empty by definition until save-time
     * generation runs, so they are exempt and produce a notification instead of an error.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $selected = array_values(array_filter(
            skills::CODES,
            fn($code) => !empty($data['skill_' . $code])
        ));
        $topics = $this->topicsworking;
        // The mastery check needs at least one thing to track: a core skill or a custom topic.
        if ($selected === [] && $topics === []) {
            $errors['skillsgroup'] = get_string('errnoskills', 'mod_stackmastery');
        }
        if (count($topics) > self::MAX_TOPICS) {
            $errors['newtopic'] = get_string('topicslimit', 'mod_stackmastery', self::MAX_TOPICS);
        }
        foreach ($topics as $topic) {
            if ($topic['error'] !== null) {
                // The forgery defence could not re-establish this row's template match:
                // never silently saved, never silently dropped.
                $errors['newtopic'] = get_string('topicrecheck', 'mod_stackmastery');
                break;
            }
        }
        if (!in_array((string) $data['targetmastery'], ['0.85', '0.95'], true)) {
            $errors['targetmastery'] = get_string('errtargetmastery', 'mod_stackmastery');
        }
        $budget = (int) ($data['budget'] ?? 0);
        if ($budget < 1 || $budget > 500) {
            $errors['budget'] = get_string('errbudgetrange', 'mod_stackmastery');
        }
        if (empty($data['poolcategoryid'])) {
            $errors['poolcategoryid'] = get_string('errpoolcategorymissing', 'mod_stackmastery');
        }

        if ($selected !== [] && empty($errors['poolcategoryid'])) {
            $result = pool::validate_selection((int) $data['poolcategoryid'], $selected);
            $errors += $result['errors'];
            if (empty($result['errors'])) {
                // Thin cells warn without blocking: the session notification renders post-redirect.
                foreach ($result['warnings'] as $warning) {
                    \core\notification::add($warning, \core\output\notification::NOTIFY_WARNING);
                }
            }
        }
        if ($errors === [] && $topics !== []) {
            // The custom-topic exemption's counterpart: say that empty topic cells are filled
            // by the save-time generation rather than blocking the save on them.
            $slugs = array_column($topics, 'slug');
            $gaps = pool::cell_gaps(pool::cell_counts((int) $data['poolcategoryid'], $slugs), 1);
            if ($gaps !== []) {
                \core\notification::add(
                    get_string('topicspoolpending', 'mod_stackmastery'),
                    \core\output\notification::NOTIFY_INFO
                );
            }
        }
        return $errors;
    }

    /**
     * Add the custom completion rule element (Moodle 4.3+ suffix API).
     *
     * @return string[] The element names added.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();
        $element = 'completionreachedtarget' . $suffix;
        $mform->addElement(
            'advcheckbox',
            $element,
            '',
            get_string('completionreachedtarget', 'mod_stackmastery')
        );
        $mform->setDefault($element, 0);
        return [$element];
    }

    /**
     * Whether the custom completion rule is enabled in the submitted data.
     *
     * @param array $data Form data.
     * @return bool True when the rule is ticked.
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionreachedtarget' . $this->get_suffix()]);
    }
}
