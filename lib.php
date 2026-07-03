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
 * Library hooks for mod_stackmastery: feature matrix, instance CRUD, grades, reset, navigation.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_stackmastery\local\attempt_store;
use mod_stackmastery\local\grades;
use mod_stackmastery\local\skills;

/**
 * Declare the features this module supports.
 *
 * Notable stances: no groups or groupings in v1 (a groupmode selector with no behaviour would
 * mislead); grade is one fixed 0 to 100 value item; the module owns question usages.
 *
 * @param string $feature Constant for the requested feature, e.g. FEATURE_GROUPS.
 * @return mixed True/false when known, the purpose constant for FEATURE_MOD_PURPOSE, null otherwise.
 */
function stackmastery_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS:
            return false;
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_MODEDIT_DEFAULT_COMPLETION:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_CONTROLS_GRADE_VISIBILITY:
            return false;
        case FEATURE_ADVANCED_GRADING:
            return false;
        case FEATURE_USES_QUESTIONS:
            return true;
        case FEATURE_PLAGIARISM:
            return false;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Add a stackmastery instance.
 *
 * Snapshots the admin epsilon onto the instance (clamped to [0, 0.2]) so a mid-course admin
 * change never silently alters a running study arm. Normalises skills and derives the target
 * vector so the CLI/generator path (no form) works too.
 *
 * @param stdClass $data Form or generator data.
 * @param mod_stackmastery_mod_form|null $mform The form, when saved through the UI.
 * @return int The new instance id.
 */
function stackmastery_add_instance(stdClass $data, ?mod_stackmastery_mod_form $mform = null): int {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    if (empty($data->skills)) {
        $data->skills = implode(',', skills::CODES);
    }
    if (!isset($data->targetmastery) || $data->targetmastery === '') {
        $data->targetmastery = 0.95;
    }
    $data->targetmastery = (float) $data->targetmastery;
    $data->targetvector = json_encode(array_fill_keys(skills::CODES, $data->targetmastery));
    $epsilon = (float) get_config('mod_stackmastery', 'epsilon');
    $data->epsilon = min(max($epsilon, 0.0), 0.2);

    $data->id = $DB->insert_record('stackmastery', $data);
    stackmastery_grade_item_update($data);
    return $data->id;
}

/**
 * Update a stackmastery instance.
 *
 * Editing skills/target/pool NEVER rewrites existing attempts: each attempt snapshots them at
 * start and finishes under the old rules. The epsilon snapshot is deliberately left untouched.
 * Grades are recomputed because grademode/skills/target may have changed (derive-on-read).
 *
 * @param stdClass $data Form data.
 * @param mod_stackmastery_mod_form|null $mform The form.
 * @return bool True on success.
 */
function stackmastery_update_instance(stdClass $data, ?mod_stackmastery_mod_form $mform = null): bool {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    if (empty($data->skills)) {
        $data->skills = implode(',', skills::CODES);
    }
    if (isset($data->targetmastery)) {
        $data->targetmastery = (float) $data->targetmastery;
        $data->targetvector = json_encode(array_fill_keys(skills::CODES, $data->targetmastery));
    }
    $DB->update_record('stackmastery', $data);

    $instance = $DB->get_record('stackmastery', ['id' => $data->id], '*', MUST_EXIST);
    stackmastery_grade_item_update($instance);
    stackmastery_update_grades($instance);
    return true;
}

/**
 * Delete a stackmastery instance with everything it owns.
 *
 * All attempt data (question usages included) goes through attempt_store::delete_attempts(),
 * the single deletion primitive shared with privacy and course reset. This also makes plugin
 * uninstall safe (core deletes each course module first).
 *
 * @param int $id Instance id.
 * @return bool True on success.
 */
function stackmastery_delete_instance(int $id): bool {
    global $DB;

    $instance = $DB->get_record('stackmastery', ['id' => $id], '*', MUST_EXIST);
    attempt_store::delete_attempts($id);
    stackmastery_grade_item_delete($instance);
    $DB->delete_records('stackmastery', ['id' => $id]);
    return true;
}

/**
 * Create or update the grade item for an instance.
 *
 * The item is a fixed 0 to 100 value item; the grademode only changes how attempt grades map
 * onto it, never the item shape.
 *
 * @param stdClass $stackmastery Instance record (id, course, name).
 * @param array|string|null $grades Grade objects keyed by userid, 'reset', or null (item only).
 * @return int GRADE_UPDATE_OK or a grade_update() error constant.
 */
function stackmastery_grade_item_update(stdClass $stackmastery, $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $item = [
        'itemname'  => clean_param($stackmastery->name, PARAM_NOTAGS),
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => 100.0,
        'grademin'  => 0.0,
    ];
    if ($grades === 'reset') {
        $item['reset'] = true;
        $grades = null;
    }
    return grade_update(
        'mod/stackmastery',
        $stackmastery->course,
        'mod',
        'stackmastery',
        $stackmastery->id,
        0,
        $grades,
        $item
    );
}

/**
 * Delete the grade item for an instance.
 *
 * @param stdClass $stackmastery Instance record.
 * @return int GRADE_UPDATE_OK or a grade_update() error constant.
 */
function stackmastery_grade_item_delete(stdClass $stackmastery): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update(
        'mod/stackmastery',
        $stackmastery->course,
        'mod',
        'stackmastery',
        $stackmastery->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * The gradebook grades for one or all users: highest finished attempt, derived on read.
 *
 * @param stdClass $stackmastery Instance record.
 * @param int $userid One user, or 0 for all users with gradable attempts.
 * @return array<int, stdClass> Map userid => grade object.
 */
function stackmastery_get_user_grades(stdClass $stackmastery, int $userid = 0): array {
    return grades::get_user_grades($stackmastery, $userid);
}

/**
 * Push (re)computed user grades into the gradebook.
 *
 * Called on attempt finish/abandon/delete and on instance update (grademode may have changed).
 *
 * @param stdClass $stackmastery Instance record.
 * @param int $userid One user, or 0 for all.
 * @param bool $nullifnone Push a null grade when the named user has no gradable attempt.
 * @return void
 */
function stackmastery_update_grades(stdClass $stackmastery, int $userid = 0, bool $nullifnone = true): void {
    $grades = stackmastery_get_user_grades($stackmastery, $userid);
    if ($grades) {
        stackmastery_grade_item_update($stackmastery, $grades);
    } else if ($userid && $nullifnone) {
        $grade = (object) ['userid' => $userid, 'rawgrade' => null];
        stackmastery_grade_item_update($stackmastery, [$userid => $grade]);
    } else {
        stackmastery_grade_item_update($stackmastery);
    }
}

/**
 * Remove all grades from the gradebook for every instance in a course.
 *
 * @param int $courseid The course id.
 * @param string $type Unused (module has one grade item kind).
 * @return void
 */
function stackmastery_reset_gradebook(int $courseid, string $type = ''): void {
    global $DB;

    $instances = $DB->get_records('stackmastery', ['course' => $courseid]);
    foreach ($instances as $instance) {
        stackmastery_grade_item_update($instance, 'reset');
    }
}

/**
 * Add module-specific controls to the course reset form.
 *
 * @param MoodleQuickForm $mform The course reset form.
 * @return void
 */
function stackmastery_reset_course_form_definition(MoodleQuickForm &$mform): void {
    $mform->addElement('header', 'stackmasteryheader', get_string('modulenameplural', 'mod_stackmastery'));
    $mform->addElement(
        'advcheckbox',
        'reset_stackmastery_attempts',
        get_string('attempts', 'mod_stackmastery')
    );
}

/**
 * Default values for the course reset form.
 *
 * @param stdClass $course The course record.
 * @return array Defaults keyed by form element name.
 */
function stackmastery_reset_course_form_defaults(stdClass $course): array {
    return ['reset_stackmastery_attempts' => 1];
}

/**
 * Perform the course reset: delete every attempt (question usages included) in the course.
 *
 * @param stdClass $data The reset form data (courseid, reset_stackmastery_attempts).
 * @return array Standard component/item/error status rows.
 */
function stackmastery_reset_userdata(stdClass $data): array {
    global $DB;

    $status = [];
    $componentstr = get_string('modulenameplural', 'mod_stackmastery');
    if (!empty($data->reset_stackmastery_attempts)) {
        $instances = $DB->get_records('stackmastery', ['course' => $data->courseid]);
        foreach ($instances as $instance) {
            attempt_store::delete_attempts($instance->id);
        }
        if (empty($data->reset_gradebook_grades)) {
            stackmastery_reset_gradebook($data->courseid);
        }
        $status[] = [
            'component' => $componentstr,
            'item'      => get_string('attempts', 'mod_stackmastery'),
            'error'     => false,
        ];
    }
    return $status;
}

/**
 * Course-page info: description plus the custom completion rule value.
 *
 * @param stdClass $coursemodule The course module record.
 * @return cached_cm_info|false Info for the course page, or false when the instance is missing.
 */
function stackmastery_get_coursemodule_info(stdClass $coursemodule) {
    global $DB;

    $fields = 'id, name, intro, introformat, completionreachedtarget';
    $instance = $DB->get_record('stackmastery', ['id' => $coursemodule->instance], $fields);
    if (!$instance) {
        return false;
    }

    $info = new cached_cm_info();
    $info->name = $instance->name;
    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $info->content = format_module_intro('stackmastery', $instance, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionreachedtarget'] =
            $instance->completionreachedtarget;
    }
    return $info;
}

/**
 * Add the report node to the module settings navigation (rendered as a tab in Boost).
 *
 * @param settings_navigation $settingsnav The settings navigation.
 * @param navigation_node $stackmasterynode This module's node.
 * @return void
 */
function stackmastery_extend_settings_navigation(
    settings_navigation $settingsnav,
    navigation_node $stackmasterynode
): void {
    $cm = $settingsnav->get_page()->cm;
    if ($cm && has_capability('mod/stackmastery:viewreports', $cm->context)) {
        $stackmasterynode->add(
            get_string('report', 'mod_stackmastery'),
            new moodle_url('/mod/stackmastery/report.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'stackmasteryreport'
        );
    }
}

/**
 * Mark the activity viewed: fire the view event and update completion view tracking.
 *
 * @param stdClass $stackmastery Instance record.
 * @param stdClass $course Course record.
 * @param cm_info|stdClass $cm Course module.
 * @param context_module $context Module context.
 * @return void
 */
function stackmastery_view(stdClass $stackmastery, stdClass $course, $cm, context_module $context): void {
    global $CFG;
    require_once($CFG->libdir . '/completionlib.php');

    $event = \mod_stackmastery\event\course_module_viewed::create([
        'objectid' => $stackmastery->id,
        'context'  => $context,
    ]);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('stackmastery', $stackmastery);
    $event->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}
