<?php

namespace FluentCart\Framework\Database\Migration;

use FluentCart\Framework\Database\Schema;

class ColumnDefinition
{
    protected $name;
    protected $type = '';
    protected $isNullable = false;
    protected $defaultValue = null;
    protected $hasDefault = false;
    protected $isUnsigned = false;
    protected $afterColumn = null;
    protected $isPrimary = false;
    protected $isAutoIncrement = false;
    protected $isUnique = false;
    protected $isIndex = false;
    protected $comment = null;
    protected $charset = null;
    protected $collation = null;

    public function __construct($name)
    {
        $this->name = $name;
    }

    // ── Type Methods ─────────────────────────────────────────

    /**
     * Auto-increment primary key (bigint unsigned).
     */
    public function id()
    {
        $this->type = 'bigint(20) unsigned';
        $this->isAutoIncrement = true;
        $this->isPrimary = true;
        $this->isNullable = false;
        return $this;
    }

    public function string($length = 255)
    {
        $this->type = "varchar({$length})";
        return $this;
    }

    public function char($length = 1)
    {
        $this->type = "char({$length})";
        return $this;
    }

    public function text()
    {
        $this->type = 'text';
        return $this;
    }

    public function mediumText()
    {
        $this->type = 'mediumtext';
        return $this;
    }

    public function longText()
    {
        $this->type = 'longtext';
        return $this;
    }

    public function integer()
    {
        $this->type = 'int';
        return $this;
    }

    public function bigInteger()
    {
        $this->type = 'bigint(20)';
        return $this;
    }

    public function tinyInteger()
    {
        $this->type = 'tinyint';
        return $this;
    }

    public function smallInteger()
    {
        $this->type = 'smallint';
        return $this;
    }

    public function mediumInteger()
    {
        $this->type = 'mediumint';
        return $this;
    }

    public function decimal($precision = 8, $scale = 2)
    {
        $this->type = "decimal({$precision},{$scale})";
        return $this;
    }

    public function float()
    {
        $this->type = 'float';
        return $this;
    }

    public function double()
    {
        $this->type = 'double';
        return $this;
    }

    public function boolean()
    {
        $this->type = 'tinyint(1)';
        return $this;
    }

    public function json()
    {
        $this->type = Schema::jsonType();
        return $this;
    }

    public function binary($length = 255)
    {
        $this->type = "binary({$length})";
        return $this;
    }

    public function blob()
    {
        $this->type = 'blob';
        return $this;
    }

    public function mediumBlob()
    {
        $this->type = 'mediumblob';
        return $this;
    }

    public function longBlob()
    {
        $this->type = 'longblob';
        return $this;
    }

    public function enum(array $values)
    {
        if (Schema::isSqlite()) {
            $this->type = 'varchar(255)';
        } else {
            $quoted = array_map(function ($v) {
                return "'" . addslashes($v) . "'";
            }, $values);
            $this->type = 'enum(' . implode(',', $quoted) . ')';
        }
        return $this;
    }

    public function set(array $values)
    {
        if (Schema::isSqlite()) {
            $this->type = 'varchar(255)';
        } else {
            $quoted = array_map(function ($v) {
                return "'" . addslashes($v) . "'";
            }, $values);
            $this->type = 'set(' . implode(',', $quoted) . ')';
        }
        return $this;
    }

    public function timestamp()
    {
        $this->type = 'timestamp';
        return $this;
    }

    public function date()
    {
        $this->type = 'date';
        return $this;
    }

    public function datetime()
    {
        $this->type = 'datetime';
        return $this;
    }

    public function time()
    {
        $this->type = 'time';
        return $this;
    }

    public function year()
    {
        $this->type = 'year';
        return $this;
    }

    // ── Modifier Methods ─────────────────────────────────────

    public function nullable()
    {
        $this->isNullable = true;
        return $this;
    }

    public function default($value)
    {
        $this->hasDefault = true;
        $this->defaultValue = $value;
        return $this;
    }

    public function unsigned()
    {
        $this->isUnsigned = true;
        return $this;
    }

    public function after($column)
    {
        $this->afterColumn = $column;
        return $this;
    }

    public function primary()
    {
        $this->isPrimary = true;
        return $this;
    }

    public function autoIncrement()
    {
        $this->isAutoIncrement = true;
        return $this;
    }

    public function unique()
    {
        $this->isUnique = true;
        return $this;
    }

    public function index()
    {
        $this->isIndex = true;
        return $this;
    }

    public function comment($comment)
    {
        $this->comment = $comment;
        return $this;
    }

    public function charset($charset)
    {
        $this->charset = $charset;
        return $this;
    }

    public function collation($collation)
    {
        $this->collation = $collation;
        return $this;
    }

    // ── SQL Generation ───────────────────────────────────────

    public function getName()
    {
        return $this->name;
    }

    public function isUnique()
    {
        return $this->isUnique;
    }

    public function isIndex()
    {
        return $this->isIndex;
    }

    /**
     * Column definition for CREATE TABLE (used by dbDelta).
     * e.g. "status varchar(20) NOT NULL DEFAULT 'pending'"
     */
    public function toSql()
    {
        $parts = [$this->name, $this->type];

        if ($this->isUnsigned && !str_contains($this->type, 'unsigned')) {
            $parts[] = 'unsigned';
        }

        if ($this->charset && !Schema::isSqlite()) {
            $parts[] = 'CHARACTER SET ' . $this->charset;
        }

        if ($this->collation && !Schema::isSqlite()) {
            $parts[] = 'COLLATE ' . $this->collation;
        }

        if ($this->isAutoIncrement) {
            $parts[] = 'NOT NULL';
            $parts[] = 'AUTO_INCREMENT';
        } else {
            $parts[] = $this->isNullable ? 'NULL' : 'NOT NULL';
        }

        if ($this->hasDefault) {
            $parts[] = 'DEFAULT ' . $this->formatDefault($this->defaultValue);
        }

        if ($this->isPrimary && !$this->isAutoIncrement) {
            $parts[] = 'PRIMARY KEY';
        }

        if ($this->comment !== null && !Schema::isSqlite()) {
            $parts[] = "COMMENT '" . addslashes($this->comment) . "'";
        }

        return implode(' ', $parts);
    }

    /**
     * For ALTER TABLE ADD COLUMN.
     */
    public function toAlterAddSql()
    {
        $sql = 'ADD COLUMN ' . $this->toSql();

        if ($this->isUnique) {
            $sql .= ' UNIQUE';
        }

        if ($this->afterColumn && !Schema::isSqlite()) {
            $sql .= ' AFTER ' . $this->afterColumn;
        }

        return $sql;
    }

    /**
     * For ALTER TABLE MODIFY COLUMN.
     */
    public function toAlterModifySql()
    {
        if (Schema::isSqlite()) {
            return 'CHANGE COLUMN ' . $this->name . ' ' . $this->toSql();
        }

        $sql = 'MODIFY COLUMN ' . $this->toSql();

        if ($this->afterColumn) {
            $sql .= ' AFTER ' . $this->afterColumn;
        }

        return $sql;
    }

    protected function formatDefault($value)
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // Raw SQL expressions like CURRENT_TIMESTAMP
        $raw = strtoupper($value);
        if (in_array($raw, ['CURRENT_TIMESTAMP', 'NOW()', 'NULL'])) {
            return $raw;
        }

        return "'" . addslashes($value) . "'";
    }
}
