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
 * Version metadata for the STACK Mastery activity module.
 *
 * An adaptive formative mastery check for STACK mathematics questions: a trained RL policy plus
 * per-skill BKT mastery tracking pick each next question until the student reaches the target.
 *
 * @package    mod_stackmastery
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'mod_stackmastery';
$plugin->version      = 2026070301;
$plugin->requires     = 2024100700;             // Moodle 4.5 (LTS).
$plugin->supported    = [405, 405];             // Developed and tested on Moodle 4.5 LTS.
$plugin->maturity     = MATURITY_BETA;
$plugin->release      = '0.1.0-beta';
$plugin->dependencies = [
    'qtype_stack' => ANY_VERSION, // The question pool is STACK questions; the loop grades via qtype_stack.
];
