# Raven CMS Theming

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This document covers public-theme rendering behavior, brace-tag syntax, and theme CLI workflows.

Authoritative runtime contract: [public/theme/AGENTS.md](../public/theme/AGENTS.md).

## 1) Theme Template Roots

Public rendering resolves templates from:

1. `public/theme/{active_theme}/tpl/*`
2. parent themes in inheritance order (`tpl/*`)
3. core fallback `private/tpl/*`

Theme wrappers are expected at:

- `public/theme/{slug}/tpl/wrapper.php`

## 2) Complete Brace-Tag Directive Inventory

All supported tag directives are listed below.

- Escaped value output:
  - `{site:name}`
  - `{page:title}`
  - `{pagination:total_pages}`
- Raw value output (unescaped):
  - `{raw:page:content}`
- Truthy conditional open:
  - `{if page:title}`
- Falsy conditional open:
  - `{if not page:title}`
- Conditional close:
  - `{/if}`
- Loop open:
  - `{each pages}`
- Loop close:
  - `{/each}`
- Loop row scope:
  - `{item:title}`
  - `{item:public_path}`
  - `{item:channel_slug}`

Path format:

- Colon-delimited lookup: `{root:child:leaf}`
- Value tags require at least two path segments (for example `site:name`).
- Missing paths resolve to empty output.

Templates still support regular PHP. Brace tags are additive.

## 3) Tag Semantics

- Escaped value tags use HTML escaping (`Raven\Core\Support\e()`).
- `raw:` tags bypass escaping and should only be used with trusted HTML.
- Truthy checks:
  - `bool`: native boolean value
  - numbers: non-zero is true
  - strings: non-empty and not `'0'` is true
  - arrays: non-empty is true
- Loops iterate arrays only. Non-array loop targets are treated as empty.
- Loop scope is stack-based; nested loops can shadow `item` safely.

## 4) Route Data Roots For Tags

Depending on route/template, these top-level roots are available:

- shared roots:
  - `site`
  - `view_meta`
- page/home/channel routes:
  - `page`
  - `galleryEnabled`
  - `galleryImages`
- category routes:
  - `category`
  - `pages`
  - `pagination`
- tag routes:
  - `tag`
  - `pages`
  - `pagination`
- profile routes:
  - `profile`
  - `profile_show_denied` (placeholder mode)
- group routes:
  - `group`
  - `members`
  - `group_show_denied` (placeholder mode)
- wrapper routes:
  - `content`

Common shared keys:

- `site:name`
- `site:domain`
- `site:panel_path`
- `site:current_url`
- `site:public_theme`
- `site:public_theme_css`
- `view_meta:title`
- `view_meta:description`
- `view_meta:document_title`

## 5) Theme CLI Workflows

Use `private/bin/rvn-theme` for theme lifecycle commands:

- `list`
- `enable --slug <slug>`
- `create --slug <slug> --name <name> [--clone <source_slug>] [--parent <slug>] [--set-default <1|0>]`
- `delete --slug <slug>`

Delete constraints:

- active themes cannot be deleted (activate another theme first)
- stock themes such as `raven` cannot be deleted
- `--force` is rejected for theme deletion

Related helpers:

- `private/bin/rvn-conf get --key site.default_theme`
- `private/bin/rvn-theme enable --slug <slug>`
- `private/bin/rvn-sys info`

Theme selection policy:

- `site.default_theme` remains stored in config but is managed through Theme Manager / `rvn-theme`.
- `rvn-conf set --key site.default_theme ...` is blocked by design.

Panel counterpart:

- `GET /{panel.path}/themes` provides Theme Manager UI for list/enable/scaffold.
- `POST /{panel.path}/themes/upload` uploads one `.zip` archive into `public/theme/{slug}/`.
- `POST /{panel.path}/themes/delete` deletes one non-active, non-stock theme.

Theme upload notes:

- Upload archive must include `theme.json` at archive root.
- Uploaded themes are not auto-enabled; activate them from Theme Manager or `rvn-theme enable`.

## 6) Theme Scaffold Contract (rvn-theme create)

Generated files:

- `public/theme/{slug}/theme.json`
- `public/theme/{slug}/css/style.css`
- `public/theme/{slug}/tpl/wrapper.php`
- `public/theme/{slug}/tpl/home.php`
- optional (panel create-form checkboxes, default off):
  - `public/theme/{slug}/AGENTS.md`
  - `public/theme/{slug}/composer.json`
  - `public/theme/{slug}/package.json`

Clone mode:

- `rvn-theme create --clone <source_slug> ...` copies all files from the source theme directory into the new theme directory, then rewrites `theme.json` for the new theme.

## 7) Rendering Performance

Brace tags are compiled to cached PHP files under:

- `.tmp/template_tag_cache/`

Compilation model:

- compile once per source template mtime change
- include cached PHP directly after warm-up
- avoid repeat regex parsing on hot requests

## 8) Security Notes

- Escaped tags are safe by default for untrusted text values.
- `raw:` tags can output unsanitized HTML; use only for trusted/controlled content.
- Brace tags do not execute expressions or arbitrary PHP.
