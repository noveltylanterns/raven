<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/PhraseGenerate.php
 * Recovery-phrase generation and password-hashing helpers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Generates random BIP39-based recovery phrases and hashes them for at-rest storage.
 */
final class PhraseGenerate
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

    /**
     * Generates a new random recovery phrase from the BIP39 word list.
     *
     * Validates the generated phrase before returning to guard against
     * corrupted word-list reads. Returns null when the word pool is too
     * small, CSPRNG entropy fails, or the result fails validation.
     *
     * @param int $wordCount Number of words in the phrase; clamped to at least 1.
     * @return string|null Space-separated phrase, or null on failure.
     */
    public static function generate(int $wordCount = 12): ?string
    {
        $wordCount = max(1, $wordCount);
        $pool = self::wordPool();
        $poolCount = count($pool);
        // Cannot generate unique selections when the loaded pool is smaller than requested count.
        if ($poolCount < $wordCount) {
            return null;
        }

        $words = [];
        // CSPRNG failures or invalid pool entries abort generation.
        try {
            for ($i = 0; $i < $wordCount; $i++) {
                $index = random_int(0, $poolCount - 1);
                $word = trim((string) ($pool[$index] ?? ''));
                // Defensive guard against malformed/empty pool entries.
                if ($word === '') {
                    return null;
                }
                $words[] = $word;
            }
        } catch (\Throwable) {
            return null;
        }

        $phrase = implode(' ', $words);
        // Re-validate generated phrase against canonical validator before returning.
        if (!PhraseValidate::isValid($phrase, $wordCount)) {
            return null;
        }

        return $phrase;
    }

    /**
     * Normalizes and hashes a recovery phrase for at-rest storage.
     *
     * Uses Argon2id when available, falling back to bcrypt. Returns null when
     * the phrase fails validation or password_hash produces an empty result.
     *
     * @param string $phrase Recovery phrase to hash; normalization is applied before hashing.
     * @param int $wordCount Expected word count used for pre-hash validation.
     * @return string|null Hashed phrase string, or null on failure.
     */
    public static function hash(string $phrase, int $wordCount = 12): ?string
    {
        $normalized = PhraseValidate::normalize($phrase);
        // Only valid phrases are accepted for hashing.
        if (!PhraseValidate::isValid($normalized, $wordCount)) {
            return null;
        }

        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $options = $algorithm === PASSWORD_ARGON2ID
            ? self::HASH_ARGON2ID_OPTIONS
            : self::HASH_BCRYPT_OPTIONS;

        $hashed = password_hash($normalized, $algorithm, $options);
        // Hash generation must produce a non-empty string to be persisted.
        if (!is_string($hashed) || trim($hashed) === '') {
            return null;
        }

        return $hashed;
    }

    /**
     * @return array<int, string>
     */
    private static function wordPool(): array
    {
        static $pool = null;
        // Cache loaded word pool for repeated calls within the request lifecycle.
        if (is_array($pool)) {
            return $pool;
        }

        $lines = @file(self::WORD_LIST_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // Missing/unreadable word list yields an empty pool.
        if (!is_array($lines)) {
            $pool = [];
            return $pool;
        }

        $loaded = [];
        // Normalize and validate each candidate word from the BIP39 source file.
        foreach ($lines as $line) {
            $word = strtolower(trim((string) $line));
            // Skip invalid word tokens outside allowed alpha/length constraints.
            if ($word === '' || preg_match('/^[a-z]{2,20}$/', $word) !== 1) {
                continue;
            }
            $loaded[$word] = $word;
        }

        $pool = array_values($loaded);
        return $pool;
    }
}
