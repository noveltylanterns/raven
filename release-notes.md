### March 11, 2026

- Rebooted repo structure from scratch
- Switched to 'rolling release' style distribution
- Fixed panel-side public 404 fallback rendering so denied/misrouted panel requests no longer dump raw `{site:*}` brace tags from the public wrapper.
- Added server-log breadcrumbs for panel login exceptions that were previously collapsed into a generic "Invalid credentials" response.
- Moved the installer default SQLite storage path to `private/dat/db/`.
- Hardened `public/install.php` to use the same Composer autoload guard as runtime bootstrap, preventing first-run installer fatals caused by `tualo/easymde` autoload side effects.
- Fixed extension schema bootstrapping so enabled extensions load `lib/schema.php` correctly, allowing fresh installs to create the required `contact` and `signups` tables when those extensions are enabled later.
