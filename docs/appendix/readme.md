# Raven Developer Appendix

This page is the developer-surface index for Raven CMS. It links the stable implementation surfaces and the generated reference appendices that track code-level contracts.

## Core Runtime

- Runtime ownership and boundaries: `private/sys/*`
- Primary references:
  - [Architecture Appendix](./architecture.md)
  - [Filetree Appendix](./filetree.md)
  - [Router Developer Reference](./router.md)
  - [Core Router Symbol Inventory](./core/router.md)
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
2. [Router Developer Reference](./router.md)
3. [Config Appendix](./config.md)
4. [Database Appendix](./database.md)
5. [Extensions Appendix Overview](./extensions/overview.md)
6. [Libraries Appendix Overview](./libraries/overview.md)
7. [CLI Appendix Overview](./cli/overview.md)
8. [Templates Appendix (Public)](./templates/public.md)
9. [Bootstrap Appendix](./bootstrap.md)
