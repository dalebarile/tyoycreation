<?php
declare(strict_types=1);

namespace Qes\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qes\Tests\Fakes\FakeConnection;

class BookingServiceTest extends TestCase
{
    private FakeConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = new FakeConnection();
    }

    public function test_fails_when_required_fields_missing(): void
    {
        $result = create_booking_inquiry($this->conn, [
            'client_name' => '',
            'client_email' => 'juan@example.com'
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('required fields', $result['message']);
    }

    public function test_fails_on_invalid_email(): void
    {
        $result = create_booking_inquiry($this->conn, [
            'client_name'    => 'Juan dela Cruz',
            'client_email'   => 'invalid-email-address',
            'client_phone'   => '09171234567',
            'event_title'    => 'Debut Celebration',
            'event_type'     => 'Theme Party',
            'event_date'     => date('Y-m-d', strtotime('+30 days')),
            'location_venue' => 'Manila Grand Hotel'
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('valid Email Address', $result['message']);
    }

    public function test_fails_on_invalid_contact_number(): void
    {
        $result = create_booking_inquiry($this->conn, [
            'client_name'    => 'Juan dela Cruz',
            'client_email'   => 'juan@example.com',
            'client_phone'   => '0912abc345',
            'event_title'    => 'Debut Celebration',
            'event_type'     => 'Theme Party',
            'event_date'     => date('Y-m-d', strtotime('+30 days')),
            'location_venue' => 'Manila Grand Hotel'
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('valid Contact Number', $result['message']);
    }

    public function test_fails_when_guest_count_exceeds_catering_package(): void
    {
        $result = create_booking_inquiry($this->conn, [
            'client_name'          => 'Juan dela Cruz',
            'client_email'         => 'juan@example.com',
            'client_phone'         => '09171234567',
            'event_title'          => 'Company Dinner',
            'event_type'           => 'Corporate Events',
            'event_date'           => date('Y-m-d', strtotime('+30 days')),
            'guest_count'          => 150,
            'location_venue'       => 'Makati Diamond',
            'service_requirements' => 'Catering Service (100 pax): ₱85,000'
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('exceeds your selected Catering Service package limit', $result['message']);
    }

    public function test_fails_when_date_is_in_the_past(): void
    {
        // Dates in the past cannot be booked
        $pastDate = date('Y-m-d', strtotime('-5 days'));
        $result = create_booking_inquiry($this->conn, [
            'client_name'          => 'Maria Santos',
            'client_email'         => 'maria@example.com',
            'client_phone'         => '09181234567',
            'client_address'       => 'Quezon City',
            'event_title'          => 'Maria & John Wedding',
            'event_type'           => 'Weddings',
            'event_date'           => $pastDate,
            'event_time'           => '14:00',
            'guest_count'          => 100,
            'location_venue'       => 'Tagaytay Highlands',
            'service_requirements' => 'Full Wedding Styling & Coordination'
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cannot be in the past', $result['message']);
    }

    public function test_allows_dates_within_six_months(): void
    {
        $this->conn->insert_id = 998;
        $this->conn->addQueryResult("SELECT id FROM bookings WHERE reference_no = ?", []);

        // With restrictions removed, weddings can be scheduled within 6 months (e.g. +30 days)
        $flexibleDate = date('Y-m-d', strtotime('+30 days'));
        $result = create_booking_inquiry($this->conn, [
            'client_name'          => 'Maria Santos',
            'client_email'         => 'maria@example.com',
            'client_phone'         => '09181234567',
            'client_address'       => 'Quezon City',
            'event_title'          => 'Maria & John Wedding',
            'event_type'           => 'Weddings',
            'event_date'           => $flexibleDate,
            'event_time'           => '14:00',
            'guest_count'          => 100,
            'location_venue'       => 'Tagaytay Highlands',
            'service_requirements' => 'Full Wedding Styling & Coordination',
            'special_notes'        => 'Outdoor garden ceremony',
            'source'               => 'online_inquiry',
            'send_notice'          => 'none'
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(998, $result['booking_id']);
    }

    public function test_successful_booking_creation(): void
    {
        $this->conn->insert_id = 999;
        // Mock uniqueness query: return empty result so reference_no is accepted
        $this->conn->addQueryResult("SELECT id FROM bookings WHERE reference_no = ?", []);

        // Weddings require 6 months, so +7 months is safely valid
        $validDate = date('Y-m-d', strtotime('+7 months'));
        $result = create_booking_inquiry($this->conn, [
            'client_name'          => 'Maria Santos',
            'client_email'         => 'maria@example.com',
            'client_phone'         => '09181234567',
            'client_address'       => 'Quezon City',
            'event_title'          => 'Maria & John Wedding',
            'event_type'           => 'Weddings',
            'event_date'           => $validDate,
            'event_time'           => '14:00',
            'guest_count'          => 100,
            'location_venue'       => 'Tagaytay Highlands',
            'service_requirements' => 'Full Wedding Styling & Coordination',
            'special_notes'        => 'Outdoor garden ceremony',
            'source'               => 'online_inquiry',
            'send_notice'          => 'none'
        ]);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame(999, $result['booking_id']);
        $this->assertMatchesRegularExpression('/^EV-\d{4}-\d{4}$/', $result['reference_no']);
        $this->assertSame('Maria Santos', $result['client_name']);
        $this->assertSame('Maria & John Wedding', $result['event_title']);
    }

    public function test_conflict_check_blocks_manual_overlapping_event(): void
    {
        $validDate = date('Y-m-d', strtotime('+30 days'));
        $eventStart = date('Y-m-d 14:00:00', strtotime($validDate));
        $eventEnd = date('Y-m-d 19:00:00', strtotime($validDate));

        // Simulate an existing approved booking conflict in the fake database
        $this->conn->addQueryResult("status = 'approved'", [
            [
                'id' => 12,
                'reference_no' => 'EV-2026-1234',
                'event_title' => 'Prior Confirmed Gala',
                'event_type' => 'Corporate Events',
                'event_start' => $eventStart,
                'event_end' => $eventEnd,
                'location_venue' => 'Grand Ballroom',
                'status' => 'approved'
            ]
        ]);

        $result = create_booking_inquiry($this->conn, [
            'client_name'          => 'Director Reyes',
            'client_email'         => 'reyes@example.com',
            'client_phone'         => '09201234567',
            'event_title'          => 'Annual Gala Dinner',
            'event_type'           => 'Corporate Events',
            'event_date'           => $validDate,
            'event_time'           => '14:00',
            'guest_count'          => 80,
            'location_venue'       => 'Grand Ballroom',
            'service_requirements' => 'Stage & Audio Lighting',
            'status'               => 'approved',
            'source'               => 'manual_entry',
            'check_conflict'       => true,
            'send_notice'          => 'none'
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Schedule Conflict', $result['message']);
    }

    public function test_update_booking_status_fails_on_missing_booking(): void
    {
        $this->conn->addQueryResult("SELECT id, reference_no, event_title, event_type, event_start, event_end, status FROM bookings WHERE id = ?", []);
        $result = update_booking_status($this->conn, 9999, 'approve', null, false);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('was not found', $result['message']);
    }

    public function test_update_booking_status_approves_when_no_conflict(): void
    {
        $this->conn->addQueryResult("SELECT id, reference_no, event_title, event_type, event_start, event_end, status FROM bookings WHERE id = ?", [
            [
                'id' => 101,
                'reference_no' => 'EV-2026-1001',
                'event_title' => 'Sample Debut Party',
                'event_type' => 'Debut & Others',
                'event_start' => '2027-01-10 14:00:00',
                'event_end' => '2027-01-10 19:00:00',
                'status' => 'pending'
            ]
        ]);
        // No conflicts
        $this->conn->addQueryResult("status = 'approved'", []);

        $result = update_booking_status($this->conn, 101, 'approve', null, false);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('APPROVED', $result['message']);
    }

    public function test_update_booking_status_rejects_with_reason(): void
    {
        $this->conn->addQueryResult("SELECT id, reference_no, event_title, event_type, event_start, event_end, status FROM bookings WHERE id = ?", [
            [
                'id' => 102,
                'reference_no' => 'EV-2026-1002',
                'event_title' => 'Overbooked Reception',
                'event_type' => 'Weddings',
                'event_start' => '2027-05-15 10:00:00',
                'event_end' => '2027-05-15 15:00:00',
                'status' => 'pending'
            ]
        ]);

        $result = update_booking_status($this->conn, 102, 'reject', 'Venue under maintenance on requested date', false);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('REJECTED', $result['message']);
    }

    public function test_conflict_check_blocks_different_times_on_same_calendar_date(): void
    {
        // Existing approved event is in morning: 09:00 to 12:00
        $this->conn->addQueryResult("WHERE (status = 'approved' OR status = 'completed')", [
            [
                'id' => 50,
                'reference_no' => 'EV-2027-0050',
                'event_title' => 'Morning Workshop',
                'event_type' => 'Corporate Events',
                'event_start' => '2027-08-20 09:00:00',
                'event_end' => '2027-08-20 12:00:00',
                'location_venue' => 'Grand Ballroom',
                'status' => 'approved'
            ]
        ]);

        // New request is in the evening: 18:00 to 22:00 (non-overlapping hours, but same calendar day)
        $conflict = check_booking_conflict($this->conn, '2027-08-20 18:00:00', '2027-08-20 22:00:00');

        $this->assertTrue($conflict['conflict']);
        $this->assertNotNull($conflict['conflicting_event']);
        $this->assertStringContainsString('Schedule Conflict', $conflict['message']);
        $this->assertStringContainsString('only one confirmed event per calendar day', $conflict['message']);
    }

    public function test_conflict_check_blocks_when_existing_is_completed(): void
    {
        // Existing event is marked completed
        $this->conn->addQueryResult("WHERE (status = 'approved' OR status = 'completed')", [
            [
                'id' => 51,
                'reference_no' => 'EV-2027-0051',
                'event_title' => 'Completed Anniversary',
                'event_type' => 'Weddings',
                'event_start' => '2027-09-10 14:00:00',
                'event_end' => '2027-09-10 19:00:00',
                'location_venue' => 'Garden Plaza',
                'status' => 'completed'
            ]
        ]);

        $conflict = check_booking_conflict($this->conn, '2027-09-10 10:00:00');

        $this->assertTrue($conflict['conflict']);
        $this->assertSame('completed', $conflict['conflicting_event']['status']);
        $this->assertStringContainsString('Schedule Conflict', $conflict['message']);
    }

    public function test_update_booking_status_cancels_and_records_reason(): void
    {
        $this->conn->addQueryResult("SELECT id, reference_no, event_title, event_type, event_start, event_end, status FROM bookings WHERE id = ?", [
            [
                'id' => 103,
                'reference_no' => 'EV-2027-1003',
                'event_title' => 'Cancelled Reception',
                'event_type' => 'Weddings',
                'event_start' => '2027-10-12 15:00:00',
                'event_end' => '2027-10-12 20:00:00',
                'status' => 'approved'
            ]
        ]);

        $result = update_booking_status($this->conn, 103, 'cancel', 'Client requested event cancellation', false);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('CANCELLED', $result['message']);
    }

    public function test_update_booking_status_fails_approval_when_conflict_exists(): void
    {
        $this->conn->addQueryResult("SELECT id, reference_no, event_title, event_type, event_start, event_end, status FROM bookings WHERE id = ?", [
            [
                'id' => 104,
                'reference_no' => 'EV-2027-1004',
                'event_title' => 'Pending Debut Inquiry',
                'event_type' => 'Theme Party',
                'event_start' => '2027-11-05 16:00:00',
                'event_end' => '2027-11-05 21:00:00',
                'status' => 'pending'
            ]
        ]);

        // There is already an approved event on 2027-11-05
        $this->conn->addQueryResult("WHERE (status = 'approved' OR status = 'completed')", [
            [
                'id' => 99,
                'reference_no' => 'EV-2027-0099',
                'event_title' => 'Already Confirmed Gala',
                'event_type' => 'Corporate Events',
                'event_start' => '2027-11-05 08:00:00',
                'event_end' => '2027-11-05 12:00:00',
                'location_venue' => 'Manila Grand Hotel',
                'status' => 'approved'
            ]
        ]);

        $result = update_booking_status($this->conn, 104, 'approve', null, false);
        $this->assertFalse($result['success']);
        $this->assertTrue($result['conflict'] ?? false);
        $this->assertStringContainsString('Schedule Conflict', $result['message']);
    }
}


