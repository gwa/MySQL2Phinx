# MySQL2Phinx

A simple cli php script to generate a [phinx](https://github.com/robmorgan/phinx) migration from an existing MySQL database.

## Usage

```
$ php -f mysql2phinx.php [database] [user] [password] > migration.php
```

Will create an initial migration class in the file `migration.php` for all tables in the database passed. 

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
