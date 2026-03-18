<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Encrypts/decrypts persisted TOTP secrets for at-rest protection.
 */
final class TotpSecretCipher
{
    private const ENCRYPTED_PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const KEY_LENGTH = 32;
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $keyPath;
    private ?string $cachedKey = null;

    public function __construct(?string $keyPath = null)
    {
        $defaultPath = dirname(__DIR__, 2) . '/dat/.totp_secret.key';
        $this->keyPath = is_string($keyPath) && trim($keyPath) !== '' ? $keyPath : $defaultPath;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with(trim($value), self::ENCRYPTED_PREFIX);
    }

    public function encryptSecret(string $secret): ?string
    {
        $normalizedSecret = TotpService::normalizeSecret($secret);
        if (!TotpService::isValidSecret($normalizedSecret)) {
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

    public function decryptSecret(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (!$this->isEncrypted($trimmed)) {
            $normalized = TotpService::normalizeSecret($trimmed);
            return TotpService::isValidSecret($normalized) ? $normalized : null;
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

        $normalized = TotpService::normalizeSecret($plaintext);
        return TotpService::isValidSecret($normalized) ? $normalized : null;
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
