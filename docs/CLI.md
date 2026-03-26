# Raven CMS CLI

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This file documents Raven's redistributable CLI tools under `private/bin/`.

Maintenance note: keep this file updated whenever any tool is added, removed, or behavior-changed under `private/bin/`.

## 1) Command Inventory

Shipped CLI entrypoints:

- `private/bin/rvn` (universal dispatcher)
- `private/bin/rvn-cat`
- `private/bin/rvn-chan`
- `private/bin/rvn-group`
- `private/bin/rvn-tag`
- `private/bin/rvn-redir`
- `private/bin/rvn-conf`
- `private/bin/rvn-theme`
- `private/bin/rvn-ext`
- `private/bin/rvn-sys`
- `private/bin/rvn.sh` (shell completion helper)

## 2) Global Flags

All CLI commands support:

- `-v, --verbose`: verbose status output
- `-E, --verbose-errors`: include exception details/trace output
- `-i, --interactive`: prompt/answer interactive mode
- `--json`: machine-readable JSON output
- `--no-banner`: suppress placeholder help/interactive banners

Banner contract:

- help/interactive modes include explicit placeholder lines for future ASCII-art and notice banners.

## 3) Universal Dispatcher

Use `private/bin/rvn` as the single entrypoint for humans, macros, and headless automation:

```bash
php private/bin/rvn --help
php private/bin/rvn category list --json
php private/bin/rvn config set --key site.name --value "Raven Demo" --type string
```

Dispatcher commands:

- `category`
- `channel`
- `group`
- `tag`
- `redirect`
- `config`
- `theme`
- `ext`
- `system`

## 4) Focused Commands

### `rvn-cat`

CRUD for categories (text-only):

- `list`
- `show --id <id>` or `show --slug <slug>`
- `create --name <name> --slug <slug> [--description <text>]`
- `update --id <id>|--slug <slug> --name <name> --slug <slug> [--description <text>]`
- `delete --id <id>` or `delete --slug <slug>`

### `rvn-chan`

CRUD for channels (flat file metadata + linked ids):

- `list`
- `show --id <id>` or `show --slug <slug>`
- `create --name <name> --slug <slug> [--description <text>] [--editor <inherit|tinymce|plaintext|autobr|markdown>] [--route-mode <inherit|slug|date_slug|month_slug|id|date_id|month_id>] [--separator <inherit|-|_>]`
- `update --id <id>|--slug <slug> ...` (same payload fields)
- `delete --id <id>` or `delete --slug <slug>`

### `rvn-group`

CRUD for groups (permissions + route toggle):

- `list`
- `show --id <id>` or `show --slug <slug>`
- `create --name <name> [--slug <slug>] [--route-enabled <1|0>] [--permission-mask <int>] [--permissions <csv>]`
- `update --id <id>|--slug <slug> [--name <name>] [--slug <slug>] [--route-enabled <1|0>] [--permission-mask <int>] [--permissions <csv>]`
- `delete --id <id>` or `delete --slug <slug>`

Permission input notes:

- `--permission-mask` and `--permissions` are mutually exclusive.
- `--permissions` accepts CSV names:
  - `view_public`
  - `view_private`
  - `view_disabled`
  - `panel_login`
  - `manage_content`
  - `manage_taxonomy`
  - `manage_users`
  - `manage_groups`
  - `manage_configuration`
- Alias names are accepted (for example `public`, `private`, `disabled`, `panel`, `content`, `taxonomy`, `users`, `groups`, `configuration`).

### `rvn-tag`

CRUD for tags (text-only):

- `list`
- `show --id <id>` or `show --slug <slug>`
- `create --name <name> --slug <slug> [--description <text>]`
- `update --id <id>|--slug <slug> --name <name> --slug <slug> [--description <text>]`
- `delete --id <id>` or `delete --slug <slug>`

### `rvn-redir`

CRUD for redirects (text-only):

- `list`
- `show --id <id>` or `show --slug <slug> [--channel <channel_slug>]`
- `create --title <title> --slug <slug> --target <url> [--description <text>] [--channel <channel_slug>] [--active <1|0>]`
- `update --id <id>|--slug <slug> [--channel <channel_slug>] ...` (same payload fields)
- `delete --id <id>` or `delete --slug <slug> [--channel <channel_slug>]`

### `rvn-conf`

Config key management:

- `list [--prefix <dot.path.prefix>]`
- `get --key <dot.path>`
- `set --key <dot.path> --value <value> [--type <auto|string|int|float|bool|null|json>]`
- `sync-defaults` (adds missing keys from `private/dat/config.php.dist` without overwriting existing keys)

`rvn-conf set` does not allow `site.default_theme`; use `rvn-theme enable --slug <slug>` instead.

### `rvn-ext`

Extension management:

- `list`
- `enable --slug <slug>`
- `disable --slug <slug>`
- `create --slug <slug> --name <name> [--type <helper|content|plugin|module|system>] [--version <semver>] [--description <text>] [--author <name>] [--homepage <url>] [--author-url <url>] [--with-shortcodes <1|0>] [--with-fields <1|0>] [--with-public-routes <1|0>] [--with-agents <1|0>] [--with-composer <1|0>]`
- `import --archive <zip_path> [--slug <slug>]`
- `uninstall --slug <slug> [--force]`

`create` writes extension scaffolds under `private/ext/{slug}/` using current route/view conventions (`ext.php`, `ext.json`, `lib/*.php`, `tpl/*.php`), and sets `ext.json.slug` to the directory slug.
`import` uses `ext.json.slug` when `--slug` is omitted.
Legacy compatibility note: `rvn-ext delete --slug <slug>` is accepted as an alias for `uninstall`.
### `rvn-theme`

Public-theme management:

- `list`
- `enable --slug <slug>`
- `create --slug <slug> --name <name> [--clone <source_slug>] [--parent <slug>] [--set-default <1|0>]`
- `uninstall --slug <slug>`

Uninstall rules:

- active themes cannot be uninstalled (enable a different theme first)
- stock themes (for example `raven`) cannot be uninstalled
- `--force` is not supported for `rvn-theme uninstall`

Legacy compatibility note: `rvn-theme delete --slug <slug>` is accepted as an alias for `uninstall`.

`create` writes theme scaffolds under `public/theme/{slug}/` with:

- `theme.json`
- `css/style.css`
- `tpl/wrapper.php`
- `tpl/home.php`

When `--clone` is provided, all files from `public/theme/{source_slug}/` are copied into the new theme directory and `theme.json` is rewritten with the new theme metadata.

### `rvn-sys`

System/environment/version inspection:

- `info` (default)
- `version`
- `env`
- `extensions`

## 5) Shell Completion Helper

`private/bin/rvn.sh` provides basic command/flag completion for bash/zsh-style `complete` usage.

Example load in shell profile:

```bash
source /home/dev/app/private/bin/rvn.sh
```

## 6) Symlink / PATH Usage

Recommended:

- invoke directly with `php private/bin/rvn ...`

Optional:

- symlink selected scripts into a trusted local bin dir
- or add `private/bin/` to your shell `PATH`

## 7) Data Path Contract

CLI commands are wired to the same repository/config structures used by panel flows:

- category/tag/redirect use repository CRUD methods
- channel uses filesystem-backed `ChannelRepository`
- config updates persist through `Config::save()`
- extension enablement uses the runtime state map at `private/dat/ext/.state.php`

## 8) QA Contract

Use your local validation workflow to verify CLI basics:

- system/info command output
- category/channel/tag/redirect create-delete flow
- config set/get/restore behavior
- extension list/create/uninstall behavior
- destructive-guard behavior (stock group delete blocked, stock extensions such as `contact`, `database`, `phpinfo`, `signups`, and `smallweb` blocked from uninstall, active-theme uninstall blocked)
- unsafe-input behavior (path-traversal slugs rejected, unsafe ZIP entry paths rejected on import)
- dedicated web-security smoke runner for CSRF/auth/XSS-escape/SQLi-baseline checks
