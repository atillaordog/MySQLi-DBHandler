DBHandler
=========

A small database handler for MySQL and MariaDB, built on `mysqli`.

- Every value travels to the database separately from the SQL text, through a prepared statement.
- Failures throw a `DatabaseException`, so transactions can be rolled back reliably.
- Values keep their type. Text that looks like a number stays text, and `null` is stored as `NULL`.

Requirements
------------

PHP 8.3 or newer with the `mysqli` extension.

Installation
------------

```
composer require dbhandler/dbhandler
```

Without Composer, include the bundled autoloader: `require 'DBHandler/autoload.php';`

How to use
----------

```php
use DBHandler\DBHandler;

$db = new DBHandler([
    'server'   => 'localhost',   // "host:port" works too
    'user'     => 'root',
    'pass'     => '',
    'database' => 'shop',
    'charset'  => 'utf8mb4',     // the default
]);

// Insert returns the new auto increment id
$id = $db->insert_data('products', ['name' => 'Chair', 'price' => 49.9]);

// Read rows, the extra SQL comes after "WHERE 1 = 1"
$rows = $db->get_table_data('products', 10, 0, 'AND price > :min ORDER BY id', ['min' => 20]);

// Update: the values of the matching fields are in the same array
$db->update_data('products', ['id' => $id, 'price' => 39.9], ['price'], ['id']);

// Delete, a list of ids matches any of them
$db->delete_data('products', ['id' => [1, 2, 3]], ['id']);

// Count
$total = $db->get_table_total('products', 'AND price > :min', 'id', ['min' => 20]);

// Anything else
$rows = $db->custom_query('SELECT * FROM products WHERE id IN :ids', ['ids' => [1, 2, 3]]);
```

Rows come back with native types: whole numbers as `int`, decimals as `float`, everything else as text.

### Placeholders

- `:name` placeholders are bound as values. An array value becomes a list, so `IN :ids` works (an empty array matches nothing).
- Question marks work with a plain list of values: `custom_query('... WHERE a = ? AND b = ?', [1, 'x'])`. The two styles cannot be mixed.
- Placeholders inside quoted text or comments are **not** replaced. To search with `LIKE`, build the pattern in PHP and bind it: `custom_query('... LIKE :pattern', ['pattern' => '%-' . $id . '-%'])`.
- The extra SQL of `get_table_data()` and `get_table_total()` is used as it is. Never put user input into it, bind it through placeholders.

### Matching in update and delete

`update_data()` and `delete_data()` match on the fields listed in `$by_fields`, whose values are taken from the data array.

- An array value matches any of its items, `null` matches `NULL`.
- At least one matching field is required, so a forgotten condition can never touch the whole table.
- When `$fields` is empty in `update_data()`, every column except the matching ones is set.

### Transactions

```php
$db->begin_transaction();
$db->commit_transaction();
$db->rollback_transaction();

// Or let the handler do it: commits when the callback returns, rolls back when it throws
$result = $db->transaction(function (DBHandler $db) {
    $db->insert_data('orders', [...]);
    return $db->last_insert_id();
});
```

Other helpers: `last_insert_id()`, `affected_rows()` and `close()`.

Upgrading from 0.x
------------------

Version 2 is a rewrite of the internals with the same method names. The breaking changes:

- PHP 8.3 or newer is required, and the class is `DBHandler\DBHandler`. The old global class name still works through an alias.
- **Errors throw a `DatabaseException`** instead of returning `false`, `0` or an empty array. Wrap calls in a transaction and let the exception roll it back.
- **Placeholders are real prepared statements.** A placeholder inside quotes (`LIKE "%-:id-%"`) is no longer replaced, bind the whole pattern instead.
- **No more input changes.** Strings are no longer run through `strip_tags` and `FILTER_SANITIZE_STRING`, so quotes are not turned into HTML entities. Escape output where it is displayed.
- `null` is stored as `NULL` (it used to become an empty string), and text that looks like a number is no longer converted (`007` stays `007`).
- Rows come back with native types instead of all text.
- `update_data()` and `delete_data()` need the matching fields, and joining several of them with AND works (it used to produce invalid SQL).
- `insert_data()` returns the new id and no longer takes a primary key argument.
- `custom_query()` returns the rows, or `true` for a query without rows.
- The package no longer reads `config.php`. Pass the settings to the constructor.

Tests
-----

```
composer install
composer test
```

The unit tests need nothing else. The integration tests need an empty MySQL or MariaDB database that they can create and drop a table in, and they are skipped when it cannot be reached:

```sql
CREATE DATABASE dbhandler_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

The defaults are `127.0.0.1`, user `root`, no password and the database `dbhandler_test`. Change them with the environment variables `DBHANDLER_TEST_HOST`, `DBHANDLER_TEST_USER`, `DBHANDLER_TEST_PASS` and `DBHANDLER_TEST_DATABASE`, or copy `phpunit.xml.dist` to `phpunit.xml` and edit it.
