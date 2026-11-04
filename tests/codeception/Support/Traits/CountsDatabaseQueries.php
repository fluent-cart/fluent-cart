<?php

namespace Tests\Support\Traits;

/**
 * Records the SQL a block of code issues, so a test can assert that a query
 * count stays flat as the data grows — the only reliable way to pin an N+1
 * shut. Counting the raw total is deliberately avoided: it moves whenever an
 * unrelated query is added or removed, which makes the test noise rather than
 * a specification.
 *
 * Uses the `query` filter rather than SAVEQUERIES so it works regardless of
 * how the test bootstrap defines its constants.
 */
trait CountsDatabaseQueries
{
    /**
     * Run $callback and return every SQL statement it issued.
     *
     * @param callable $callback
     * @return array<int, string>
     */
    protected function recordQueries(callable $callback): array
    {
        $queries = [];

        $recorder = function ($sql) use (&$queries) {
            $queries[] = $sql;
            return $sql;
        };

        add_filter('query', $recorder, 1);

        try {
            $callback();
        } finally {
            remove_filter('query', $recorder, 1);
        }

        return $queries;
    }

    /**
     * How many of the recorded statements contain every given fragment.
     *
     * Fragments are matched case-insensitively against whitespace-collapsed
     * SQL, so a caller can write `['from wp_posts', 'where id =']` without
     * caring how the builder wrapped or indented the statement.
     *
     * @param array<int, string> $queries
     * @param array<int, string> $fragments
     * @return int
     */
    protected function countQueriesContaining(array $queries, array $fragments): int
    {
        $matches = 0;

        foreach ($queries as $sql) {
            $normalized = strtolower((string) preg_replace('/\s+/', ' ', $sql));

            foreach ($fragments as $fragment) {
                if (strpos($normalized, strtolower($fragment)) === false) {
                    continue 2;
                }
            }

            $matches++;
        }

        return $matches;
    }
}
