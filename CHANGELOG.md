# Changelog

All notable changes to **mod_stackmastery** are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to
[Semantic Versioning](https://semver.org/).

## [0.2.0-beta] - 2026-07-03

### Added
- **One-click pool builder** on the activity page (teachers with manageinstance): "Build my pool"
  enumerates every (skill, difficulty) cell below a chosen per-cell target (1 to 20, default 3)
  and queues one STACK Question Forge generation job per thin cell through the forge's new public
  `queue_generation` API, mastery-tagged so the oracle-validated questions drop straight into the
  pool. Shown only when local_stackforge (with the API) is installed.
- **Shipped starter pack**: "Load sample pool" imports the module's bundled sample bank (14
  oracle-validated forge-exported STACK questions covering all 8 skills, `questionbank/`) into the
  instance's pool category and tags each question by skill and difficulty derived from its file
  name (single-file types are tagged for all three difficulties). Idempotent: questions whose name
  already exists in the category are skipped.
- **Optional nightly refill** (`pool_refill_task`, daily): when the new `poolrefill` admin setting
  is enabled (off by default) and the forge API exists, thin cells of every instance are topped up
  to the `poolrefilltarget` setting (clamped 1 to 20, default 3), capped at 30 queued jobs per run
  and attributed to the primary administrator.

## [0.1.1-beta] - 2026-07-03

- Attempt page layout (user feedback from the first live session): the mastery bars are now
  anchored at the top of the page in every state, directly under the outcome banner, so
  "your mastery moved from 10% to 42%" and the bar showing 42% read as one unit; the review
  of the just-answered question is a collapsible panel (open by default) - the worked
  solution stays available as scaffolding for the next question, one click folds it away.

## [0.1.0-beta] - 2026-07-03

First complete release: built, reviewed and verified in one continuous campaign.

- Full adaptive mastery loop on real STACK questions, live-verified end to end on Moodle 4.5
  (22-check Playwright E2E: CAS grading, mastery movement, resume, end-attempt-now, teacher
  report drilldown, activity duplication).
- Two pre-release Codex reviews folded: the build plan (export re-linkability closed with a
  discarded-salt seqkey; open-slot sealing on early finish) and the implementation
  (availability enforced on every mutating POST; crash-safe policy promote/rollback swap;
  export run lock; restore normalisation of usage-less in-progress attempts).
- Behaviour-agnostic graded-try detection: qtype_stack substitutes qbehaviour_adaptivemultipart
  for adaptive requests and never sets the _try variable; the engine now recognises graded
  tries for both behaviours (found by the live VM gate, invisible to shortanswer-based CI).
- Mirror CI green across the battery: 170+ PHPUnit tests (31 on real question usages), Behat
  incl. a @javascript full-loop flow, phpcs/phpdoc at zero warnings, plus an advisory CAS job
  running the suite against real qtype_stack + Maxima.

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
