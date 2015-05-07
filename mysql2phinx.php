<?php
/**
 * Simple command line tool for creating phinx migration code from an existing MySQL database.
 *
 * Commandline usage:
 * ```
 * $ php -f mysql2phinx [database] [user] [password] > migration.php
 * ```
 */

if ($argc < 4) {
    echo '===============================' . PHP_EOL;
    echo 'Phinx MySQL migration generator' . PHP_EOL;
    echo '===============================' . PHP_EOL;
    echo 'Usage:' . PHP_EOL;
    echo 'php -f ' . $argv[0] . ' [database] [user] [password] > migration.php';
    echo PHP_EOL;
    exit;
}

$config = array(
    'name'    => $argv[1],
    'user'    => $argv[2],
    'pass'    => $argv[3],
    'host'    => $argc === 5 ? $argv[6] : '127.0.0.1',
    'port'    => $argc === 6 ? $argv[5] : '3306'
);

function createMigration($mysqli)
{
    $output = array();
    foreach (getTables($mysqli) as $table) {
        $output[] = getTableMigration($table, $mysqli);
    }
    return implode(PHP_EOL, $output) . PHP_EOL ;
}

function getMysqliConnection($config)
{
    return new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);
}

function getTables($mysqli)
{
    $res = $mysqli->query('SHOW TABLES');
    return array_map(function($a) { return $a[0]; }, $res->fetch_all());
}

function getTableMigration($table, $mysqli)
{
    $output = array();
    $output[] = '// Migration for table ' . $table;
    $output[] = '$table = $this->table(\'' . $table . '\');';
    $output[] = '$table';

    foreach (getColumns($table, $mysqli) as $column) {
        if ($column['Field'] !== 'id') {
            $output[] = getColumnMigration($column['Field'], $column);
        }
    }

    if ($indexes = getIndexMigrations(getIndexes($table, $mysqli))) {
        $output[] = $indexes;
    }

    $output[] = '    ->create();';
    $output[] = PHP_EOL;

    return implode(PHP_EOL, $output);
}

function getColumnMigration($column, $columndata)
{
    $phinxtype = getPhinxColumnType($columndata);
    $columnattributes = getPhinxColumnAttibutes($phinxtype, $columndata);
    $output = '    ->addColumn(\'' . $column . '\', \'' . $phinxtype . '\', ' . $columnattributes . ')';
    return $output;
}

function getMySQLColumnType($columndata)
{
    $type = $columndata['Type'];
    $pattern = '/^[a-z]+/';
    preg_match($pattern, $type, $match);
    return $match[0];
}

function getPhinxColumnType($columndata)
{
    $type = getMySQLColumnType($columndata);

    switch($type) {
        case 'tinyint':
        case 'smallint':
        case 'int':
        case 'mediumint':
            return 'integer';

        case 'timestamp':
            return 'timestamp';

        case 'date':
            return 'date';

        case 'datetime':
            return 'datetime';

        case 'enum':
            return 'enum';

        case 'char':
            return 'char';

        case 'text':
        case 'tinytext':
            return 'text';

        case 'varchar':
            return 'string';

        default:
            return '[' . $type . ']';
    }
}

function getPhinxColumnAttibutes($phinxtype, $columndata)
{
    $attributes = array();

    // var_dump($columndata);

    // has NULL
    if ($columndata['Null'] === 'YES') {
        $attributes[] = '\'null\' => true';
    }

    // default value
    if ($columndata['Default'] !== null) {
        $default = is_int($columndata['Default']) ? $columndata['Default'] : '\'' . $columndata['Default'] . '\'';
        $attributes[] = '\'default\' => ' . $default;
    }

    // limit / length
    $limit = 0;
    switch (getMySQLColumnType($columndata)) {
        case 'tinyint':
            $limit = 'MysqlAdapter::INT_TINY';
            break;

        case 'smallint':
            $limit = 'MysqlAdapter::INT_SMALL';
            break;

        case 'mediumint':
            $limit = 'MysqlAdapter::INT_MEDIUM';
            break;

        case 'bigint':
            $limit = 'MysqlAdapter::INT_BIG';
            break;

        case 'tinytext':
            $limit = 'MysqlAdapter::TEXT_TINY';
            break;

        case 'mediumtext':
            $limit = 'MysqlAdapter::TEXT_MEDIUM';
            break;

        case 'longtext':
            $limit = 'MysqlAdapter::TEXT_LONG';
            break;

        default:
            $pattern = '/\((\d+)\)$/';
            if (1 === preg_match($pattern, $columndata['Type'], $match)) {
                $limit = $match[1];
            }
    }
    if ($limit) {
        $attributes[] = '\'limit\' => ' . $limit;
    }

    // unsigned
    $pattern = '/\(\d+\) unsigned$/';
    if (1 === preg_match($pattern, $columndata['Type'], $match)) {
        $attributes[] = '\'signed\' => false';
    }

    // enum values
    if ($phinxtype === 'enum') {
        $attributes[] = '\'values\' => ' . str_replace('enum', 'array', $columndata['Type']);
    }

    return 'array(' . implode(', ', $attributes) . ')';
}

function getIndexMigrations($indexes)
{
    $keyedindexes = array();
    foreach($indexes as $index) {
        if ($index['Column_name'] === 'id') {
            continue;
        }

        $key = $index['Key_name'];
        if (!isset($keyedindexes[$key])) {
            $keyedindexes[$key] = array();
            $keyedindexes[$key]['columns'] = array();
            $keyedindexes[$key]['unique'] = $index['Non_unique'] !== '1';
        }

        $keyedindexes[$key]['columns'][] = $index['Column_name'];
    }

    $output = [];

    foreach ($keyedindexes as $index) {
        $columns = 'array(\'' . implode('\', \'', $index['columns']) . '\')';
        $options = $index['unique'] ? 'array(\'unique\' => true)' : 'array()';
        $output[] = '    ->addIndex(' . $columns . ', ' . $options . ')';
    }

    return implode(PHP_EOL, $output);
}

function getColumns($table, $mysqli)
{
    $res = $mysqli->query('SHOW COLUMNS FROM ' . $table);
    return $res->fetch_all(MYSQLI_ASSOC);
}

function getIndexes($table, $mysqli)
{
    $res = $mysqli->query('SHOW INDEXES FROM ' . $table);
    return $res->fetch_all(MYSQLI_ASSOC);
}

echo '<?php' . PHP_EOL;
echo '// Automatically created phinx migration commands for tables from database ' . $config['name'] . PHP_EOL . PHP_EOL ;
echo createMigration(getMysqliConnection($config));
