<?php
/**
 * Smoke test — proves PHPUnit is wired up and the Fakes are loadable.
 */

declare(strict_types=1);

namespace Qes\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qes\Tests\Fakes\FakeConnection;
use Qes\Tests\Fakes\FakeStatement;
use Qes\Tests\Fakes\FakeResult;

class SmokeTest extends TestCase
{
    public function test_phpunit_is_working(): void
    {
        $this->assertTrue(true, 'PHPUnit runs successfully');
    }

    public function test_timezone_is_manila(): void
    {
        $this->assertSame('Asia/Manila', date_default_timezone_get());
    }

    public function test_fake_connection_returns_preloaded_rows(): void
    {
        $conn = new FakeConnection();
        $conn->addQueryResult(
            "SELECT id, name FROM users WHERE id = ?",
            [['id' => 1, 'name' => 'Alice']]
        );

        $stmt = $conn->prepare("SELECT id, name FROM users WHERE id = ?");
        $this->assertNotFalse($stmt);

        $id = 1;
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $result = $stmt->get_result();
        $this->assertSame(1, $result->num_rows);

        $row = $result->fetch_assoc();
        $this->assertSame('Alice', $row['name']);

        // Second fetch returns null
        $this->assertNull($result->fetch_assoc());
    }

    public function test_fake_connection_returns_empty_for_unknown_query(): void
    {
        $conn = new FakeConnection();
        $stmt = $conn->prepare("SELECT * FROM unknown_table");
        $stmt->execute();
        $result = $stmt->get_result();
        $this->assertSame(0, $result->num_rows);
    }
}
