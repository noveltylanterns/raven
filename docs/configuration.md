# Raven Configuration Guide

This guide covers how Raven configuration is edited, validated, and persisted.

## 1) Configuration Sources

- Active runtime config:
  - `private/dat/config.php`
- Distribution/default template:
  - `private/dat/config.php.dist`
- Generated key reference:
  - `docs/appendix/config.md`

Raven treats `private/dat/config.php` as the canonical runtime source of truth.

## 2) Panel Configuration Editor

Panel route surface:

- `GET /configuration`
- `POST /configuration/save`

Primary implementation files:

- `private/sys/Controller/Panel/ConfigController.php`
- `private/tpl/panel/configuration.php`
- `private/sys/Router/Panel/ConfigRouter.php`

Access requirements:

- authenticated panel user
- permission to manage system configuration

## 3) Editor Behavior

The panel editor renders normalized field descriptors from config and groups them by domain tabs (for example basic/content/database/debug/media/meta/security/users). The Basic tab presents Site, Routing, Panel, and Timekeeping sections; Domain, Protocol, and Site Routing Mode are under Routing, while Timezone and Scheduler are grouped at the end under Timekeeping.

Notable behavior:

- Active tab is preserved across saves.
- Driver-specific database fields are shown by selected `database.driver`.
- Captcha provider fields are shown by selected `captcha.provider`.
- The Content tab's `content.selector` setting accepts `slug` or `id` and controls the default content URL selector.
- The Basic tab's `site.routing` setting accepts `no_trailing_slash` or `trailing_slash` and applies the selected canonical slash policy to public site links and routes; the non-canonical form receives a built-in 301 redirect.
- Save operations replace the full config snapshot after normalization/validation.

## 4) Save Workflow

`ConfigController::configurationSave()` performs:

1. Login + permission checks.
2. CSRF validation.
3. Form payload normalization by key/type policy.
4. Required-key and value-shape validation.
5. Compatibility/default normalization passes.
6. Full write to `private/dat/config.php`.

## 5) Configuration Ownership Boundaries

- System runtime behavior:
  - `site.*`, `panel.*`, `database.*`, `debug.*`, `logging.*`, `session.*`
- Public content and routing controls:
  - `content.*`, `feed.*`, `category.*`, `tag.*`
- User/profile/auth behavior:
  - `user.*`, `group.*`, `captcha.*`
- Media constraints:
  - `media.*`
- Metadata/social card defaults:
  - `meta.*`

Theme enablement (`site.theme`) is stored in config but should be managed through Theme Manager or CLI theme commands.

## 6) CLI Configuration Workflows

Use `private/bin/rvn-conf` for scripted changes:

- `list [--prefix <dot.path>]`
- `get --key <dot.path>`
- `set --key <dot.path> --value <value> [--type ...]`
- `sync-defaults`

`rvn-conf` is appropriate for automation and deploy scripts; the panel editor is appropriate for interactive operations.

## 7) Security Expectations

- Panel config save is CSRF-protected.
- Config editing is permission-gated.
- Normalization rejects invalid enum/shape values for constrained fields.
- Sensitive values (for example database credentials) should remain server-local and never be exposed in public docs/output.

## 8) Related References

- `docs/appendix/config.md`
- `docs/appendix/router.md`
- `docs/appendix/core/controller.md`
- `docs/appendix/core/runtime.md`
