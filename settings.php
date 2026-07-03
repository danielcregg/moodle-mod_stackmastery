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
 * Admin settings for mod_stackmastery.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Exploration rate. Snapshotted onto each instance at creation; the runtime clamps to [0, 0.2].
    $settings->add(new admin_setting_configtext(
        'mod_stackmastery/epsilon',
        get_string('epsilon', 'mod_stackmastery'),
        get_string('epsilon_desc', 'mod_stackmastery'),
        '0.05',
        PARAM_FLOAT,
        8
    ));

    // Question types eligible for the pool. Production default is stack; tests may add shortanswer.
    $settings->add(new admin_setting_configtext(
        'mod_stackmastery/allowedqtypes',
        get_string('allowedqtypes', 'mod_stackmastery'),
        get_string('allowedqtypes_desc', 'mod_stackmastery'),
        'stack',
        PARAM_RAW_TRIMMED,
        24
    ));

    // How long an untouched in-progress attempt may sit before the cleanup task abandons it.
    $settings->add(new admin_setting_configduration(
        'mod_stackmastery/abandonafter',
        get_string('abandonafter', 'mod_stackmastery'),
        get_string('abandonafter_desc', 'mod_stackmastery'),
        7 * DAYSECS,
        DAYSECS
    ));

    // Inert default (repo convention): no generation jobs are queued until an admin opts in.
    $settings->add(new admin_setting_configcheckbox(
        'mod_stackmastery/poolrefill',
        get_string('poolrefill', 'mod_stackmastery'),
        get_string('poolrefill_desc', 'mod_stackmastery'),
        0
    ));

    // Per-cell target for the nightly refill. The task clamps the value to 1..20 at read.
    $settings->add(new admin_setting_configtext(
        'mod_stackmastery/poolrefilltarget',
        get_string('poolrefilltarget', 'mod_stackmastery'),
        get_string('poolrefilltarget_desc', 'mod_stackmastery'),
        '3',
        PARAM_INT,
        4
    ));

    $settings->add(new admin_setting_heading(
        'mod_stackmastery/experienceheading',
        get_string('experienceheading', 'mod_stackmastery'),
        get_string('experienceheading_desc', 'mod_stackmastery')
    ));

    // Retention of the per-question experience log. Default 0 = keep forever: destruction of
    // research data is opt-in, never silent.
    $settings->add(new admin_setting_configduration(
        'mod_stackmastery/stepretention',
        get_string('stepretention', 'mod_stackmastery'),
        get_string('stepretention_desc', 'mod_stackmastery'),
        0,
        DAYSECS
    ));

    // Export files in moodledata are re-derivable while steps live; prune them independently.
    $settings->add(new admin_setting_configduration(
        'mod_stackmastery/exportfileretention',
        get_string('exportfileretention', 'mod_stackmastery'),
        get_string('exportfileretention_desc', 'mod_stackmastery'),
        35 * DAYSECS,
        DAYSECS
    ));

    // Inert default (repo convention): no training files are generated until an admin opts in.
    $settings->add(new admin_setting_configcheckbox(
        'mod_stackmastery/experienceexport',
        get_string('experienceexport', 'mod_stackmastery'),
        get_string('experienceexport_desc', 'mod_stackmastery'),
        0
    ));

    // Cross-link to the policy review page. Guarded so the link never 404s before the page ships.
    if (file_exists(__DIR__ . '/adminpolicy.php')) {
        $settings->add(new admin_setting_description(
            'mod_stackmastery/policypagelink',
            get_string('policypage', 'mod_stackmastery'),
            get_string(
                'policypagelink_desc',
                'mod_stackmastery',
                (new moodle_url('/mod/stackmastery/adminpolicy.php'))->out()
            )
        ));
    }
}

// The pending-policy review page (review, promote, roll back). Registered outside fulltree so it
// always appears in the admin tree; guarded until the page lands.
if ($hassiteconfig && file_exists(__DIR__ . '/adminpolicy.php')) {
    $ADMIN->add('modsettings', new admin_externalpage(
        'modstackmasterypolicy',
        get_string('policypage', 'mod_stackmastery'),
        new moodle_url('/mod/stackmastery/adminpolicy.php'),
        'moodle/site:config'
    ));
}
