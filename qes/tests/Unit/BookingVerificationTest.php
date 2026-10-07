<?php
declare(strict_types=1);

namespace Qes\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qes\Tests\Fakes\FakeConnection;

require_once __DIR__ . '/../../booking_verification_helper.php';

use BookingVerificationHelper;

class BookingVerificationTest extends TestCase
{
    private FakeConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $this->conn = new FakeConnection();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function test_mask_email(): void
    {
        $this->assertSame('j**n@gmail.com', BookingVerificationHelper::maskEmail('juan@gmail.com'));
        $this->assertSame('a*@domain.com', BookingVerificationHelper::maskEmail('ab@domain.com'));
        $this->assertSame('j*****e@example.com', BookingVerificationHelper::maskEmail('johndoe@example.com'));
    }

    public function test_stage_booking_stores_in_session_and_does_not_commit_to_db(): void
    {
        $params = [
            'client_name'          => 'Maria Santos',
            'client_email'         => 'maria@example.com',
            'client_phone'         => '09181234567',
            'client_address'       => 'Quezon City',
            'event_title'          => 'Maria 18th Debut',
            'event_type'           => 'Kids Party',
            'event_date'           => date('Y-m-d', strtotime('+30 days')),
            'event_time'           => '14:00',
            'guest_count'          => 80,
            'location_venue'       => 'Grand Ballroom QC',
            'service_requirements' => 'Balloon Styling & Floral Arch',
            'special_notes'        => 'Pastel palette preferred'
        ];

        $res = BookingVerificationHelper::stageBooking($this->conn, $params);

        $this->assertTrue($res['success']);
        $this->assertTrue($res['requires_verification']);
        $this->assertNotEmpty($res['verification_token']);
        $this->assertSame('m***a@example.com', $res['masked_email']);
        $this->assertSame(600, $res['expires_in']);

        // Assert session contains staged data
        $this->assertArrayHasKey('pending_booking_verification', $_SESSION);
        $pending = $_SESSION['pending_booking_verification'];
        $this->assertSame(6, strlen($pending['code']));
        $this->assertGreaterThan(time(), $pending['expires_at']);
        $this->assertSame('Maria Santos', $pending['name']);

        // Assert NO commit to database bookings table happened during stage
        $dbHasInsert = false;
        foreach ($this->conn->getPreparedStatements() as $stmt) {
            if (stripos($stmt->getSql(), 'INSERT INTO bookings') !== false) {
                $dbHasInsert = true;
                break;
            }
        }
        $this->assertFalse($dbHasInsert, 'Database should NOT have any INSERT INTO bookings when staging inquiry.');
    }

    public function test_stage_booking_validation_failures(): void
    {
        // Missing name
        $res = BookingVerificationHelper::stageBooking($this->conn, [
            'client_name' => '',
            'client_email' => 'valid@example.com'
        ]);
        $this->assertFalse($res['success']);

        // Invalid email
        $res = BookingVerificationHelper::stageBooking($this->conn, [
            'client_name'    => 'Juan dela Cruz',
            'client_email'   => 'not-an-email',
            'client_phone'   => '09171234567',
            'event_title'    => 'My Birthday',
            'event_date'     => date('Y-m-d', strtotime('+30 days')),
            'location_venue' => 'Manila'
        ]);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Email Address', $res['message']);

        // Invalid phone
        $res = BookingVerificationHelper::stageBooking($this->conn, [
            'client_name'    => 'Juan dela Cruz',
            'client_email'   => 'juan@example.com',
            'client_phone'   => '0912abc',
            'event_title'    => 'My Birthday',
            'event_date'     => date('Y-m-d', strtotime('+30 days')),
            'location_venue' => 'Manila'
        ]);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Contact Number', $res['message']);

        // Catering limit exceeded without add-ons
        $res = BookingVerificationHelper::stageBooking($this->conn, [
            'client_name'          => 'Juan dela Cruz',
            'client_email'         => 'juan@example.com',
            'client_phone'         => '09171234567',
            'event_title'          => 'My Birthday',
            'event_date'           => date('Y-m-d', strtotime('+30 days')),
            'guest_count'          => 100,
            'location_venue'       => 'Manila',
            'service_requirements' => 'Catering Service: 75 pax'
        ]);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('exceeds your selected Catering Service package limit', $res['message']);

        // Catering limit satisfied when add-on pax is added (75 pax + 25 pax = 100 pax)
        $res = BookingVerificationHelper::stageBooking($this->conn, [
            'client_name'          => 'Juan dela Cruz',
            'client_email'         => 'juan@example.com',
            'client_phone'         => '09171234567',
            'event_title'          => 'My Birthday',
            'event_date'           => date('Y-m-d', strtotime('+30 days')),
            'guest_count'          => 100,
            'location_venue'       => 'Manila',
            'service_requirements' => 'Catering Service: 75 pax; Catering Add-on: +25 pax'
        ]);
        $this->assertTrue($res['success']);
    }

    public function test_verify_booking_fails_on_wrong_code(): void
    {
        $params = [
            'client_name'    => 'Pedro Cruz',
            'client_email'   => 'pedro@example.com',
            'client_phone'   => '09179876543',
            'event_title'    => 'Silver Wedding Anniversary',
            'event_type'     => 'Weddings',
            'event_date'     => date('Y-m-d', strtotime('+185 days')),
            'location_venue' => 'Tagaytay Highlands'
        ];

        BookingVerificationHelper::stageBooking($this->conn, $params);
        $token = $_SESSION['pending_booking_verification']['token'];

        // Submit wrong 6-digit code
        $verifyRes = BookingVerificationHelper::verifyBooking($this->conn, '999999', $token);

        $this->assertFalse($verifyRes['success']);
        $this->assertStringContainsString('incorrect', $verifyRes['message']);
        $this->assertSame(1, $_SESSION['pending_booking_verification']['attempts']);
    }

    public function test_verify_booking_succeeds_and_commits_to_database(): void
    {
        $this->conn->insert_id = 999;
        $this->conn->addQueryResult("SELECT id FROM bookings WHERE reference_no = ?", []);

        $params = [
            'client_name'    => 'Pedro Cruz',
            'client_email'   => 'pedro@example.com',
            'client_phone'   => '09179876543',
            'event_title'    => 'Silver Wedding Anniversary',
            'event_type'     => 'Weddings',
            'event_date'     => date('Y-m-d', strtotime('+185 days')),
            'location_venue' => 'Tagaytay Highlands'
        ];

        BookingVerificationHelper::stageBooking($this->conn, $params);
        $correctCode = $_SESSION['pending_booking_verification']['code'];
        $token       = $_SESSION['pending_booking_verification']['token'];

        $verifyRes = BookingVerificationHelper::verifyBooking($this->conn, $correctCode, $token);

        $this->assertTrue($verifyRes['success'], $verifyRes['message'] ?? '');
        $this->assertTrue($verifyRes['verified']);
        $this->assertNotEmpty($verifyRes['reference_no']);
        $this->assertSame('Silver Wedding Anniversary', $verifyRes['event_title']);

        // Assert session has been cleared after successful verification
        $this->assertArrayNotHasKey('pending_booking_verification', $_SESSION);

        // Assert database commit query was actually performed
        $dbHasInsert = false;
        foreach ($this->conn->getPreparedStatements() as $stmt) {
            if (stripos($stmt->getSql(), 'INSERT INTO bookings') !== false) {
                $dbHasInsert = true;
                break;
            }
        }
        $this->assertTrue($dbHasInsert, 'Database must have committed record to bookings table upon successful verification.');
    }

    public function test_verify_booking_fails_when_expired(): void
    {
        $params = [
            'client_name'    => 'Ana Gomez',
            'client_email'   => 'ana@example.com',
            'client_phone'   => '09171112233',
            'event_title'    => 'Kids 7th Birthday',
            'event_type'     => 'Kids Party',
            'event_date'     => date('Y-m-d', strtotime('+20 days')),
            'location_venue' => 'Valenzuela Clubhouse'
        ];

        BookingVerificationHelper::stageBooking($this->conn, $params);
        $code  = $_SESSION['pending_booking_verification']['code'];
        $token = $_SESSION['pending_booking_verification']['token'];

        // Simulate 10-minute expiry
        $_SESSION['pending_booking_verification']['expires_at'] = time() - 5;

        $verifyRes = BookingVerificationHelper::verifyBooking($this->conn, $code, $token);

        $this->assertFalse($verifyRes['success']);
        $this->assertStringContainsString('expired', $verifyRes['message']);
    }

    public function test_verify_booking_max_attempts_lockout(): void
    {
        $params = [
            'client_name'    => 'Lockout User',
            'client_email'   => 'lockout@example.com',
            'client_phone'   => '09170001122',
            'event_title'    => 'Party Test',
            'event_type'     => 'Kids Party',
            'event_date'     => date('Y-m-d', strtotime('+20 days')),
            'location_venue' => 'Clubhouse'
        ];

        BookingVerificationHelper::stageBooking($this->conn, $params);
        $_SESSION['pending_booking_verification']['attempts'] = 5;

        $verifyRes = BookingVerificationHelper::verifyBooking($this->conn, '123456');

        $this->assertFalse($verifyRes['success']);
        $this->assertStringContainsString('Too many failed', $verifyRes['message']);
        $this->assertArrayNotHasKey('pending_booking_verification', $_SESSION);
    }

    public function test_resend_code_cooldown_and_refresh(): void
    {
        $params = [
            'client_name'    => 'Resend User',
            'client_email'   => 'resend@example.com',
            'client_phone'   => '09173334455',
            'event_title'    => 'Debut Event',
            'event_type'     => 'Kids Party',
            'event_date'     => date('Y-m-d', strtotime('+20 days')),
            'location_venue' => 'Manila Hotel'
        ];

        BookingVerificationHelper::stageBooking($this->conn, $params);
        $initialCode = $_SESSION['pending_booking_verification']['code'];
        $token       = $_SESSION['pending_booking_verification']['token'];

        // Immediate resend must fail due to 60s cooldown
        $resend1 = BookingVerificationHelper::resendCode($this->conn, $token);
        $this->assertFalse($resend1['success']);
        $this->assertStringContainsString('Please wait', $resend1['message']);

        // Fast-forward cooldown by setting last_resend_at back 65 seconds
        $_SESSION['pending_booking_verification']['last_resend_at'] = time() - 65;

        // Resend should now succeed
        $resend2 = BookingVerificationHelper::resendCode($this->conn, $token);
        $this->assertTrue($resend2['success']);
        $this->assertSame(600, $resend2['expires_in']);

        $newCode = $_SESSION['pending_booking_verification']['code'];
        $this->assertSame(6, strlen($newCode));
    }

    public function testUpdateEmailUpdatesRecipientAndGeneratesNewCode(): void {
        $params = [
            'client_name'    => 'Dale Barile',
            'client_email'   => 'barildale@gmail.com', // initial typo
            'client_phone'   => '09171234567',
            'client_address' => 'Makati City',
            'event_title'    => 'Debut Event',
            'event_type'     => 'Kids Party',
            'event_date'     => date('Y-m-d', strtotime('+20 days')),
            'location_venue' => 'Manila Hotel'
        ];

        BookingVerificationHelper::stageBooking($this->conn, $params);
        $token = $_SESSION['pending_booking_verification']['token'];

        // Fast-forward cooldown by 15 seconds
        $_SESSION['pending_booking_verification']['last_resend_at'] = time() - 15;

        // Correct the typo: barildale -> bariledale
        $correctedEmail = 'bariledale@gmail.com';
        $res = BookingVerificationHelper::updateEmail($this->conn, $correctedEmail, $token);

        $this->assertTrue($res['success']);
        $this->assertSame($correctedEmail, $res['email']);
        $this->assertSame($correctedEmail, $_SESSION['pending_booking_verification']['email']);
        $this->assertSame($correctedEmail, $_SESSION['pending_booking_verification']['payload']['client_email']);
        $this->assertSame(6, strlen($_SESSION['pending_booking_verification']['code']));
    }

    public function testUpdateEmailRejectsInvalidEmail(): void {
        $params = [
            'client_name'    => 'Dale Barile',
            'client_email'   => 'barildale@gmail.com',
            'client_phone'   => '09171234567',
            'client_address' => 'Makati City',
            'event_title'    => 'Debut Event',
            'event_type'     => 'Kids Party',
            'event_date'     => date('Y-m-d', strtotime('+20 days')),
            'location_venue' => 'Manila Hotel'
        ];

        BookingVerificationHelper::stageBooking($this->conn, $params);
        $token = $_SESSION['pending_booking_verification']['token'];
        $_SESSION['pending_booking_verification']['last_resend_at'] = time() - 15;

        $res = BookingVerificationHelper::updateEmail($this->conn, 'not-an-email', $token);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('valid email', $res['message']);
    }
}
