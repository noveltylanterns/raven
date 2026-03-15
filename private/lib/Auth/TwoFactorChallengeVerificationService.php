<?php

declare(strict_types=1);

namespace Raven\Lib\Auth;

/**
 * Shared verification flow for pending interactive 2FA challenge methods.
 */
final class TwoFactorChallengeVerificationService
{
    /**
     * @param array<string, mixed>|null $preferences
     */
    public function verifyPendingTotpCode(
        ?array $preferences,
        string $submittedCode,
        UserSecurityProfileService $securityProfiles,
        string $issuer = 'Raven CMS'
    ): bool {
        if (!is_array($preferences)) {
            return false;
        }

        $methods = is_array($preferences['two_factor_methods'] ?? null)
            ? $preferences['two_factor_methods']
            : [];

        return $securityProfiles->verifyTotpCode($methods, $submittedCode, $issuer);
    }

    /**
     * @param array<string, mixed>|null $preferences
     * @param callable(array<int, array<string, mixed>>): bool $persistUpdatedMethods
     */
    public function verifyPendingRecoveryCode(
        ?array $preferences,
        string $submittedPhrase,
        string $selectedMethodKey,
        UserSecurityProfileService $securityProfiles,
        callable $persistUpdatedMethods
    ): bool {
        if (!is_array($preferences)) {
            return false;
        }

        $methods = is_array($preferences['two_factor_methods'] ?? null)
            ? array_values($preferences['two_factor_methods'])
            : [];
        $matched = $securityProfiles->matchRecoveryMethod($methods, $submittedPhrase, $selectedMethodKey);
        if (!is_array($matched)) {
            return false;
        }

        if (!(bool) ($matched['reusable'] ?? false)) {
            $matchedIndex = (int) ($matched['index'] ?? -1);
            if ($matchedIndex < 0 || !array_key_exists($matchedIndex, $methods)) {
                return false;
            }

            unset($methods[$matchedIndex]);
            return (bool) $persistUpdatedMethods(array_values($methods));
        }

        return true;
    }
}
