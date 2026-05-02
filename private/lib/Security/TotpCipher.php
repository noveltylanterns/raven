<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/TotpCipher.php
 * At-rest encryption/decryption helpers for stored TOTP secrets.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Encrypts/decrypts persisted TOTP secrets for at-rest protection.
 */
final class TotpCipher
{
    private const ENCRYPTED_PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const KEY_LENGTH = 32;
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $keyPath;
    private ?string $cachedKey = null;

    /**
     * @param string|null $keyPath Absolute path to the AES key file; defaults to `private/dat/.totp_secret.key`.
     */
    public function __construct(?string $keyPath = null)
    {
        $defaultPath = dirname(__DIR__, 2) . '/dat/.totp_secret.key';
        $this->keyPath = is_string($keyPath) && trim($keyPath) !== '' ? $keyPath : $defaultPath;
    }

    /**
     * Returns true when a stored secret string carries the encryption prefix.
     *
     * @param string $value Stored secret value to inspect.
     * @return bool True when the value was encrypted by this class.
     */
    public function isEncrypted(string $value): bool
    {
        return str_starts_with(trim($value), self::ENCRYPTED_PREFIX);
    }

    /**
     * Encrypts a plaintext TOTP secret using AES-256-GCM and returns the encoded ciphertext string.
     *
     * Returns null when the secret fails TOTP validation or when the key file cannot be read or created.
     *
     * @param string $secret Plaintext base32 TOTP secret.
     * @return string|null Encoded ciphertext with prefix, or null on failure.
     */
    public function encryptSecret(string $secret): ?string
    {
        $normalizedSecret = Totp::normalizeSecret($secret);
        if (!Totp::isValidSecret($normalizedSecret)) {
            return null;
        }

        $key = $this->loadOrCreateKey();
        if (!is_string($key) || strlen($key) !== self::KEY_LENGTH) {
            return null;
        }

        try {
            $iv = random_bytes(self::IV_LENGTH);
        } catch (\Throwable) {
            return null;
        }

        $tag = '';
        $ciphertext = openssl_encrypt(
            $normalizedSecret,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );
        if (!is_string($ciphertext) || $ciphertext === '' || strlen($tag) !== self::TAG_LENGTH) {
            return null;
        }

        return self::ENCRYPTED_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts an encrypted TOTP secret string and returns the normalized plaintext.
     *
     * When the value has no encryption prefix it is treated as a legacy plaintext secret and
     * normalized directly. Returns null when decryption fails or the result is not a valid secret.
     *
     * @param string $value Stored secret value (encrypted or legacy plaintext).
     * @return string|null Normalized plaintext TOTP secret, or null on failure.
     */
    public function decryptSecret(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (!$this->isEncrypted($trimmed)) {
            $normalized = Totp::normalizeSecret($trimmed);
            return Totp::isValidSecret($normalized) ? $normalized : null;
        }

        $payload = substr($trimmed, strlen(self::ENCRYPTED_PREFIX));
        $decoded = base64_decode($payload, true);
        if (!is_string($decoded) || strlen($decoded) <= (self::IV_LENGTH + self::TAG_LENGTH)) {
            return null;
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);
        $tag = substr($decoded, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($decoded, self::IV_LENGTH + self::TAG_LENGTH);
        if (!is_string($iv) || !is_string($tag) || !is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        $key = $this->loadOrCreateKey();
        if (!is_string($key) || strlen($key) !== self::KEY_LENGTH) {
            return null;
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );
        if (!is_string($plaintext) || $plaintext === '') {
            return null;
        }

        $normalized = Totp::normalizeSecret($plaintext);
        return Totp::isValidSecret($normalized) ? $normalized : null;
    }

    /**
     * Encrypts plaintext TOTP secrets in a 2FA method list before persistence.
     *
     * @param array<int, array<string, mixed>> $methods 2FA method rows.
     * @return array<int, array<string, mixed>> Method rows with TOTP secrets encrypted.
     */
    public function encryptMethodSecrets(array $methods): array
    {
        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = strtolower(trim((string) ($method['type'] ?? '')));
            if ($type !== 'totp') {
                continue;
            }

            $secret = trim((string) ($method['secret'] ?? ''));
            if ($secret === '' || $this->isEncrypted($secret)) {
                continue;
            }

            $encryptedSecret = $this->encryptSecret($secret);
            if (!is_string($encryptedSecret) || $encryptedSecret === '') {
                continue;
            }

            $method['secret'] = $encryptedSecret;
            $methods[$index] = $method;
        }

        return $methods;
    }

    /**
     * Decrypts encrypted TOTP secrets in a 2FA method list after reads.
     *
     * When decryption fails, secret is set to empty string so downstream
     * validation safely rejects the method.
     *
     * @param array<int, array<string, mixed>> $methods 2FA method rows.
     * @return array<int, array<string, mixed>> Method rows with TOTP secrets decrypted.
     */
    public function decryptMethodSecrets(array $methods): array
    {
        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = strtolower(trim((string) ($method['type'] ?? '')));
            if ($type !== 'totp') {
                continue;
            }

            $secret = trim((string) ($method['secret'] ?? ''));
            if ($secret === '') {
                continue;
            }

            $decryptedSecret = $this->decryptSecret($secret);
            $method['secret'] = is_string($decryptedSecret) ? $decryptedSecret : '';
            $methods[$index] = $method;
        }

        return $methods;
    }

    private function loadOrCreateKey(): ?string
    {
        if (is_string($this->cachedKey) && strlen($this->cachedKey) === self::KEY_LENGTH) {
            return $this->cachedKey;
        }

        if (is_file($this->keyPath)) {
            $existing = @file_get_contents($this->keyPath);
            if (is_string($existing)) {
                $decoded = base64_decode(trim($existing), true);
                if (is_string($decoded) && strlen($decoded) === self::KEY_LENGTH) {
                    $this->cachedKey = $decoded;
                    return $this->cachedKey;
                }
            }
        }

        $directory = dirname($this->keyPath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return null;
        }

        try {
            $generated = random_bytes(self::KEY_LENGTH);
        } catch (\Throwable) {
            return null;
        }

        $encoded = base64_encode($generated) . "\n";
        $written = @file_put_contents($this->keyPath, $encoded, LOCK_EX);
        if (!is_int($written) || $written < 1) {
            return null;
        }

        @chmod($this->keyPath, 0600);
        $this->cachedKey = $generated;
        return $this->cachedKey;
    }
}
