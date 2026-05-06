<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/PhraseValidate.php
 * Recovery-phrase normalization, validation, hashing-check, and method-matching helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

use Raven\Lib\Auth\Login2fa;

/**
 * Pure recovery-phrase validation and method-matching helpers.
 *
 * Covers normalization, BIP39 word-set validation, stored-hash inspection,
 * password-hash verification, and matching a submitted phrase against a
 * confirmed recovery method row — all without session or DB access.
 */
final class PhraseValidate
{
    private const WORD_LIST_PATH = __DIR__ . '/data/bip39-english.txt';

    /**
     * Lowercases, trims, and collapses whitespace in a raw recovery phrase string.
     *
     * @param string $raw Raw phrase from user input or storage.
     * @return string Normalized lowercase phrase with single spaces between words.
     */
    public static function normalize(string $raw): string
    {
        $normalized = strtolower(trim($raw));
        $normalized = preg_replace('/[^a-z]+/', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';
        return trim($normalized);
    }

    /**
     * Returns true when a normalized phrase contains exactly the expected count of valid BIP39 words.
     *
     * @param string $phrase Phrase string; should be normalized before calling.
     * @param int $wordCount Expected number of words; clamped to at least 1.
     * @return bool True when the phrase is non-empty, the correct length, and every word is in the BIP39 list.
     */
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

    /**
     * Returns true when a stored hash string carries a recognized password_hash algorithm identifier.
     *
     * @param string $hash Stored hash value to inspect.
     * @return bool True when the hash is non-empty and password_get_info reports a known algorithm.
     */
    public static function isValidHash(string $hash): bool
    {
        $hash = trim($hash);
        if ($hash === '') {
            return false;
        }

        $info = password_get_info($hash);
        return (is_array($info) && (string) ($info['algoName'] ?? 'unknown') !== 'unknown');
    }

    /**
     * Verifies a submitted phrase against a stored password hash.
     *
     * Normalizes the phrase and validates both the phrase and the hash before
     * calling password_verify to prevent timing-safe bypasses on invalid inputs.
     *
     * @param string $submittedPhrase Raw phrase from the user.
     * @param string $hash Stored hash to verify against.
     * @param int $wordCount Expected word count; passed through to isValid.
     * @return bool True when the phrase verifies against the stored hash.
     */
    public static function verify(string $submittedPhrase, string $hash, int $wordCount = 12): bool
    {
        $normalizedPhrase = self::normalize($submittedPhrase);
        if (!self::isValid($normalizedPhrase, $wordCount) || !self::isValidHash($hash)) {
            return false;
        }

        return password_verify($normalizedPhrase, $hash);
    }

    /**
     * Returns match metadata when the submitted phrase verifies against any confirmed recovery method.
     *
     * When a non-pool method key is given, only the row whose derived key matches is checked.
     * Returns the array index and reusable flag so the caller can decide whether to consume
     * (remove) the row after a successful single-use match.
     *
     * @param array<int, array<string, mixed>> $methods            2FA method rows decoded from the user preferences column.
     * @param string                           $submittedPhrase    Recovery phrase submitted by the user.
     * @param string                           $selectedMethodKey  Specific method key to check, or the pool key to check all.
     * @return array{index: int, reusable: bool}|null Match result with row index and reusable flag, or null on no match.
     */
    public static function matchRecoveryMethod(
        array $methods,
        string $submittedPhrase,
        string $selectedMethodKey = ''
    ): ?array {
        $normalizedSubmittedPhrase = self::normalize($submittedPhrase);
        if (!self::isValid($normalizedSubmittedPhrase, 12)) {
            return null;
        }

        $selectedMethodKey = trim($selectedMethodKey);

        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            $type   = Login2fa::normalizeType((string) ($method['type'] ?? ''));
            $status = Login2fa::normalizeStatus((string) ($method['status'] ?? ''), $type);
            if ($type !== 'recovery' || $status !== 'confirmed') {
                continue;
            }

            $recoveryHash = trim((string) ($method['recovery_hash'] ?? ''));
            if (!self::isValidHash($recoveryHash)) {
                continue;
            }

            // Skip rows whose derived key does not match the selected key (unless using the pool key).
            if (
                $selectedMethodKey !== ''
                && !Login2fa::isRecoveryPool($selectedMethodKey)
                && $selectedMethodKey !== Login2fa::forRecoveryHash($recoveryHash)
            ) {
                continue;
            }

            if (!self::verify($normalizedSubmittedPhrase, $recoveryHash, 12)) {
                continue;
            }

            return [
                'index'    => (int) $index,
                'reusable' => (bool) ($method['reusable'] ?? false),
            ];
        }

        return null;
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
