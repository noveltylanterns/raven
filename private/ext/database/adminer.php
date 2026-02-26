<?php

/**
 * RAVEN CMS
 * ~/private/ext/database/adminer.php
 * Database Manager extension entrypoint that boots Adminer.
 * Docs: https://raven.lanterns.io
 */

// Inline note: This file is executed by the extension web entrypoint under `~/panel/ext/database/adminer/index.php`.

declare(strict_types=1);

/**
 * Renders a simple HTML error response for Adminer bootstrap failures.
 */
function raven_database_manager_error(string $message): void
{
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Database Manager</title></head><body>';
    echo '<h1>Database Manager</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
}

/**
 * Returns true when a path is absolute for Unix or Windows.
 */
function raven_database_manager_is_absolute_path(string $path): bool
{
    if ($path === '') {
        return false;
    }

    return str_starts_with($path, '/')
        || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
}

/**
 * Returns Raven canonical SQLite filename map.
 *
 * @return array<string, string>
 */
function raven_database_manager_sqlite_files_map(): array
{
    return [
        'pages' => 'pages.db',
        'users' => 'users.db',
        'groups' => 'groups.db',
        'taxonomy' => 'taxonomy.db',
        'extensions' => 'extensions.db',
        'login_failures' => 'login_failures.db',
    ];
}

/**
 * Builds the Adminer auth payload from Raven database config.
 *
 * @param array<string, mixed> $databaseConfig
 *
 * @return array{driver: string, server: string, username: string, password: string, db: string}|null
 */
function raven_database_manager_auth_payload(array $databaseConfig, string $root): ?array
{
    $driver = strtolower(trim((string) ($databaseConfig['driver'] ?? 'sqlite')));

    if ($driver === 'sqlite') {
        $sqlite = (array) ($databaseConfig['sqlite'] ?? []);
        $files = raven_database_manager_sqlite_files_map();

        // The app data connection in SQLite mode uses pages.db as the primary file.
        $basePath = trim((string) ($sqlite['base_path'] ?? ''));
        if ($basePath === '') {
            $basePath = rtrim($root, '/') . '/private/db';
        } elseif (!raven_database_manager_is_absolute_path($basePath)) {
            $basePath = rtrim($root, '/') . '/' . ltrim($basePath, '/');
        }

        $pagesFile = trim((string) ($files['pages'] ?? 'pages.db'));
        if ($pagesFile === '') {
            $pagesFile = 'pages.db';
        }

        return [
            'driver' => 'sqlite',
            'server' => '',
            'username' => 'raven',
            'password' => '',
            'db' => rtrim($basePath, '/') . '/' . ltrim($pagesFile, '/'),
        ];
    }

    if ($driver === 'mysql') {
        $mysql = (array) ($databaseConfig['mysql'] ?? []);
        $host = trim((string) ($mysql['host'] ?? '127.0.0.1'));
        $port = (int) ($mysql['port'] ?? 3306);
        $server = $host;

        // Preserve explicit host:port if already provided; otherwise append configured port.
        if ($server !== '' && $port > 0 && preg_match('/:\d+$/', $server) !== 1) {
            $server .= ':' . $port;
        }

        return [
            'driver' => 'server',
            'server' => $server,
            'username' => trim((string) ($mysql['user'] ?? '')),
            'password' => (string) ($mysql['password'] ?? ''),
            'db' => trim((string) ($mysql['dbname'] ?? '')),
        ];
    }

    if ($driver === 'pgsql') {
        $pgsql = (array) ($databaseConfig['pgsql'] ?? []);
        $host = trim((string) ($pgsql['host'] ?? '127.0.0.1'));
        $port = (int) ($pgsql['port'] ?? 5432);
        $server = $host;

        // Preserve explicit host:port if already provided; otherwise append configured port.
        if ($server !== '' && $port > 0 && preg_match('/:\d+$/', $server) !== 1) {
            $server .= ':' . $port;
        }

        return [
            'driver' => 'pgsql',
            'server' => $server,
            'username' => trim((string) ($pgsql['user'] ?? '')),
            'password' => (string) ($pgsql['password'] ?? ''),
            'db' => trim((string) ($pgsql['dbname'] ?? '')),
        ];
    }

    return null;
}

/**
 * Builds absolute SQLite file list from Raven canonical filename map.
 *
 * @param array<string, mixed> $databaseConfig
 *
 * @return array<int, string>
 */
function raven_database_manager_sqlite_database_list(array $databaseConfig, string $root): array
{
    $driver = strtolower(trim((string) ($databaseConfig['driver'] ?? 'sqlite')));
    if ($driver !== 'sqlite') {
        return [];
    }

    $sqlite = (array) ($databaseConfig['sqlite'] ?? []);
    $files = raven_database_manager_sqlite_files_map();

    $basePath = trim((string) ($sqlite['base_path'] ?? ''));
    if ($basePath === '') {
        $basePath = rtrim($root, '/') . '/private/db';
    } elseif (!raven_database_manager_is_absolute_path($basePath)) {
        $basePath = rtrim($root, '/') . '/' . ltrim($basePath, '/');
    }

    $resolved = [];
    foreach ($files as $fileName) {
        $candidate = trim((string) $fileName);
        if ($candidate === '') {
            continue;
        }

        $absolute = rtrim($basePath, '/') . '/' . ltrim($candidate, '/');
        // Keep duplicates out while preserving source order.
        if (!in_array($absolute, $resolved, true)) {
            $resolved[] = $absolute;
        }
    }

    return $resolved;
}

/**
 * Builds SQLite DB label map keyed by absolute path.
 *
 * Example:
 * - `/.../pages.db` => `Pages (pages.db)`
 *
 * @param array<string, mixed> $databaseConfig
 *
 * @return array<string, string>
 */
function raven_database_manager_sqlite_label_map(array $databaseConfig, string $root): array
{
    $driver = strtolower(trim((string) ($databaseConfig['driver'] ?? 'sqlite')));
    if ($driver !== 'sqlite') {
        return [];
    }

    $sqlite = (array) ($databaseConfig['sqlite'] ?? []);
    $files = raven_database_manager_sqlite_files_map();

    $basePath = trim((string) ($sqlite['base_path'] ?? ''));
    if ($basePath === '') {
        $basePath = rtrim($root, '/') . '/private/db';
    } elseif (!raven_database_manager_is_absolute_path($basePath)) {
        $basePath = rtrim($root, '/') . '/' . ltrim($basePath, '/');
    }

    $labels = [];
    foreach ($files as $key => $fileName) {
        $candidate = trim((string) $fileName);
        if ($candidate === '') {
            continue;
        }

        $absolute = rtrim($basePath, '/') . '/' . ltrim($candidate, '/');
        $prettyName = trim((string) $key);
        if ($prettyName === '' || is_int($key)) {
            $prettyName = pathinfo($candidate, PATHINFO_FILENAME);
        }

        $prettyName = ucwords(str_replace(['_', '-'], ' ', $prettyName));
        $labels[$absolute] = $prettyName . ' (' . basename($candidate) . ')';
    }

    return $labels;
}

/**
 * Validates requested SQLite DB path against Raven's configured allow-list.
 *
 * @param array<string, mixed> $databaseConfig
 */
function raven_database_manager_validate_requested_sqlite_db(array $databaseConfig, string $root, string $requested): ?string
{
    $candidate = trim($requested);
    if ($candidate === '') {
        return null;
    }

    $allowed = raven_database_manager_sqlite_database_list($databaseConfig, $root);
    return in_array($candidate, $allowed, true) ? $candidate : null;
}

// Route callback passes app container in `$ravenApp`.
if (!isset($ravenApp) || !is_array($ravenApp)) {
    raven_database_manager_error('Raven app context was not provided to Database Manager entrypoint.');
    return;
}

$root = (string) ($ravenApp['root'] ?? '');
if ($root === '') {
    raven_database_manager_error('Unable to resolve Raven root path.');
    return;
}

// Tighten browser-side protections for this high-risk admin surface.
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');

// Build one canonical login payload from current Raven DB settings.
$databaseConfig = [];
if (isset($ravenApp['config']) && is_object($ravenApp['config']) && method_exists($ravenApp['config'], 'get')) {
    /** @var mixed $rawDatabaseConfig */
    $rawDatabaseConfig = $ravenApp['config']->get('database', []);
    if (is_array($rawDatabaseConfig)) {
        $databaseConfig = $rawDatabaseConfig;
    }
}

$authPayload = raven_database_manager_auth_payload($databaseConfig, $root);
if ($authPayload === null) {
    raven_database_manager_error('Database Manager could not determine a valid database driver from Raven configuration.');
    return;
}

// Share resolved SQLite file list and default target with Adminer customization methods.
$GLOBALS['_raven_adminer_sqlite_databases'] = raven_database_manager_sqlite_database_list($databaseConfig, $root);
$GLOBALS['_raven_adminer_sqlite_labels'] = raven_database_manager_sqlite_label_map($databaseConfig, $root);
$GLOBALS['_raven_adminer_default_db'] = (string) ($authPayload['db'] ?? '');

// Define Adminer customization before loading Adminer runtime.
//
// SQLite mode must allow password-less auth because SQLite driver rejects passwords.
if (!function_exists('adminer_object')) {
    function adminer_object(): object
    {
        return new class extends Adminer\Adminer {
            /**
             * Uses Raven branding inside Adminer title/header.
             */
            public function name(): string
            {
                return 'Raven Database Manager';
            }

            /**
             * Allows SQLite login without a password while preserving default checks for other drivers.
             *
             * @param string $login
             * @param string $password
             *
             * @return mixed
             */
            public function login(string $login, string $password)
            {
                if (defined('Adminer\\DRIVER') && constant('Adminer\\DRIVER') === 'sqlite') {
                    return true;
                }

                return parent::login($login, $password);
            }

            /**
             * Supplies Raven's configured SQLite file set so Adminer can switch DB files.
             *
             * @return array<int, string>
             */
            public function databases(bool $flush = true): array
            {
                if (defined('Adminer\\DRIVER') && constant('Adminer\\DRIVER') === 'sqlite') {
                    $configured = $GLOBALS['_raven_adminer_sqlite_databases'] ?? [];
                    if (is_array($configured) && $configured !== []) {
                        $normalized = [];
                        foreach ($configured as $entry) {
                            $path = trim((string) $entry);
                            if ($path !== '') {
                                // Keep DB value stable as absolute path so selection/use posts correct value.
                                $normalized[$path] = $path;
                            }
                        }
                        if ($normalized !== []) {
                            return $normalized;
                        }
                    }
                }

                return parent::databases($flush);
            }

            /**
             * Selects Raven default SQLite DB on first load, then respects explicit `db` in URL.
             */
            public function database(): ?string
            {
                if (defined('Adminer\\DRIVER') && constant('Adminer\\DRIVER') === 'sqlite') {
                    $selected = trim((string) ($_GET['db'] ?? ''));
                    if ($selected !== '') {
                        $allowed = $GLOBALS['_raven_adminer_sqlite_databases'] ?? [];
                        if (is_array($allowed) && in_array($selected, $allowed, true)) {
                            return $selected;
                        }
                    }

                    // Support selection from legacy key names used by some Adminer links.
                    $selectedLegacy = trim((string) ($_GET['database'] ?? ''));
                    if ($selectedLegacy !== '') {
                        $allowed = $GLOBALS['_raven_adminer_sqlite_databases'] ?? [];
                        if (is_array($allowed) && in_array($selectedLegacy, $allowed, true)) {
                            return $selectedLegacy;
                        }
                    }

                    $selectedFromAuth = trim((string) ($_POST['auth']['db'] ?? ''));
                    if ($selectedFromAuth !== '') {
                        $allowed = $GLOBALS['_raven_adminer_sqlite_databases'] ?? [];
                        if (is_array($allowed) && in_array($selectedFromAuth, $allowed, true)) {
                            return $selectedFromAuth;
                        }
                    }

                    $default = trim((string) ($GLOBALS['_raven_adminer_default_db'] ?? ''));
                    if ($default !== '') {
                        return $default;
                    }
                }

                return parent::database();
            }

            /**
             * Re-labels SQLite DB selector options with friendly names while preserving real DB values.
             */
            public function databasesPrint(string $missing): void
            {
                if (!(defined('Adminer\\DRIVER') && constant('Adminer\\DRIVER') === 'sqlite')) {
                    parent::databasesPrint($missing);
                    return;
                }

                // Capture parent HTML so we can change only human-facing option text.
                ob_start();
                parent::databasesPrint($missing);
                $html = (string) ob_get_clean();

                $labelMap = $GLOBALS['_raven_adminer_sqlite_labels'] ?? [];
                if (!is_array($labelMap) || $labelMap === []) {
                    echo $html;
                    return;
                }

                $rewritten = $html;
                foreach ($labelMap as $dbPath => $label) {
                    $escapedPath = htmlspecialchars((string) $dbPath, ENT_QUOTES, 'UTF-8');
                    $escapedLabel = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');

                    // Replace only option label text (`>text`), keep `value="path"` unchanged.
                    $rewritten = str_replace(
                        '>' . $escapedPath,
                        '>' . $escapedLabel,
                        $rewritten
                    );
                }

                echo $rewritten;
            }
        };
    }
}

// Adminer expects initial credentials via auth POST, then it redirects into session-backed mode.
//
// Auto-seed auth only when username is missing from query string; this means:
// - first open auto-connects immediately
// - normal Adminer in-app requests are left untouched
if (!array_key_exists('username', $_GET)) {
    $driver = strtolower((string) ($databaseConfig['driver'] ?? 'sqlite'));
    if ($driver === 'sqlite') {
        $requestedDb = (string) ($_GET['db'] ?? '');
        $validatedDb = raven_database_manager_validate_requested_sqlite_db($databaseConfig, $root, $requestedDb);
        if (is_string($validatedDb) && $validatedDb !== '') {
            $authPayload['db'] = $validatedDb;
        }
    }

    $_POST['auth'] = [
        'driver' => $authPayload['driver'],
        'server' => $authPayload['server'],
        'username' => $authPayload['username'],
        'password' => $authPayload['password'],
        'db' => $authPayload['db'],
    ];
}

// Adminer is installed by Composer into `./composer/` per Raven conventions.
// Support both package layouts:
// - top-level `adminer.php`
// - nested `adminer/index.php`
$adminerBasePath = rtrim($root, '/') . '/composer/vrana/adminer';
$adminerEntrypoint = null;
$adminerCandidates = [
    $adminerBasePath . '/adminer.php',
    $adminerBasePath . '/adminer/index.php',
];
foreach ($adminerCandidates as $candidatePath) {
    if (is_file($candidatePath)) {
        $adminerEntrypoint = $candidatePath;
        break;
    }
}

if ($adminerEntrypoint === null) {
    raven_database_manager_error(
        'Adminer is not installed locally. Install dependencies and try again.'
        . ' Expected one of: ~/composer/vrana/adminer/adminer.php or ~/composer/vrana/adminer/adminer/index.php'
    );
    return;
}

// Adminer's distributed `index.php` uses relative `include "./..."` paths.
// Force current working directory to the Adminer runtime directory before loading it.
$adminerRuntimeDirectory = dirname($adminerEntrypoint);
if ($adminerRuntimeDirectory === '' || !is_dir($adminerRuntimeDirectory)) {
    raven_database_manager_error('Adminer runtime directory is missing.');
    return;
}

$previousWorkingDirectory = getcwd();
if (!@chdir($adminerRuntimeDirectory)) {
    raven_database_manager_error('Unable to switch into Adminer runtime directory.');
    return;
}

// Build one stable asset endpoint so Adminer does not depend on fragile relative URLs.
$panelPath = trim((string) $ravenApp['config']->get('panel.path', 'panel'), '/');
if ($panelPath === '') {
    $panelPath = 'panel';
}
$assetEndpoint = '/' . $panelPath . '/database/adminer/asset?f=';

// Adminer upstream omits explicit <head> tags in favor of implicit HTML parsing.
// Inject explicit <head> wrappers so generated markup is friendlier to validators
// and browser devtools expectations without changing page behavior.
ob_start(static function (string $buffer) use ($assetEndpoint): string {
    if (!str_contains($buffer, '<body') || str_contains($buffer, '<head')) {
        $result = $buffer;
    } else {
        $withHeadOpen = preg_replace('~(<html[^>]*>)~i', '$1' . "\n<head>", $buffer, 1);
        if (!is_string($withHeadOpen)) {
            $result = $buffer;
        } else {
            $withHeadClosed = preg_replace('~<body\b~i', "</head>\n<body", $withHeadOpen, 1);
            $result = is_string($withHeadClosed) ? $withHeadClosed : $buffer;
        }
    }

    // Rewrite Adminer stylesheet/script URLs to one deterministic Raven asset endpoint.
    $replaceMap = [
        '../adminer/static/default.css' => $assetEndpoint . rawurlencode('adminer/static/default.css'),
        '../adminer/static/dark.css' => $assetEndpoint . rawurlencode('adminer/static/dark.css'),
        '../adminer/static/functions.js' => $assetEndpoint . rawurlencode('adminer/static/functions.js'),
        'static/editing.js' => $assetEndpoint . rawurlencode('adminer/static/editing.js'),
        '../adminer/static/logo.png' => $assetEndpoint . rawurlencode('adminer/static/logo.png'),
        '../externals/jush/jush.css' => $assetEndpoint . rawurlencode('externals/jush/jush.css'),
        '../externals/jush/jush-dark.css' => $assetEndpoint . rawurlencode('externals/jush/jush-dark.css'),
    ];
    $result = str_replace(array_keys($replaceMap), array_values($replaceMap), $result);

    // Jush module paths are dynamic by DB driver, so rewrite them with a callback.
    $rewrittenModules = preg_replace_callback(
        '~\.\./externals/jush/modules/([a-z0-9._-]+)~i',
        static fn (array $match): string => $assetEndpoint . rawurlencode('externals/jush/modules/' . (string) $match[1]),
        $result
    );

    return is_string($rewrittenModules) ? $rewrittenModules : $result;
});

// Hand off request directly to Adminer upstream script once relative includes are safe.
require basename($adminerEntrypoint);

// Restore original working directory for request hygiene when possible.
if ($previousWorkingDirectory !== false) {
    @chdir($previousWorkingDirectory);
}
