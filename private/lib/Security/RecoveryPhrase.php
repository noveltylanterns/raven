<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Shared recovery phrase generation and validation utilities.
 */
final class RecoveryPhrase
{
    private const WORD_LIST_PATH = __DIR__ . '/data/bip39-english.txt';

    private const HASH_ARGON2ID_OPTIONS = [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 2,
    ];

    private const HASH_BCRYPT_OPTIONS = [
        'cost' => 12,
    ];

    public static function normalize(string $raw): string
    {
        $normalized = strtolower(trim($raw));
        $normalized = preg_replace('/[^a-z]+/', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';
        return trim($normalized);
    }

    public static function isValid(string $phrase, int $wordCount = 12): bool
    {
        $wordCount = max(1, $wordCount);
        $phrase = self::normalize($phrase);
        if ($phrase === '') {
            return false;
        }

        $words = explode(' ', $phrase);
        if (count($words) !== $wordCount) {
            return false;
        }

        $wordLookup = self::wordPoolLookup();
        if ($wordLookup === []) {
            return false;
        }

        foreach ($words as $word) {
            if (!isset($wordLookup[$word])) {
                return false;
            }
        }

        return true;
    }

    public static function generate(int $wordCount = 12): ?string
    {
        $wordCount = max(1, $wordCount);
        $pool = self::wordPool();
        $poolCount = count($pool);
        if ($poolCount < $wordCount) {
            return null;
        }

        $words = [];
        try {
            for ($i = 0; $i < $wordCount; $i++) {
                $index = random_int(0, $poolCount - 1);
                $word = trim((string) ($pool[$index] ?? ''));
                if ($word === '') {
                    return null;
                }
                $words[] = $word;
            }
        } catch (\Throwable) {
            return null;
        }

        $phrase = implode(' ', $words);
        if (!self::isValid($phrase, $wordCount)) {
            return null;
        }

        return $phrase;
    }

    public static function hash(string $phrase, int $wordCount = 12): ?string
    {
        $normalized = self::normalize($phrase);
        if (!self::isValid($normalized, $wordCount)) {
            return null;
        }

        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $options = $algorithm === PASSWORD_ARGON2ID
            ? self::HASH_ARGON2ID_OPTIONS
            : self::HASH_BCRYPT_OPTIONS;

        $hashed = password_hash($normalized, $algorithm, $options);
        if (!is_string($hashed) || trim($hashed) === '') {
            return null;
        }

        return $hashed;
    }

    public static function isValidHash(string $hash): bool
    {
        $hash = trim($hash);
        if ($hash === '') {
            return false;
        }

        $info = password_get_info($hash);
        return (is_array($info) && (string) ($info['algoName'] ?? 'unknown') !== 'unknown');
    }

    public static function verify(string $submittedPhrase, string $hash, int $wordCount = 12): bool
    {
        $normalizedPhrase = self::normalize($submittedPhrase);
        if (!self::isValid($normalizedPhrase, $wordCount) || !self::isValidHash($hash)) {
            return false;
        }

        return password_verify($normalizedPhrase, $hash);
    }

    /**
     * @return array<int, string>
     */
    private static function wordPool(): array
    {
        static $pool = null;
        if (is_array($pool)) {
            return $pool;
        }

        $lines = @file(self::WORD_LIST_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            $pool = [];
            return $pool;
        }

        $loaded = [];
        foreach ($lines as $line) {
            $word = strtolower(trim((string) $line));
            if ($word === '' || preg_match('/^[a-z]{2,20}$/', $word) !== 1) {
                continue;
            }
            $loaded[$word] = $word;
        }

        $pool = array_values($loaded);
        return $pool;
    }

    /**
     * @return array<string, bool>
     */
    private static function wordPoolLookup(): array
    {
        static $lookup = null;
        if (is_array($lookup)) {
            return $lookup;
        }

        $lookup = [];
        foreach (self::wordPool() as $word) {
            $lookup[$word] = true;
        }

        return $lookup;
    }
}
