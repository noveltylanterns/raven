# Repo Extension Build Notes

Updated: 2026-03-29

## Purpose
This file captures anything vague, blocked, or likely to require Raven core work rather than extension-local changes.

## Resolved Since Initial Build
- Raven core now provides the shared scheduler contract. The repo extension uses `scheduler: true` plus `cron.php` for automated sync passes.
- Raven core now provides generic content shortcode runtimes. The repo extension registers a live `[repo ...]` runtime under `shortcode_runtimes`.
- Raven core now provides the `renderPublicExtension` helper for module routes. The repo extension no longer hand-builds its public wrapper output.
- Raven core now provides `bin` storage. The repo extension now requests `bin => true` and ships `rvn-repo` as an extension-owned CLI companion.
- Repo settings and registry now live in JSON files rather than mutable PHP-array includes, so this extension no longer depends on opcache coherence for those two runtime stores.

## Current Notes
- Public Git cloning/downloading still depends on the extension maintaining bare repositories in the public bucket and running `git update-server-info` after syncs when clone-over-static-HTTP is expected.
- Panel mobile card-flattening in core currently targets direct child cards under `#rvnp-main`. When a stock extension editor needs to wrap multiple cards inside a `<form>`, those cards no longer inherit the default mobile border/radius flattening. Core likely needs a reusable class or broader selector for form-wrapped card stacks. If a temporary repo-local CSS override is introduced before that core patch lands, remove it once the shared core fix exists.
- Repeating add/remove row blocks like the contact-information editor are not yet styled through a clearly reusable generic panel repeater class. Copying the contact-row structure into the repo editor did not inherit the same color treatment automatically, which suggests the current styling is still too feature-specific. Core likely needs a reusable repeater-row styling hook/class for stock editors.

## Follow-Up Candidates For Core Build Agent
- Any future shared helper for safe command execution/timeouts if multiple extensions start shelling out to system tools like `git`.
- A reusable panel class/selector for mobile form-wrapped card stacks so stock extension editors can keep cards inside a `<form>` without losing the default flat mobile card treatment.
- A reusable panel repeater-row class/style contract so dynamic add/remove field groups can share the same color and container treatment across stock editors without copying feature-specific selectors.