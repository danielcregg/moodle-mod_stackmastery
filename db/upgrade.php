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
 * Upgrade steps for mod_stackmastery.
 *
 * Discipline (binding): every post-release schema change edits db/install.xml AND appends a matching
 * guarded, idempotent block below ending in upgrade_mod_savepoint(); a fresh install and an upgraded
 * site must produce identical schemas. Never edit a shipped block.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute the mod_stackmastery upgrade from the given old version.
 *
 * @param int $oldversion The currently installed plugin version.
 * @return bool Always true on success.
 */
function xmldb_stackmastery_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026070310) {
        // Custom topics (design D1): the per-instance topic table. Definition mirrors
        // install.xml exactly so upgraded and fresh sites end up schema-identical.
        $table = new xmldb_table('stackmastery_topics');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('stackmasteryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('slug', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('templatetype', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('stackmasteryid', XMLDB_KEY_FOREIGN, ['stackmasteryid'], 'stackmastery', ['id']);
        $table->add_index('instanceslug', XMLDB_INDEX_UNIQUE, ['stackmasteryid', 'slug']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Widen stackmastery_attempts.skillssnapshot char(255) -> text: the snapshot csv now
        // also carries custom topic slugs (design D3) and 8 codes plus 12 slugs can exceed 255.
        $table = new xmldb_table('stackmastery_attempts');
        $field = new xmldb_field('skillssnapshot', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null, 'masterycurrent');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070310, 'stackmastery');
    }
    return true;
}
