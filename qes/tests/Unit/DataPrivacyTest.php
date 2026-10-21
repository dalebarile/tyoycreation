<?php
declare(strict_types=1);

namespace Qes\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DataPrivacyHelper;
use Qes\Tests\Fakes\FakeConnection;

require_once __DIR__ . '/../../data_privacy_helper.php';

class DataPrivacyTest extends TestCase
{
    private FakeConnection $conn;

    protected function setUp(): void
    {
        $this->conn = new FakeConnection();
    }

    public function test_export_requires_valid_identifier(): void
    {
        $res = DataPrivacyHelper::exportDataSubjectArchive($this->conn, '   ');
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('identifier', $res['message']);
    }

    public function test_export_returns_not_found_when_no_records_match(): void
    {
        $res = DataPrivacyHelper::exportDataSubjectArchive($this->conn, 'nonexistent@example.com');
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('No personal data records found', $res['message']);
    }

    public function test_erasure_requires_valid_booking_ids(): void
    {
        $res = DataPrivacyHelper::executeErasureRequest($this->conn, []);
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('No valid booking IDs', $res['message']);
    }

    public function test_retention_purge_returns_stats(): void
    {
        $res = DataPrivacyHelper::runRetentionSchedulePurge($this->conn);
        $this->assertTrue($res['success']);
        $this->assertArrayHasKey('stats', $res);
        $this->assertArrayHasKey('rejected_purged', $res['stats']);
        $this->assertArrayHasKey('timestamp', $res['stats']);
    }
}
