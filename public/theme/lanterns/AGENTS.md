# Novelty Lanterns Theme Agent Guide

Last updated: 2026-03-30

## Scope
- This file applies only to `public/theme/lanterns/`.
- Follow project-wide theme contracts in `public/theme/AGENTS.md`.

## Required Files
- `theme.json`
- `tpl/wrapper.php`
- `css/style.css`

## Safety Rules
- Keep customizations inside this theme directory.
- Do not edit core templates under `private/tpl/` for theme-only changes.
- Use escaped brace tags by default; reserve `{raw:...}` for trusted HTML only.
