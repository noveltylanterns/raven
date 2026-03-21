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
- Core fallback wrapper is `private/tpl/wrapper.php` and loads `/theme/fallback.css` (compiled from `public/theme/fallback.scss`).

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
  - `{item:url}`
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
  - `meta`
- page/home/channel routes:
  - `page`
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
  - `profile_denied` (placeholder mode)
- group routes:
  - `group`
  - `members`
  - `group_denied` (placeholder mode)
- wrapper routes:
  - `content`

Common shared keys:

- `site:name`
- `site:url`
- `site:theme`
- `site:theme_css`
- `site:theme_url`
- `site:domain`
- `site:scheme`
- `site:panel_path`
- `site:current_url`
- `meta:title`
- `meta:description`
- `meta:document_title`

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
- If slug override is blank, Theme Manager derives slug from `theme.json` `slug` (fallback: archive filename).
- If that derived slug already exists, Theme Manager auto-renames with `-copy`.
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

## 9) Tag Appendix

This appendix documents the stable public-theme tag contract. Brace tags can read any scalar child present in the runtime payload, but the keys below are the intentional, documented API for themes.

### 9.1 Directive Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `{/each}` | closes `{each ...}` loop scope | no output |
| `{/if}` | closes `{if ...}` conditional | no output |
| `{each path}` | iterates an array tag path such as `{each pages}` or `{each pagination:links}` | no direct output; pushes `item` loop scope |
| `{if not path}` | falsy check for a tag path such as `{if not profile_denied}` | no direct output; conditionally renders enclosed markup |
| `{if path}` | truthy check for a tag path such as `{if page:show_title}` | no direct output; conditionally renders enclosed markup |
| `{path}` | escaped scalar lookup such as `{site:name}` | HTML-escaped string |
| `{raw:path}` | raw scalar lookup such as `{raw:page:content}` | unescaped string |

### 9.2 Stable Data Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `category:description` | category route payload description when present | category description text |
| `category:name` | category route payload display name | category name |
| `category:slug` | category route payload slug | category slug |
| `content` | wrapper-layout rendered inner template body | rendered HTML string; use with `{raw:content}` |
| `group:member_count` | group route payload normalized member count | integer-like member count |
| `group:name` | group route payload display name | group name |
| `group:slug` | group route payload slug | group slug |
| `group_denied` | group placeholder payload flag for private-mode denial | boolean-like truthy/falsey flag |
| `item:alt_text` | current loop item image alt text, when iterating gallery-like rows | image alt text |
| `item:avatar_thumb_url` | current loop item avatar thumbnail URL, when iterating `members` | avatar thumbnail URL |
| `item:avatar_url` | current loop item avatar URL, when iterating `members` | avatar original URL |
| `item:caption` | current loop item caption, when iterating gallery-like rows | caption text |
| `item:channel_slug` | current loop item channel slug, when iterating `pages` | channel slug |
| `item:class` | current loop item CSS class string, when iterating `page:extended_blocks` | CSS class list |
| `item:css_id` | current loop item CSS id token, when iterating `page:extended_blocks` | CSS id |
| `item:display_name_resolved` | current loop item display name, when iterating `members` | resolved display name |
| `item:flags:featured` | nested loop-item flag example supported by path resolver | scalar nested flag value |
| `item:full_url` | current loop item full-size image URL, when iterating gallery-like rows | absolute or root-relative image URL |
| `item:has_avatar` | current loop item avatar-presence flag, when iterating `members` | boolean-like truthy/falsey flag |
| `item:href` | current loop item link href, when iterating pagination or contact rows | URL/path string |
| `item:html` | current loop item rendered HTML fragment, when iterating `page:extended_blocks` | trusted HTML string; use with `{raw:item:html}` |
| `item:image_url` | current loop item image URL, when iterating gallery-like rows | absolute or root-relative image URL |
| `item:is_current` | current loop item current-page flag, when iterating `pagination:links` | boolean-like truthy/falsey flag |
| `item:is_external` | current loop item external-link flag, when iterating `profile:contact_profiles` | boolean-like truthy/falsey flag |
| `item:label` | current loop item label, when iterating pagination or contact rows | label text |
| `item:title` | current loop item title, when iterating `pages` | page title |
| `item:url` | current loop item public URL, when iterating `pages` | root-relative page URL |
| `item:username` | current loop item username, when iterating `members` | username |
| `item:value` | current loop item value, when iterating `profile:contact_profiles` | contact value text |
| `members` | group route member list | array for `{each members}` |
| `meta:description` | public template metadata description | meta description text |
| `meta:document_title` | full document title assembled for `<title>` and social tags | complete document title |
| `meta:title` | logical route title before site suffix | title text |
| `page:channel_slug` | page payload channel slug when page belongs to a channel | channel slug |
| `page:content` | page payload main rendered body source | trusted HTML string; use with `{raw:page:content}` |
| `page:description` | page payload description when present | page description text |
| `page:extended_blocks` | page payload rendered extended block rows | array for `{each page:extended_blocks}` |
| `page:show_title` | page payload normalized display-title flag | boolean-like truthy/falsey flag |
| `page:slug` | page payload slug | page slug |
| `page:title` | page payload title | page title |
| `pages` | category/tag route page list | array for `{each pages}` |
| `pagination:base_path` | pagination payload base path | root-relative base path |
| `pagination:current` | pagination payload current page number | integer-like page number |
| `pagination:links` | pagination link rows | array for `{each pagination:links}` |
| `pagination:per_page` | pagination payload items per page | integer-like page size |
| `pagination:total_items` | pagination payload total item count | integer-like total count |
| `pagination:total_pages` | pagination payload total page count | integer-like total pages |
| `profile:avatar_thumb_url` | profile payload avatar thumbnail URL | avatar thumbnail URL |
| `profile:avatar_url` | profile payload avatar URL | avatar original URL |
| `profile:contact_profiles` | profile payload normalized contact rows | array for `{each profile:contact_profiles}` |
| `profile:display_name_resolved` | profile payload resolved display name | display name or username fallback |
| `profile:has_avatar` | profile payload avatar-presence flag | boolean-like truthy/falsey flag |
| `profile:username` | profile payload username | username |
| `profile_denied` | profile placeholder payload flag for private-mode denial | boolean-like truthy/falsey flag |
| `site:apple_touch_icon` | configured Apple touch icon URL | absolute URL |
| `site:current_url` | current request URL | absolute URL |
| `site:domain` | configured site host/domain value | host or host/path string |
| `site:name` | configured site name | site name |
| `site:og_image` | resolved OpenGraph image URL | absolute URL |
| `site:og_locale` | resolved OpenGraph locale | locale string |
| `site:og_type` | resolved OpenGraph type | type string |
| `site:panel_path` | configured panel route prefix | panel path slug |
| `site:robots` | configured robots policy | robots meta content |
| `site:scheme` | configured public scheme | `http` or `https` |
| `site:theme` | active public theme slug | theme slug |
| `site:theme_css` | resolved theme slug that provides active CSS/assets | theme slug |
| `site:theme_url` | resolved public theme asset base URL | absolute `/theme/{slug}` URL without trailing slash |
| `site:twitter_card` | resolved Twitter card type | card type string |
| `site:twitter_creator` | resolved Twitter creator handle | handle string |
| `site:twitter_image` | resolved Twitter image URL | absolute URL |
| `site:twitter_site` | resolved Twitter site handle/name | handle or text string |
| `site:url` | configured public site base URL | absolute site URL without trailing slash |
| `tag:description` | tag route payload description when present | tag description text |
| `tag:name` | tag route payload display name | tag name |
| `tag:slug` | tag route payload slug | tag slug |

Notes:

- `item:*` depends on the active `{each ...}` loop target.
- Additional scalar children on `page`, `category`, `tag`, `profile`, `group`, `members`, and `pages` may also be readable when Raven includes them in the payload, but the table above is the stable contract themes should rely on.
