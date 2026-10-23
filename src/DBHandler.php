<?php

declare(strict_types=1);

namespace DBHandler;

use InvalidArgumentException;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use Throwable;

/**
 * A small mysqli based database handler.
 *
 * Every value goes to the database separately from the SQL text, through a prepared statement.
 * Failures are reported with a DatabaseException, so a transaction can be rolled back reliably.
 * Rows are returned with native types: whole numbers as int, decimals as float, the rest as text.
 */
class DBHandler
{
    private ?mysqli $mysqli = null;

    private int|string $lastInsertId = 0;

    private int|string $affectedRows = 0;

    /**
     * @param array{server?: string, user?: string, pass?: string, database?: string, charset?: string, port?: int|null} $config
     *        The server can also carry the port, like "localhost:3307".
     *
     * @throws DatabaseException When the connection cannot be made
     */
    public function __construct(array $config = [])
    {
        if (!extension_loaded('mysqli')) {
            throw new DatabaseException('The mysqli extension is not installed');
        }

        $settings = array_merge(
            [
                'server' => 'localhost',
                'user' => '',
                'pass' => '',
                'database' => '',
                'charset' => 'utf8mb4',
                'port' => null,
            ],
            array_intersect_key($config, ['server' => 1, 'user' => 1, 'pass' => 1, 'database' => 1, 'charset' => 1, 'port' => 1])
        );

        [$host, $port] = self::splitServer((string) $settings['server'], $settings['port']);

        $mysqli = new mysqli();
        $mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);

        try {
            $connected = @$mysqli->real_connect(
                $host,
                (string) $settings['user'],
                (string) $settings['pass'],
                $settings['database'] === '' ? null : (string) $settings['database'],
                $port
            );

            if (!$connected || $mysqli->connect_errno !== 0) {
                throw new DatabaseException(
                    'Failed to connect to MySQL: (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error,
                    $mysqli->connect_errno
                );
            }

            if (!$mysqli->set_charset((string) $settings['charset'])) {
                throw new DatabaseException('Could not set the connection charset: ' . $mysqli->error, $mysqli->errno);
            }
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException('Failed to connect to MySQL: (' . $e->getCode() . ') ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        $this->mysqli = $mysqli;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Closes the connection. Closing twice does no harm.
     */
    public function close(): void
    {
        if ($this->mysqli === null) {
            return;
        }

        try {
            $this->mysqli->close();
        } catch (Throwable) {
            // Nothing to do when closing fails
        }

        $this->mysqli = null;
    }

    /**
     * Reads rows from a table.
     *
     * The extra SQL comes after "WHERE 1 = 1", so it can start with AND, ORDER BY and so on.
     * It is used as it is, so user input must only get in through placeholders.
     *
     * @param array<int|string, mixed> $params Values for the placeholders of the extra SQL
     *
     * @return list<array<string, mixed>>
     */
    public function get_table_data(
        string $table_name,
        ?int $limit = null,
        ?int $offset = null,
        string $extra_sql = '',
        array $params = []
    ): array {
        $rows = $this->custom_query(Sql::select($table_name, $limit, $offset, $extra_sql), $params);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Counts the rows of a table, with the same extra SQL rules as get_table_data().
     *
     * @param array<int|string, mixed> $params Values for the placeholders of the extra SQL
     */
    public function get_table_total(string $table_name, string $extra_sql = '', string $total_field = 'id', array $params = []): int
    {
        $rows = $this->custom_query(Sql::count($table_name, $extra_sql, $total_field), $params);

        return is_array($rows) ? (int) ($rows[0]['total'] ?? 0) : 0;
    }

    /**
     * Inserts a row and returns the new auto increment id (0 when the table has none).
     *
     * @param array<string, mixed> $data Column names and their values
     */
    public function insert_data(string $table_name, array $data): int|string
    {
        [$sql, $params] = Sql::insert($table_name, $data);

        $this->run($sql, $params);

        return $this->lastInsertId;
    }

    /**
     * Updates rows. The values of the matching fields are taken from $data too.
     * A matching value that is an array matches any of its items, null matches NULL.
     *
     * @param array<string, mixed> $data Column names and values, including the ones to match on
     * @param list<string> $fields The columns to set, all except the matching ones when empty
     * @param list<string> $by_fields The columns to match on
     *
     * @throws InvalidArgumentException When there is nothing to set or nothing to match on
     */
    public function update_data(string $table_name, array $data, array $fields, array $by_fields): bool
    {
        [$sql, $params] = Sql::update($table_name, $data, $fields, $by_fields);

        return $this->run($sql, $params) === true;
    }

    /**
     * Deletes rows. Matching works like in update_data().
     *
     * @param array<string, mixed> $data Holds the values of the matching fields
     * @param list<string> $by_fields The columns to match on
     *
     * @throws InvalidArgumentException When there is nothing to match on, so everything would be deleted
     */
    public function delete_data(string $table_name, array $data, array $by_fields): bool
    {
        [$sql, $params] = Sql::delete($table_name, $data, $by_fields);

        return $this->run($sql, $params) === true;
    }

    /**
     * Runs any query. Named placeholders (:name) are bound as values, and an array value
     * becomes a list, so "id IN :ids" works. Question marks take a plain list of values.
     * Placeholders inside quoted text are not replaced, build such text in PHP and bind it.
     *
     * @param array<int|string, mixed> $data Values for the placeholders
     *
     * @return list<array<string, mixed>>|true The rows of a query that returns rows, true for any other query
     *
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When a value is missing or has an unsupported type
     */
    public function custom_query(string $sql, array $data = []): array|true
    {
        [$rewritten, $params] = Sql::rewrite($sql, $data);

        return $this->run($rewritten, $params);
    }

    /**
     * The id generated by the last insert.
     */
    public function last_insert_id(): int|string
    {
        return $this->lastInsertId;
    }

    /**
     * The number of rows the last query changed.
     */
    public function affected_rows(): int|string
    {
        return $this->affectedRows;
    }

    public function begin_transaction(): void
    {
        $this->wrap(fn (): bool => $this->connection()->begin_transaction());
    }

    public function commit_transaction(): void
    {
        $this->wrap(fn (): bool => $this->connection()->commit());
    }

    public function rollback_transaction(): void
    {
        $this->wrap(fn (): bool => $this->connection()->rollback());
    }

    /**
     * Runs the callback in a transaction. It is committed when the callback returns
     * and rolled back when it throws, the exception is thrown again.
     *
     * @template T
     *
     * @param callable(self): T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->begin_transaction();

        try {
            $result = $callback($this);
            $this->commit_transaction();

            return $result;
        } catch (Throwable $e) {
            try {
                $this->rollback_transaction();
            } catch (Throwable) {
                // The original problem is more useful than a failed rollback
            }

            throw $e;
        }
    }

    /**
     * @param list<int|float|string|null> $params
     *
     * @return list<array<string, mixed>>|true
     */
    private function run(string $sql, array $params = []): array|true
    {
        $connection = $this->connection();

        try {
            if ($params === []) {
                $result = $connection->query($sql);

                if ($result === false) {
                    throw new DatabaseException($connection->error, $connection->errno);
                }

                $this->lastInsertId = $connection->insert_id;
                $this->affectedRows = $connection->affected_rows;

                return $result instanceof mysqli_result ? $this->rows($result) : true;
            }

            $statement = $connection->prepare($sql);

            if ($statement === false) {
                throw new DatabaseException($connection->error, $connection->errno);
            }

            try {
                $statement->bind_param(Sql::types($params), ...$params);

                if (!$statement->execute()) {
                    throw new DatabaseException($statement->error, $statement->errno);
                }

                $this->lastInsertId = $statement->insert_id;
                $this->affectedRows = $statement->affected_rows;

                $result = $statement->get_result();

                return $result instanceof mysqli_result ? $this->rows($result) : true;
            } finally {
                $statement->close();
            }
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mysqli_result $result): array
    {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return $rows;
    }

    /**
     * Runs a call on the connection and turns its failures into a DatabaseException.
     *
     * @param callable(): bool $call
     */
    private function wrap(callable $call): void
    {
        try {
            if ($call() === false) {
                $connection = $this->connection();

                throw new DatabaseException($connection->error, $connection->errno);
            }
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    private function connection(): mysqli
    {
        return $this->mysqli ?? throw new DatabaseException('The connection is closed');
    }

    /**
     * Splits "host:port" and leaves everything else (names, sockets, IPv6 addresses) as it is.
     *
     * @return array{0: string, 1: int|null}
     */
    private static function splitServer(string $server, int|string|null $port): array
    {
        if ($port !== null && $port !== '') {
            return [$server, (int) $port];
        }

        if (substr_count($server, ':') === 1) {
            [$host, $candidate] = explode(':', $server);

            if ($candidate !== '' && ctype_digit($candidate)) {
                return [$host, (int) $candidate];
            }
        }

        return [$server, null];
    }
}
