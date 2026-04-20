<?php

namespace FluentCart\Framework\Database\Migration;

use FluentCart\Framework\Database\Schema;

class Blueprint
{
    protected $table;
    protected $mode;

    /**
     * @var ColumnDefinition[]
     */
    protected $columns = [];

    /**
     * Index definitions for CREATE mode.
     * Each: ['type' => 'KEY|UNIQUE KEY', 'columns' => [...], 'name' => '...']
     */
    protected $indexes = [];

    /**
     * Alter operations (executed in order).
     * Each: ['type' => string, 'data' => mixed]
     */
    protected $operations = [];

    public function __construct($table, $mode = 'create')
    {
        $this->table = $table;
        $this->mode = $mode;
    }

    // ══════════════════════════════════════════════════════════
    //  CREATE MODE — Column Definitions
    // ══════════════════════════════════════════════════════════

    public function id($name = 'id')
    {
        $col = new ColumnDefinition($name);
        $col->id();
        $this->columns[] = $col;
        return $col;
    }

    public function string($name, $length = 255)
    {
        $col = new ColumnDefinition($name);
        $col->string($length);
        $this->columns[] = $col;
        return $col;
    }

    public function char($name, $length = 1)
    {
        $col = new ColumnDefinition($name);
        $col->char($length);
        $this->columns[] = $col;
        return $col;
    }

    public function text($name)
    {
        $col = new ColumnDefinition($name);
        $col->text();
        $this->columns[] = $col;
        return $col;
    }

    public function mediumText($name)
    {
        $col = new ColumnDefinition($name);
        $col->mediumText();
        $this->columns[] = $col;
        return $col;
    }

    public function longText($name)
    {
        $col = new ColumnDefinition($name);
        $col->longText();
        $this->columns[] = $col;
        return $col;
    }

    public function integer($name)
    {
        $col = new ColumnDefinition($name);
        $col->integer();
        $this->columns[] = $col;
        return $col;
    }

    public function bigInteger($name)
    {
        $col = new ColumnDefinition($name);
        $col->bigInteger();
        $this->columns[] = $col;
        return $col;
    }

    public function tinyInteger($name)
    {
        $col = new ColumnDefinition($name);
        $col->tinyInteger();
        $this->columns[] = $col;
        return $col;
    }

    public function smallInteger($name)
    {
        $col = new ColumnDefinition($name);
        $col->smallInteger();
        $this->columns[] = $col;
        return $col;
    }

    public function mediumInteger($name)
    {
        $col = new ColumnDefinition($name);
        $col->mediumInteger();
        $this->columns[] = $col;
        return $col;
    }

    public function decimal($name, $precision = 8, $scale = 2)
    {
        $col = new ColumnDefinition($name);
        $col->decimal($precision, $scale);
        $this->columns[] = $col;
        return $col;
    }

    public function float($name)
    {
        $col = new ColumnDefinition($name);
        $col->float();
        $this->columns[] = $col;
        return $col;
    }

    public function double($name)
    {
        $col = new ColumnDefinition($name);
        $col->double();
        $this->columns[] = $col;
        return $col;
    }

    public function boolean($name)
    {
        $col = new ColumnDefinition($name);
        $col->boolean();
        $this->columns[] = $col;
        return $col;
    }

    public function json($name)
    {
        $col = new ColumnDefinition($name);
        $col->json();
        $this->columns[] = $col;
        return $col;
    }

    public function binary($name, $length = 255)
    {
        $col = new ColumnDefinition($name);
        $col->binary($length);
        $this->columns[] = $col;
        return $col;
    }

    public function blob($name)
    {
        $col = new ColumnDefinition($name);
        $col->blob();
        $this->columns[] = $col;
        return $col;
    }

    public function mediumBlob($name)
    {
        $col = new ColumnDefinition($name);
        $col->mediumBlob();
        $this->columns[] = $col;
        return $col;
    }

    public function longBlob($name)
    {
        $col = new ColumnDefinition($name);
        $col->longBlob();
        $this->columns[] = $col;
        return $col;
    }

    public function enum($name, array $values)
    {
        $col = new ColumnDefinition($name);
        $col->enum($values);
        $this->columns[] = $col;
        return $col;
    }

    public function set($name, array $values)
    {
        $col = new ColumnDefinition($name);
        $col->set($values);
        $this->columns[] = $col;
        return $col;
    }

    public function timestamp($name)
    {
        $col = new ColumnDefinition($name);
        $col->timestamp();
        $this->columns[] = $col;
        return $col;
    }

    public function date($name)
    {
        $col = new ColumnDefinition($name);
        $col->date();
        $this->columns[] = $col;
        return $col;
    }

    public function datetime($name)
    {
        $col = new ColumnDefinition($name);
        $col->datetime();
        $this->columns[] = $col;
        return $col;
    }

    public function time($name)
    {
        $col = new ColumnDefinition($name);
        $col->time();
        $this->columns[] = $col;
        return $col;
    }

    public function year($name)
    {
        $col = new ColumnDefinition($name);
        $col->year();
        $this->columns[] = $col;
        return $col;
    }

    // ── Compound Shortcuts ───────────────────────────────────

    public function timestamps()
    {
        $this->timestamp('created_at')->default('CURRENT_TIMESTAMP');
        $this->timestamp('updated_at')->nullable();
    }

    public function softDeletes()
    {
        $this->timestamp('deleted_at')->nullable();
    }

    public function morphs($name)
    {
        $this->integer($name . '_id');
        $this->string($name . '_type');
    }

    public function nullableMorphs($name)
    {
        $this->integer($name . '_id')->nullable();
        $this->string($name . '_type')->nullable();
    }

    // ── Index Definitions ────────────────────────────────────

    /**
     * Add a regular index.
     */
    public function index($columns, $name = null)
    {
        $columns = (array) $columns;
        $name = $name ?: implode('_', $columns);

        if ($this->mode === 'create') {
            $this->indexes[] = [
                'type' => 'KEY',
                'columns' => $columns,
                'name' => $name,
            ];
        } else {
            $this->operations[] = [
                'type' => 'addIndex',
                'data' => ['columns' => $columns, 'name' => $name],
            ];
        }

        return $this;
    }

    /**
     * Add a unique index.
     */
    public function unique($columns, $name = null)
    {
        $columns = (array) $columns;
        $name = $name ?: 'unique_' . implode('_', $columns);

        if ($this->mode === 'create') {
            $this->indexes[] = [
                'type' => 'UNIQUE KEY',
                'columns' => $columns,
                'name' => $name,
            ];
        } else {
            $this->operations[] = [
                'type' => 'addUniqueIndex',
                'data' => ['columns' => $columns, 'name' => $name],
            ];
        }

        return $this;
    }

    // ══════════════════════════════════════════════════════════
    //  ALTER MODE — Guarded Operations
    // ══════════════════════════════════════════════════════════

    /**
     * Add a column (only if it doesn't exist).
     */
    public function addColumn($name)
    {
        $col = new ColumnDefinition($name);
        $this->operations[] = [
            'type' => 'addColumn',
            'data' => $col,
        ];
        return $col;
    }

    /**
     * Drop a column (only if it exists).
     */
    public function dropColumn($name)
    {
        $this->operations[] = [
            'type' => 'dropColumn',
            'data' => $name,
        ];
        return $this;
    }

    /**
     * Modify a column type/size (only if it exists).
     */
    public function modifyColumn($name)
    {
        $col = new ColumnDefinition($name);
        $this->operations[] = [
            'type' => 'modifyColumn',
            'data' => $col,
        ];
        return $col;
    }

    /**
     * Rename a column (only if old exists and new doesn't).
     */
    public function renameColumn($from, $to)
    {
        $this->operations[] = [
            'type' => 'renameColumn',
            'data' => ['from' => $from, 'to' => $to],
        ];
        return $this;
    }

    /**
     * Drop an index (only if it exists).
     */
    public function dropIndex($name)
    {
        $this->operations[] = [
            'type' => 'dropIndex',
            'data' => $name,
        ];
        return $this;
    }

    // ══════════════════════════════════════════════════════════
    //  EXECUTION
    // ══════════════════════════════════════════════════════════

    /**
     * Execute CREATE TABLE via dbDelta (idempotent).
     */
    public function executeCreate()
    {
        $sql = $this->buildCreateSql();
        return Schema::createTable($this->table, $sql);
    }

    /**
     * Execute ALTER operations with guards.
     */
    public function executeAlter()
    {
        $results = [];
        $tbl = Schema::table($this->table);

        if (!Schema::hasTable($this->table)) {
            return $results;
        }

        foreach ($this->operations as $op) {
            switch ($op['type']) {
                case 'addColumn':
                    /** @var ColumnDefinition $col */
                    $col = $op['data'];
                    if (!Schema::hasColumn($col->getName(), $this->table)) {
                        Schema::alterTable($this->table, $col->toAlterAddSql());
                        $results[] = "Added column {$col->getName()} to {$tbl}";
                    }
                    break;

                case 'dropColumn':
                    $name = $op['data'];
                    if (Schema::hasColumn($name, $this->table)) {
                        Schema::query("ALTER TABLE {$tbl} DROP COLUMN {$name}");
                        $results[] = "Dropped column {$name} from {$tbl}";
                    }
                    break;

                case 'modifyColumn':
                    /** @var ColumnDefinition $col */
                    $col = $op['data'];
                    if (Schema::hasColumn($col->getName(), $this->table)) {
                        Schema::alterTable($this->table, $col->toAlterModifySql());
                        $results[] = "Modified column {$col->getName()} in {$tbl}";
                    }
                    break;

                case 'renameColumn':
                    $from = $op['data']['from'];
                    $to = $op['data']['to'];
                    if (Schema::hasColumn($from, $this->table) && !Schema::hasColumn($to, $this->table)) {
                        $currentDef = $this->getCurrentColumnDef($from);
                        if ($currentDef) {
                            Schema::query("ALTER TABLE {$tbl} CHANGE COLUMN {$from} {$to} {$currentDef}");
                            $results[] = "Renamed column {$from} to {$to} in {$tbl}";
                        }
                    }
                    break;

                case 'addIndex':
                    $name = $op['data']['name'];
                    $columns = $op['data']['columns'];
                    if (!$this->hasIndex($name)) {
                        $colList = implode(', ', $columns);
                        Schema::query("ALTER TABLE {$tbl} ADD INDEX {$name} ({$colList})");
                        $results[] = "Added index {$name} on {$tbl}";
                    }
                    break;

                case 'addUniqueIndex':
                    $name = $op['data']['name'];
                    $columns = $op['data']['columns'];
                    if (!$this->hasIndex($name)) {
                        $colList = implode(', ', $columns);
                        Schema::query("ALTER TABLE {$tbl} ADD UNIQUE INDEX {$name} ({$colList})");
                        $results[] = "Added unique index {$name} on {$tbl}";
                    }
                    break;

                case 'dropIndex':
                    $name = $op['data'];
                    if ($this->hasIndex($name)) {
                        Schema::dropIndex($this->table, $name);
                        $results[] = "Dropped index {$name} from {$tbl}";
                    }
                    break;
            }
        }

        return $results;
    }

    // ══════════════════════════════════════════════════════════
    //  INTERNAL HELPERS
    // ══════════════════════════════════════════════════════════

    /**
     * Build the column+index SQL block for CREATE TABLE.
     * Uses KEY (not INDEX) for dbDelta compatibility.
     */
    protected function buildCreateSql()
    {
        $lines = [];

        $hasPrimaryKey = false;

        foreach ($this->columns as $col) {
            $sql = $col->toSql();

            // dbDelta needs PRIMARY KEY on its own line
            if (str_contains($sql, 'AUTO_INCREMENT')) {
                $hasPrimaryKey = true;
            }

            $lines[] = $col->toSql();
        }

        // Add PRIMARY KEY line for auto-increment columns (dbDelta requirement)
        if ($hasPrimaryKey) {
            foreach ($this->columns as $col) {
                if (str_contains($col->toSql(), 'AUTO_INCREMENT')) {
                    $lines[] = 'PRIMARY KEY  (' . $col->getName() . ')';
                    break;
                }
            }
        }

        // Add index lines from column-level modifiers
        foreach ($this->columns as $col) {
            if ($col->isIndex()) {
                $lines[] = 'KEY ' . $col->getName() . ' (' . $col->getName() . ')';
            }
            if ($col->isUnique() && !$col->isIndex()) {
                $lines[] = 'UNIQUE KEY ' . $col->getName() . ' (' . $col->getName() . ')';
            }
        }

        // Add index lines from explicit index() / unique() calls
        foreach ($this->indexes as $idx) {
            $colList = implode(', ', $idx['columns']);
            $lines[] = $idx['type'] . ' ' . $idx['name'] . ' (' . $colList . ')';
        }

        return implode(",\n", $lines);
    }

    /**
     * Check if an index exists on this table.
     */
    protected function hasIndex($indexName)
    {
        $tbl = Schema::table($this->table);

        $rows = (array) Schema::db()->get_results("SHOW INDEX FROM {$tbl}");

        foreach ($rows as $row) {
            if ($row->Key_name === $indexName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the current column definition for CHANGE COLUMN.
     */
    protected function getCurrentColumnDef($column)
    {
        $tbl = Schema::table($this->table);

        $row = Schema::db()->get_row(
            "SHOW COLUMNS FROM {$tbl} WHERE Field = '{$column}'"
        );

        if (!$row) {
            return null;
        }

        $def = $row->Type;

        if ($row->Null === 'NO') {
            $def .= ' NOT NULL';
        } else {
            $def .= ' NULL';
        }

        if ($row->Default !== null) {
            $def .= " DEFAULT '" . addslashes($row->Default) . "'";
        }

        if (str_contains($row->Extra, 'auto_increment')) {
            $def .= ' AUTO_INCREMENT';
        }

        return $def;
    }

    public function getTable()
    {
        return $this->table;
    }
}
