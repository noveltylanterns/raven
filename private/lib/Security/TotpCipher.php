<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/TotpCipher.php
 * At-rest encryption/decryption helpers for stored TOTP secrets.
 * Docs: https://lanterns.io/raven
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
        // Only syntactically valid TOTP secrets are eligible for encryption.
        if (!Totp::isValidSecret($normalizedSecret)) {
            return null;
        }

        $key = $this->loadOrCreateKey();
        // Require a full-length AES-256 key before encrypting.
        if (!is_string($key) || strlen($key) !== self::KEY_LENGTH) {
            return null;
        }

        // Abort on entropy failures when generating the GCM IV.
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
        // Encryption must yield both ciphertext and authentication tag.
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
        // Empty values never represent usable secrets.
        if ($trimmed === '') {
            return null;
        }

        // Legacy plaintext values are normalized/validated without cipher processing.
        if (!$this->isEncrypted($trimmed)) {
            $normalized = Totp::normalizeSecret($trimmed);
            return Totp::isValidSecret($normalized) ? $normalized : null;
        }

        $payload = substr($trimmed, strlen(self::ENCRYPTED_PREFIX));
        $decoded = base64_decode($payload, true);
        // Decoded payload must contain IV + tag + non-empty ciphertext.
        if (!is_string($decoded) || strlen($decoded) <= (self::IV_LENGTH + self::TAG_LENGTH)) {
            return null;
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);
        $tag = substr($decoded, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($decoded, self::IV_LENGTH + self::TAG_LENGTH);
        // Guard against malformed binary segments before decryption.
        if (!is_string($iv) || !is_string($tag) || !is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        $key = $this->loadOrCreateKey();
        // Decryption requires the same validated key shape as encryption.
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
        // Decryption/authentication failure returns false; normalize to null.
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
        // Walk method rows and encrypt only eligible TOTP secrets.
        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = strtolower(trim((string) ($method['type'] ?? '')));
            // Non-TOTP methods carry no secret to encrypt here.
            if ($type !== 'totp') {
                continue;
            }

            $secret = trim((string) ($method['secret'] ?? ''));
            // Skip blank secrets and already-encrypted values.
            if ($secret === '' || $this->isEncrypted($secret)) {
                continue;
            }

            $encryptedSecret = $this->encryptSecret($secret);
            // On encryption failure keep the original row unchanged.
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
        // Walk method rows and normalize each TOTP secret into plaintext.
        foreach ($methods as $index => $method) {
            if (!is_array($method)) {
                continue;
            }

            $type = strtolower(trim((string) ($method['type'] ?? '')));
            // Decryption only applies to TOTP methods.
            if ($type !== 'totp') {
                continue;
            }

            $secret = trim((string) ($method['secret'] ?? ''));
            // Blank secrets are left untouched.
            if ($secret === '') {
                continue;
            }

            $decryptedSecret = $this->decryptSecret($secret);
            $method['secret'] = is_string($decryptedSecret) ? $decryptedSecret : '';
            $methods[$index] = $method;
        }

        return $methods;
    }

    /**
     * Loads the persisted encryption key or generates/stores a new one.
     *
     * @return string|null Raw binary key material, or null on IO/entropy failure.
     */
    private function loadOrCreateKey(): ?string
    {
        // Reuse cached key material when already loaded and valid.
        if (is_string($this->cachedKey) && strlen($this->cachedKey) === self::KEY_LENGTH) {
            return $this->cachedKey;
        }

        // Prefer loading existing persisted key before generating a new one.
        if (is_file($this->keyPath)) {
            $existing = @file_get_contents($this->keyPath);
            // Existing key material must be readable before decode/length checks.
            if (is_string($existing)) {
                $decoded = base64_decode(trim($existing), true);
                // Persisted key must decode to the exact AES-256 key length.
                if (is_string($decoded) && strlen($decoded) === self::KEY_LENGTH) {
                    $this->cachedKey = $decoded;
                    return $this->cachedKey;
                }
            }
        }

        $directory = dirname($this->keyPath);
        // Create key directory if missing; fail closed when creation is impossible.
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return null;
        }

        // Generate fresh key material when no usable persisted key exists.
        try {
            $generated = random_bytes(self::KEY_LENGTH);
        } catch (\Throwable) {
            return null;
        }

        $encoded = base64_encode($generated) . "\n";
        $written = @file_put_contents($this->keyPath, $encoded, LOCK_EX);
        // Treat short/failed writes as key-generation failure to avoid partial key files.
        if (!is_int($written) || $written < 1) {
            return null;
        }

        @chmod($this->keyPath, 0600);
        $this->cachedKey = $generated;
        return $this->cachedKey;
    }
}
