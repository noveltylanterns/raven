# Raven CMS CLI

***Note: This document was generated with ChatGPT Codex. I have not been able to personally verify every detail within matches the actual script. I do not plan on hammering these `docs/` files down until later releases, so use them with caution!***

This file documents Raven's redistributable CLI tools under `private/bin/`.

Maintenance note: keep this file updated whenever any tool is added, removed, or behavior-changed under `private/bin/`.

## 1) Operator Guide

Current status:

- `private/bin/` is currently reserved for future CLI tooling.
- No public CLI commands are shipped yet.

Planned scope (1.0.0+ target):

- content CRUD helpers (pages/channels/categories/tags/redirects)
- user/group management helpers
- configuration management helpers
- consistent CLI command contracts for both human developers and AI agents

## 2) Developer & Agent Contract

Rules for `private/bin/`:

- only redistributable CLI tooling belongs here
- each CLI file must have usage/help output and safe input validation
- each CLI file must be documented in this `docs/CLI.md` file
- prefer routing through existing repositories/services over direct schema mutation
- keep security controls intact (sanitization, permission-sensitive behavior, and safe defaults)

Out of scope for this file:

- environment-specific helper tooling that is not part of redistributed CLI releases
