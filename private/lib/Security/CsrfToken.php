<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/CsrfToken.php
 * Token storage contract used by the shared CSRF helper.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Defines the storage contract for one CSRF token backend.
 */
interface CsrfToken
{
    /**
     * Fetches one stored token value by key.
     *
     * @param string $key Storage key for the token value.
     * @return string|null Stored token string when present.
     */
    public function get(string $key): ?string;

    /**
     * Persists one token value under the provided key.
     *
     * @param string $key Storage key for the token value.
     * @param string $value Token string to persist.
     * @return void
     */
    public function set(string $key, string $value): void;

    /**
     * Removes one stored token value by key.
     *
     * @param string $key Storage key for the token value.
     * @return void
     */
    public function remove(string $key): void;
}
