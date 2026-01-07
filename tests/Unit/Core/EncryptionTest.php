<?php

declare(strict_types=1);

namespace CharityCashbook\Tests\Unit\Core;

use CharityCashbook\Core\Encryption;
use PHPUnit\Framework\TestCase;

class EncryptionTest extends TestCase
{
    private Encryption $encryption;
    private string $validKey;

    protected function setUp(): void
    {
        // Generate a valid 32-byte key
        $this->validKey = base64_encode(random_bytes(32));
        $this->encryption = new Encryption($this->validKey);
    }

    // ===========================================
    // Constructor tests
    // ===========================================

    public function testConstructorThrowsForEmptyKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Encryption key not configured');

        new Encryption('');
    }

    public function testConstructorThrowsForInvalidKeyLength(): void
    {
        $shortKey = base64_encode(random_bytes(16)); // 16 bytes instead of 32

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Encryption key must be 32 bytes (256 bits)');

        new Encryption($shortKey);
    }

    public function testConstructorAcceptsValidKey(): void
    {
        $encryption = new Encryption($this->validKey);
        $this->assertInstanceOf(Encryption::class, $encryption);
    }

    // ===========================================
    // encrypt() tests
    // ===========================================

    public function testEncryptReturnsBase64String(): void
    {
        $result = $this->encryption->encrypt('test data');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // Base64 encoded string should be decodable
        $this->assertNotFalse(base64_decode($result));
    }

    public function testEncryptProducesDifferentOutputEachTime(): void
    {
        $plaintext = 'same input';

        $result1 = $this->encryption->encrypt($plaintext);
        $result2 = $this->encryption->encrypt($plaintext);

        // Due to random IV, each encryption should produce different output
        $this->assertNotEquals($result1, $result2);
    }

    public function testEncryptHandlesEmptyString(): void
    {
        $result = $this->encryption->encrypt('');

        $this->assertIsString($result);
        $this->assertNotEmpty($result); // Even empty string produces IV + tag
    }

    public function testEncryptHandlesUnicodeCharacters(): void
    {
        $result = $this->encryption->encrypt('こんにちは 🔐');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testEncryptHandlesLongString(): void
    {
        $longString = str_repeat('a', 10000);
        $result = $this->encryption->encrypt($longString);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    // ===========================================
    // decrypt() tests
    // ===========================================

    public function testDecryptReturnsOriginalString(): void
    {
        $original = 'test data';
        $encrypted = $this->encryption->encrypt($original);

        $decrypted = $this->encryption->decrypt($encrypted);

        $this->assertEquals($original, $decrypted);
    }

    public function testDecryptHandlesEmptyString(): void
    {
        $encrypted = $this->encryption->encrypt('');

        $decrypted = $this->encryption->decrypt($encrypted);

        $this->assertEquals('', $decrypted);
    }

    public function testDecryptHandlesUnicodeCharacters(): void
    {
        $original = 'こんにちは 🔐 Привет';
        $encrypted = $this->encryption->encrypt($original);

        $decrypted = $this->encryption->decrypt($encrypted);

        $this->assertEquals($original, $decrypted);
    }

    public function testDecryptHandlesLongString(): void
    {
        $original = str_repeat('a', 10000);
        $encrypted = $this->encryption->encrypt($original);

        $decrypted = $this->encryption->decrypt($encrypted);

        $this->assertEquals($original, $decrypted);
    }

    public function testDecryptThrowsForInvalidBase64(): void
    {
        $this->expectException(\RuntimeException::class);
        // The actual error depends on how PHP handles the invalid base64
        // It might fail at base64_decode or during decryption

        $this->encryption->decrypt('not valid base64!!!');
    }

    public function testDecryptThrowsForTamperedData(): void
    {
        $encrypted = $this->encryption->encrypt('original data');
        
        // Tamper with the ciphertext by modifying a character
        $data = base64_decode($encrypted);
        $tampered = $data;
        $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0xFF);
        $tamperedEncrypted = base64_encode($tampered);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');

        $this->encryption->decrypt($tamperedEncrypted);
    }

    public function testDecryptThrowsForWrongKey(): void
    {
        $encrypted = $this->encryption->encrypt('secret data');

        // Create encryption with different key
        $differentKey = base64_encode(random_bytes(32));
        $differentEncryption = new Encryption($differentKey);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');

        $differentEncryption->decrypt($encrypted);
    }

    // ===========================================
    // generateKey() tests
    // ===========================================

    public function testGenerateKeyReturnsBase64String(): void
    {
        $key = Encryption::generateKey();

        $this->assertIsString($key);
        // Should be decodable base64
        $decoded = base64_decode($key);
        $this->assertNotFalse($decoded);
    }

    public function testGenerateKeyReturns32Bytes(): void
    {
        $key = Encryption::generateKey();
        $decoded = base64_decode($key);

        $this->assertEquals(32, strlen($decoded));
    }

    public function testGenerateKeyProducesUniqueKeys(): void
    {
        $key1 = Encryption::generateKey();
        $key2 = Encryption::generateKey();

        $this->assertNotEquals($key1, $key2);
    }

    public function testGeneratedKeyCanBeUsedForEncryption(): void
    {
        $key = Encryption::generateKey();
        $encryption = new Encryption($key);

        $original = 'test data';
        $encrypted = $encryption->encrypt($original);
        $decrypted = $encryption->decrypt($encrypted);

        $this->assertEquals($original, $decrypted);
    }

    // ===========================================
    // Round-trip tests
    // ===========================================

    public function testRoundTripWithSpecialCharacters(): void
    {
        $testCases = [
            'simple text',
            "text with\nnewlines\r\nand tabs\t",
            'text with "quotes" and \'apostrophes\'',
            '<html>tags</html>',
            'JSON: {"key": "value"}',
            '🔐🔑🔒',
            'null byte: ' . chr(0) . ' embedded',
        ];

        foreach ($testCases as $original) {
            $encrypted = $this->encryption->encrypt($original);
            $decrypted = $this->encryption->decrypt($encrypted);

            $this->assertEquals($original, $decrypted, "Round-trip failed for: $original");
        }
    }
}
