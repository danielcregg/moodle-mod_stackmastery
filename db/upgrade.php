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
    // Fresh plugin: install.xml is authoritative at install time. Add guarded, idempotent,
    // append-only steps here as the schema evolves after first release.
    return true;
}
