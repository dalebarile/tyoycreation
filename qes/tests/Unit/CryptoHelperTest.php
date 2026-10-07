<?php
declare(strict_types=1);

namespace Qes\Tests\Unit;

use PHPUnit\Framework\TestCase;

class CryptoHelperTest extends TestCase
{
    public function test_encrypt_and_decrypt_roundtrip(): void
    {
        $original = "Juan Dela Cruz - 0917-123-4567";
        $encrypted = qes_encrypt($original);

        $this->assertStringStartsWith('ENC::v1::', $encrypted);
        $this->assertNotSame($original, $encrypted);

        $decrypted = qes_decrypt($encrypted);
        $this->assertSame($original, $decrypted);
    }

    public function test_deterministic_encryption_is_repeatable(): void
    {
        $email = "test@example.com";
        $enc1 = qes_encrypt($email, true);
        $enc2 = qes_encrypt($email, true);

        $this->assertSame($enc1, $enc2, "Deterministic mode must produce identical ciphertexts for identical inputs");
        $this->assertSame($email, qes_decrypt($enc1));
    }

    public function test_nondeterministic_encryption_uses_unique_iv(): void
    {
        $name = "Maria Santos";
        $enc1 = qes_encrypt($name, false);
        $enc2 = qes_encrypt($name, false);

        $this->assertNotSame($enc1, $enc2, "Non-deterministic mode must produce different ciphertexts via random IV");
        $this->assertSame($name, qes_decrypt($enc1));
        $this->assertSame($name, qes_decrypt($enc2));
    }

    public function test_unencrypted_plaintext_returns_as_is(): void
    {
        $legacy = "plain_unencrypted_name";
        $this->assertSame($legacy, qes_decrypt($legacy));
    }

    public function test_tampered_ciphertext_returns_empty_string(): void
    {
        $encrypted = qes_encrypt("Sensitive PII Data");
        // Tamper with the token payload
        $tampered = substr($encrypted, 0, -5) . 'XXXXX';

        $this->assertSame('', qes_decrypt($tampered), "Tampered ciphertext must fail HMAC check and return empty string");
    }

    public function test_invalid_base64_payload_returns_empty_string(): void
    {
        $invalid = 'ENC::v1::!!!not_base64!!!';
        $this->assertSame('', qes_decrypt($invalid));
    }

    public function test_empty_and_null_handling(): void
    {
        $this->assertSame('', qes_encrypt(''));
        $this->assertSame('', qes_encrypt(null));
        $this->assertSame('', qes_decrypt(''));
        $this->assertSame('', qes_decrypt(null));
    }

    public function test_prevent_double_encryption(): void
    {
        $encrypted = qes_encrypt("Hello World");
        $doubleEncrypted = qes_encrypt($encrypted);
        $this->assertSame($encrypted, $doubleEncrypted);
    }

    public function test_encrypt_and_decrypt_booking(): void
    {
        $booking = [
            'id' => 42,
            'client_name' => 'Juan Cruz',
            'client_email' => 'juan@example.com',
            'client_phone' => '0918-999-8888',
            'client_address' => '123 Main St, Quezon City',
            'event_title' => 'Wedding Celebration',
        ];

        $encBooking = qes_encrypt_booking($booking);
        $this->assertStringStartsWith('ENC::v1::', $encBooking['client_name']);
        $this->assertStringStartsWith('ENC::v1::', $encBooking['client_email']);
        $this->assertStringStartsWith('ENC::v1::', $encBooking['client_phone']);
        $this->assertStringStartsWith('ENC::v1::', $encBooking['client_address']);
        $this->assertSame('Wedding Celebration', $encBooking['event_title']);

        $decBooking = qes_decrypt_booking($encBooking);
        $this->assertSame($booking, $decBooking);
    }
}
