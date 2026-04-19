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
- Stock/core wrappers advertise configured root feeds in `<head>` using `{if site:feed_rss_url}<link rel="alternate" ...>{/if}` and `{if site:feed_atom_url}<link rel="alternate" ...>{/if}`.

## 2) Complete Brace-Tag Directive Inventory

All supported tag directives are listed below.

- Escaped value output:
  - `{site:name}`
  - `{page:title}`
  - `{pagination:total_pages}`
- Raw value output (unescaped):
  - `{raw:item:html}`
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

- Escaped value tags use HTML escaping (`Raven\Support\e()`).
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
  - `theme`
  - `panel`
  - `head`
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
- `site:domain`
- `site:protocol`
- `site:current_url`
- `site:feed_rss_url`
- `site:feed_atom_url`
- `theme:slug`
- `theme:active`
- `theme:url`
- `panel:slug`
- `panel:url`
- `meta:title`
- `meta:desc`
- `meta:apple_touch_icon`
- `meta:robots`
- `meta:image`
- `meta:url`
- `meta:og_locale`
- `meta:og_type`
- `meta:x_card`
- `meta:x_creator`
- `meta:x_site`

## 5) Theme CLI Workflows

Use `private/bin/rvn-theme` for theme lifecycle commands:

- `list`
- `enable --slug <slug>`
- `create --slug <slug> --name <name> [--clone <source_slug>] [--parent <slug>] [--set-default <1|0>]`
- `uninstall --slug <slug>`

Uninstall constraints:

- active themes cannot be uninstalled (activate another theme first)
- stock themes such as `raven` cannot be uninstalled
- `--force` is rejected for theme uninstall

Related helpers:

- `private/bin/rvn-conf get --key site.theme`
- `private/bin/rvn-theme enable --slug <slug>`
- `private/bin/rvn-sys info`

Theme selection policy:

- `site.theme` remains stored in config but is managed through Theme Manager / `rvn-theme`.
- `rvn-conf set --key site.theme ...` is blocked by design.

Panel counterpart:

- `GET /{panel.path}/themes` provides Theme Manager UI for list/enable/scaffold.
- `POST /{panel.path}/themes/upload` uploads one supported archive package into `public/theme/{slug}/`.
- `POST /{panel.path}/themes/uninstall` uninstalls one non-active, non-stock theme.

Theme upload notes:

- Upload archive must include `theme.json` at archive root.
- Theme Manager accepts `.zip`, `.tar`, `.tar.gz/.tgz`, `.tar.bz2/.tbz2`, `.tar.xz/.txz`, `.tar.zst/.tzst`, `.7z`, and `.rar` packages.
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
| `{else}` | fallback branch inside an open `{if ...}` block | no output |
| `{/each}` | closes `{each ...}` loop scope | no output |
| `{/if}` | closes `{if ...}` conditional | no output |
| `{each path}` | iterates an array tag path such as `{each pages}` or `{each pagination:links}` | no direct output; pushes `item` loop scope |
| `{ifelse not path}` | elseif falsy check inside an open `{if ...}` block | no direct output; conditionally renders enclosed markup |
| `{ifelse path}` | elseif truthy check inside an open `{if ...}` block | no direct output; conditionally renders enclosed markup |
| `{if not path}` | falsy check for a tag path such as `{if not profile_denied}` | no direct output; conditionally renders enclosed markup |
| `{if path}` | truthy check for a tag path such as `{if page:title_show}` | no direct output; conditionally renders enclosed markup |
| `{path}` | escaped scalar lookup such as `{site:name}` | HTML-escaped string |
| `{raw:path}` | raw scalar lookup such as `{raw:item:html}` | unescaped string |

### 9.2 Stable Data Tags

#### 9.2.1 Category Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `category:desc` | category route payload description when present | category description text |
| `category:id` | category route payload id | integer id |
| `category:name` | category route payload display name | category name |
| `category:slug` | category route payload slug | category slug |

#### 9.2.2 Channel Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `channel:desc` | channel route payload description when present | channel description text |
| `channel:id` | channel route payload id | integer id |
| `channel:name` | channel route payload display name | channel name |
| `channel:slug` | channel route payload slug | channel slug |

#### 9.2.3 Content Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `content` | wrapper-layout rendered inner template body | rendered HTML string; use with `{raw:content}` |

#### 9.2.4 Group Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `group:count` | group route payload normalized member count | integer-like member count |
| `group:id` | group route payload id | integer id |
| `group:name` | group route payload display name | group name |
| `group:slug` | group route payload slug | group slug |
| `group_denied` | group placeholder payload flag for private-mode denial | boolean-like truthy/falsey flag |

#### 9.2.5 Item Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `item:alt_text` | current loop item image alt text, when iterating gallery-like rows | image alt text |
| `item:avatar` | current loop item avatar-presence flag, when iterating `members` | boolean-like truthy/falsey flag |
| `item:avatar_full` | current loop item avatar URL, when iterating `members` | avatar original URL |
| `item:avatar_thumb` | current loop item avatar thumbnail URL, when iterating `members` | avatar thumbnail URL |
| `item:caption` | current loop item caption, when iterating gallery-like rows | caption text |
| `item:channel_slug` | current loop item channel slug, when iterating `pages` | channel slug |
| `item:class` | current loop item CSS class string, when iterating `page:content` | CSS class list |
| `item:css_id` | current loop item CSS id token, when iterating `page:content` | CSS id |
| `item:flags:featured` | nested loop-item flag example supported by path resolver | scalar nested flag value |
| `item:full_url` | current loop item full-size image URL, when iterating gallery-like rows | absolute or root-relative image URL |
| `item:href` | current loop item link href, when iterating pagination or contact rows | URL/path string |
| `item:html` | current loop item rendered HTML fragment, when iterating `page:content` | trusted HTML string; use with `{raw:item:html}` |
| `item:image_url` | current loop item image URL, when iterating gallery-like rows | absolute or root-relative image URL |
| `item:is_current` | current loop item current-page flag, when iterating `pagination:links` | boolean-like truthy/falsey flag |
| `item:is_external` | current loop item external-link flag, when iterating `profile:contacts` | boolean-like truthy/falsey flag |
| `item:label` | current loop item label, when iterating pagination or contact rows | label text |
| `item:name` | current loop item display name, when iterating `members` | display name or username fallback when public usernames are enabled |
| `item:title` | current loop item title, when iterating `pages` | page title |
| `item:url` | current loop item public URL, when iterating `pages` | root-relative page URL |
| `item:username` | current loop item username, when iterating `members` | username, blank when `user.auth.login=email` |
| `item:value` | current loop item value, when iterating `profile:contacts` | contact value text |

#### 9.2.6 Meta Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `meta:apple_touch_icon` | public template Apple touch icon URL | URL string |
| `meta:desc` | public template metadata description | meta description text |
| `meta:image` | shared metadata image URL for the wrapper's OpenGraph and X cards | absolute URL |
| `meta:og_locale` | public template OpenGraph locale value | locale string |
| `meta:og_type` | public template OpenGraph type value | type string |
| `meta:robots` | public template robots value | robots directive string |
| `meta:title` | logical route title before site suffix | title text |
| `meta:url` | shared metadata page URL for canonical, OpenGraph, and X tags | absolute URL without forced trailing slash |
| `meta:x_card` | public template X card value | card type string |
| `meta:x_creator` | public template X creator value | creator handle or name string |
| `meta:x_site` | public template X site value | site handle or name string |

#### 9.2.7 Member Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `members` | group route member list | array for `{each members}` |

#### 9.2.8 Page Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `page:channel_id` | page payload parent channel id (`0` means root scope) | integer id |
| `page:channel_slug` | page payload channel slug when page belongs to a channel | channel slug |
| `page:content` | page payload rendered content block rows | array for `{each page:content}` |
| `page:desc` | page payload description when present | page description text |
| `page:id` | page payload id | integer id |
| `page:slug` | page payload slug | page slug |
| `page:title` | page payload title | page title |
| `page:title_show` | page payload normalized display-title flag | boolean-like truthy/falsey flag |
| `pages` | category/tag route page list | array for `{each pages}` |

#### 9.2.9 Pagination Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `pagination:base_path` | pagination payload base path | root-relative base path |
| `pagination:current` | pagination payload current page number | integer-like page number |
| `pagination:links` | pagination link rows | array for `{each pagination:links}` |
| `pagination:per_page` | pagination payload items per page | integer-like page size |
| `pagination:total_items` | pagination payload total item count | integer-like total count |
| `pagination:total_pages` | pagination payload total page count | integer-like total pages |

#### 9.2.10 Panel Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `panel:slug` | configured panel route prefix | panel path slug |
| `panel:url` | absolute panel base URL | absolute panel URL without trailing slash |

#### 9.2.11 Profile Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `profile:avatar` | profile payload avatar-presence flag | boolean-like truthy/falsey flag |
| `profile:avatar_full` | profile payload avatar URL | avatar original URL |
| `profile:avatar_thumb` | profile payload avatar thumbnail URL | avatar thumbnail URL |
| `profile:contact:{type}` | profile payload contact value by type such as `profile:contact:x` | contact value text |
| `profile:contacts` | profile payload normalized contact rows | array for `{each profile:contacts}` |
| `profile:id` | profile payload id | integer id |
| `profile:name` | profile payload resolved display name | display name or username fallback when public usernames are enabled |
| `profile:username` | profile payload username | username, blank when `user.auth.login=email` |
| `profile_denied` | profile placeholder payload flag for private-mode denial | boolean-like truthy/falsey flag |

#### 9.2.12 Redirect Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `redirect:denied` | strict template redirect to `status/denied` | internal redirect token; if present anywhere in rendered output, Raven redirects to that stock status template |
| `redirect:disabled` | strict template redirect to `status/disabled` | internal redirect token; if present anywhere in rendered output, Raven redirects to that stock status template |
| `redirect:404` | strict template redirect to `status/404` | internal redirect token; if present anywhere in rendered output, Raven redirects to that stock status template |

#### 9.2.13 Site Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `site:current_url` | current request URL | absolute URL |
| `site:domain` | configured site host/domain value | host or host/path string |
| `site:name` | configured site name | site name |
| `site:protocol` | configured public protocol | `http` or `https` |
| `site:url` | configured public site base URL | absolute site URL without trailing slash |

#### 9.2.14 Tag Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `tag:desc` | tag route payload description when present | tag description text |
| `tag:id` | tag route payload id | integer id |
| `tag:name` | tag route payload display name | tag name |
| `tag:slug` | tag route payload slug | tag slug |

#### 9.2.15 Theme Tags

| Key | What It Calls | Returns |
| --- | --- | --- |
| `theme:active` | resolved theme slug that provides active CSS/assets | theme slug |
| `theme:slug` | active public theme slug | theme slug |
| `theme:url` | resolved public theme asset base URL | absolute `/theme/{slug}` URL without trailing slash |

Notes:

- `item:*` depends on the active `{each ...}` loop target.
- `redirect:*` is a strict template redirect, not an include. If Raven sees one of these tokens anywhere in the rendered template output, it discards the surrounding markup and delegates to the matching stock status template.
- Additional scalar children on `page`, `category`, `tag`, `profile`, `group`, `members`, and `pages` may also be readable when Raven includes them in the payload, but the table above is the stable contract themes should rely on.
