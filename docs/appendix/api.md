# Raven Developer API Appendix

This page is the developer-surface index for Raven CMS. It links the stable implementation surfaces and the generated reference appendices that track code-level contracts.

## Core Runtime

- Runtime ownership and boundaries: `private/sys/*`
- Primary references:
  - [Architecture Appendix](./architecture.md)
  - [Filetree Appendix](./filetree.md)
  - [Core Runtime Appendix Overview](./core/overview.md)
  - [Config Appendix](./config.md)
  - [Database Appendix](./database.md)

## CLI Commands

- Command wrappers: `private/bin/rvn-*`
- Shared CLI implementation: `private/lib/Shell/raven_cli.php`
- Primary references:
  - [CLI Appendix Overview](./cli/overview.md)
  - [CLI Narrative Guide](../cli.md)

## Extensions

- Extension root: `private/ext/{slug}/`
- Manifest: `private/ext/{slug}/ext.json`
- Primary references:
  - [Extensions Appendix Overview](./extensions/overview.md)
  - [Extensions Narrative Guide](../extensions.md)
  - [Extension Authoring Contract](../../private/ext/AGENTS.md)

## Libraries

- Shared reusable core library: `private/lib/*`
- Primary references:
  - [Libraries Appendix Overview](./libraries/overview.md)

## Templates And Theming

- Public fallback templates: `private/tpl/public/*`
- Panel fallback templates: `private/tpl/panel/*`
- Public themes: `public/theme/{slug}/`
- Primary references:
  - [Templates Appendix (Public)](./templates/public.md)
  - [Templates Appendix (Panel)](./templates/panel.md)
  - [Bootstrap Appendix](./bootstrap.md)
  - [Theming Narrative Guide](../theming.md)
  - [Public Theme Contract](../../public/theme/AGENTS.md)
  - [Panel Theme Contract](../../panel/theme/AGENTS.md)

## Suggested Reading Order

1. [Core Runtime Appendix Overview](./core/overview.md)
2. [Config Appendix](./config.md)
3. [Database Appendix](./database.md)
4. [Extensions Appendix Overview](./extensions/overview.md)
5. [Libraries Appendix Overview](./libraries/overview.md)
6. [CLI Appendix Overview](./cli/overview.md)
7. [Templates Appendix (Public)](./templates/public.md)
8. [Bootstrap Appendix](./bootstrap.md)
