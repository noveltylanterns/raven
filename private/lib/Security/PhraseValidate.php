<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/PhraseValidate.php
 * Pure recovery-phrase matching against a stored set of 2FA method rows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

use Raven\Lib\Auth\Login2fa;

/**
 * Matches a submitted recovery phrase against confirmed recovery methods.
 *
 * Extracted from AuthService so callers that hold a method list can perform
 * phrase matching without depending on the full authentication facade.
 */
final class PhraseValidate
{
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
        $normalizedSubmittedPhrase = RecoveryPhrase::normalize($submittedPhrase);
        if (!RecoveryPhrase::isValid($normalizedSubmittedPhrase, 12)) {
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
            if (!RecoveryPhrase::isValidHash($recoveryHash)) {
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

            if (!RecoveryPhrase::verify($normalizedSubmittedPhrase, $recoveryHash, 12)) {
                continue;
            }

            return [
                'index'    => (int) $index,
                'reusable' => (bool) ($method['reusable'] ?? false),
            ];
        }

        return null;
    }
}
