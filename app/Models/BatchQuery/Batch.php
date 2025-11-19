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

        $driver = $table->getConnection()->getName();
        foreach ($values as $key => $val) {
            $ids[] = $val[$index];

            if ($table->usesTimestamps()) {
                $updatedAtColumn = $table->getUpdatedAtColumn();

                if (!isset($val[$updatedAtColumn])) {
                    $val[$updatedAtColumn] = gmdate($table->getDateFormat());
                }
            }

            foreach (array_keys($val) as $field) {
                if ($field !== $index) {
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
                            $value = "'" . json_encode($val[$field]) . "'";
                        }

                    } else {
                        // Only update
                        $finalField = $raw ? Common::mysqlEscape($val[$field]) : "'" . Common::mysqlEscape($val[$field]) . "'";
                        $value = (is_null($val[$field]) ? 'NULL' : $finalField);
                    }

                    if (Common::disableBacktick($driver))
                        $final[$field][] = 'WHEN ' . $index . ' = \'' . $val[$index] . '\' THEN ' . $value . ' ';
                    else
                        $final[$field][] = 'WHEN `' . $index . '` = \'' . $val[$index] . '\' THEN ' . $value . ' ';
                }
            }
        }

        if (Common::disableBacktick($driver)) {

            $cases = '';
            foreach ($final as $k => $v) {
                $cases .= '"' . $k . '" = (CASE ' . implode("\n", $v) . "\n"
                    . 'ELSE "' . $k . '" END), ';
            }

            $query = "UPDATE \"" . $this->getFullTableName($table) . '" SET ' . substr($cases, 0, -2) . " WHERE \"$index\" IN('" . implode("','", $ids) . "');";

        } else {

            $cases = '';
            foreach ($final as $k => $v) {
                $cases .= '`' . $k . '` = (CASE ' . implode("\n", $v) . "\n"
                    . 'ELSE `' . $k . '` END), ';
            }

            $query = "UPDATE `" . $this->getFullTableName($table) . "` SET " . substr($cases, 0, -2) . " WHERE `$index` IN(" . '"' . implode('","', $ids) . '"' . ");";

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
        $driver = $table->getConnection()->getDriverName();

        if (!count($values)) {
            return false;
        }

        if (!isset($index) || empty($index)) {
            $index = $table->getKeyName();
        }

        foreach ($values as $key => $val) {
            $ids[] = $val[$index];
            $ids2[] = $val[$index2];
            foreach (array_keys($val) as $field) {
                if ($field !== $index || $field !== $index2) {
                    $finalField = $raw ? Common::mysqlEscape($val[$field]) : "'" . Common::mysqlEscape($val[$field]) . "'";
                    $value = (is_null($val[$field]) ? 'NULL' : $finalField);

                    if (Common::disableBacktick($driver)) {
                        $final[$field][] = 'WHEN (' . $index . ' = \'' . Common::mysqlEscape($val[$index]) . '\' AND ' . $index2 . ' = \'' . $val[$index2] . '\') THEN ' . $value . ' ';
                    } else {
                        $final[$field][] = 'WHEN (`' . $index . '` = "' . Common::mysqlEscape($val[$index]) . '" AND `' . $index2 . '` = "' . $val[$index2] . '") THEN ' . $value . ' ';
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

            $query = "UPDATE \"" . $this->getFullTableName($table) . '" SET ' . substr($cases, 0, -2) . " WHERE \"$index\" IN('" . implode("','", $ids) . "') AND \"$index2\" IN('" . implode("','", $ids2) . "');";
        } else {
            $cases = '';
            foreach ($final as $k => $v) {
                $cases .= '`' . $k . '` = (CASE ' . implode("\n", $v) . "\n"
                    . 'ELSE `' . $k . '` END), ';
            }
            $query = "UPDATE `" . $this->getFullTableName($table) . "` SET " . substr($cases, 0, -2) . " WHERE `$index` IN(" . '"' . implode('","', $ids) . '")' . " AND `$index2` IN(" . '"' . implode('","', $ids2) . '"' . " );";
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
}
