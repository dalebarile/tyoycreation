<?php
/**
 * Fake prepared statement for tests — mirrors SupabaseStatement / mysqli_stmt.
 *
 * Usage in tests:
 *   $conn = new FakeConnection();
 *   $conn->addQueryResult('SELECT ...', [['id' => 1, 'name' => 'Alice']]);
 *   $stmt = $conn->prepare('SELECT ...');
 *   $stmt->bind_param('i', $id);
 *   $stmt->execute();
 *   $result = $stmt->get_result();
 */

declare(strict_types=1);

namespace Qes\Tests\Fakes;

class FakeStatement
{
    private FakeConnection $conn;
    private string $sql;

    /** @var array<int, mixed> */
    private array $params = [];

    private ?FakeResult $result = null;

    public int $num_rows = 0;
    public int $insert_id = 0;
    public int $affected_rows = 0;
    public string $error = '';
    public int $errno = 0;

    public function __construct(FakeConnection $conn, string $sql)
    {
        $this->conn = $conn;
        $this->sql = $sql;
    }

    /**
     * Captures bound parameters (types string is ignored in the fake).
     */
    public function bind_param(string $types, mixed &...$params): bool
    {
        $this->params = [];
        foreach ($params as &$p) {
            $this->params[] = &$p;
        }
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $rows = $this->conn->getQueryResult($this->sql);

        if ($rows === false) {
            $this->error = 'No fake result configured for: ' . $this->sql;
            $this->errno = 9999;
            return false;
        }

        $this->result = new FakeResult($rows);
        $this->num_rows = $this->result->num_rows;
        $this->insert_id = $this->conn->insert_id;
        $this->affected_rows = count($rows);
        return true;
    }

    public function get_result(): FakeResult|false
    {
        return $this->result ?? new FakeResult([]);
    }

    public function close(): bool
    {
        return true;
    }

    /**
     * @return string The raw SQL this statement was prepared with.
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * @return array<int, mixed> The bound parameter values.
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
