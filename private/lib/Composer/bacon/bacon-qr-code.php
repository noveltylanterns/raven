<?php

/**
 * RAVEN CMS
 * ~/private/lib/Composer/bacon/bacon-qr-code.php
 * Package handler for bacon/bacon-qr-code and its dasprid/enum dependency.
 * Load on pages that need QR code generation (e.g. 2FA setup).
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
        'BaconQrCode\\' => $vendorDir . '/bacon/bacon-qr-code/src/',
        'DASPRiD\\Enum\\' => $vendorDir . '/dasprid/enum/src/',
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
