### March 11, 2026

- Rebooted repo structure from scratch
- Switched to 'rolling release' style distribution
- Removed local/upstream version fields from the panel Update System page and now resolve updater status strictly by Git revision and branch state.
- Changed panel and CLI updater runs to preserve an already-removed `public/install.php` on installed instances instead of restoring it from upstream and deleting it again.
- Hardened `public/install.php` to use the same Composer autoload guard as runtime bootstrap, preventing first-run installer fatals caused by `tualo/easymde` autoload side effects.
- Fixed extension schema bootstrapping so enabled extensions load `lib/schema.php` correctly, allowing fresh installs to create the required `contact` and `signups` tables when those extensions are enabled later.
