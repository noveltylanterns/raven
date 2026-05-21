<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/OutputEncoder.php
 * HTML output-encoding helper for safe template rendering.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Escapes a string for safe HTML output, preventing XSS via unescaped user-supplied values.
 *
 * Wraps htmlspecialchars with ENT_QUOTES so both single and double quotes are encoded;
 * all templates should call this on any value sourced from user input or database content.
 *
 * @param string $value Raw string to encode.
 * @return string       HTML-safe encoded string.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
