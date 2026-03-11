### March 11, 2026

- Rebooted repo structure from scratch
- Switched to 'rolling release' style distribution
- Removed local/upstream version fields from the panel Update System page and now resolve updater status strictly by Git revision and branch state.
- Removed the panel updater's `public/install.php` workaround so panel update runs now do a straight `reset --hard FETCH_HEAD`, preventing partial updates that could delete newly added upstream files during the follow-up clean step.
- CLI updater runs still preserve an already-removed `public/install.php` on installed instances.
- Fixed panel-side public 404 fallback rendering so denied/misrouted panel requests no longer dump raw `{site:*}` brace tags from the public wrapper.
- Added server-log breadcrumbs for panel login exceptions that were previously collapsed into a generic "Invalid credentials" response.
- Hardened `public/install.php` to use the same Composer autoload guard as runtime bootstrap, preventing first-run installer fatals caused by `tualo/easymde` autoload side effects.
- Fixed extension schema bootstrapping so enabled extensions load `lib/schema.php` correctly, allowing fresh installs to create the required `contact` and `signups` tables when those extensions are enabled later.
