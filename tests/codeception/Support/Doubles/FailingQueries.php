<?php

namespace Tests\Support\Doubles;

/**
 * Makes the queries that match a pattern fail, so a test can prove how the
 * code reacts to a failed write.
 *
 * wpdb prints and logs every error the database returns, so a real SQL error
 * would put a line in the test output. Emptying the query makes wpdb::query()
 * return false without a round trip, which is what the code sees when a write
 * fails. Register it in the test and always call `remove()` in a finally block.
 */
class FailingQueries
{
    /** @var string */
    private $pattern;

    /** @var int */
    private $limit;

    /** @var int */
    private $hits = 0;

    private function __construct(string $pattern, int $limit)
    {
        $this->pattern = $pattern;
        $this->limit   = $limit;
    }

    /**
     * Fails matching queries from now on.
     *
     * @param string $pattern Regular expression tested against each query.
     * @param int    $limit   Number of matching queries to fail. 0 fails every match.
     */
    public static function matching(string $pattern, int $limit = 0): self
    {
        return (new self($pattern, $limit))->add();
    }

    /**
     * Builds the failure without starting it, for a test that starts it later.
     */
    public static function prepared(string $pattern, int $limit = 0): self
    {
        return new self($pattern, $limit);
    }

    public function add(): self
    {
        add_filter('query', $this, 10, 1);

        return $this;
    }

    public function __invoke($query)
    {
        if (($this->limit === 0 || $this->hits < $this->limit) && preg_match($this->pattern, $query)) {
            $this->hits++;

            return '';
        }

        return $query;
    }

    public function hits(): int
    {
        return $this->hits;
    }

    public function remove(): void
    {
        remove_filter('query', $this, 10);
    }
}
