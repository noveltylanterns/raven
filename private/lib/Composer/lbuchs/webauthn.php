<?php

/**
 * RAVEN CMS
 * ~/private/lib/Composer/lbuchs/webauthn.php
 * Package handler for lbuchs/webauthn.
 * Load on pages that need WebAuthn/FIDO2 authentication.
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
        // Only resolve classes from the lbuchs WebAuthn namespace.
        if (str_starts_with($class, 'lbuchs\\WebAuthn\\')) {
            $file = $vendorDir . '/lbuchs/webauthn/src/' . str_replace('\\', '/', substr($class, strlen('lbuchs\\WebAuthn\\'))) . '.php';
            // Require the class file only when it exists on disk.
            if (is_file($file)) {
                require_once $file;
            }
        }
    });
})();
