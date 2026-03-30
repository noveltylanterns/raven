# Scheduled Tasks

This Raven `system` extension adds one panel page at `panel/cron/` for managing custom scheduler jobs.

- Jobs are stored in the extension-local `crontab.json` file under the requested `local` storage bucket.
- Each saved row becomes one Raven scheduler job when the row is enabled.
- Commands run from the Raven project root through `/bin/bash -lc`.
- Raven can trigger due jobs from request traffic through `site.scheduler` when that fallback is set to `panel` or `always`.
- When `site.scheduler` is `off`, point server cron at `php private/bin/rvn-cron run` or invoke that command manually.
