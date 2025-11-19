<?php

namespace FluentCart\Framework\Database;

use RuntimeException;
use FluentCart\Framework\Support\Helper;
use FluentCart\Framework\Database\Schema;
use FluentCart\Framework\Support\MacroableTrait;
use FluentCart\Framework\Database\Query\Expression;

abstract class BaseGrammar
{
    use MacroableTrait;

    /**
     * Cache of aliased table names.
     * 
     * @var array
     */
    protected $alias = [];

    /**
     * The connection used for escaping values.
     *
     * @var \FluentCart\Framework\Database\ConnectionInterface
     */
    protected $connection;

    /**
     * The base prefix frpm $wpdb.
     *
     * @var string
     */
    protected $basePrefix = '';

    /**
     * The grammar table prefix.
     *
     * @var string
     */
    protected $tablePrefix = '';

    /**
     * Check if the given table is a built-in table.
     * 
     * @param  string  $table
     * @return boolean
     */
    public function isAliasedTable($table)
    {
        return in_array("`$table`", $this->alias);
    }

    /**
     * Wrap an array of values.
     *
     * @param  array  $values
     * @return array
     */
    public function wrapArray(array $values)
    {
        return array_map([$this, 'wrap'], $values);
    }

    /**
     * Wrap a value in keyword identifiers.
     *
     * @param  \FluentCart\Framework\Database\Query\Expression|string  $value
     * @return string
     */
    public function wrap($value)
    {
        if ($this->isExpression($value)) {
            return $this->getValue($value);
        }

        // If the value being wrapped has a column alias we will need to separate // out the pieces so we can wrap each of the segments of the
        // expression on its own, and then join these both
        // back together using the "as" connector.
        if (stripos($value, ' as ') !== false) {
            return $this->wrapAliasedValue($value);
        }

        // If the given value is a JSON selector we will wrap it differently than a
        // traditional value. We will need to split this path and wrap each part
        // wrapped, etc. Otherwise, we will simply wrap the value as a string.
        if ($this->isJsonSelector($value)) {
            return $this->wrapJsonSelector($value);
        }

        return $this->wrapSegments(explode('.', $value));
    }

    /**
     * Wrap a table in keyword identifiers.
     *
     * @param  \FluentCart\Framework\Database\Query\Expression|string  $table
     * @return string
     */
    public function wrapTable($table)
    {
        if ($this->isExpression($table)) {
            return $this->getValue($table);
        }

        // If the table being wrapped has an alias we'll need to separate the pieces
        // so we can prefix the table and then wrap each of the segments on their
        // own and then join these both back together using the "as" connector.
        if (stripos($table, ' as ') !== false) {
            return $this->wrapAliasedTable($table);
        }

        $tablePrefix = $this->resolveTablePrefix($table);

        // If the table being wrapped has a custom schema name specified, we need to
        // prefix the last segment as the table name then wrap each segment alone
        // and eventually join them both back together using the dot connector.
        if (str_contains($table, '.')) {
            $table = substr_replace(
                $table, '.' . $tablePrefix, strrpos($table, '.'), 1
            );

            return Helper::collect(explode('.', $table))
                ->map(function($value) {
                    return $this->wrapValue($value);
                })
                ->implode('.');
        }

        if ($this->isAliasedTable($table)) {
            return $table;
        }

        return $this->wrapValue($tablePrefix.$table);
    }

    /**
     * Resolve the table prefix based on the table name.
     * 
     * @param  string $table
     * @return string
     */
    protected function resolveTablePrefix($table)
    {
        if ($this->isAliasedTable($table)) {
            return '';
        }
        
        if (!is_multisite()) {
            return $this->tablePrefix;
        }

        $sharedTables = [
            'users',
            'usermeta',
            'site',
            'sitemeta',
            'blogs',
            'blog_versions',
            'registration_log',
            'signups',
        ];

        return in_array(
            $table, $sharedTables
        ) ? $this->basePrefix : $this->tablePrefix;
    }

    /**
     * Wrap a value that has an alias.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapAliasedValue($value)
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        return $this->wrap(
            $segments[0]
        ) . ' as ' . $this->wrapValue($segments[1]);
    }

    /**
     * Wrap a table that has an alias.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapAliasedTable($value)
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        $this->alias[] = $alias = $this->wrapValue($segments[1]);

        return $this->wrapTable($segments[0]) . ' as ' . $alias;
    }

    /**
     * Wrap the given value segments.
     *
     * @param  array  $segments
     * @return string
     */
    protected function wrapSegments($segments)
    {
        return Helper::collect($segments)->map(function (
            $segment, $key
        ) use ($segments) {
            return $key == 0 && count($segments) > 1
                            ? $this->wrapTable($segment)
                            : $this->wrapValue($segment);
        })->implode('.');
    }

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        if ($value !== '*') {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    /**
     * Wrap the given JSON selector.
     *
     * @param  string  $value
     * @return string
     *
     * @throws \RuntimeException
     */
    protected function wrapJsonSelector($value)
    {
        throw new RuntimeException(
            'This database engine does not support JSON operations.'
        );
    }

    /**
     * Determine if the given string is a JSON selector.
     *
     * @param  string  $value
     * @return bool
     */
    protected function isJsonSelector($value)
    {
        return str_contains($value, '->');
    }

    /**
     * Convert an array of column names into a delimited string.
     *
     * @param  array  $columns
     * @return string
     */
    public function columnize(array $columns)
    {
        return implode(', ', array_map([$this, 'wrap'], $columns));
    }

    /**
     * Create query parameter place-holders for an array.
     *
     * @param  array  $values
     * @return string
     */
    public function parameterize(array $values)
    {
        return implode(', ', array_map([$this, 'parameter'], $values));
    }

    /**
     * Get the appropriate query parameter place-holder for a value.
     *
     * @param  mixed  $value
     * @return string
     */
    public function parameter($value)
    {
        return $this->isExpression($value) ? $this->getValue($value) : '?';
    }

    /**
     * Quote the given string literal.
     *
     * @param  string|array  $value
     * @return string
     */
    public function quoteString($value)
    {
        if (is_array($value)) {
            return implode(', ', array_map([$this, __FUNCTION__], $value));
        }

        return "'$value'";
    }

    /**
     * Escapes a value for safe SQL embedding.
     *
     * @param  string|float|int|bool|null  $value
     * @param  bool  $binary
     * @return string
     */
    public function escape($value, $binary = false)
    {
        if (is_null($this->connection)) {
            throw new RuntimeException(
                "The database driver's grammar implementation does not support escaping values."
            );
        }

        return $this->connection->escape($value, $binary);
    }

    /**
     * Determine if the given value is a raw expression.
     *
     * @param  mixed  $value
     * @return bool
     */
    public function isExpression($value)
    {
        return $value instanceof Expression;
    }

    /**
     * Transforms expressions to their scalar types.
     *
     * @param  \FluentCart\Framework\Database\Query\Expression|string|int|float  $expression
     * @return string|int|float
     */
    public function getValue($expression)
    {
        if ($this->isExpression($expression)) {
            return $this->getValue($expression->getValue($this));
        }

        return $expression;
    }

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Get the grammar's table prefix.
     *
     * @return string
     */
    public function getTablePrefix()
    {
        return $this->tablePrefix;
    }

    /**
     * Set the grammar's table prefix.
     *
     * @param object $wpdb WordPress database object
     * @return $this
     */
    public function setTablePrefix($wpdb)
    {
        $this->basePrefix = $wpdb->base_prefix;

        $this->tablePrefix = $wpdb->prefix;

        return $this;
    }

    /**
     * Set the grammar's database connection.
     *
     * @param  \FluentCart\Framework\Database\ConnectionInterface  $connection
     * @return $this
     */
    public function setConnection($connection)
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Track table aliases.
     * 
     * @param void
     */
    public function addAlias($alias)
    {
        $this->alias[] = "`$alias`";
    }
}
