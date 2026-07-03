# STACK Mastery (mod_stackmastery)

[![Moodle Plugin CI](https://github.com/danielcregg/moodle-mod_stackmastery/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/danielcregg/moodle-mod_stackmastery/actions/workflows/moodle-ci.yml)
![Moodle 4.5](https://img.shields.io/badge/Moodle-4.5%20LTS-orange)
![License GPL v3](https://img.shields.io/badge/license-GPLv3-blue)

A Moodle **activity module** for **adaptive formative mastery checks** on
[STACK](https://stack-assessment.org/) mathematics questions. A teacher selects the skills and the
mastery level students should reach; the student takes a formative test in which **every answer
updates a per-skill Bayesian Knowledge Tracing (BKT) estimate of the student**, and a **trained
reinforcement-learning policy picks the next question that leads most rapidly to the target**.
The attempt ends when every selected skill reaches the target, the question budget is spent, or
the student stops. Formative only: designed for practice, not for exams.

## How it works

- **Skills and tags.** Questions are pooled from one question bank category and tagged with a
  skill tag (`stackmastery_skill_<code>`; the 8 canonical codes are `differentiate, integrate,
  expand, factor, simplify, solve_linear, solve_quadratic, numerical`) and a difficulty tag
  (`stackmastery_diff_easy|medium|hard`). STACK Question Forge can tag generated questions
  automatically.
- **Selection.** The policy is a pure lookup table shipped as a plugin data file. No network, no
  service, no per-answer weight updates. A small admin-set exploration rate (logged per step)
  keeps future batch retraining able to discover better strategies.
- **Recording.** Every answered question is logged as a complete off-policy experience record
  with provenance (recommended vs served action, action source, propensity, policy/BKT/encoding/
  reward versions). Steps deliberately carry **no user id**; user linkage exists only via the
  attempt row.
- **Self-improvement is gated.** An optional weekly task exports pseudonymised experience JSONL
  inside moodledata. Retraining happens offline; a retrained policy becomes **pending** and an
  administrator explicitly promotes it (logged as an event). The policy is never updated live.

## Requirements

- Moodle 4.5 LTS (or later 4.5.x).
- [qtype_stack](https://moodle.org/plugins/qtype_stack) with a working Maxima/CAS, for real STACK
  pools. (The module's own CI and unit tests run against core question types via the
  `allowedqtypes` test seam, so Maxima is not needed to develop it.)

## Install

1. Copy this directory to `mod/stackmastery` in your Moodle root.
2. Visit *Site administration > Notifications* to install.
3. Review *Site administration > Plugins > Activity modules > STACK Mastery*.

## Instance settings (teacher)

- **Skills**: which of the 8 canonical skills the check covers (default all).
- **Target mastery**: Confident (95%, default) or Working (85%). Affects termination only.
- **Question pool category**: one category, no subcategories. Saving **hard-blocks** if any
  selected (skill, difficulty) cell has zero questions and **warns** below 3 per cell.
- **Maximum questions per attempt** (budget, default 40; capped at the eligible unique count at
  attempt start), **maximum attempts** (default unlimited).
- **Grade**: reached-target (100 or 0, default) or mean final mastery; the highest attempt counts.
- **Show mastery progress to students**: per-skill bars with a target line.
- **Open/close times** and the completion rule "Student must reach the mastery target".

## Admin settings

- **Exploration rate (epsilon)**: probability a question is drawn uniformly instead of by policy
  (0 to 0.2; snapshotted per activity at creation so running study arms never silently change).
- **Allowed question types**: pool eligibility filter, default `stack`.
- **Auto-abandon stale attempts after**: untouched in-progress attempts are closed and graded
  as-is by the cleanup task (default 7 days; 0 = never).
- **Experience log retention**: how long finished attempts' step logs are kept (default forever;
  destruction of research data is opt-in). Retention wins over export.
- **Export experience for retraining** (off by default) and **export file retention**.

## Retraining the policy

The full runbook lives in `phase3/POLICY_UPDATE.md` of the parent project
([stack-question-forge](https://github.com/danielcregg/stack-question-forge)); in short:

1. Enable *Export experience for retraining*; the weekly task writes
   `<moodledata>/stackmastery/export/*.jsonl` (or run the task now from *Scheduled tasks*).
2. **On the Moodle host** (export files and transitions never leave it), adapt and fit:
   `python3 phase3/adapt_moodle_experience.py <moodledata>/stackmastery/export --out transitions.json`
   then `python3 phase3/offline.py fit transitions.json --out policy_pending.json`. Only a policy
   that **passes the acceptance gate** (beats all baselines in simulation) is written.
3. Stage the artifact at `<moodledata>/stackmastery/pending/policy_pending.json` and promote it on
   the *STACK Mastery policy* admin page (validated, confirmable, logged, revertible).

## Design decisions (short list)

- **New activity module, not mod_quiz**: quiz fixes the question set at attempt start; this
  module owns a question usage and adds one slot at a time (mod_adaptivequiz precedent), graded
  under the `adaptivenopenalty` behaviour (STACK's validate-then-submit UX).
- **Mastery, not marks, is the currency**: marks are hidden student-facing; displayed progress and
  termination use model mastery, which rises through appropriately-difficult practice (lucky
  streaks on easy questions cannot fake mastery of hard variants).
- **Frozen pool snapshot per attempt**: mid-attempt question edits or re-tagging never change a
  running attempt; new questions affect new attempts only.
- **Derived-on-read grades**: no stored final grade; flipping the grade mode regrades cleanly.
- **One open attempt per user** is enforced both by a cross-DB unique index trick
  (`inprogressuniq`) and by the runtime's locks.
- **No groups** in this version; the report lists all participants.

## Development

- Tests: standard `moodle-plugin-ci` battery (phplint, phpcs/phpdoc `--max-warnings 0`, savepoints,
  mustache, grunt, PHPUnit, Behat) on PHP 8.1/8.3, Moodle 4.5, pgsql, with qtype_stack + its three
  behaviours + qbank_importasversion installed. An advisory `cas` job runs PHPUnit against real
  Maxima 5.42.2; CAS-dependent tests skip cleanly where Maxima is absent.
- Unit/Behat pools use `shortanswer` questions via the `allowedqtypes` seam, so no scenario needs
  a CAS. No scenario presses a STACK Check button in CI.
- Style traps that have burned CI cycles before: lang keys strictly alphabetical; inline comments
  start with a capital and end with punctuation; prefer single-line `if` conditions or extract
  named booleans; every file/class/method needs a docblock (`@package mod_stackmastery`).

## Privacy

Attempts, steps (mastery estimates and answer outcomes) and pool snapshots are personal data and
are declared to the privacy API together with the core question usages the module owns; deletion
removes question usages via the question engine. Experience exports are pseudonymised (no user
ids; per-run random keys that cannot be re-linked after the run) and stay in moodledata.

## License

GPL v3 or later. Copyright 2026 Daniel Cregg.
