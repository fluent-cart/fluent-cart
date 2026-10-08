<?php

namespace FluentCart\App\Models\BatchQuery;

use FluentCart\Framework\Database\Orm\Model;

class Batch implements BatchInterface
{

    protected $db;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    public function update(Model $table, array $values, ?string $index = null, bool $raw = false)
    {
        $final = [];
        $ids = [];

        if (!count($values)) {
            return false;
        }

        if (!isset($index) || empty($index)) {
            $index = $table->getKeyName();
        }

        // The keys of each row become raw SQL column identifiers below. They can
        // originate from user input (e.g. the products/bulk-update payload), so an
        // unvalidated key carrying a backtick breaks out of the identifier context
        // and injects SQL. Reject anything that is not a plain column identifier.
        $this->assertIdentifier($index);

        $driver = $table->getConnection()->getName();
        foreach ($values as $key => $val) {
            $ids[] = $this->db->prepare('%s', $val[$index]);

            if ($table->usesTimestamps()) {
                $updatedAtColumn = $table->getUpdatedAtColumn();

                if (!isset($val[$updatedAtColumn])) {
                    $val[$updatedAtColumn] = gmdate($table->getDateFormat());
                }
            }

            foreach (array_keys($val) as $field) {
                if ($field !== $index) {
                    $this->assertIdentifier($field);
                    $indexValue = $this->db->prepare('%s', $val[$index]);
                    // If increment / decrement
                    if (gettype($val[$field]) == 'array') {

                        $isMathOperator = true;
                        // If array has two values
                        if (!array_key_exists(0, $val[$field]) || !array_key_exists(1, $val[$field])) {
                            $isMathOperator = false;

                        }

                        if($isMathOperator){
                            // Check first value
                            if (gettype($val[$field][0]) != 'string' || !in_array($val[$field][0], ['+', '-', '*', '/', '%'])) {
                                throw new \TypeError('First value in Increment/Decrement array needs to be a string and a math operator (+, -, *, /, %)');
                            }
                            // Check second value
                            if (!is_numeric($val[$field][1])) {
                                throw new \TypeError('Second value in Increment/Decrement array needs to be numeric');
                            }
                            // Increment / decrement
                            if (Common::disableBacktick($driver)) {
                                $value = $field . $val[$field][0] . $val[$field][1];
                            } else {
                                $value = '`' . $field . '`' . $val[$field][0] . $val[$field][1];
                            }
                        }
                        else{
                            // Array values are serialized to JSON and dropped into the
                            // SQL as a string literal. json_encode() does NOT escape
                            // single quotes, so an array value such as other_info
                            // carrying a quote breaks out of the literal and injects
                            // SQL. prepare('%s', ...) adds the quotes and escapes the
                            // payload (quotes and backslashes) safely.
                            $value = $this->db->prepare('%s', json_encode($val[$field]));
                        }

                    } else {
                        // Scalar value. prepare('%s', ...) both quotes and fully
                        // escapes it. Common::mysqlEscape() must NOT be used here:
                        // it treats a JSON-valid scalar string (e.g. the literal
                        // "O'Reilly") specially and returns it decoded but unescaped,
                        // so the apostrophe would break out of the string literal.
                        // $raw is an internal-only path (no caller passes it).
                        if (is_null($val[$field])) {
                            $value = 'NULL';
                        } elseif ($raw) {
                            $value = Common::mysqlEscape($val[$field]);
                        } else {
                            $value = $this->prepareScalar($table, $val[$field]);
                        }
                    }

                    if (Common::disableBacktick($driver))
                        $final[$field][] = 'WHEN ' . $index . ' = ' . $indexValue . ' THEN ' . $value . ' ';
                    else
                        $final[$field][] = 'WHEN `' . $index . '` = ' . $indexValue . ' THEN ' . $value . ' ';
                }
            }
        }

        if (Common::disableBacktick($driver)) {

            $cases = '';
            foreach ($final as $k => $v) {
                $cases .= '"' . $k . '" = (CASE ' . implode("\n", $v) . "\n"
                    . 'ELSE "' . $k . '" END), ';
            }

            $query = "UPDATE \"" . $this->getFullTableName($table) . '" SET ' . substr($cases, 0, -2) . " WHERE \"$index\" IN(" . implode(",", $ids) . ");";

        } else {

            $cases = '';
            foreach ($final as $k => $v) {
                $cases .= '`' . $k . '` = (CASE ' . implode("\n", $v) . "\n"
                    . 'ELSE `' . $k . '` END), ';
            }

            $query = "UPDATE `" . $this->getFullTableName($table) . "` SET " . substr($cases, 0, -2) . " WHERE `$index` IN(" . implode(",", $ids) . ");";

        }

        return $this->db->query($query);

    }

    /**
     * Update multiple rows
     * @param Model $table
     * @param array $values
     * @param string $index
     * @param string|null $index2
     * @param bool $raw
     * @return bool|int
     *
     * @desc
     * Example
     * $table = 'users';
     * $value = [
     *     [
     *         'id' => 1,
     *         'status' => 'active',
     *         'nickname' => 'Mohammad'
     *     ] ,
     *     [
     *         'id' => 5,
     *         'status' => 'deactive',
     *         'nickname' => 'Ghanbari'
     *     ] ,
     * ];
     * $index = 'id';
     * $index2 = 'user_id';
     *
     */
    public function updateWithTwoIndex(Model $table, array $values, ?string $index = null, ?string $index2 = null, bool $raw = false)
    {
        $final = [];
        $ids = [];
        $driver = $table->getConnection()->getName();

        if (!count($values)) {
            return false;
        }

        if (!isset($index) || empty($index)) {
            $index = $table->getKeyName();
        }

        // Identifiers are interpolated as raw SQL below — reject anything that is
        // not a plain column name so an attacker-supplied key cannot inject SQL.
        $this->assertIdentifier($index);
        $this->assertIdentifier($index2);

        foreach ($values as $key => $val) {
            $id1 = $this->db->prepare('%s', $val[$index]);
            $id2 = $this->db->prepare('%s', $val[$index2]);
            $ids[] = $id1;
            $ids2[] = $id2;
            foreach (array_keys($val) as $field) {
                if ($field !== $index || $field !== $index2) {
                    $this->assertIdentifier($field);
                    // prepare('%s', ...) quotes and escapes; Common::mysqlEscape()
                    // is unsafe for JSON-valid scalar strings. $raw is internal-only.
                    if (is_null($val[$field])) {
                        $value = 'NULL';
                    } elseif ($raw) {
                        $value = Common::mysqlEscape($val[$field]);
                    } else {
                        $value = $this->prepareScalar($table, $val[$field]);
                    }

                    if (Common::disableBacktick($driver)) {
                        $final[$field][] = 'WHEN (' . $index . ' = ' . $id1 . ' AND ' . $index2 . ' = ' . $id2 . ') THEN ' . $value . ' ';
                    } else {
                        $final[$field][] = 'WHEN (`' . $index . '` = ' . $id1 . ' AND `' . $index2 . '` = ' . $id2 . ') THEN ' . $value . ' ';
                    }
                }
            }
        }


        if (Common::disableBacktick($driver)) {
            $cases = '';
            foreach ($final as $k => $v) {
                $cases .= '"' . $k . '" = (CASE ' . implode("\n", $v) . "\n"
                    . 'ELSE "' . $k . '" END), ';
            }

            $query = "UPDATE \"" . $this->getFullTableName($table) . '" SET ' . substr($cases, 0, -2) . " WHERE \"$index\" IN(" . implode(",", $ids) . ") AND \"$index2\" IN(" . implode(",", $ids2) . ");";
        } else {
            $cases = '';
            foreach ($final as $k => $v) {
                $cases .= '`' . $k . '` = (CASE ' . implode("\n", $v) . "\n"
                    . 'ELSE `' . $k . '` END), ';
            }
            $query = "UPDATE `" . $this->getFullTableName($table) . "` SET " . substr($cases, 0, -2) . " WHERE `$index` IN(" . implode(",", $ids) . ")" . " AND `$index2` IN(" . implode(",", $ids2) . ");";
        }
        return $this->db->query($query);
    }

    /**
     * Get the full table name.
     *
     * @param Model $model
     * @return string
     */
    private function getFullTableName(Model $model): string
    {
        return $this->db->prefix . $model->getTable();
    }

    /**
     * Guard a value that is about to be used as a raw SQL column/index identifier.
     *
     * Identifiers cannot be bound as parameters, so any key that is interpolated
     * between backticks must be a plain column name. A value carrying a backtick
     * (or anything outside [A-Za-z0-9_]) would break out of the identifier and
     * inject SQL, so we fail closed rather than escape — a legitimate column name
     * always matches.
     *
     * @param string $identifier
     * @return void
     * @throws \InvalidArgumentException
     */
    private function assertIdentifier($identifier): void
    {
        if (!is_string($identifier) || !preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid column identifier in batch update.');
        }
    }

    /**
     * Normalize a non-null scalar value, then bind it via prepare().
     *
     * prepare('%s', ...) only accepts scalars: a DateTime or a stringable object
     * would become an empty string. Callers legitimately pass DateTime objects
     * (e.g. updated_at, refunded_at) and boolean flags, which the previous
     * string-concatenation path rendered via __toString()/(int) cast. Reproduce
     * that normalization before binding so stored values are unchanged.
     *
     * @param Model $table
     * @param mixed $input non-null value
     * @return string prepared, quoted SQL literal
     */
    private function prepareScalar(Model $table, $input): string
    {
        if ($input instanceof \DateTimeInterface) {
            $input = $input->format($table->getDateFormat());
        } elseif (is_bool($input)) {
            $input = (int) $input;
        } elseif (is_object($input) && method_exists($input, '__toString')) {
            $input = (string) $input;
        }

        return $this->db->prepare('%s', $input);
    }
}
