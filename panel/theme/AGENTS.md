# Raven Panel Theme Agent Guide

Last updated: 2026-03-14

## Scope
- This file documents admin-panel theming under `panel/theme/`.
- This guide is for maintainers and automation agents working on panel CSS/SCSS behavior.

## CLI Command References
- Use `private/bin/rvn-sys info` when you need a quick panel/runtime environment snapshot while debugging theme issues.
- Use `private/bin/rvn-conf get --key panel.default_theme` and `private/bin/rvn-conf set --key panel.default_theme ...` when validating default panel-theme switching from CLI.
- Use `private/bin/rvn-theme list` when validating public-theme inventory from a panel-admin workflow.

## Agent Safe Mode (Mandatory)
- Default path for most tasks: edit `panel/theme/css/custom.css` only.
- Do not modify panel PHP/controller code for visual-only requests.
- Do not add remote assets (CDNs, external fonts, telemetry/tracking scripts).
- Prefer small, isolated CSS changes and verify in Corporate/Ice/Midnight modes.
- If requested change appears to require core markup edits, stop and flag it as a core change.

## Critical Rule: Keep Panel Theming Update-Safe
- Do not modify core PHP/controller/routing code for style-only panel changes.
- Do not modify `private/tpl/*` for visual-only adjustments unless layout asset wiring itself must change.
- Prefer panel theme assets (`panel/theme/css/*`, `panel/theme/scss/*`, `panel/theme/fonts/*`, `panel/theme/img/*`) so updates remain merge-safe.

## Panel Theme Asset Contract
- Base panel stylesheet source: `panel/theme/scss/style.scss`
- Base compiled stylesheet: `panel/theme/css/style.css`
- Optional update-safe override stylesheet: `panel/theme/css/custom.css`
- Panel icon stylesheet: `panel/theme/css/bootstrap-icons.min.css`
- Fonts/images live under `panel/theme/fonts/` and `panel/theme/img/`
- Panel theme assets must remain local-only; do not add external CDN/font/script dependencies for panel rendering.
- Do not add telemetry/tracking scripts to panel theme output.

## Runtime CSS Load Order
- Panel layout (`private/tpl/panel/wrapper.php`) loads CSS in this order:
- `theme/css/style.css`
- `theme/css/bootstrap-icons.min.css`
- `theme/css/custom.css` (only when file exists)
- Because `custom.css` loads last, it is the preferred update-safe override layer.

## Fast Override Path (Recommended For Most Changes)
- For routine customization, create/edit `panel/theme/css/custom.css`.
- Keep all site-specific panel tweaks in that one file.
- This avoids editing `style.scss` and survives upstream changes to the base panel theme more cleanly.

## Deterministic Panel Theme Build Recipe
1. Start with `panel/theme/css/custom.css`.
2. Add minimal scoped selectors and avoid editing `style.css`.
3. If you changed `panel/theme/scss/style.scss`, compile with:
   - `sass panel/theme/scss/style.scss panel/theme/css/style.css`
4. Never hand-edit `panel/theme/css/style.css` to mirror SCSS changes; compile output instead.
5. Verify state variants: Corporate/Ice/Midnight body classes.
6. Verify common UI surfaces: cards, forms, table headers, action buttons, sidebar/mobile nav.
7. Only if Sass-level bootstrap variable changes are required, use `custom.scss -> custom.css`.

## Canonical Minimal `custom.css`
```css
/* RAVEN CMS panel overrides */
body#rvnp .card {
  border-radius: 0.5rem;
}
```

## Hard-Fail Validation Checklist (Before Hand-Off)
- No visual-only task required edits outside `panel/theme/` unless explicitly approved as core work.
- `custom.css` is loaded after `style.css` and `bootstrap-icons.min.css` (confirm runtime order unchanged).
- Changes are readable/usable on `theme-default`, `theme-light`, and `theme-dark`.
  - Runtime mapping: `corp -> theme-default`, `ice -> theme-light`, `midnight -> theme-dark`.
- Sortable table headers remain clear (`.raven-routing-sort-label`, `.raven-routing-sort-caret`, `.is-active-sort`).
- No external network dependency added for panel rendering.

## Full Sass Rebuild Path (When You Need Bootstrap-Level Changes)
- If you need deeper Bootstrap-stack changes, create a custom Sass entrypoint and compile it to `custom.css`.
- Recommended flow:
- Copy base entrypoint: `cp panel/theme/scss/style.scss panel/theme/scss/custom.scss`
- Edit `panel/theme/scss/custom.scss` with your changes.
- Compile to override artifact: `sass panel/theme/scss/custom.scss panel/theme/css/custom.css --style=expanded`
- Watch mode example: `sass --watch panel/theme/scss/custom.scss:panel/theme/css/custom.css`
- Preferred compiler: Dart Sass standalone CLI.
- NPM-based Sass tooling is allowed but less preferred due extra dependency/version overhead.

## Bootstrap Stack Pipeline (Panel)
- Bootstrap source is local Composer dependency: `composer/twbs/bootstrap/scss/bootstrap`
- Base panel pipeline is: `bootstrap scss` -> `panel/theme/scss/style.scss` -> `panel/theme/css/style.css`
- Custom pipeline variant is: `bootstrap scss` -> `panel/theme/scss/custom.scss` -> `panel/theme/css/custom.css`

## Theme Switcher Behavior (Corporate/Ice/Midnight)
- Panel `<body>` includes classes like:
- `rvn-panel`
- `theme-default` or `theme-light` or `theme-dark`
- These are generated in `private/tpl/panel/wrapper.php` from controller-provided `userTheme`.
- User preference theme values:
- `default`
- `corp`
- `ice`
- `midnight`
- `default` resolves to global config `panel.default_theme` (`corp`, `ice`, or `midnight`).
- Login page also uses this default theme resolution (not a separate theme path).
- Sass mode source selectors are in `panel/theme/scss/style.scss`:
- `body#rvnp.theme-default`
- `body#rvnp.theme-light`
- `body#rvnp.theme-dark`
- CSS class mapping keeps existing selectors stable:
  - `corp` uses `theme-default`
  - `ice` uses `theme-light`
  - `midnight` uses `theme-dark`

## Panel View/Theming Boundary
- Unlike public themes, panel theming does not provide customizable PHP view directories under `panel/theme/`.
- Panel HTML templates are under `private/tpl/` and are part of core application behavior.
- Panel visual customization should be done via CSS/SCSS assets in `panel/theme/`.

## Current Panel Table UI Hooks
- Shared sortable-header UI in panel tables uses:
- `.raven-routing-sort-label`
- `.raven-routing-sort-caret`
- active header class: `.is-active-sort`
- When adjusting table/button styling, preserve readability of sortable headers and active sort state across themes.
- Current list-table convention: `Actions` columns are center-aligned.

## Update-Safe Workflow
- Keep local branding and tone tweaks in `panel/theme/css/custom.css` whenever possible.
- Use `custom.scss -> custom.css` only when you need Sass-level control over Bootstrap/component generation.
- Avoid modifying `panel/theme/scss/style.scss` for deployment-specific branding unless you intend to maintain a fork.
