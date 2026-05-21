<?php

/**
 * RAVEN CMS
 * ~/private/lib/Composer/delight-im/auth.php
 * Package handler for delight-im/auth and its dependencies.
 * Registers PSR-4 autoloaders for all delight-im/* packages.
 * Call this handler once from any bootstrap path that needs the auth library.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

(static function (): void {
    static $loaded = false;
    // Guard against duplicate autoloader registration.
    if ($loaded) {
        return;
    }
    $loaded = true;

    $vendorDir = dirname(__DIR__, 4) . '/composer';
    $prefixMap = [
        'Delight\\Auth\\'   => $vendorDir . '/delight-im/auth/src/',
        'Delight\\Db\\'     => $vendorDir . '/delight-im/db/src/',
        'Delight\\Http\\'   => $vendorDir . '/delight-im/http/src/',
        'Delight\\Cookie\\' => $vendorDir . '/delight-im/cookie/src/',
        'Delight\\Base64\\' => $vendorDir . '/delight-im/base64/src/',
    ];

    spl_autoload_register(static function (string $class) use ($prefixMap): void {
        // Resolve class files from the first matching PSR-4 prefix map entry.
        foreach ($prefixMap as $prefix => $basePath) {
            // Skip prefixes that do not match the requested class.
            if (str_starts_with($class, $prefix)) {
                $file = $basePath . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                // Require the class file only when it exists on disk.
                if (is_file($file)) {
                    require_once $file;
                }
                return;
            }
        }
    });
})();
