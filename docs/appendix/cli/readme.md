# Raven CMS CLI

This is the landing page for Raven's command-line tools. Each command link opens its generated reference page, which combines the shipped command guidance with the command's current `--help` output.

## Command Inventory

- [rvn](./rvn.md)
- [rvn-cat](./cat.md)
- [rvn-chan](./chan.md)
- [rvn-conf](./conf.md)
- [rvn-cron](./cron.md)
- [rvn-ext](./ext.md)
- [rvn-group](./group.md)
- [rvn-redir](./redir.md)
- [rvn-repo](./repo.md)
- [rvn-sys](./sys.md)
- [rvn-tag](./tag.md)
- [rvn-theme](./theme.md)
- [rvn.sh](./sh.md)

## Global Flags

All CLI commands *(except `rvn` and `rvn.sh`)* support:

- `-v, --verbose`: verbose status output
- `-E, --verbose-errors`: include exception details and trace output
- `-i, --interactive`: prompt and answer interactive mode
- `--json`: machine-readable JSON output
- `--no-banner`: suppress placeholder help and interactive banners

Help and interactive modes include explicit placeholder lines for future ASCII-art and notice banners.

## Universal Dispatcher

Use `private/bin/rvn` as the single entrypoint for humans, macros, and headless automation:

```bash
php private/bin/rvn --help
php private/bin/rvn category list --json
php private/bin/rvn config set --key site.name --value "Raven Demo" --type string
```

Dispatcher commands are `category`, `channel`, `group`, `tag`, `redirect`, `config`, `theme`, `ext`, and `system`.

## Shell Completion

`private/bin/rvn.sh` provides basic command and flag completion for bash/zsh-style `complete` usage.

```bash
source /home/dev/app/private/bin/rvn.sh
```

## Data Path Contract

CLI commands use the same repository and configuration structures as panel flows:

- Category, tag, and redirect commands use repository CRUD methods.
- Channel commands use `ChannelRead` and `ChannelWrite`, including records under `private/dat/channel/`.
- Configuration updates persist through the shared configuration writer.
- Extension enablement uses the runtime state map at `private/dat/ext/.state.php`.

## PATH Usage

The portable invocation is:

```bash
php private/bin/rvn <command> <action> [options]
```

Individual wrappers under `private/bin/rvn-*` may also be symlinked into a trusted local bin directory or added to the shell `PATH`.

## QA Contract

Use the local validation workflow to verify CLI basics:

- system/info command output
- category, channel, tag, and redirect create-delete flows
- config set/get/restore behavior
- extension list/create/uninstall behavior
- destructive guards for protected stock groups, extensions, and active themes
- unsafe-input behavior, including path-traversal slugs and unsafe archive entry paths
- the dedicated web-security smoke runner for CSRF, auth, XSS-escape, and SQL-injection baselines
