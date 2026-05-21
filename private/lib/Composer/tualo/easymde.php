<?php

/**
 * RAVEN CMS
 * ~/private/lib/Composer/tualo/easymde.php
 * Package handler for tualo/easymde.
 * Load on pages that serve EasyMDE editor assets (page editor only).
 * Registers PSR-4 autoloader and loads the package's functions file.
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
    $functionsFile = $vendorDir . '/tualo/easymde/src/functions.php';

    spl_autoload_register(static function (string $class) use ($vendorDir): void {
        // Only resolve classes from the EasyMDE package namespace.
        if (str_starts_with($class, 'Tualo\\Office\\EasyMDE\\')) {
            $file = $vendorDir . '/tualo/easymde/src/' . str_replace('\\', '/', substr($class, strlen('Tualo\\Office\\EasyMDE\\'))) . '.php';
            // Require the class file only when it exists on disk.
            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    // Load package function helpers when distributed with the package.
    if (is_file($functionsFile)) {
        require_once $functionsFile;
    }
})();
