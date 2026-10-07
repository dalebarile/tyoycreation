<?php
/**
 * QES Data Privacy & Cryptography Helper
 * Provides AES-256 authenticated encryption & decryption for sensitive client PII
 * (client_name, client_email, client_phone, client_address) at rest.
 */

if (!function_exists('qes_get_encryption_key')) {
    function qes_get_encryption_key(): string {
        if (defined('ENV_ENCRYPTION_KEY') && !empty(ENV_ENCRYPTION_KEY)) {
            return ENV_ENCRYPTION_KEY;
        }
        throw new RuntimeException(
            'FATAL: ENV_ENCRYPTION_KEY is not configured in .env.php. '
            . 'Cannot encrypt or decrypt PII data without a valid encryption key.'
        );
    }
}

if (!function_exists('qes_encrypt')) {
    /**
     * Encrypts plaintext string using AES-256-CBC with HMAC-SHA256 authentication.
     *
     * @param string|null $plaintext Plain text to encrypt
     * @param bool $deterministic If true, derives IV deterministically from data (useful for email/phone grouping and indexing)
     * @return string Encrypted payload prefixed with ENC::v1::
     */
    function qes_encrypt(?string $plaintext, bool $deterministic = false): string {
        if ($plaintext === null || $plaintext === '') {
            return '';
        }
        
        // Prevent double encryption
        if (str_starts_with($plaintext, 'ENC::v1::')) {
            return $plaintext;
        }

        $masterKey = qes_get_encryption_key();
        $rawKey = hash('sha256', $masterKey, true);
        $method = 'aes-256-cbc';

        if ($deterministic) {
            // Normalize for deterministic matching (trim and lowercase for email/phone)
            $norm = strtolower(trim((string)$plaintext));
            $iv = substr(hash_hmac('sha256', 'det_iv:' . $norm, $rawKey, true), 0, 16);
        } else {
            $iv = random_bytes(16);
        }

        $ciphertext = openssl_encrypt($plaintext, $method, $rawKey, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return $plaintext;
        }

        $hmac = hash_hmac('sha256', $iv . $ciphertext, $rawKey, true);
        $token = rtrim(strtr(base64_encode($iv . $hmac . $ciphertext), '+/', '-_'), '=');

        return 'ENC::v1::' . $token;
    }
}

if (!function_exists('qes_decrypt')) {
    /**
     * Decrypts an encrypted payload. If payload is plaintext, returns it as-is safely.
     *
     * @param string|null $ciphertext
     * @return string Decrypted plaintext
     */
    function qes_decrypt(?string $ciphertext): string {
        if ($ciphertext === null || $ciphertext === '') {
            return '';
        }

        // Return plaintext as-is if not encrypted
        if (!str_starts_with($ciphertext, 'ENC::v1::')) {
            return $ciphertext;
        }

        $masterKey = qes_get_encryption_key();
        $rawKey = hash('sha256', $masterKey, true);
        $method = 'aes-256-cbc';

        $payload = substr($ciphertext, strlen('ENC::v1::'));
        $decoded = base64_decode(strtr($payload, '-_', '+/'));
        if ($decoded === false || strlen($decoded) < 48) {
            return '';
        }

        $iv = substr($decoded, 0, 16);
        $hmac = substr($decoded, 16, 32);
        $cipher = substr($decoded, 48);

        $expectedHmac = hash_hmac('sha256', $iv . $cipher, $rawKey, true);
        if (!hash_equals($hmac, $expectedHmac)) {
            // Authentication check failed
            return '';
        }

        $plaintext = openssl_decrypt($cipher, $method, $rawKey, OPENSSL_RAW_DATA, $iv);
        return ($plaintext !== false) ? $plaintext : '';
    }
}

if (!function_exists('qes_decrypt_booking')) {
    /**
     * Decrypts client PII fields in a booking row array
     */
    function qes_decrypt_booking(?array $booking): ?array {
        if (!$booking) return $booking;

        if (isset($booking['client_name'])) {
            $booking['client_name'] = qes_decrypt($booking['client_name']);
        }
        if (isset($booking['client_email'])) {
            $booking['client_email'] = qes_decrypt($booking['client_email']);
        }
        if (isset($booking['client_phone'])) {
            $booking['client_phone'] = qes_decrypt($booking['client_phone']);
        }
        if (isset($booking['client_address'])) {
            $booking['client_address'] = qes_decrypt($booking['client_address']);
        }

        return $booking;
    }
}

if (!function_exists('qes_encrypt_booking')) {
    /**
     * Encrypts client PII fields in a booking row array before database persistence
     */
    function qes_encrypt_booking(?array $booking): ?array {
        if (!$booking) return $booking;

        if (isset($booking['client_name'])) {
            $booking['client_name'] = qes_encrypt($booking['client_name'], false);
        }
        if (isset($booking['client_email'])) {
            $booking['client_email'] = qes_encrypt($booking['client_email'], true);
        }
        if (isset($booking['client_phone'])) {
            $booking['client_phone'] = qes_encrypt($booking['client_phone'], true);
        }
        if (isset($booking['client_address'])) {
            $booking['client_address'] = qes_encrypt($booking['client_address'], false);
        }

        return $booking;
    }
}

if (!function_exists('qes_decrypt_notification')) {
    function qes_decrypt_notification(?array $row): ?array {
        if (!$row) return $row;
        if (isset($row['recipient_name'])) {
            $row['recipient_name'] = qes_decrypt($row['recipient_name']);
        }
        if (isset($row['recipient_contact'])) {
            $row['recipient_contact'] = qes_decrypt($row['recipient_contact']);
        }
        return $row;
    }
}

if (!function_exists('qes_encrypt_notification')) {
    function qes_encrypt_notification(?array $row): ?array {
        if (!$row) return $row;
        if (isset($row['recipient_name'])) {
            $row['recipient_name'] = qes_encrypt($row['recipient_name'], false);
        }
        if (isset($row['recipient_contact'])) {
            $row['recipient_contact'] = qes_encrypt($row['recipient_contact'], true);
        }
        return $row;
    }
}
