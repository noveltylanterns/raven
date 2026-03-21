<?php

declare(strict_types=1);

namespace Raven\Lib\Routing;

/**
 * Shared normalization policy for filesystem-backed channel records.
 */
final class ChannelRecordPolicy
{
    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', strtolower(trim($slug))) === 1;
    }

    public static function normalizeEditorOverride(string $value): string
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['inherit', 'tinymce', 'plaintext', 'autobr', 'markdown'], true)
            ? $normalized
            : 'inherit';
    }

    public static function normalizeRouteMode(string $value): string
    {
        return ChannelRoutePolicy::normalizeChannelRouteMode($value);
    }

    public static function normalizeRouteSeparator(string $value): string
    {
        return ChannelRoutePolicy::normalizeChannelSeparator($value);
    }

    public static function normalizeNullablePath(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $path = trim((string) ($value ?? ''));
        return $path === '' ? null : $path;
    }

    public static function normalizeChannelId(mixed $value): ?int
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $id = (int) trim((string) ($value ?? ''));
        return $id > 0 ? $id : null;
    }
}
