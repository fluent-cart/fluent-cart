<?php
/**
 * Run-local protected-table count capture and comparison.
 */

class FcProtectedTables
{
    /**
     * @param array<int,string> $tables
     * @return array<string,int>
     */
    public static function capture(array $tables)
    {
        global $wpdb;

        $counts = [];
        foreach ($tables as $table) {
            $table = (string) $table;
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $table)) {
                throw new InvalidArgumentException(
                    'Invalid protected table identifier: ' . $table
                );
            }

            $fullTable = $wpdb->prefix . $table;
            $counts[$table] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$fullTable}`"
            );
            if ($wpdb->last_error !== '') {
                throw new RuntimeException(
                    'Protected count query failed for ' . $fullTable . ': '
                    . $wpdb->last_error
                );
            }
        }

        return $counts;
    }

    /**
     * @param array<string,int> $baseline
     * @param array<int,string> $tables
     * @return array<string,int>
     */
    public static function assertUnchanged(array $baseline, array $tables, $context)
    {
        global $wpdb;

        $actual = self::capture($tables);
        if ($actual === $baseline) {
            return $actual;
        }

        $changes = [];
        foreach ($tables as $table) {
            $start = array_key_exists($table, $baseline)
                ? (string) $baseline[$table]
                : 'missing';
            $end = array_key_exists($table, $actual)
                ? (string) $actual[$table]
                : 'missing';
            if ($start !== $end) {
                $changes[] = sprintf(
                    '%s%s start=%s end=%s',
                    $wpdb->prefix,
                    $table,
                    $start,
                    $end
                );
            }
        }

        throw new RuntimeException(
            'PROTECTED TABLE CHANGED during ' . $context . ': '
            . implode('; ', $changes)
        );
    }

    /**
     * @param array<string,int> $counts
     * @return string
     */
    public static function format(array $counts)
    {
        global $wpdb;

        $parts = [];
        foreach ($counts as $table => $count) {
            $parts[] = $wpdb->prefix . $table . '=' . $count;
        }

        return implode(' ', $parts);
    }
}
