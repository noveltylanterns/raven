<?php

/**
 * RAVEN CMS
 * ~/private/ext/smallweb/lib/Smallweb/SmallwebService.php
 * Smallweb settings I/O and protocol file CRUD service.
 * docs: /private/ext/AGENTS.md
 */

declare(strict_types=1);

namespace Raven\Ext\Smallweb;

use Raven\Lib\Archive\Folder;
use Raven\Lib\Extension\ExtensionStorageProvisioner;
use Raven\Lib\Format\Txt;

final class SmallwebService
{
    private string $projectRoot;
    private string $storageDir;
    /** @var object $config */
    private object $config;
    private ?array $cachedSettings = null;
    private Folder $folders;
    private Txt $text;

    private const SETTINGS_FILE = 'settings.php';

    public const SUPPORTED_PROTOCOLS = ['finger', 'fingers', 'gemini', 'gopher', 'spartan'];

    private const PROTOCOL_META = [
        'finger'  => ['label' => 'Finger',  'icon' => 'bi-hand-index',  'scheme' => 'finger'],
        'fingers' => ['label' => 'Fingers', 'icon' => 'bi-hand-index-fill', 'scheme' => 'fingers'],
        'gemini'  => ['label' => 'Gemini',  'icon' => 'bi-rocket',      'scheme' => 'gemini'],
        'gopher'  => ['label' => 'Gopher',  'icon' => 'bi-hdd-network', 'scheme' => 'gopher'],
        'spartan' => ['label' => 'Spartan', 'icon' => 'bi-shield',      'scheme' => 'spartan'],
    ];

    private const DEFAULT_SETTINGS = [
        'protocols' => [
            'finger' => [
                'enabled' => false,
                'chmod_dir' => '0755',
                'chmod_txt' => '0644',
                'chmod_cgi' => '0755',
            ],
            'fingers' => [
                'enabled' => false,
                'chmod_dir' => '0755',
                'chmod_txt' => '0644',
                'chmod_cgi' => '0755',
            ],
            'gemini' => [
                'enabled' => false,
                'chmod_dir' => '0755',
                'chmod_txt' => '0644',
                'chmod_cgi' => '0755',
            ],
            'gopher' => [
                'enabled' => false,
                'chmod_dir' => '0755',
                'chmod_txt' => '0644',
                'chmod_cgi' => '0755',
            ],
            'spartan' => [
                'enabled' => false,
                'chmod_dir' => '0755',
                'chmod_txt' => '0644',
                'chmod_cgi' => '0755',
            ],
        ],
    ];

    private const PROTOCOL_TYPES = [
        'finger'  => [
            'txt' => ['label' => 'Plaintext (.txt)', 'ext' => 'txt'],
            'cgi' => ['label' => 'Script (.cgi)', 'ext' => 'cgi'],
        ],
        'fingers' => [
            'txt' => ['label' => 'Plaintext (.txt)', 'ext' => 'txt'],
            'cgi' => ['label' => 'Script (.cgi)', 'ext' => 'cgi'],
        ],
        'gemini' => [
            'gmi' => ['label' => 'Gemini (.gmi)', 'ext' => 'gmi'],
            'txt' => ['label' => 'Plaintext (.txt)', 'ext' => 'txt'],
            'cgi' => ['label' => 'Script (.cgi)', 'ext' => 'cgi'],
        ],
        'gopher' => [
            'gph'       => ['label' => 'Gopher (.gph)', 'ext' => 'gph'],
            'gophermap' => ['label' => 'Gophermap (.gophermap)', 'ext' => 'gophermap'],
            'txt'       => ['label' => 'Plaintext (.txt)', 'ext' => 'txt'],
            'cgi'       => ['label' => 'CGI Script (.cgi)', 'ext' => 'cgi'],
        ],
        'spartan' => [
            'gmi' => ['label' => 'Gemini (.gmi)', 'ext' => 'gmi'],
            'txt' => ['label' => 'Plaintext (.txt)', 'ext' => 'txt'],
            'cgi' => ['label' => 'Script (.cgi)', 'ext' => 'cgi'],
        ],
    ];

    private const TYPE_LABELS = [
        'txt'       => 'Plaintext',
        'cgi'       => 'Script',
        'gmi'       => 'Gemini',
        'gph'       => 'Gopher',
        'gophermap' => 'Gophermap',
        'file'      => 'File',
    ];

    public const DEFAULT_UPLOAD_EXTENSIONS = 'gif,jpg,jpeg,png,txt,pdf,zip,tar,gz';

    private const BUILTIN_EXTENSIONS = ['txt', 'cgi', 'gmi', 'gph', 'gophermap'];
    private const FILENAME_PATTERN = '/^\.?[a-z0-9][a-z0-9_-]*\.[a-z0-9]+$/';
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

    public function __construct(
        string $projectRoot,
        string $storageDir,
        object $config,
        ?Folder $folders = null,
        ?Txt $text = null
    )
    {
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->storageDir = $storageDir;
        $this->config = $config;
        $this->folders = $folders ?? new Folder();
        $this->text = $text ?? new Txt();
    }

    // ── Protocol metadata ──

    public function isValidProtocol(string $protocol): bool
    {
        return in_array($protocol, self::SUPPORTED_PROTOCOLS, true);
    }

    public function protocolLabel(string $protocol): string
    {
        return self::PROTOCOL_META[$protocol]['label'] ?? ucfirst($protocol);
    }

    public function protocolIcon(string $protocol): string
    {
        return self::PROTOCOL_META[$protocol]['icon'] ?? 'bi-globe';
    }

    public function protocolScheme(string $protocol): string
    {
        return self::PROTOCOL_META[$protocol]['scheme'] ?? $protocol;
    }

    /**
     * @return array<string, array{label: string, ext: string}>
     */
    public function protocolTypes(string $protocol): array
    {
        return self::PROTOCOL_TYPES[$protocol] ?? self::PROTOCOL_TYPES['finger'];
    }

    public function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? ucfirst($type);
    }

    public function protocolSupportsHidden(string $protocol): bool
    {
        return in_array($protocol, ['finger', 'fingers'], true);
    }

    public function protocolSupportsExecutable(string $protocol): bool
    {
        return in_array($protocol, ['gemini', 'spartan', 'gopher'], true);
    }

    public function protocolSupportsDirectories(string $protocol): bool
    {
        return in_array($protocol, ['gemini', 'spartan', 'gopher'], true);
    }

    public function protocolSupportsUpload(string $protocol): bool
    {
        return $this->protocolSupportsDirectories($protocol);
    }

    /**
     * @return string[]
     */
    public function getAllowedUploadExtensions(): array
    {
        $settings = $this->loadSettings();
        $raw = (string) ($settings['allowed_upload_extensions'] ?? self::DEFAULT_UPLOAD_EXTENSIONS);
        $exts = array_filter(array_map(
            static fn(string $e): string => strtolower(trim($e)),
            explode(',', $raw)
        ), static fn(string $e): bool => $e !== '' && preg_match('/^[a-z0-9]+$/', $e) === 1);
        return array_values($exts);
    }

    public function isAllowedUploadFilename(string $filename): bool
    {
        if (preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            return false;
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, self::BUILTIN_EXTENSIONS, true)) {
            return false;
        }
        return in_array($ext, $this->getAllowedUploadExtensions(), true);
    }

    /**
     * @return string[] Combined list of built-in + allowed upload extensions for filename matching.
     */
    private function allAllowedExtensions(): array
    {
        return array_unique(array_merge(self::BUILTIN_EXTENSIONS, $this->getAllowedUploadExtensions()));
    }

    private function isAllowedFileExtension(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $this->allAllowedExtensions(), true);
    }

    /**
     * @return array<string, bool>
     */
    public function enabledProtocols(): array
    {
        $settings = $this->loadSettings();
        $result = [];
        foreach (self::SUPPORTED_PROTOCOLS as $proto) {
            $result[$proto] = (bool) ($settings['protocols'][$proto]['enabled'] ?? false);
        }
        return $result;
    }

    // ── Settings ──

    public function loadSettings(): array
    {
        if ($this->cachedSettings !== null) {
            return $this->cachedSettings;
        }

        $decoded = $this->loadRawSettings();
        if (!is_array($decoded)) {
            $this->cachedSettings = self::DEFAULT_SETTINGS;
            return $this->cachedSettings;
        }

        $settings = self::DEFAULT_SETTINGS;
        foreach (self::SUPPORTED_PROTOCOLS as $proto) {
            if (!isset($decoded['protocols'][$proto]) || !is_array($decoded['protocols'][$proto])) {
                continue;
            }
            $src = $decoded['protocols'][$proto];
            $settings['protocols'][$proto]['enabled'] = (bool) ($src['enabled'] ?? $settings['protocols'][$proto]['enabled']);
            foreach (['chmod_dir', 'chmod_txt', 'chmod_cgi'] as $key) {
                $val = trim((string) ($src[$key] ?? ''));
                if (preg_match('/^0[0-7]{3}$/', $val) === 1) {
                    $settings['protocols'][$proto][$key] = $val;
                }
            }
        }

        if (isset($decoded['allowed_upload_extensions']) && is_string($decoded['allowed_upload_extensions'])) {
            $settings['allowed_upload_extensions'] = $decoded['allowed_upload_extensions'];
        }

        $this->cachedSettings = $settings;
        return $this->cachedSettings;
    }

    public function saveSettings(array $settings): bool
    {
        if (!$this->folders->ensureDirectory($this->storageDir, 0750)) {
            return false;
        }

        $file = $this->storageDir . '/' . self::SETTINGS_FILE;
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($settings, true) . ";\n";
        try {
            $this->text->write($file, $content, 0640);
        } catch (\RuntimeException) {
            return false;
        }

        $this->invalidatePhpFileCache($file);

        $this->cachedSettings = null;
        return true;
    }

    public function getProtocolSettings(string $protocol): array
    {
        $settings = $this->loadSettings();
        return $settings['protocols'][$protocol] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRawSettings(): ?array
    {
        $phpFile = $this->storageDir . '/' . self::SETTINGS_FILE;
        if (!is_file($phpFile)) {
            return null;
        }

        $this->invalidatePhpFileCache($phpFile);

        try {
            /** @var mixed $raw */
            $raw = require $phpFile;
        } catch (\Throwable) {
            $raw = null;
        }

        return is_array($raw) ? $raw : null;
    }

    private function invalidatePhpFileCache(string $path): void
    {
        $normalized = trim($path);
        if ($normalized === '') {
            return;
        }

        clearstatcache(true, $normalized);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($normalized, true);
        }
    }

    // ── Domain ──

    public function getSiteDomain(): string
    {
        $domain = trim((string) $this->config->get('site.domain', 'localhost'));
        if ($domain === '') {
            return 'localhost';
        }
        return $domain;
    }

    // ── Directory management ──

    public function getProtocolDir(string $protocol): string
    {
        return $this->projectRoot . '/' . $protocol;
    }

    public function ensureProtocolDirectory(string $protocol): bool
    {
        if (!$this->isValidProtocol($protocol)) {
            return false;
        }

        $dir = $this->getProtocolDir($protocol);
        $settings = $this->getProtocolSettings($protocol);
        $chmodDir = (int) octdec($settings['chmod_dir'] ?? '0755');

        if (!$this->folders->ensureDirectory($dir, $chmodDir)) {
            try {
                $dir = (new ExtensionStorageProvisioner($this->projectRoot))->ensureAuxStorageDirectory($protocol);
            } catch (\Throwable) {
                return false;
            }

            if (!$this->folders->ensureDirectory($dir, $chmodDir)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Re-apply current chmod settings to all existing files in a protocol directory.
     */
    public function applyProtocolPermissions(string $protocol): void
    {
        if (!$this->isValidProtocol($protocol)) {
            return;
        }

        $dir = $this->getProtocolDir($protocol);
        if (!is_dir($dir)) {
            return;
        }

        $settings = $this->getProtocolSettings($protocol);
        $chmodDir = (int) octdec($settings['chmod_dir'] ?? '0755');
        $chmodTxt = (int) octdec($settings['chmod_txt'] ?? '0644');
        $chmodCgi = (int) octdec($settings['chmod_cgi'] ?? '0755');

        $this->folders->ensureDirectory($dir, $chmodDir);

        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_file($path) || preg_match(self::FILENAME_PATTERN, $entry) !== 1) {
                continue;
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $isExec = ($ext === 'cgi') || is_executable($path);
            $chmod = $isExec ? $chmodCgi : $chmodTxt;
            @chmod($path, $chmod);
        }
    }

    public function removeProtocolDirectoryIfEmpty(string $protocol): bool
    {
        if (!$this->isValidProtocol($protocol)) {
            return false;
        }

        $dir = $this->getProtocolDir($protocol);
        if (!is_dir($dir)) {
            return true;
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }

        $contents = array_filter($entries, static fn (string $entry): bool => $entry !== '.' && $entry !== '..');
        if ($contents !== []) {
            return false;
        }

        return $this->folders->removeEmptyDirectory($dir);
    }

    public function syncProtocolDirectories(): void
    {
        foreach (self::SUPPORTED_PROTOCOLS as $protocol) {
            $settings = $this->getProtocolSettings($protocol);
            if (!empty($settings['enabled'])) {
                if ($this->ensureProtocolDirectory($protocol)) {
                    $this->applyProtocolPermissions($protocol);
                }
                continue;
            }

            $this->removeProtocolDirectoryIfEmpty($protocol);
        }
    }

    // ── Subdirectory management ──

    /**
     * Return full recursive tree for a protocol directory.
     * @return array{dirs: array, files: array}
     */
    public function getProtocolTree(string $protocol, string $subdir = ''): array
    {
        $empty = ['dirs' => [], 'files' => []];
        if (!$this->isValidProtocol($protocol) || !$this->isValidSubdirPath($subdir)) {
            return $empty;
        }

        $dir = $this->resolveWorkingDir($protocol, $subdir);
        if (!is_dir($dir)) {
            return $empty;
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            return $empty;
        }

        $dirs = [];
        $files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            $relativePath = $subdir !== '' ? $subdir . '/' . $entry : $entry;

            if (is_dir($path) && $this->isValidSlug($entry)) {
                $dirs[] = [
                    'name' => $entry,
                    'path' => $relativePath,
                    'children' => $this->getProtocolTree($protocol, $relativePath),
                ];
            } elseif (is_file($path) && preg_match(self::FILENAME_PATTERN, $entry) === 1 && $this->isAllowedFileExtension($entry)) {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                $type = $this->extensionToType($ext, $protocol);
                $stat = @stat($path);
                $files[] = [
                    'name' => $entry,
                    'type' => $type,
                    'hidden' => str_starts_with($entry, '.'),
                    'executable' => is_executable($path),
                    'size' => $stat !== false ? (int) $stat['size'] : 0,
                    'modified' => $stat !== false ? (int) $stat['mtime'] : 0,
                ];
            }
        }

        usort($dirs, static fn(array $a, array $b) => strcasecmp($a['name'], $b['name']));
        usort($files, static fn(array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return ['dirs' => $dirs, 'files' => $files];
    }

    /**
     * Flat list of all subdirectory paths for parent-directory dropdowns.
     * @return array<string, string> path => display label
     */
    public function getAvailableParentDirs(string $protocol): array
    {
        $result = ['' => '/'];
        $baseDir = $this->getProtocolDir($protocol);
        if (!is_dir($baseDir)) {
            return $result;
        }
        $this->collectDirs($baseDir, '', $result);
        return $result;
    }

    private function collectDirs(string $baseDir, string $prefix, array &$result): void
    {
        $entries = @scandir($baseDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $baseDir . '/' . $entry;
            if (is_dir($path) && $this->isValidSlug($entry)) {
                $relative = $prefix !== '' ? $prefix . '/' . $entry : $entry;
                $result[$relative] = '/' . $relative;
                $this->collectDirs($path, $relative, $result);
            }
        }
    }

    public function createProtocolSubdir(string $protocol, string $slug, string $parent = ''): bool
    {
        if (!$this->isValidProtocol($protocol) || !$this->isValidSlug($slug)) {
            return false;
        }
        if ($parent !== '' && !$this->isValidSubdirPath($parent)) {
            return false;
        }

        if (!$this->ensureProtocolDirectory($protocol)) {
            return false;
        }

        $parentDir = $this->resolveWorkingDir($protocol, $parent);
        if (!is_dir($parentDir)) {
            return false;
        }

        $newDir = $parentDir . '/' . $slug;
        if (!$this->isPathSafe($newDir . '/x', $protocol)) {
            return false;
        }
        if (is_dir($newDir) || is_file($newDir)) {
            return false;
        }

        $settings = $this->getProtocolSettings($protocol);
        $chmodDir = (int) octdec($settings['chmod_dir'] ?? '0755');
        return $this->folders->createDirectory($newDir, $chmodDir);
    }

    public function deleteProtocolSubdir(string $protocol, string $subdir): bool
    {
        if (!$this->isValidProtocol($protocol) || $subdir === '' || !$this->isValidSubdirPath($subdir)) {
            return false;
        }

        $dir = $this->resolveWorkingDir($protocol, $subdir);
        if (!$this->isPathSafe($dir . '/x', $protocol)) {
            return false;
        }
        if (!is_dir($dir)) {
            return false;
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }
        $contents = array_filter($entries, static fn(string $e) => $e !== '.' && $e !== '..');
        if ($contents !== []) {
            return false;
        }

        return $this->folders->removeEmptyDirectory($dir);
    }

    private function resolveWorkingDir(string $protocol, string $subdir = ''): string
    {
        $dir = $this->getProtocolDir($protocol);
        if ($subdir !== '') {
            $dir .= '/' . trim($subdir, '/');
        }
        return $dir;
    }

    // ── Protocol file CRUD ──

    /**
     * @return array<int, array{name: string, type: string, size: int, modified: int}>
     */
    public function listProtocolFiles(string $protocol, string $subdir = ''): array
    {
        if (!$this->isValidProtocol($protocol) || !$this->isValidSubdirPath($subdir)) {
            return [];
        }

        $dir = $this->resolveWorkingDir($protocol, $subdir);
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!is_file($dir . '/' . $entry)) {
                continue;
            }
            if (preg_match(self::FILENAME_PATTERN, $entry) !== 1 || !$this->isAllowedFileExtension($entry)) {
                continue;
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $type = $this->extensionToType($ext, $protocol);
            $filePath = $dir . '/' . $entry;

            $stat = @stat($filePath);
            $files[] = [
                'name' => $entry,
                'type' => $type,
                'hidden' => str_starts_with($entry, '.'),
                'executable' => is_executable($filePath),
                'size' => $stat !== false ? (int) $stat['size'] : 0,
                'modified' => $stat !== false ? (int) $stat['mtime'] : 0,
            ];
        }

        usort($files, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        return $files;
    }

    public function readProtocolFile(string $protocol, string $filename, string $subdir = ''): ?string
    {
        if (!$this->isValidProtocol($protocol) || !$this->isValidFilename($filename) || !$this->isValidSubdirPath($subdir)) {
            return null;
        }

        $path = $this->resolveWorkingDir($protocol, $subdir) . '/' . $filename;
        if (!$this->isPathSafe($path, $protocol)) {
            return null;
        }

        if (!is_file($path)) {
            return null;
        }

        try {
            return $this->text->read($path);
        } catch (\RuntimeException) {
            return null;
        }
    }

    public function writeProtocolFile(string $protocol, string $slug, string $type, string $content, bool $hidden = false, bool $executable = false, string $subdir = ''): bool
    {
        if (!$this->isValidProtocol($protocol) || !$this->isValidSlug($slug) || !$this->isValidSubdirPath($subdir)) {
            return false;
        }

        $type = $this->normalizeType($type, $protocol);
        $filename = $this->resolveFilename($slug, $type, $hidden);

        if (!$this->ensureProtocolDirectory($protocol)) {
            return false;
        }

        $dir = $this->resolveWorkingDir($protocol, $subdir);
        if (!is_dir($dir)) {
            return false;
        }
        $path = $dir . '/' . $filename;
        if (!$this->isPathSafe($path, $protocol)) {
            return false;
        }

        try {
            $this->text->write($path, $content);
        } catch (\RuntimeException) {
            return false;
        }

        $settings = $this->getProtocolSettings($protocol);
        $isExec = $this->protocolSupportsExecutable($protocol) ? $executable : ($type === 'cgi');
        $chmodKey = $isExec ? 'chmod_cgi' : 'chmod_txt';
        $chmod = (int) octdec($settings[$chmodKey] ?? ($isExec ? '0755' : '0644'));
        @chmod($path, $chmod);

        return true;
    }

    public function deleteProtocolFile(string $protocol, string $filename, string $subdir = ''): bool
    {
        if (!$this->isValidProtocol($protocol) || !$this->isValidFilename($filename) || !$this->isValidSubdirPath($subdir)) {
            return false;
        }

        $path = $this->resolveWorkingDir($protocol, $subdir) . '/' . $filename;
        if (!$this->isPathSafe($path, $protocol)) {
            return false;
        }

        if (!is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * Upload a file into a protocol directory.
     * Filename is sanitized to lowercase slug + original extension.
     * Returns the final filename on success, or null on failure.
     */
    public function uploadProtocolFile(string $protocol, string $tmpPath, string $originalName, string $subdir = ''): ?string
    {
        if (!$this->isValidProtocol($protocol) || !$this->protocolSupportsUpload($protocol) || !$this->isValidSubdirPath($subdir)) {
            return null;
        }

        $originalName = strtolower(trim($originalName));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $this->getAllowedUploadExtensions(), true)) {
            return null;
        }

        $stem = pathinfo($originalName, PATHINFO_FILENAME);
        $slug = (string) preg_replace('/[^a-z0-9_-]/', '-', $stem);
        $slug = (string) preg_replace('/-+/', '-', trim($slug, '-'));
        if ($slug === '' || !$this->isValidSlug($slug)) {
            return null;
        }

        $filename = $slug . '.' . $ext;
        if (preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            return null;
        }

        if (!$this->ensureProtocolDirectory($protocol)) {
            return null;
        }

        $dir = $this->resolveWorkingDir($protocol, $subdir);
        if (!is_dir($dir)) {
            return null;
        }

        $destPath = $dir . '/' . $filename;
        if (!$this->isPathSafe($destPath, $protocol)) {
            return null;
        }

        if (is_file($destPath)) {
            return null;
        }

        if (!@move_uploaded_file($tmpPath, $destPath)) {
            return null;
        }

        $settings = $this->getProtocolSettings($protocol);
        $chmod = (int) octdec($settings['chmod_txt'] ?? '0644');
        @chmod($destPath, $chmod);

        return $filename;
    }

    /**
     * Check whether a different file already exists for a slug
     * (e.g. slug.txt exists when trying to save slug.cgi, or .slug.txt vs slug.cgi).
     */
    public function findConflictingFile(string $protocol, string $slug, string $type, bool $hidden = false, string $subdir = ''): ?string
    {
        if (!$this->isValidProtocol($protocol) || !$this->isValidSubdirPath($subdir)) {
            return null;
        }

        $dir = $this->resolveWorkingDir($protocol, $subdir);
        $target = $this->resolveFilename($slug, $type, $hidden);
        $supportsHidden = $this->protocolSupportsHidden($protocol);
        $types = self::PROTOCOL_TYPES[$protocol] ?? [];

        foreach ($types as $typeKey => $meta) {
            $candidate = $slug . '.' . $meta['ext'];
            if ($candidate !== $target && is_file($dir . '/' . $candidate)) {
                return $candidate;
            }
            if ($supportsHidden) {
                $candidate = '.' . $slug . '.' . $meta['ext'];
                if ($candidate !== $target && is_file($dir . '/' . $candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public function protocolFileExists(string $protocol, string $filename, string $subdir = ''): bool
    {
        if (!$this->isValidProtocol($protocol) || !$this->isValidFilename($filename) || !$this->isValidSubdirPath($subdir)) {
            return false;
        }

        $path = $this->resolveWorkingDir($protocol, $subdir) . '/' . $filename;
        return $this->isPathSafe($path, $protocol) && is_file($path);
    }

    // ── Helpers ──

    public function resolveFilename(string $slug, string $type, bool $hidden = false): string
    {
        return ($hidden ? '.' : '') . $slug . '.' . $type;
    }

    public function normalizeType(string $type, string $protocol = ''): string
    {
        $type = strtolower(trim($type));

        if ($protocol !== '' && isset(self::PROTOCOL_TYPES[$protocol])) {
            if (isset(self::PROTOCOL_TYPES[$protocol][$type])) {
                return $type;
            }
            return (string) array_key_first(self::PROTOCOL_TYPES[$protocol]);
        }

        // Legacy finger/fingers fallback
        return $type === 'cgi' ? 'cgi' : 'txt';
    }

    public function isValidFilename(string $filename): bool
    {
        return preg_match(self::FILENAME_PATTERN, $filename) === 1;
    }

    public function isValidSlug(string $slug): bool
    {
        return $slug !== '' && preg_match(self::SLUG_PATTERN, $slug) === 1 && strlen($slug) <= 120;
    }

    public function filenameToSlug(string $filename): string
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        return ltrim($stem, '.');
    }

    public function isHiddenFile(string $filename): bool
    {
        return str_starts_with($filename, '.');
    }

    public function isValidSubdirPath(string $subdir): bool
    {
        if ($subdir === '') {
            return true;
        }
        if (str_contains($subdir, '..') || str_contains($subdir, "\0")) {
            return false;
        }
        $segments = explode('/', trim($subdir, '/'));
        foreach ($segments as $segment) {
            if (!$this->isValidSlug($segment)) {
                return false;
            }
        }
        return true;
    }

    private function extensionToType(string $ext, string $protocol): string
    {
        $types = self::PROTOCOL_TYPES[$protocol] ?? self::PROTOCOL_TYPES['finger'];
        foreach ($types as $typeKey => $meta) {
            if ($meta['ext'] === $ext) {
                return $typeKey;
            }
        }
        if (!in_array($ext, self::BUILTIN_EXTENSIONS, true)) {
            return 'file';
        }
        return (string) array_key_first($types);
    }

    private function isPathSafe(string $path, string $protocol): bool
    {
        $dir = $this->getProtocolDir($protocol);
        $realDir = realpath($dir);
        if ($realDir === false) {
            $normalized = $dir . '/';
            $normalizedPath = $path;
            if (str_contains($normalizedPath, '..') || str_contains($normalizedPath, "\0")) {
                return false;
            }
            return str_starts_with($normalizedPath, $normalized);
        }

        $realPath = realpath($path);
        if ($realPath !== false) {
            return str_starts_with($realPath, $realDir . '/');
        }

        // Walk up the path until we find a real (existing) ancestor directory.
        $check = dirname($path);
        $depth = 0;
        while ($check !== '/' && $check !== '.' && $depth < 10) {
            $realCheck = realpath($check);
            if ($realCheck !== false) {
                return $realCheck === $realDir || str_starts_with($realCheck, $realDir . '/');
            }
            $check = dirname($check);
            $depth++;
        }

        return false;
    }
}
