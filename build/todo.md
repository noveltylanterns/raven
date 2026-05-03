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



# Archive Library Cleanup
- [ ] in lib/Archive/Upstream - $repoUrl should be $customUrl, normalizeRepoUrl should be normalizeCustomRepo, isValidCustomRepoUrl should be validateCustomRepo, and normalizeGitHubRepo should be normalizeGithubRepo. we also need a corresponding validateGithubRepo.
- [ ] Many of our function names are all based on stale class names. Others are absurdly long. Go through all our Archive classes and make sure all their function names are concise & accurate.
- [ ] Many parts of our codebase are using variables based on stale class/function names. Do a sweep of all lib/Archive/* callers and make sure the variables they use are concise & accurate.
- [ ] Do a full sweep of lib/Archive/* and make sure all headings, classes & functions have present & accurate PHPdoc blocks.
- [ ] Plan this out, and append these guidelines with a more detailed checklist in case we lose session.


# Format Library Cleanup
- [ ] in lib/Format/Json - decodeAssocString should be decode, and decodeFileAssoc should be decodeFile
- [ ] in lib/Format/Git - extractWorktree should be extractTree, and cloneRepository should be clone
- [ ] Many of our function names are all based on stale class names. Others are absurdly long. Go through all our Format classes and make sure all their function names are concise & accurate.
- [ ] Many parts of our codebase are using variables based on stale class/function names. Do a sweep of all lib/Format/* callers and make sure the variables they use are concise & accurate.
- [ ] Do a full sweep of lib/Format/* and make sure all headings, classes & functions have present & accurate PHPdoc blocks.
- [ ] Plan this out, and append these guidelines with a more detailed checklist in case we lose session.


# Misc Bugs & Tweaks
**Do not delete this heading!**
- [ ] Fold Scribe/UserMediaScribe.php into UserScribe.php
- [ ] Move Scribe/AuthThrottleScribe.php to lib/Auth/LoginThrottle.php
- [ ] Put all public-route tpl/ templates in tpl/public/, so tpl/ has just public/ and panel/ folders.
- [ ] Our six `*Factories.php` files should be renamed `*Factory.php`
- [ ] Move lib/Database/Schema/ and lib/Database/Connection/ classes right into lib/Database/ and delete empty folders.
- [ ] sys/Logger.php says its for writing "panel event log entries" when thats ultimately just one category of things that will be piping to this Logger, which should be route-agnostic. This way extension authors & other things can pipe into the Logger later.

# Postmaster Service
- [ ] We need a dedicated set of mail delivery primitives in sys/Postmaster.php
- [ ] Anything that sends out email should be routing it through Postmaster.php for canonical mail delivery contracts, metadata assembly & message assembly.
- [ ] Email-based 2fa code functions should be updated to use this mailer instead of its own delivery logic.
- [ ] Put reusable mail primitives in lib/Mail/ since many of them will likely be independently useful to extension authiors outside of default Postmaster logic. That gives us a spot for future additional mail handlers like Mailgun, in-house SMTP, etc, etc.
- [ ] Plan this out, and append these guidelines with a more detailed checklist in case we lose session.


# Legacy Fallback Log

Running ledger of backward-friendly and legacy shims added during the cleanup work, so they can be removed later once the new schema/contracts are fully settled.

Items below are the remaining classified legacy/compatibility lanes after the current purge pass.

---

- None currently logged.

---
