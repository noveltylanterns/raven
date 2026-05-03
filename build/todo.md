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



# Format Library Cleanup
- [x] All Format library method renames complete (see release-notes.md — May 3, 2026 — Format library method naming pass)


# Misc Bugs & Tweaks
**Do not delete this heading!**

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
