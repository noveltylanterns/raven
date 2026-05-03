# Raven CMS Running To-Do Checklist

This document tracks current/future bugs, patches, modifications & feature additions for the Raven CMS platform.
This is the default Build Mode backlog file. If the user asks about goals, unpatched bugs, roadmap goals or what to build next, check this file before searching elsewhere in the repo.

## REQUIRED AGENT PROCEDURE
- Every task completed in this file gets noted in `release-notes.md`
- After completing a batch of tasks, make sure relevant documentation is up-to-date.
- Periodically prune checked items off of this list, since `release-notes.md` logs them.
- For every legacy fallback/migration path, function, variable & alias you create, note it in "Legacy Fallback Log" at bottom of this page, since we will be pruning them in future maintenance runs.
- Update this file as you go (add sub-checklists as need be) to keep track of your progress, in case the session breaks and we have to start over.
- `build/long.md` houses long-term project & roadmap goals, for optional secondary context. Do not load it on short-term build tasks.


# Misc Bugs & Tweaks
**Do not delete this heading!**



# Postmaster Service

**Context:** Two places currently send mail independently — `lib/Auth/LoginEmail.php::sendCode()` (2FA codes, uses `mail()` only) and `ext/contact/lib/ContactPublicFormRuntime.php::sendContactMail()` (form submissions, tries sendmail binary first then falls back to `mail()`). Both duplicate: address normalization, domain extraction for headers, no-reply fallback generation, header sanitization, and Message-ID generation. Postmaster lifts the working contact-ext sendmail+fallback delivery into a shared service; lib/Mail/ houses the reusable primitives both it and extensions need.

### Phase 1 — lib/Mail/ primitives (new files)
- [x] `lib/Mail/Address.php` — static utility class: `normalize(string): ?string`, `mask(string): string`, `headerDomain(string): string`, `defaultNoReply(string): string`, `sanitizeHeader(string, int): string`
- [x] `lib/Mail/Message.php` — immutable value object: `to`, `cc`, `bcc`, `replyTo`, `subject`, `body`, `customHeaders`; fluent builder: `withReplyTo`, `withCc`, `withBcc`, `withHeader`

### Phase 2 — sys/Postmaster.php (new file)
- [x] `__construct(Config $config)` — reads `mail.agent`, `mail.sender_address`, `mail.sender_name`, `site.domain`
- [x] `send(Message $message): array{ok, message?}` — tries sendmail binary first (lifted from contact ext), falls back to `mail()`
- [x] `senderAddress(): string` and `senderName(): string` — expose configured sender metadata
- [x] Private: `sendmailBinary(): ?string`, `viaSendmail(...)`, `buildBaseHeaders(...)`, `buildMessageId(string): string`

### Phase 3 — Wire Postmaster into container
- [x] Add `'postmaster' => new Postmaster($config)` to `$rvn` in `private/Raven.php` (after Config is ready, before extension boot)

### Phase 4 — Refactor lib/Auth/LoginEmail.php
- [ ] `sendCode()` signature: drop `$siteDomain`, `$senderAddress`, `$senderName`, `$mailAgent` params; add `Postmaster $postmaster`; use `Address::` helpers and build a `Message`, call `$postmaster->send()`
- [ ] `maskEmail()` → thin wrapper around `Address::mask()`
- [ ] Remove private helpers now owned by Address/Postmaster: `sanitizeText`, `defaultNoReplyAddress`, `mailHeaderDomain`

### Phase 5 — Refactor lib/Auth/LoginChallenge.php
- [ ] Add `Postmaster $postmaster` to constructor; remove mail config reads from `sendCode()` call site

### Phase 6 — Update LoginChallenge instantiation sites (2 files)
- [ ] `sys/Controller/Public/AuthController.php::loginChallengeWorkflow()` — pass `new Postmaster($this->context->config())`
- [ ] `sys/Controller/Panel/AuthController.php::loginChallengeWorkflow()` — pass `new Postmaster($this->config)`

### Phase 7 — Refactor ext/contact/lib/ContactPublicFormRuntime.php
- [ ] Add `Postmaster $postmaster` to constructor
- [ ] `sendContactMail()` — build a `Message`, call `$this->postmaster->send()`; remove thrown RuntimeException style (return error instead, or keep throw — decide at implementation time)
- [ ] Remove private helpers now owned by Postmaster/Address: `sendContactMailViaSendmail`, `sendmailBinaryPath`, `configuredMailSenderAddress`, `configuredMailSenderName`, `mailHeaderDomain`, `defaultNoReplyEmail`

### Phase 8 — Update ext/contact/ext.php
- [ ] Pass `$rvn['postmaster']` when constructing `ContactPublicFormRuntime`

### Phase 9 — PHPDoc sweep & release notes
- [ ] PHPDoc all new and changed methods (lib/Mail/*, sys/Postmaster.php, LoginEmail, LoginChallenge)
- [ ] Append to release-notes.md


# Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- None currently logged.

---
