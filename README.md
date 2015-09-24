# MySQLi DBHandler

This is a very basic DB adapter based on Mysqli.

  - one-file solution
  - basic filter for input

### Version
0.1

### Installation

Just put the file into your project and include it.
After including it, you need to initialise it like:
```php
$db = new DBHandler(array(
    'server' => 'localhost',
	'user' => 'root',
	'pass' => '',
	'database' => 'myDB'
));
```


### Development

Want to contribute? Great! Fork, change, pull request!

### Todos

 - Write Tests

License
----

MIT

