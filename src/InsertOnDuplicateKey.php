<?php

namespace Ndexondeck;

trait InsertOnDuplicateKey
{
    /**
     * Insert using mysql ON DUPLICATE KEY UPDATE.
     * @link http://dev.mysql.com/doc/refman/5.7/en/insert-on-duplicate.html
     *
     * Example:  $data = [
     *     ['id' => 1, 'name' => 'John'],
     *     ['id' => 2, 'name' => 'Mike'],
     * ];
     *
     * @param array $data is an array of array.
     * @param array $updateColumns NULL or [] means update all columns
     *
     * @return int 0 if row is not changed, 1 if row is inserted, 2 if row is updated
     */
    public static function insertOnDuplicateKey(array $data, array $updateColumns = null)
    {
        if (empty($data)) {
            return false;
        }

        $connection =  static::getModelConnectionName();
        $driver = config('database.connections.'.$connection->getName().'.driver');
        if($driver == "sqlsrv"){

            $table = static::getTableName();

            if(is_null($updateColumns)){
                return $connection->table($table)->insert($data);
            }

            $sets = $conditions = $rows = [];
            foreach($data as $row){
                $fields = [];
                foreach($row as $key=>$value){
                    $fields[] = "'$value' AS [$key]";
                }
                $rows[] = "SELECT ".implode(",",$fields);
            }
            $cols = array_keys($row);

            $updateAbles = array_diff($cols,$updateColumns);

            foreach($cols as $k=>$v){
                $cols[$k] = "[$v]";
            }

            foreach($updateColumns as $updateColumn){
                $conditions[] = "target.[$updateColumn] = source.[$updateColumn]";
            }
            foreach($updateAbles as $updateAble){
                $sets[] = "target.[$updateAble] = source.[$updateAble]";
            }

            $sql = "MERGE $table AS target
            USING (".implode(" UNION ",$rows).") AS source (".implode(", ",$cols).")
            ON (".implode(" AND ",$conditions).")
            WHEN MATCHED THEN
                UPDATE SET
                    ".implode(", ",$sets)."
            WHEN NOT MATCHED THEN
            INSERT (".implode(", ",$cols).")
            VALUES (source.".implode(", source.",$cols).")
            ;";

            return $connection->statement($sql);
        }
        else{
            // Case where $data is not an array of arrays.
            if (!isset($data[0])) {
                $data = [$data];
            }

            $sql = static::buildInsertOnDuplicateSql($data, $updateColumns);

            $data = static::inLineArray($data);

            return $connection->affectingStatement($sql, $data);
        }
    }

    /**
     * Insert using mysql INSERT IGNORE INTO.
     *
     * @param array $data
     *
     * @return int 0 if row is ignored, 1 if row is inserted
     */
    public static function insertIgnore(array $data)
    {
        if (empty($data)) {
            return false;
        }

        // Case where $data is not an array of arrays.
        if (!isset($data[0])) {
            $data = [$data];
        }

        $sql = static::buildInsertIgnoreSql($data);

        $data = static::inLineArray($data);

        return self::getModelConnectionName()->affectingStatement($sql, $data);
    }

    /**
     * Insert using mysql REPLACE INTO.
     *
     * @param array $data
     *
     * @return int 1 if row is inserted without replacements, greater than 1 if rows were replaced
     */
    public static function replace(array $data)
    {
        if (empty($data)) {
            return false;
        }

        // Case where $data is not an array of arrays.
        if (!isset($data[0])) {
            $data = [$data];
        }

        $sql = static::buildReplaceSql($data);

        $data = static::inLineArray($data);

        return self::getModelConnectionName()->affectingStatement($sql, $data);
    }

    /**
     * Static function for getting table name.
     *
     * @return string
     */
    public static function getTableName()
    {
        $class = get_called_class();

        return (new $class())->getTable();
    }

    /**
     * Static function for getting connection name
     *
     * @return string
     */
    public static function getModelConnectionName()
    {
        $class = get_called_class();

        return (new $class())->getConnection();
    }

    /**
     * Get the table prefix.
     *
     * @return string
     */
    public static function getTablePrefix()
    {
        return self::getModelConnectionName()->getTablePrefix();
    }

    /**
     * Static function for getting the primary key.
     *
     * @return string
     */
    public static function getPrimaryKey()
    {
        $class = get_called_class();

        return (new $class())->getKeyName();
    }

    /**
     * Build the question mark placeholder.  Helper function for insertOnDuplicateKeyUpdate().
     * Helper function for insertOnDuplicateKeyUpdate().
     *
     * @param $data
     *
     * @return string
     */
    protected static function buildQuestionMarks($data)
    {
        $lines = [];
        foreach ($data as $row) {
            $count = count($row);
            $questions = [];
            for ($i = 0; $i < $count; ++$i) {
                $questions[] = '?';
            }
            $lines[] = '(' . implode(',', $questions) . ')';
        }

        return implode(', ', $lines);
    }

    /**
     * Get the first row of the $data array.
     *
     * @param array $data
     *
     * @return mixed
     */
    protected static function getFirstRow(array $data)
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Empty data.');
        }

        list($first) = $data;

        if (!is_array($first)) {
            throw new \InvalidArgumentException('$data is not an array of array.');
        }

        return $first;
    }

    /**
     * Build a value list.
     *
     * @param array $first
     *
     * @return string
     */
    protected static function getColumnList(array $first)
    {
        if (empty($first)) {
            throw new \InvalidArgumentException('Empty array.');
        }

        return '`' . implode('`,`', array_keys($first)) . '`';
    }

    /**
     * Build a value list.
     *
     * @param array $updatedColumns
     *
     * @return string
     */
    protected static function buildValuesList(array $updatedColumns)
    {
        $out = [];

        foreach ($updatedColumns as $key => $value) {
            if (is_numeric($key)) {
                $out[] = sprintf('`%s` = VALUES(`%s`)', $value, $value);
            } else {
                $out[] = sprintf('%s = %s', $key, $value);
            }
        }

        return implode(', ', $out);
    }

    /**
     * Inline a multiple dimensions array.
     *
     * @param $data
     *
     * @return array
     */
    protected static function inLineArray(array $data)
    {
        return call_user_func_array('array_merge', array_map('array_values', $data));
    }

    /**
     * Build the INSERT ON DUPLICATE KEY sql statement.
     *
     * @param array $data
     * @param array $updateColumns
     *
     * @return string
     */
    protected static function buildInsertOnDuplicateSql(array $data, array $updateColumns = null)
    {
        $first = static::getFirstRow($data);

        $sql  = 'INSERT INTO `' . static::getTablePrefix() . static::getTableName() . '`(' . static::getColumnList($first) . ') VALUES' . PHP_EOL;
        $sql .=  static::buildQuestionMarks($data) . PHP_EOL;
        $sql .= 'ON DUPLICATE KEY UPDATE ';

        if (empty($updateColumns)) {
            $sql .= static::buildValuesList(array_keys($first));
        } else {
            $sql .= static::buildValuesList($updateColumns);
        }

        return $sql;
    }

    /**
     * Build the INSERT IGNORE sql statement.
     *
     * @param array $data
     *
     * @return string
     */
    protected static function buildInsertIgnoreSql(array $data)
    {
        $first = static::getFirstRow($data);

        $sql  = 'INSERT IGNORE INTO `' . static::getTablePrefix() . static::getTableName() . '`(' . static::getColumnList($first) . ') VALUES' . PHP_EOL;
        $sql .=  static::buildQuestionMarks($data);

        return $sql;
    }

    /**
     * Build REPLACE sql statement.
     *
     * @param array $data
     *
     * @return string
     */
    protected static function buildReplaceSql(array $data)
    {
        $first = static::getFirstRow($data);

        $sql  = 'REPLACE INTO `' . static::getTablePrefix() . static::getTableName() . '`(' . static::getColumnList($first) . ') VALUES' . PHP_EOL;
        $sql .=  static::buildQuestionMarks($data);

        return $sql;
    }
}
