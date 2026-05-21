# Raven Doc Generator Prep Plan

Last updated: 2026-05-20

## Objective
Define and stage the implementation plan for the Raven documentation generator so `php build/docs/rvn-docs.php` can produce reference docs deterministically at release time.

## Current Constraints
- Generator implementation must be pure PHP (Reflection + lightweight PHPDoc parsing), with no new Composer dependencies.
- Generated reference docs are generator-owned and should not be hand-edited once the tool is live.
- `docs/appendix/` does not currently exist in this workspace and must be created by the generator when needed.

## Initial Decisions
- Canonical CLI entrypoint target: `php build/docs/rvn-docs.php`.
- All generator components live under `build/docs/` because this is a build-only tool.
- Generator output should be idempotent: no file writes when content has not changed.

## Source Inventory (Phase 0)
- CLI docs input: `private/bin/rvn-*` command `--help` output.
- Config docs input: `private/dat/config.php.dist`, plus panel config controller metadata in `private/sys/Controller/Panel/`.
- Core runtime docs input: classes/functions under `private/sys/`.
- Library docs input: classes/functions under `private/lib/`.
- Extension docs input: each `private/ext/*/ext.json` and extension `lib/` classes.
- Template docs input: fallback templates under `private/tpl/`, `panel/theme/`, and `public/theme/` base template roots.
- Database docs input: schema builders in `private/lib/` + route/controller/form traces in `private/sys/`.
- Bootstrap docs input: Bootstrap injection points in view/template layers plus Sass compile paths in `panel/theme/scss/`.

## Output Contract Draft
- Every generated file begins with a deterministic H1 and a generator notice line.
- Group entries by domain key (command group, class group, extension slug, template scope).
- Method/function sections include first doc line, parameter table, return semantics, and throws list when present.
- Emit alphabetical ordering within each group to reduce diff churn.
- Use stable heading IDs and consistent relative links across appendix pages.

## CLI Contract Draft
- `php build/docs/rvn-docs.php --all` generates all appendix targets.
- `php build/docs/rvn-docs.php --target=<name>` generates one target family.
- `php build/docs/rvn-docs.php --check` verifies outputs are up-to-date without writing files.
- `php build/docs/rvn-docs.php --list-targets` prints supported generator targets.

## Next Implementation Steps
- Build a minimal command shell for `build/docs/rvn-docs.php` with argument parsing and target registry. (Completed 2026-05-20)
- Implement writer utilities (stable sort, normalized whitespace, no-op write when unchanged). (Completed 2026-05-20)
- Implement first target (`cli`) end-to-end, then use it as the template for other targets. (Completed 2026-05-20)
- Add maintenance checklist hook once `--check` is stable. (Completed 2026-05-20)
