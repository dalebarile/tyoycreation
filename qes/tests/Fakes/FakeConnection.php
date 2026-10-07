<?php
/**
 * Fake database connection for tests — mirrors SupabaseConnection / mysqli.
 *
 * Pre-load expected query results, then pass this into any function that
 * takes a $conn and calls prepare() / bind_param() / execute() / get_result().
 *
 * Usage:
 *   $conn = new FakeConnection();
 *   $conn->addQueryResult(
 *       "SELECT id, reference_no FROM bookings WHERE status = 'approved'",
 *       [['id' => 1, 'reference_no' => 'EV-2026-0001']]
 *   );
 */

declare(strict_types=1);

namespace Qes\Tests\Fakes;

class FakeConnection
{
    public int $insert_id = 0;
    public int $affected_rows = 0;
    public string $error = '';
    public int $errno = 0;
    public ?string $connect_error = null;

    /**
     * Map of SQL pattern → rows to return.
     * Keys are normalized (trimmed, collapsed whitespace) SQL strings.
     *
     * @var array<string, array<int, array<string, mixed>>|false>
     */
    private array $queryResults = [];

    /**
     * Log of all prepared statements for inspection/assertion.
     *
     * @var array<int, FakeStatement>
     */
    private array $preparedStatements = [];

    /**
     * Register the rows that should be returned for a given SQL query.
     * The SQL is normalized (trimmed, whitespace collapsed) for matching.
     *
     * Pass `false` instead of rows to simulate a query failure.
     *
     * @param string $sql
     * @param array<int, array<string, mixed>>|false $rows
     */
    public function addQueryResult(string $sql, array|false $rows): void
    {
        $this->queryResults[$this->normalizeKey($sql)] = $rows;
    }

    /**
     * Look up the pre-loaded result for a SQL query.
     * Returns the row array, `false` for configured failure, or `false` if nothing was registered.
     *
     * @internal Called by FakeStatement::execute()
     */
    public function getQueryResult(string $sql): array|false
    {
        $key = $this->normalizeKey($sql);

        // Exact match first
        if (array_key_exists($key, $this->queryResults)) {
            return $this->queryResults[$key];
        }

        // Substring/contains match (useful when the test doesn't care about the full query)
        foreach ($this->queryResults as $pattern => $rows) {
            if (str_contains($key, $pattern) || str_contains($pattern, $key)) {
                return $rows;
            }
        }

        // Default: empty result set (no rows) rather than failing
        return [];
    }

    public function prepare(string $query): FakeStatement|false
    {
        $stmt = new FakeStatement($this, $query);
        $this->preparedStatements[] = $stmt;
        return $stmt;
    }

    public function set_charset(string $charset): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    /**
     * @return array<int, FakeStatement>
     */
    public function getPreparedStatements(): array
    {
        return $this->preparedStatements;
    }

    /**
     * Returns the last prepared statement (handy for single-query tests).
     */
    public function getLastStatement(): ?FakeStatement
    {
        return end($this->preparedStatements) ?: null;
    }

    /**
     * Normalize SQL for matching: trim + collapse whitespace.
     */
    private function normalizeKey(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql));
    }
}
