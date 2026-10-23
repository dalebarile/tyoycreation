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
        $this->assertSame(300, $res['expires_in']);

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
        $this->assertSame(300, $resend2['expires_in']);

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

    public function test_stage_booking_handles_email_delivery_failure_without_false_success(): void
    {
        $GLOBALS['TEST_MOCK_EMAIL_FAILURE'] = 'Connection timed out to smtp.gmail.com:587';
        try {
            $params = [
                'client_name'    => 'Failure Test User',
                'client_email'   => 'failuser@gmail.com',
                'client_phone'   => '09171234567',
                'client_address' => 'Quezon City',
                'event_title'    => 'Birthday Celebration',
                'event_type'     => 'Kids Party',
                'event_date'     => date('Y-m-d', strtotime('+25 days')),
                'location_venue' => 'Clubhouse'
            ];

            $res = BookingVerificationHelper::stageBooking($this->conn, $params);

            // Staging succeeds so data is not lost, but email_sent is accurately reported as false
            $this->assertTrue($res['success']);
            $this->assertFalse($res['email_sent']);
            $this->assertNotEmpty($res['email_error']);
            $this->assertStringContainsString('could not deliver the verification code', $res['message']);

            // Session preserves pending state so customer can edit or resend
            $this->assertArrayHasKey('pending_booking_verification', $_SESSION);
            $this->assertTrue($_SESSION['pending_booking_verification']['last_send_failed']);
        } finally {
            unset($GLOBALS['TEST_MOCK_EMAIL_FAILURE']);
        }
    }

    public function test_resend_code_does_not_corrupt_existing_code_on_email_failure(): void
    {
        $params = [
            'client_name'    => 'Resend Guard User',
            'client_email'   => 'resendguard@gmail.com',
            'client_phone'   => '09171234567',
            'client_address' => 'Pasig City',
            'event_title'    => 'Debut Celebration',
            'event_type'     => 'Kids Party',
            'event_date'     => date('Y-m-d', strtotime('+30 days')),
            'location_venue' => 'Ballroom'
        ];

        // 1. Stage initial booking successfully
        BookingVerificationHelper::stageBooking($this->conn, $params);
        $initialCode = $_SESSION['pending_booking_verification']['code'];
        $token       = $_SESSION['pending_booking_verification']['token'];
        $this->assertNotEmpty($initialCode);

        // Fast-forward initial cooldown
        $_SESSION['pending_booking_verification']['last_resend_at'] = time() - 65;

        // 2. Simulate SMTP delivery failure during resend
        $GLOBALS['TEST_MOCK_EMAIL_FAILURE'] = 'Recipient mailbox unavailable';
        try {
            $res = BookingVerificationHelper::resendCode($this->conn, $token);

            // Must NOT report success when email failed
            $this->assertFalse($res['success']);
            $this->assertFalse($res['email_sent']);
            $this->assertStringContainsString('Unable to deliver verification code', $res['message']);

            // The original working code must NOT be destroyed or overwritten
            $this->assertSame($initialCode, $_SESSION['pending_booking_verification']['code']);
            $this->assertTrue($_SESSION['pending_booking_verification']['last_send_failed']);
        } finally {
            unset($GLOBALS['TEST_MOCK_EMAIL_FAILURE']);
        }
    }

    public function test_update_email_does_not_commit_state_on_email_failure(): void
    {
        $params = [
            'client_name'    => 'Update Guard User',
            'client_email'   => 'original@gmail.com',
            'client_phone'   => '09171234567',
            'client_address' => 'Taguig City',
            'event_title'    => 'Corporate Gala',
            'event_type'     => 'Kids Party',
            'event_date'     => date('Y-m-d', strtotime('+40 days')),
            'location_venue' => 'BGC Hall'
        ];

        BookingVerificationHelper::stageBooking($this->conn, $params);
        $originalEmail = $_SESSION['pending_booking_verification']['email'];
        $originalCode  = $_SESSION['pending_booking_verification']['code'];
        $token         = $_SESSION['pending_booking_verification']['token'];

        // Fast-forward cooldown
        $_SESSION['pending_booking_verification']['last_resend_at'] = time() - 15;

        // Simulate SMTP delivery failure when changing email
        $GLOBALS['TEST_MOCK_EMAIL_FAILURE'] = 'Domain DNS resolution failed';
        try {
            $res = BookingVerificationHelper::updateEmail($this->conn, 'newtarget@gmail.com', $token);

            $this->assertFalse($res['success']);
            $this->assertFalse($res['email_sent']);
            $this->assertStringContainsString('Failed to deliver verification code', $res['message']);

            // Session email and code remain intact with original valid state
            $this->assertSame($originalEmail, $_SESSION['pending_booking_verification']['email']);
            $this->assertSame($originalCode, $_SESSION['pending_booking_verification']['code']);
        } finally {
            unset($GLOBALS['TEST_MOCK_EMAIL_FAILURE']);
        }
    }

    public function test_stage_booking_detects_schedule_conflict_and_halts_staging(): void
    {
        $targetDate = '2026-11-20';
        $targetTime = '14:00';

        // Preload conflicting celebration in FakeConnection
        $conflictSql = "SELECT id, reference_no, event_title, event_type, event_start, event_end, location_venue, status 
                FROM bookings 
                WHERE (status = 'approved' OR status = 'completed') 
                  AND id != ? 
                  AND DATE(event_start) = ? 
                ORDER BY event_start ASC 
                LIMIT 1";

        $this->conn->addQueryResult($conflictSql, [[
            'id'             => 99,
            'reference_no'   => 'EV-2026-CONFIDENTIAL',
            'event_title'    => 'Confidential Private Wedding',
            'event_type'     => 'Weddings',
            'event_start'    => '2026-11-20 14:00:00',
            'event_end'      => '2026-11-20 19:00:00',
            'location_venue' => 'Manila Hotel',
            'status'         => 'approved'
        ]]);

        $params = [
            'client_name'          => 'Clara Bautista',
            'client_email'         => 'clara@example.com',
            'client_phone'         => '09171234567',
            'client_address'       => 'Makati City',
            'event_title'          => 'Clara Debut Celebration',
            'event_type'           => 'Kids Party',
            'event_date'           => $targetDate,
            'event_time'           => $targetTime,
            'guest_count'          => 75,
            'location_venue'       => 'Makati Sports Club',
            'service_requirements' => 'Floral Arch & Stage Decor'
        ];

        $res = BookingVerificationHelper::stageBooking($this->conn, $params);

        // 1. Must halt staging and return conflict flag
        $this->assertFalse($res['success']);
        $this->assertTrue($res['conflict']);
        $this->assertTrue($res['is_conflict']);
        $this->assertSame('schedule_overlap', $res['conflict_type']);
        $this->assertSame($targetDate, $res['event_date']);
        $this->assertSame($targetTime, $res['event_time']);
        $this->assertSame('November 20, 2026', $res['friendly_date']);
        $this->assertStringContainsString('already reserved', $res['message']);

        // 2. Data Privacy Act (RA 10173): Conflict message must NEVER expose conflicting party details
        $this->assertStringNotContainsString('Confidential Private Wedding', $res['message']);
        $this->assertStringNotContainsString('EV-2026-CONFIDENTIAL', $res['message']);

        // 3. OTP verification session MUST NOT be staged when double-booking is detected
        $this->assertArrayNotHasKey('pending_booking_verification', $_SESSION);
    }

    public function test_stage_booking_detects_pending_booking_conflict_and_halts_staging(): void
    {
        $targetDate = '2026-12-15';
        $targetTime = '10:00';

        $conflictSql = "SELECT id FROM bookings 
                WHERE status IN ('pending', 'approved') 
                  AND id != ? 
                  AND DATE(event_start) = ? 
                LIMIT 1";

        // Conflict is a 'pending' booking
        $this->conn->addQueryResult($conflictSql, [[
            'id' => 101
        ]]);

        $params = [
            'client_name'          => 'Eduardo Ramos',
            'client_email'         => 'eduardo@example.com',
            'client_phone'         => '09181234567',
            'client_address'       => 'Pasig City',
            'event_title'          => 'Eduardo Corporate Gala',
            'event_type'           => 'Corporate Events',
            'event_date'           => $targetDate,
            'event_time'           => $targetTime,
            'guest_count'          => 100,
            'location_venue'       => 'Megamall Event Center',
            'service_requirements' => 'Full Stage Setup'
        ];

        $res = BookingVerificationHelper::stageBooking($this->conn, $params);

        $this->assertFalse($res['success']);
        $this->assertFalse($res['available']);
        $this->assertTrue($res['conflict']);
        $this->assertTrue($res['is_conflict']);
        $this->assertSame('DATE_CONFLICT', $res['code']);
        $this->assertSame(
            'Sorry, the date and time you selected is already reserved. Please choose a different date or time, or contact us if you need help.',
            $res['message']
        );

        // OTP verification session MUST NOT be created
        $this->assertArrayNotHasKey('pending_booking_verification', $_SESSION);
    }

    public function test_stage_booking_detects_approved_booking_conflict_and_halts_staging(): void
    {
        $targetDate = '2026-12-20';
        $targetTime = '15:00';

        $conflictSql = "SELECT id FROM bookings 
                WHERE status IN ('pending', 'approved') 
                  AND id != ? 
                  AND DATE(event_start) = ? 
                LIMIT 1";

        // Conflict is an 'approved' booking
        $this->conn->addQueryResult($conflictSql, [[
            'id' => 102
        ]]);

        $params = [
            'client_name'          => 'Beatriz Mendoza',
            'client_email'         => 'beatriz@example.com',
            'client_phone'         => '09191234567',
            'client_address'       => 'Taguig City',
            'event_title'          => 'Beatriz Wedding Reception',
            'event_type'           => 'Weddings',
            'event_date'           => $targetDate,
            'event_time'           => $targetTime,
            'guest_count'          => 150,
            'location_venue'       => 'BGC High Street Lounge',
            'service_requirements' => 'Floral Styling & Lighting'
        ];

        $res = BookingVerificationHelper::stageBooking($this->conn, $params);

        $this->assertFalse($res['success']);
        $this->assertFalse($res['available']);
        $this->assertTrue($res['conflict']);
        $this->assertTrue($res['is_conflict']);
        $this->assertSame('DATE_CONFLICT', $res['code']);
        $this->assertSame(
            'Sorry, the date and time you selected is already reserved. Please choose a different date or time, or contact us if you need help.',
            $res['message']
        );

        // OTP verification session MUST NOT be created
        $this->assertArrayNotHasKey('pending_booking_verification', $_SESSION);
    }

    public function test_conflict_response_never_leaks_pii_or_status(): void
    {
        $targetDate = '2026-11-20';
        $targetTime = '14:00';

        // Preload conflicting celebration containing sensitive private information
        $conflictSql = "SELECT id FROM bookings 
                WHERE status IN ('pending', 'approved') 
                  AND id != ? 
                  AND DATE(event_start) = ? 
                LIMIT 1";

        $this->conn->addQueryResult($conflictSql, [[
            'id'             => 99,
            'reference_no'   => 'EV-2026-CONFIDENTIAL',
            'event_title'    => 'Confidential Private Wedding',
            'client_name'    => 'Secret VIP Client',
            'client_email'   => 'secret@vip.com',
            'client_phone'   => '09179999999',
            'location_venue' => 'Exclusive Luxury Resort',
            'status'         => 'pending'
        ]]);

        $params = [
            'client_name'          => 'Clara Bautista',
            'client_email'         => 'clara@example.com',
            'client_phone'         => '09171234567',
            'client_address'       => 'Makati City',
            'event_title'          => 'Clara Debut Celebration',
            'event_type'           => 'Kids Party',
            'event_date'           => $targetDate,
            'event_time'           => $targetTime,
            'guest_count'          => 75,
            'location_venue'       => 'Makati Sports Club',
            'service_requirements' => 'Floral Arch & Stage Decor'
        ];

        $res = BookingVerificationHelper::stageBooking($this->conn, $params);

        $this->assertFalse($res['success']);
        $msg = $res['message'];

        // Strict assertions that NO existing booking details or status are leaked
        $this->assertStringNotContainsString('Secret VIP Client', $msg);
        $this->assertStringNotContainsString('secret@vip.com', $msg);
        $this->assertStringNotContainsString('09179999999', $msg);
        $this->assertStringNotContainsString('EV-2026-CONFIDENTIAL', $msg);
        $this->assertStringNotContainsString('Confidential Private Wedding', $msg);
        $this->assertStringNotContainsString('Exclusive Luxury Resort', $msg);
        $this->assertStringNotContainsString('pending', strtolower($msg));
        $this->assertStringNotContainsString('approved', strtolower($msg));
        $this->assertStringNotContainsString('EV-', $msg);

        $this->assertSame(
            'Sorry, the date and time you selected is already reserved. Please choose a different date or time, or contact us if you need help.',
            $msg
        );
    }

    public function test_verify_booking_rechecks_conflict_before_db_commit(): void
    {
        $targetDate = date('Y-m-d', strtotime('+45 days'));
        $targetTime = '16:00';

        // 1. Stage a booking when the date is clear (no conflict)
        $params = [
            'client_name'    => 'Race Condition Test',
            'client_email'   => 'race@example.com',
            'client_phone'   => '09171234567',
            'event_title'    => 'Race Birthday Party',
            'event_type'     => 'Kids Party',
            'event_date'     => $targetDate,
            'event_time'     => $targetTime,
            'location_venue' => 'Quezon City Hall'
        ];

        $stageRes = BookingVerificationHelper::stageBooking($this->conn, $params);
        $this->assertTrue($stageRes['success']);
        $code  = $_SESSION['pending_booking_verification']['code'];
        $token = $_SESSION['pending_booking_verification']['token'];

        // 2. Now simulate a conflicting booking having been approved while user had OTP open
        $conflictSql = "SELECT id FROM bookings 
                WHERE status IN ('pending', 'approved') 
                  AND id != ? 
                  AND DATE(event_start) = ? 
                LIMIT 1";

        $this->conn->addQueryResult($conflictSql, [[
            'id' => 888
        ]]);

        // 3. User enters correct code and submits verification
        $verifyRes = BookingVerificationHelper::verifyBooking($this->conn, $code, $token);

        // 4. Must detect conflict and reject WITHOUT committing
        $this->assertFalse($verifyRes['success']);
        $this->assertFalse($verifyRes['available']);
        $this->assertTrue($verifyRes['conflict']);
        $this->assertSame('DATE_CONFLICT', $verifyRes['code']);
        $this->assertSame(
            'Sorry, the date and time you selected is already reserved. Please choose a different date or time, or contact us if you need help.',
            $verifyRes['message']
        );

        // Assert no INSERT query was performed
        $dbHasInsert = false;
        foreach ($this->conn->getPreparedStatements() as $stmt) {
            if (stripos($stmt->getSql(), 'INSERT INTO bookings') !== false) {
                $dbHasInsert = true;
                break;
            }
        }
        $this->assertFalse($dbHasInsert, 'Database must NOT commit booking if conflict arose before verification.');
    }
}

