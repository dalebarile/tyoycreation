<?php
/**
 * Fake result set for tests — mirrors SupabaseResult / mysqli_result.
 */

declare(strict_types=1);

namespace Qes\Tests\Fakes;

class FakeResult
{
    /** @var int */
    public int $num_rows;

    /** @var array<int, array<string, mixed>> */
    private array $rows;

    private int $cursor = 0;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
        $this->num_rows = count($rows);
    }

    public function fetch_assoc(): ?array
    {
        if ($this->cursor < $this->num_rows) {
            return $this->rows[$this->cursor++];
        }
        return null;
    }

    public function free(): void {}
    public function close(): void {}
}
