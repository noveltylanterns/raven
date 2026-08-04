# Event Log

The Event Log shows runtime messages recorded by Raven. Open it from the panel navigation or the Event Log help button.

## Reading the log

Each entry includes:

- `Time (UTC)` — when Raven recorded the event.
- `Level` — `Error`, `Warning`, or `Info`.
- `Channel` — the subsystem that recorded the message.
- `Message` — the human-readable event description.
- `Context` — expandable structured details when the event includes additional data.

The list displays up to 50 entries per page. Use `Previous` and `Next` to move through longer logs.

## Filtering

Use `Severity` to show all levels or only errors, warnings, or informational events. The `Search` field matches event messages and channels. Selecting `Filter` applies both values, and `Clear` removes the active filters.

Filters remain in the URL and are preserved while paging or exporting, so filtered log views can be bookmarked.

## Exporting

Select `Export CSV` to download the current filtered result set. The export includes the event ID, UTC timestamp, severity, channel, message, and context columns.

## Clearing entries

`Clear Log` permanently deletes every stored event-log entry and requires the panel log-delete permission. The action is protected by CSRF validation and requires confirmation.

## Enabling logging

When no logging level is enabled, the Event Log displays a warning and records no new entries. Enable `Log Errors`, `Log Warnings`, or `Log Info` in the `Debug` tab of System Configuration. The `Event Logger` section also contains syslog and retention settings when they are available in the installation.
