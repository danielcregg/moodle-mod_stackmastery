# Changelog

All notable changes to **mod_stackmastery** are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased] - 0.1.0-alpha

### Added
- **Activity module skeleton for adaptive formative mastery checks on STACK questions.** A teacher
  picks skills (8 canonical codes), a target mastery level, a tagged question pool category, a
  question budget and an attempt cap; a trained RL policy plus per-skill BKT mastery tracking will
  pick each next question (the attempt engine lands in a later package).
- Complete database schema: instance settings, attempts (one-open-attempt enforced cross-DB via
  the `inprogressuniq` unique trick), the pseudonymisation-friendly experience log
  (`stackmastery_steps`, no userid column, full off-policy provenance: recommended vs served
  action, action source, propensity, policy/BKT/encoding/reward versions), the per-attempt pool
  snapshot (row per eligible question version per cell) and export-run bookkeeping.
- Instance form with pool coverage validation: empty (skill, difficulty) cells hard-block saving,
  thin cells (below 3 questions) warn; a live coverage table on edit; open/close times; a custom
  completion rule "Student must reach the mastery target".
- Landing page with a seven-state machine (start, resume, not open, closed, retry, cap used,
  teacher placeholder), accessible per-skill mastery bars with a target line, attempt history and
  the gradebook grade line; teachers see live pool decay warnings.
- Gradebook integration: one fixed 0 to 100 value item; grade modes "reached target" (default) and
  "mean final mastery"; highest attempt counts; grades derive on read so a grade-mode change
  regrades with zero migration.
- Six capabilities, seven events (attempt started/completed, step submitted, report viewed,
  policy promoted, plus the two conventional view events) with backup mappings, admin settings
  (exploration rate snapshotting, allowed question types, retention and experience-export
  controls) and a PHPUnit/Behat test suite with a tagged-pool data generator.
- Mirror CI: the moodle-plugin-ci matrix (PHP 8.1/8.3, Moodle 4.5, pgsql) with the five STACK
  plugin dependencies, plus an advisory CAS job running the suite against real Maxima 5.42.2
  (`continue-on-error` until proven stable for two weeks; flip it to blocking then).

### Notes
- Formative only by design; not for exams.
- Group mode is not supported in this version.
