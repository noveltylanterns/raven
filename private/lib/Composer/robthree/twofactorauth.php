<?php

/**
 * RAVEN CMS
 * ~/private/lib/Composer/robthree/twofactorauth.php
 * Package handler for robthree/twofactorauth.
 * Load on pages that need TOTP two-factor authentication.
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

    spl_autoload_register(static function (string $class) use ($vendorDir): void {
        // Only resolve classes from the RobThree Auth namespace.
        if (str_starts_with($class, 'RobThree\\Auth\\')) {
            $file = $vendorDir . '/robthree/twofactorauth/lib/' . str_replace('\\', '/', substr($class, strlen('RobThree\\Auth\\'))) . '.php';
            // Require the class file only when it exists on disk.
            if (is_file($file)) {
                require_once $file;
            }
        }
    });
})();
