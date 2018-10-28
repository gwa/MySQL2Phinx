<?php
/**
 * Simple command line tool for creating phinx migration code from an existing MySQL database.
 *
 * Commandline usage:
 * ```
 * $ php -f mysql2phinx.php [database] [user] [password] [host=localhost] [port=3306] > 20170507142126_initial_migration.php
 * ```
 */

if ($argc < 4) {
    echo '===============================' . PHP_EOL;
    echo 'Phinx MySQL migration generator' . PHP_EOL;
    echo '===============================' . PHP_EOL;
    echo 'Usage:' . PHP_EOL;
    echo "php -f {$argv[0]} [database] [user] [password] [host=localhost] [port=3306] > " . date('YmdHis') . "_initial_migration.php";
    echo PHP_EOL;
    exit;
}

function getMysqliConnection($config)
{
    return new mysqli(
        $config['host'],
        $config['user'],
        $config['pass'],
        $config['name'],
        $config['port']
    );
}

function createMigration($mysqli, $indent = 2)
{
    $tables = getTables($mysqli);

    $output = [];
    $output[] = '    public function up()';
    $output[] = '    {';
    $output[] = '        // Making sure no foreign key constraints stops the creation';
    $output[] = '        $this->execute(\'SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\');';
    $output[] = '';
    foreach ($tables as $table) {
        $output[] = getTableMigration($table, $mysqli, $indent);
    }
    $output[] = '        // Resetting the default foreign key check';
    $output[] = '        $this->execute(\'SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\');';
    $output[] = '    }';
    $output[] = '';
    $output[] = '    public function down()';
    $output[] = '    {';
    $output[] = '        // Making sure no foreign key constraints stops the creation';
    $output[] = '        $this->execute(\'SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\');';
    $output[] = '';
    foreach ($tables as $table) {
        $output[] = getTableReversion($table, $mysqli, $indent);
    }
    $output[] = '        // Resetting the default foreign key check';
    $output[] = '        $this->execute(\'SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\');';
    $output[] = '    }';

    return implode(PHP_EOL, $output) . PHP_EOL ;
}

function getTables($mysqli)
{
    $tables = $mysqli->query('SHOW TABLES')->fetch_all();
    return array_column($tables, 0);
}

function getTableMigration($table, $mysqli, $indent)
{
    $ind = getIndentation($indent);

    $primaryColumns = [];

    $columns = getColumns($table, $mysqli);
    foreach ($columns as $column) {
        if ($column['Key'] == 'PRI') {
            $primaryColumns[] = $column['Field'];
        }
    }

    $tableInformation = getTableInformation($table, $mysqli);

    $output = [];
    $output[] = "{$ind}// Migration for table {$table}";
    $output[] = "{$ind}\$table = \$this->table('{$table}', "
        . "['id' => false"
        . ", 'primary_key' => " . (empty($primaryColumns) ? 'null' : "['" . implode("', '", $primaryColumns) . "']")
        . ", 'engine' => '{$tableInformation['Engine']}'"
        . ", 'collation' => '{$tableInformation['Collation']}'"
        . "]);";
    $output[] = $ind . '$table';

    foreach ($columns as $column) {
        $output[] = getColumnMigration($column, $indent + 1);
    }

    if ($tableIndexes = getIndexMigrations(getIndexes($table, $mysqli), $indent + 1)) {
        $output[] = $tableIndexes;
    }

    if ($foreignKeys = getForeignKeysMigrations(getForeignKeys($table, $mysqli), $indent + 1)) {
        $output[] = $foreignKeys;
    }

    $output[] = $ind . '    ->create()';
    $output[] = $ind . ';';
    $output[] = '';

    return implode(PHP_EOL, $output);
}

function getTableReversion($table, $mysqli, $indent)
{
    $ind = getIndentation($indent);

    $output = [];
    $output[] = "{$ind}// Reversion for table {$table}";
    $output[] = "{$ind}\$this->dropTable('{$table}');";
    $output[] = '';

    return implode(PHP_EOL, $output);
}

function getColumnMigration($columndata, $indent)
{
    $ind = getIndentation($indent);

    $phinxtype = getPhinxColumnType($columndata);
    $columnattributes = getPhinxColumnAttibutes($phinxtype, $columndata);
    $output = "{$ind}->addColumn('{$columndata['Field']}', '{$phinxtype}', {$columnattributes})";
    return $output;
}

function getIndexMigrations($indexes, $indent)
{
    $ind = getIndentation($indent);

    $keyedindexes = [];
    foreach($indexes as $index) {
        $key = $index['Key_name'];

        if ($key == 'PRIMARY') {
            continue;
        }

        if (!isset($keyedindexes[$key])) {
            $keyedindexes[$key] = [];
            $keyedindexes[$key]['columns'] = [];
            $keyedindexes[$key]['unique'] = $index['Non_unique'] !== '1';
            $keyedindexes[$key]['fulltext'] = $index['Index_type'] == 'FULLTEXT';
        }

        $keyedindexes[$key]['columns'][] = $index['Column_name'];
    }

    $output = [];

    foreach ($keyedindexes as $indexName => $index) {
        $columns = "['" . implode("', '", $index['columns']) . "']";
        $options = "['name' => '{$indexName}'";
        $options .= ($index['unique'] ? ", 'unique' => true" : '');
        $options .= ($index['fulltext'] ? ", 'type' => 'fulltext'" : '');
        $options .= ']';
        $output[] = "{$ind}->addIndex({$columns}, {$options})";
    }

    return implode(PHP_EOL, $output);
}

function getForeignKeysMigrations($foreignKeys, $indent)
{
    $ind = getIndentation($indent);

    $groupedForeignKeys = [];

    foreach ($foreignKeys as $foreignKey) {
        if (!isset($groupedForeignKeys[$foreignKey['CONSTRAINT_NAME']])) {
            $groupedForeignKeys[$foreignKey['CONSTRAINT_NAME']] = [
                'CONSTRAINT_NAME' => $foreignKey['CONSTRAINT_NAME'],
                'REFERENCED_TABLE_NAME' => $foreignKey['REFERENCED_TABLE_NAME'],
                'DELETE_RULE' => $foreignKey['DELETE_RULE'],
                'UPDATE_RULE' => $foreignKey['UPDATE_RULE'],
                'COLUMN_NAMES' => [],
                'REFERENCED_COLUMN_NAMES' => [],
            ];
        }
        $groupedForeignKeys[$foreignKey['CONSTRAINT_NAME']]['COLUMN_NAMES'][] = $foreignKey['COLUMN_NAME'];
        $groupedForeignKeys[$foreignKey['CONSTRAINT_NAME']]['REFERENCED_COLUMN_NAMES'][] = $foreignKey['REFERENCED_COLUMN_NAME'];
    }

    $output = [];

    foreach ($groupedForeignKeys as $foreignKey) {
        $output[] = "{$ind}->addForeignKey("
            . "['" . implode("', '", $foreignKey['COLUMN_NAMES']) . "'], "
            . "'{$foreignKey['REFERENCED_TABLE_NAME']}', "
            . "['" . implode("', '", $foreignKey['REFERENCED_COLUMN_NAMES']) . "'], "
            . "["
            . "'constraint' => '{$foreignKey['CONSTRAINT_NAME']}',"
            . "'delete' => '" . str_replace(' ', '_', $foreignKey['DELETE_RULE']) . "',"
            . "'update' => '" . str_replace(' ', '_', $foreignKey['UPDATE_RULE']) . "'"
        . "])";
    }

    return implode(PHP_EOL, $output);
}

function getMySQLColumnType($columndata)
{
    preg_match('/^[a-z]+/', $columndata['Type'], $match);
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
        case 'bigint':
            return 'integer';

        case 'timestamp':
            return 'timestamp';

        case 'date':
            return 'date';

        case 'datetime':
            return 'datetime';

        case 'decimal':
            return 'decimal';

        case 'enum':
            return 'enum';

        case 'char':
            return 'char';

        case 'blob':
            return 'blob';

        case 'text':
        case 'tinytext':
        case 'mediumtext':
        case 'longtext':
            return 'text';

        case 'varchar':
            return 'string';

        default:
            return "[unsupported_{$type}]";
    }
}

function getPhinxColumnAttibutes($phinxtype, $columndata)
{
    $attributes = [];

    // has NULL
    if ($columndata['Null'] === 'YES') {
        $attributes[] = "'null' => true";
    }

    // default value
    if ($columndata['Default'] !== null) {
        $default = (is_int($columndata['Default'])
            ? $columndata['Default']
            : "'{$columndata['Default']}'"
        );
        $attributes[] = "'default' => {$default}";
    }

    // on update CURRENT_TIMESTAMP
    if ($columndata['Extra'] === 'on update CURRENT_TIMESTAMP') {
        $attributes[] = "'update' => 'CURRENT_TIMESTAMP'";
    }

    // auto_increment
    if ($columndata['Extra'] === 'auto_increment') {
        $attributes[] = "'identity' => true";
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
        $attributes[] = "'limit' => {$limit}";
    }

    // unsigned
    $pattern = '/\(\d+(\s*,\s*\d+)?\) unsigned$/';
    if (1 === preg_match($pattern, $columndata['Type'], $match)) {
        $attributes[] = "'signed' => false";
    }

    // decimal values
    if ($phinxtype === 'decimal'
        && 1 === preg_match('/decimal\((\d+)\s*,\s*(\d+)\)/i', $columndata['Type'], $decimalMatch)
    ) {
        $attributes[] = "'precision' => " . (int) $decimalMatch[1];
        $attributes[] = "'scale' => " . (int) $decimalMatch[2];
    }

    // enum values
    if ($phinxtype === 'enum') {
        $enumStr = preg_replace('/^enum\((.*)\)$/', '[$1]', $columndata['Type']);
        $attributes[] = "'values' => {$enumStr}";
    }

    return '[' . implode(', ', $attributes) . ']';
}

function getColumns($table, $mysqli)
{
    return $mysqli->query("SHOW COLUMNS FROM `{$table}`")->fetch_all(MYSQLI_ASSOC);
}

function getIndexes($table, $mysqli)
{
    return $mysqli->query("SHOW INDEXES FROM `{$table}`")->fetch_all(MYSQLI_ASSOC);
}

function getTableInformation($table, $mysqli)
{
    return $mysqli->query("SHOW TABLE STATUS WHERE Name = '{$table}'")->fetch_array(MYSQLI_ASSOC);
}

function getForeignKeys($table, $mysqli)
{
    return $mysqli->query(
        "SELECT
            cols.TABLE_NAME,
            cols.COLUMN_NAME,
            refs.CONSTRAINT_NAME,
            refs.REFERENCED_TABLE_NAME,
            refs.REFERENCED_COLUMN_NAME,
            cRefs.UPDATE_RULE,
            cRefs.DELETE_RULE
        FROM INFORMATION_SCHEMA.COLUMNS as cols
        LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS refs
            ON refs.TABLE_SCHEMA=cols.TABLE_SCHEMA
            AND refs.REFERENCED_TABLE_SCHEMA=cols.TABLE_SCHEMA
            AND refs.TABLE_NAME=cols.TABLE_NAME
            AND refs.COLUMN_NAME=cols.COLUMN_NAME
        LEFT JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS cons
            ON cons.TABLE_SCHEMA=cols.TABLE_SCHEMA
            AND cons.TABLE_NAME=cols.TABLE_NAME
            AND cons.CONSTRAINT_NAME=refs.CONSTRAINT_NAME
        LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS AS cRefs
            ON cRefs.CONSTRAINT_SCHEMA=cols.TABLE_SCHEMA
            AND cRefs.CONSTRAINT_NAME=refs.CONSTRAINT_NAME
        WHERE
            cols.TABLE_NAME = '{$table}'
            AND cols.TABLE_SCHEMA = DATABASE()
            AND refs.REFERENCED_TABLE_NAME IS NOT NULL
            AND cons.CONSTRAINT_TYPE = 'FOREIGN KEY'
        ;"
    )->fetch_all(MYSQLI_ASSOC);
}

function getIndentation($level)
{
    return str_repeat('    ', $level);
}

$connection = getMysqliConnection([
    'name' => $argv[1],
    'user' => $argv[2],
    'pass' => $argv[3],
    'host' => ($argc >= 5 ? $argv[4] : 'localhost'),
    'port' => ($argc >= 6 ? $argv[5] : '3306'),
]);
if (!$connection) {
    echo 'Connection to database failed.';
    exit;
}

echo '<?php' . PHP_EOL;
echo 'use Phinx\Migration\AbstractMigration;' . PHP_EOL;
echo 'use Phinx\Db\Adapter\MysqlAdapter;' . PHP_EOL;
echo PHP_EOL;
echo 'class InitialMigration extends AbstractMigration' . PHP_EOL;
echo '{' . PHP_EOL;
echo createMigration($connection);
echo '}' . PHP_EOL;
