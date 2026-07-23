<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/cli.php
 * Smoke checks for private/bin Raven CLI command suite.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

final class CliSmokeRunner
{
    private string $root;
    /** @var array<int, string> */
    private array $phpCommand = [];
    private int $runId;
    /** @var array<int, string> */
    private array $events = [];

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->phpCommand = $this->resolvePhpCommand();
        $this->runId = time();
    }

    /**
     * @return array<int, string>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function run(): void
    {
        $slugBase = 'cli-smoke-' . $this->runId;

        $this->runJson(['private/bin/rvn-sys', 'info', '--json']);
        $this->events[] = 'system_info=ok';

        $category = $this->runJson([
            'private/bin/rvn-cat', 'create',
            '--name', 'CLI Smoke Category ' . $this->runId,
            '--slug', $slugBase . '-cat',
            '--description', 'Created by smoke-cli',
            '--json',
        ]);
        $categoryId = (int) ($category['id'] ?? 0);
        $this->assert($categoryId > 0, 'Failed to create test category.');
        $this->events[] = 'category_create=' . $categoryId;

        $this->runJson([
            'private/bin/rvn-cat', 'update',
            '--id', (string) $categoryId,
            '--name', 'CLI Smoke Category Updated ' . $this->runId,
            '--slug', $slugBase . '-cat',
            '--description', 'Updated by smoke-cli',
            '--json',
        ]);
        $this->events[] = 'category_update=' . $categoryId;

        $this->runJson([
            'private/bin/rvn-cat', 'delete',
            '--id', (string) $categoryId,
            '--json',
        ]);
        $this->events[] = 'category_delete=' . $categoryId;

        $channel = $this->runJson([
            'private/bin/rvn-chan', 'create',
            '--name', 'CLI Smoke Channel ' . $this->runId,
            '--slug', $slugBase . '-chn',
            '--description', 'Created by smoke-cli',
            '--editor', 'inherit',
            '--route-mode', 'slug',
            '--separator', 'inherit',
            '--json',
        ]);
        $channelId = (int) ($channel['id'] ?? 0);
        $this->assert($channelId > 0, 'Failed to create test channel.');
        $this->events[] = 'channel_create=' . $channelId;

        $this->runJson([
            'private/bin/rvn-chan', 'delete',
            '--id', (string) $channelId,
            '--json',
        ]);
        $this->events[] = 'channel_delete=' . $channelId;

        $group = $this->runJson([
            'private/bin/rvn-group', 'create',
            '--name', 'CLI Smoke Group ' . $this->runId,
            '--slug', $slugBase . '-grp',
            '--route-enabled', '1',
            '--permissions', 'view_public,view_disabled',
            '--json',
        ]);
        $groupId = (int) ($group['id'] ?? 0);
        $this->assert($groupId > 0, 'Failed to create test group.');
        $groupPermissionNames = is_array($group['permission_names'] ?? null) ? $group['permission_names'] : [];
        $this->assert(
            !in_array('view_disabled', $groupPermissionNames, true),
            'Group save should strip view_disabled when panel_login is not granted.'
        );
        $this->events[] = 'group_create=' . $groupId;

        $groupUpdate = $this->runJson([
            'private/bin/rvn-group', 'update',
            '--id', (string) $groupId,
            '--permissions', 'view_public,panel_login,view_disabled',
            '--json',
        ]);
        $updatedPermissionNames = is_array($groupUpdate['permission_names'] ?? null) ? $groupUpdate['permission_names'] : [];
        $this->assert(
            in_array('view_disabled', $updatedPermissionNames, true),
            'Group update should keep view_disabled when panel_login is granted.'
        );
        $this->events[] = 'group_update=' . $groupId;

        $this->runJson([
            'private/bin/rvn-group', 'delete',
            '--id', (string) $groupId,
            '--json',
        ]);
        $this->events[] = 'group_delete=' . $groupId;

        $groupsList = $this->runJson([
            'private/bin/rvn-group', 'list',
            '--json',
        ]);
        $stockGroupId = 0;
        $groupItems = is_array($groupsList['items'] ?? null) ? $groupsList['items'] : [];
        foreach ($groupItems as $groupItem) {
            if (!is_array($groupItem)) {
                continue;
            }

            if ((int) ($groupItem['is_stock'] ?? 0) === 1) {
                $stockGroupId = (int) ($groupItem['id'] ?? 0);
                if ($stockGroupId > 0) {
                    break;
                }
            }
        }
        $this->assert($stockGroupId > 0, 'No stock group found for CLI delete-guard smoke test.');
        $stockGroupDeleteBlocked = $this->runJsonExpectFailure([
            'private/bin/rvn-group', 'delete',
            '--id', (string) $stockGroupId,
            '--json',
        ]);
        $this->assert(
            str_contains(strtolower((string) ($stockGroupDeleteBlocked['error'] ?? '')), 'stock groups'),
            'rvn-group should block deleting stock groups.'
        );
        $this->events[] = 'group_delete_stock_blocked=ok';

        $tag = $this->runJson([
            'private/bin/rvn-tag', 'create',
            '--name', 'CLI Smoke Tag ' . $this->runId,
            '--slug', $slugBase . '-tag',
            '--description', 'Created by smoke-cli',
            '--json',
        ]);
        $tagId = (int) ($tag['id'] ?? 0);
        $this->assert($tagId > 0, 'Failed to create test tag.');
        $this->events[] = 'tag_create=' . $tagId;

        $this->runJson([
            'private/bin/rvn-tag', 'delete',
            '--id', (string) $tagId,
            '--json',
        ]);
        $this->events[] = 'tag_delete=' . $tagId;

        $redirect = $this->runJson([
            'private/bin/rvn-redir', 'create',
            '--title', 'CLI Smoke Redirect ' . $this->runId,
            '--slug', $slugBase . '-redir',
            '--description', 'Created by smoke-cli',
            '--target', 'https://example.test/' . $slugBase,
            '--active', '1',
            '--json',
        ]);
        $redirectId = (int) ($redirect['id'] ?? 0);
        $this->assert($redirectId > 0, 'Failed to create test redirect.');
        $this->events[] = 'redirect_create=' . $redirectId;

        $this->runJson([
            'private/bin/rvn-redir', 'delete',
            '--id', (string) $redirectId,
            '--json',
        ]);
        $this->events[] = 'redirect_delete=' . $redirectId;

        require_once $this->root . '/private/Raven.php';
        $rvn = \Raven\Raven::boot();
        $originalCreator = (string) $rvn['config']->get('meta.twitter.creator', '');
        $originalSiteTheme = trim((string) $rvn['config']->get('site.theme', $rvn['config']->get('site.default_theme', 'raven')));
        if ($originalSiteTheme === '') {
            $originalSiteTheme = 'raven';
        }
        $newCreator = 'cli-smoke-' . $this->runId;

        $this->runJson([
            'private/bin/rvn-conf', 'set',
            '--key', 'meta.twitter.creator',
            '--value', $newCreator,
            '--type', 'string',
            '--json',
        ]);

        $getCreator = $this->runJson([
            'private/bin/rvn-conf', 'get',
            '--key', 'meta.twitter.creator',
            '--json',
        ]);
        $this->assert((string) ($getCreator['value'] ?? '') === $newCreator, 'Config set/get mismatch for meta.twitter.creator.');
        $this->events[] = 'config_set_get=ok';

        $this->runJson([
            'private/bin/rvn-conf', 'set',
            '--key', 'meta.twitter.creator',
            '--value', $originalCreator,
            '--type', 'string',
            '--json',
        ]);
        $this->events[] = 'config_restore=ok';

        $blockedThemeSet = $this->runJsonExpectFailure([
            'private/bin/rvn-conf', 'set',
            '--key', 'site.theme',
            '--value', $originalSiteTheme,
            '--type', 'string',
            '--json',
        ]);
        $this->assert(
            str_contains(strtolower((string) ($blockedThemeSet['error'] ?? '')), 'rvn-theme'),
            'rvn-conf should direct site.theme writes to rvn-theme.'
        );
        $this->events[] = 'config_theme_blocked=ok';

        $this->runJson(['private/bin/rvn-ext', 'list', '--json']);
        $this->events[] = 'ext_list=ok';

        $stockExtensionDeleteBlocked = $this->runJsonExpectFailure([
            'private/bin/rvn-ext', 'delete',
            '--slug', 'contact',
            '--json',
        ]);
        $this->assert(
            str_contains(strtolower((string) ($stockExtensionDeleteBlocked['error'] ?? '')), 'stock extension'),
            'rvn-ext should block deleting stock extensions.'
        );
        $this->events[] = 'ext_delete_stock_blocked=ok';

        $extensionTraversalBlocked = $this->runJsonExpectFailure([
            'private/bin/rvn-ext', 'delete',
            '--slug', '../contact',
            '--json',
        ]);
        $this->assert(
            str_contains(strtolower((string) ($extensionTraversalBlocked['error'] ?? '')), 'invalid'),
            'rvn-ext should reject path-traversal style slugs.'
        );
        $this->events[] = 'ext_delete_traversal_blocked=ok';

        $this->runJson(['private/bin/rvn-theme', 'list', '--json']);
        $this->events[] = 'theme_list=ok';

        $themeSlug = $slugBase . '-theme';
        $themeCloneSlug = $slugBase . '-theme-clone';
        $this->runJson([
            'private/bin/rvn-theme', 'create',
            '--slug', $themeSlug,
            '--name', 'CLI Smoke Theme ' . $this->runId,
            '--parent', 'raven',
            '--json',
        ]);
        $this->events[] = 'theme_create=ok';

        $themeCloneCreate = $this->runJson([
            'private/bin/rvn-theme', 'create',
            '--slug', $themeCloneSlug,
            '--name', 'CLI Smoke Theme Clone ' . $this->runId,
            '--clone', $themeSlug,
            '--json',
        ]);
        $this->assert(
            (string) ($themeCloneCreate['cloned_from'] ?? '') === $themeSlug,
            'Theme clone create did not report expected clone source.'
        );
        $this->events[] = 'theme_clone_create=ok';

        $this->runJson([
            'private/bin/rvn-theme', 'enable',
            '--slug', $themeSlug,
            '--json',
        ]);
        $this->events[] = 'theme_enable=ok';

        $themeForceDeleteBlocked = $this->runJsonExpectFailure([
            'private/bin/rvn-theme', 'delete',
            '--slug', $themeSlug,
            '--force',
            '--json',
        ]);
        $this->assert(
            str_contains(strtolower((string) ($themeForceDeleteBlocked['error'] ?? '')), 'does not support --force'),
            'rvn-theme should reject --force delete bypass attempts.'
        );
        $this->events[] = 'theme_delete_force_blocked=ok';

        $activeDeleteBlocked = $this->runJsonExpectFailure([
            'private/bin/rvn-theme', 'delete',
            '--slug', $themeSlug,
            '--json',
        ]);
        $this->assert(
            str_contains(strtolower((string) ($activeDeleteBlocked['error'] ?? '')), 'active theme'),
            'rvn-theme should block deleting the active theme.'
        );
        $this->events[] = 'theme_delete_active_blocked=ok';

        $themeTraversalBlocked = $this->runJsonExpectFailure([
            'private/bin/rvn-theme', 'delete',
            '--slug', '../raven',
            '--json',
        ]);
        $this->assert(
            str_contains(strtolower((string) ($themeTraversalBlocked['error'] ?? '')), 'invalid'),
            'rvn-theme should reject path-traversal style slugs.'
        );
        $this->events[] = 'theme_delete_traversal_blocked=ok';

        $stockDeleteBlocked = $this->runJsonExpectFailure([
            'private/bin/rvn-theme', 'delete',
            '--slug', 'raven',
            '--json',
        ]);
        $this->assert(
            str_contains(strtolower((string) ($stockDeleteBlocked['error'] ?? '')), 'stock theme'),
            'rvn-theme should block deleting stock themes.'
        );
        $this->events[] = 'theme_delete_stock_blocked=ok';

        $this->runJson([
            'private/bin/rvn-theme', 'enable',
            '--slug', $originalSiteTheme,
            '--json',
        ]);
        $this->events[] = 'theme_restore=ok';

        $this->runJson([
            'private/bin/rvn-theme', 'delete',
            '--slug', $themeCloneSlug,
            '--json',
        ]);
        $this->events[] = 'theme_clone_delete=ok';

        $this->runJson([
            'private/bin/rvn-theme', 'delete',
            '--slug', $themeSlug,
            '--json',
        ]);
        $this->events[] = 'theme_delete=ok';

        $this->runJson([
            'private/bin/rvn-ext', 'create',
            '--slug', $slugBase . '-ext',
            '--name', 'CLI Smoke Extension ' . $this->runId,
            '--type', 'plugin',
            '--description', 'Created by smoke-cli',
            '--author', 'Raven Debug Agent',
            '--json',
        ]);
        $this->events[] = 'ext_create=ok';

        $this->runJson([
            'private/bin/rvn-ext', 'delete',
            '--slug', $slugBase . '-ext',
            '--json',
        ]);
        $this->events[] = 'ext_delete=ok';

        $this->smokeUnsafeExtensionImport($slugBase);

        $this->events[] = 'smoke_result=PASS';
        $this->events[] = 'run_id=' . $this->runId;
    }

    /**
     * @param array<int, string> $args
     * @return array<string, mixed>
     */
    private function runJson(array $args): array
    {
        $command = array_merge($this->phpCommand, $args);
        $result = $this->runCommand($command);

        if ($result['exit'] !== 0) {
            throw new RuntimeException('Command failed: ' . implode(' ', $command) . ' | ' . $result['output']);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($result['output'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Expected JSON output from command: ' . implode(' ', $command) . ' | output=' . $result['output']);
        }

        return $decoded;
    }

    /**
     * @param array<int, string> $args
     * @return array<string, mixed>
     */
    private function runJsonExpectFailure(array $args): array
    {
        $command = array_merge($this->phpCommand, $args);
        $result = $this->runCommand($command);

        if ($result['exit'] === 0) {
            throw new RuntimeException('Expected command failure: ' . implode(' ', $command) . ' | ' . $result['output']);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($result['output'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Expected JSON output from failed command: ' . implode(' ', $command) . ' | output=' . $result['output']);
        }

        return $decoded;
    }

    private function smokeUnsafeExtensionImport(string $slugBase): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->events[] = 'ext_import_zip_slip_blocked=skip(no_zip)';
            return;
        }

        $targetSlug = $slugBase . '-unsafe-import';
        $archivePath = $this->root . '/.tmp/rvn-cli-smoke-' . $this->runId . '-unsafe.zip';
        $outsideMarker = 'rvn-cli-smoke-' . $this->runId . '-escape.txt';
        $outsidePath = $this->root . '/private/ext/' . $outsideMarker;
        $targetPath = $this->root . '/private/ext/' . $targetSlug;

        $zip = new ZipArchive();
        $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('Failed to create unsafe import smoke archive.');
        }

        $zip->addFromString('../' . $outsideMarker, 'blocked');
        $zip->addFromString('ext.json', "{\"slug\":\"unsafe-smoke\",\"name\":\"Unsafe Smoke\",\"version\":\"0.1.0\",\"type\":\"plugin\"}\n");
        $zip->close();

        try {
            $unsafeImportBlocked = $this->runJsonExpectFailure([
                'private/bin/rvn-ext', 'import',
                '--archive', $archivePath,
                '--slug', $targetSlug,
                '--json',
            ]);
            $this->assert(
                str_contains(strtolower((string) ($unsafeImportBlocked['error'] ?? '')), 'unsafe'),
                'rvn-ext import should reject unsafe archive entry paths.'
            );
            $this->assert(!is_file($outsidePath), 'Unsafe import wrote outside extraction target.');
            $this->assert(!is_dir($targetPath), 'Unsafe import left target directory on disk.');
            $this->events[] = 'ext_import_zip_slip_blocked=ok';
        } finally {
            @unlink($archivePath);
            @unlink($outsidePath);
            $this->removeDirectoryRecursively($targetPath);
        }
    }

    /**
     * @param array<int, string> $command
     * @return array{exit: int, output: string}
     */
    private function runCommand(array $command): array
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $this->root);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start process: ' . implode(' ', $command));
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $output = trim((string) $stdout);
        $err = trim((string) $stderr);
        if ($err !== '') {
            $output = trim($output . PHP_EOL . $err);
        }

        return [
            'exit' => (int) $exitCode,
            'output' => $output,
        ];
    }

    private function removeDirectoryRecursively(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    /**
     * @return array<int, string>
     */
    private function resolvePhpCommand(): array
    {
        $binary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        if (stripos(basename($binary), 'phpdbg') !== false) {
            return [$binary, '-qrr'];
        }

        return [$binary];
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

$root = dirname(__DIR__, 2);
$runner = new CliSmokeRunner($root);

try {
    $runner->run();
    echo 'Raven CLI smoke test PASSED.' . PHP_EOL;
    foreach ($runner->events() as $event) {
        echo ' - ' . $event . PHP_EOL;
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Raven CLI smoke test FAILED: ' . $exception->getMessage() . PHP_EOL);
    foreach ($runner->events() as $event) {
        fwrite(STDERR, ' - ' . $event . PHP_EOL);
    }
    exit(1);
}
