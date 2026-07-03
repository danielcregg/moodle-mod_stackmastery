# Shipped sample question bank

The 14 Moodle XML files in this directory are the STACK Mastery starter pack: oracle-validated
STACK questions exported by [stack-question-forge](https://github.com/danielcregg/stack-question-forge),
regenerated from its deterministic templates (every question was proven to grade its own model
answer at full marks across all deployed random seeds before export). Together they cover all
8 skills at every difficulty.

A teacher loads them from the activity page ("Load sample pool"): `classes/local/starter_pack.php`
imports each file into the activity's pool category and applies the skill and difficulty tags
derived from the file name (`<forgetype>_<n>.xml`; a type with a single file is tagged with all
three difficulties). Files whose question name already exists in the category are skipped, so the
import is safe to repeat.

The shipped copies differ from the forge's `data/validated_bank/` exports in exactly one line
each: the question NAME is made unique per file ("Differentiate (sample 2, medium)" and so on,
matching the difficulty the file is tagged with). The source bank reuses one name per type,
which would defeat the importer's same-name idempotency check. The question content (variables,
inputs, PRTs, tests, seeds) is byte-identical to the validated export.

Do not edit these files by hand; regenerate them with the forge (`scripts/bulk_generate.mjs`),
re-copy, then re-apply the unique names.
