# Raven Pages Guide

This guide covers page authoring, storage flow, and public rendering behavior.

## 1) Panel Page Workflows

Primary panel routes:

- `GET /page` -> list pages
- `GET /page/edit` -> create form
- `GET /page/edit/{id}` -> edit form
- `POST /page/save` -> create/update
- `POST /page/delete` -> single/bulk delete
- `POST /page/gallery/upload` -> upload gallery images
- `POST /page/gallery/delete` -> remove gallery images

Primary implementation files:

- `private/sys/Controller/Panel/PageListController.php`
- `private/sys/Controller/Panel/PageEditController.php`
- `private/tpl/panel/page/list.php`
- `private/tpl/panel/page/edit.php`
- `private/sys/Router/Panel/PageRouter.php`

All mutating routes are login/permission/CSRF guarded.

## 2) Page Editor Surface

The page editor is split into four tabs:

- Content:
  - title/body editing
  - optional block-based content sections
- Taxonomy:
  - channel/category/tag assignments
- Meta:
  - status, slug, scheduling
  - page-level metadata fields
- Media:
  - gallery upload/delete and metadata
  - cover-image and gallery inclusion controls

New pages must be saved before gallery operations are available.

The panel editor exposes these corresponding field controls:

The quick Create Page menu offers root-level channel shortcuts; the page editor's channel selector starts with a bold `<root>` option, followed by alphabetized root channels and alphabetized descendants indented two spaces per level. Each channel option shows its complete parent-aware path in parentheses.

When editing a published page, the header card shows its full canonical URL, including every parent channel path segment.

- `Display title?`, `Description`, `Publish At`, and `Expire At`
- `Upload Image`, `Select`, `Alt / Title`, and `Caption`
- `Include in gallery` and `Use as cover image`

## 3) Page Storage Model

Core page persistence:

- Read side: `private/sys/Repository/PageRead.php`
- Write side: `private/sys/Repository/PageWrite.php`

Related persistence seams:

- taxonomy links (`page_categories`, `page_tags`) managed by page write flow
- gallery/image records through `MediaRead`/`MediaWrite`

Root-scope pages use channel id `0` (root channel scope), presented in the editor as the bold `<root>` option.

## 4) Public Page Rendering

Primary public route handlers:

- `private/sys/Controller/Public/PageController.php`
- `private/sys/Controller/Public/ChannelController.php`
- `private/sys/Router/Public/PageRouter.php`
- `private/sys/Router/Public/ChannelRouter.php`

Core public page seams:

- homepage (`/`)
- channel landing/root fallback (`/{slug}`)
- channel-scoped pages (`/{channel_path}/{slug}`), including parent-aware paths such as `/news/alpha/article`

Markdown file body blocks accept either a local project path or a repository-backed reference:

- Local: `/notes/example.md`
- Git mirror: `repo://docs/notes/example.md?ref=main`

Repository references are resolved by enabled extensions through the public Markdown-file loader
contract. The stock `repo` extension reads only Markdown text blobs from its mirrored bare
repositories; it never exposes the bare repository directory as a direct filesystem path.

Links in repository-sourced Markdown may use file-looking page URLs such as /docs/guide.md. When
the target resolves to a Raven page route, Raven removes the suffix and redirects the visitor to
the canonical extensionless URL, /docs/guide.

Template resolution and fallback behavior are documented in:

- `docs/appendix/templates_public.md`

## 5) Scheduling And Visibility

Page publication/expiry behavior is driven by page status and schedule fields. Runtime and scheduled checks cooperate so pages can transition between published/draft visibility by configured timestamps.

For broader routing and availability policy context:

- `docs/appendix/router.md`
- `docs/configuration.md`

## 6) Media And Gallery Behavior

Gallery operations use the media subsystem and repository write seams to keep file and database state aligned.

Canonical media references:

- `private/lib/Media/MediaUpload.php`
- `private/sys/Repository/MediaRead.php`
- `private/sys/Repository/MediaWrite.php`

Panel editor media hydration helpers are under:

- `private/lib/View/Panel/EditorMedia.php`

## 7) Security Expectations

- Panel page operations require content-management permission.
- POST routes require valid CSRF tokens.
- Inputs are normalized/sanitized before persistence.
- Upload processing validates file constraints before storage writes.

## 8) Related References

- `docs/appendix/core/controller.md`
- `docs/appendix/core/repository.md`
- `docs/appendix/templates_public.md`
- `docs/appendix/database.md`
