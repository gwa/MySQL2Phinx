# MySQL2Phinx

A simple cli php script to generate a [phinx](https://github.com/robmorgan/phinx) migration from an existing MySQL database.

## Usage

```
$ php -f mysql2phinx.php [database] [user] [password] > migration.php
```

Copy migration commands to your migration file.

You might need to add the following to the top of your file:

```
use Phinx\Db\Adapter\MysqlAdapter;
```

## Caveat

The `id` column will be unsigned. Phinx does not currently supported unsigned primary columns. There is [a workaround](https://github.com/robmorgan/phinx/issues/250).

### TODOs

Not all phinx functionality is covered! **Check your migration code before use!**

Currently **not supported**:

* [ ] Foreign keys
* Column types:
  * [ ] `float`
  * [ ] `decimal`
  * [ ] `time`
  * [ ] `binary`
  * [ ] `boolean`
