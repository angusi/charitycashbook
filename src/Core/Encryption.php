<?php

declare(strict_types=1);

namespace CharityCashbook\Core;

/**
 * Encryption utilities for sensitive data
 */
class Encryption
{
    private string $key;
    private const CIPHER = 'aes-256-gcm';

    public function __construct(string $base64Key)
    {
        if (empty($base64Key)) {
            throw new \RuntimeException('Encryption key not configured');
        }
        $this->key = base64_decode($base64Key);
        if (strlen($this->key) !== 32) {
            throw new \RuntimeException('Encryption key must be 32 bytes (256 bits)');
        }
    }

    /**
     * Encrypt a string
     */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';
        
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // Combine IV + tag + ciphertext and base64 encode
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a string
     */
    public function decrypt(string $encrypted): string
    {
        $data = base64_decode($encrypted);
        if ($data === false) {
            throw new \RuntimeException('Invalid encrypted data');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $tagLength = 16; // GCM tag is always 16 bytes

        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, $tagLength);
        $ciphertext = substr($data, $ivLength + $tagLength);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $plaintext;
    }

    /**
     * Generate a new encryption key
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }
}
