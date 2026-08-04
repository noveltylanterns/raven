# Raven CMS Introduction

Raven is a self-hosted CMS focused on readable code, update-safe customization, and local ownership of your data. It supports traditional manual development and AI-assisted workflows, but runtime behavior does not depend on AI tooling.

## Philosophy

- Keep core runtime behavior in `private/sys/`.
- Keep reusable shared logic in `private/lib/`.
- Keep user customization in themes and extensions:
  - public themes in `public/theme/{slug}/`
  - extensions in `private/ext/{slug}/`
- Keep runtime data local to the install (`private/dat/` and `.tmp/`).

## Runtime Snapshot

- Public entrypoint: `public/index.php`
- Panel entrypoint: `panel/index.php`
- Installer entrypoint: `public/install.php`
- Core bootstrap: `private/Raven.php`
- Target PHP: `8.5`
- Composer dependencies install to `composer/`

## Quick Start

1. Install requirements:
   - PHP 8.5
   - web server (for example Nginx)
   - SQLite, MySQL, or PostgreSQL
2. Install dependencies from project root:
   - `composer install`
3. Run installer:
   - open `/install.php` in your browser
4. Complete install form:
   - set site/panel config
   - configure database
   - create initial admin user
5. Post-install hardening:
   - remove `public/install.php`
   - verify panel login and public homepage

## Customization Surfaces

- Themes: use [Theming Guide](./theming.md) and [public/theme/AGENTS.md](../public/theme/AGENTS.md).
- Extensions: use [Extensions Guide](./extensions.md) and [private/ext/AGENTS.md](../private/ext/AGENTS.md).
- CLI: use [CLI Guide](./cli.md) or generated [CLI Appendix](./appendix/cli/readme.md).

## Developer References

- System map: [Filetree](./appendix/filetree.md)
- Developer API index: [Appendix API](./appendix/readme.md)
- Generated reference appendices: `docs/appendix/`
- Release history: `release-notes.md`

## Project Status

Raven is an active prototype. Some narrative docs still require full manual verification against the live codebase. When uncertain, treat `AGENTS.md` files and generated appendices as higher-confidence references.
