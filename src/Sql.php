<?php

declare(strict_types=1);

namespace DBHandler;

use InvalidArgumentException;
use Stringable;

/**
 * Builds the SQL text for DBHandler and nothing else, so it can be tested without a database.
 *
 * Values never end up inside the SQL text. They are returned next to it as a list of
 * parameters, and the database receives them separately through a prepared statement.
 */
final class Sql
{
    /**
     * Quotes a table name. A name with a dot is read as database.table.
     */
    public static function table(string $name): string
    {
        if ($name === '') {
            throw new InvalidArgumentException('The table name cannot be empty');
        }

        return implode('.', array_map(self::column(...), explode('.', $name)));
    }

    /**
     * Quotes a column name.
     */
    public static function column(string $name): string
    {
        if ($name === '') {
            throw new InvalidArgumentException('The column name cannot be empty');
        }

        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Turns a value into something that can be bound to a statement.
     * Values keep their type: a text that looks like a number stays text.
     */
    public static function normalize(mixed $value): int|float|string|null
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => (int) $value,
            is_int($value), is_float($value), is_string($value) => $value,
            $value instanceof Stringable => (string) $value,
            default => throw new InvalidArgumentException('Unsupported value type: ' . get_debug_type($value)),
        };
    }

    /**
     * The type string for bind_param(): i for integers, d for floats, s for everything else.
     *
     * @param array<int, int|float|string|null> $params
     */
    public static function types(array $params): string
    {
        $types = '';

        foreach ($params as $param) {
            $types .= match (true) {
                is_int($param) => 'i',
                is_float($param) => 'd',
                default => 's',
            };
        }

        return $types;
    }

    /**
     * Replaces the named placeholders (:name) of a query with positional ones (?).
     *
     * Placeholders inside quoted text, quoted names and comments are left alone.
     * An array value becomes a list, so "id IN :ids" turns into "id IN (?,?,?)".
     * A query that uses question marks takes the values as a plain list.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array{0: string, 1: list<int|float|string|null>} The query and its parameters
     *
     * @throws InvalidArgumentException When a value is missing or has an unsupported type
     */
    public static function rewrite(string $sql, array $data = []): array
    {
        $named = [];

        foreach ($data as $key => $value) {
            $named[ltrim((string) $key, ':')] = $value;
        }

        $out = '';
        $params = [];
        $found = false;
        $questionMarks = 0;
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = self::endOfQuoted($sql, $i);
            } elseif ($char === '#' || ($char === '-' && $next === '-' && self::startsComment($sql, $i))) {
                $newline = strpos($sql, "\n", $i);
                $end = $newline === false ? $length : $newline + 1;
            } elseif ($char === '/' && $next === '*') {
                $close = strpos($sql, '*/', $i + 2);
                $end = $close === false ? $length : $close + 2;
            } elseif ($char === '?') {
                $questionMarks++;
                $end = $i + 1;
            } elseif (
                $char === ':'
                && ($i === 0 || $sql[$i - 1] !== ':')
                && preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $sql, $match, 0, $i + 1) === 1
            ) {
                $name = $match[0];

                if (!array_key_exists($name, $named)) {
                    throw new InvalidArgumentException('No value given for :' . $name);
                }

                $found = true;
                $value = $named[$name];

                if (is_array($value)) {
                    if ($value === []) {
                        // A list without items matches nothing
                        $out .= '(NULL)';
                    } else {
                        $out .= '(' . implode(',', array_fill(0, count($value), '?')) . ')';

                        foreach ($value as $item) {
                            $params[] = self::normalize($item);
                        }
                    }
                } else {
                    $out .= '?';
                    $params[] = self::normalize($value);
                }

                $i += 1 + strlen($name);

                continue;
            } else {
                $end = $i + 1;
            }

            $out .= substr($sql, $i, $end - $i);
            $i = $end;
        }

        if ($found && $questionMarks > 0) {
            throw new InvalidArgumentException('Named and positional placeholders cannot be mixed');
        }

        if (!$found) {
            if ($questionMarks === 0) {
                // No placeholders, so there is nothing to bind
                return [$sql, []];
            }

            $values = array_values($data);

            if (count($values) !== $questionMarks) {
                throw new InvalidArgumentException(
                    sprintf('%d values given for %d placeholders', count($values), $questionMarks)
                );
            }

            return [$sql, array_map(self::normalize(...), $values)];
        }

        return [$out, $params];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: string, 1: list<int|float|string|null>}
     */
    public static function insert(string $table, array $data): array
    {
        if ($data === []) {
            throw new InvalidArgumentException('There is no data to insert');
        }

        $columns = [];
        $params = [];

        foreach ($data as $column => $value) {
            $columns[] = self::column((string) $column);
            $params[] = self::normalize($value);
        }

        $sql = 'INSERT INTO ' . self::table($table)
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($params), '?')) . ')';

        return [$sql, $params];
    }

    /**
     * @param array<string, mixed> $data The values to work with, including the ones to match on
     * @param list<string> $fields The columns to set, all except the matching ones when empty
     * @param list<string> $by_fields The columns to match on, their values are in $data
     *
     * @return array{0: string, 1: list<int|float|string|null>}
     *
     * @throws InvalidArgumentException When there is nothing to set, or nothing to match on
     */
    public static function update(string $table, array $data, array $fields, array $by_fields): array
    {
        $by = self::matchingFields($data, $by_fields);
        $fields = array_map(strval(...), $fields);

        $set = [];
        $params = [];

        foreach ($data as $column => $value) {
            $column = (string) $column;
            $wanted = $fields === [] ? !in_array($column, $by, true) : in_array($column, $fields, true);

            if ($wanted) {
                $set[] = self::column($column) . ' = ?';
                $params[] = self::normalize($value);
            }
        }

        if ($set === []) {
            throw new InvalidArgumentException('There is nothing to update');
        }

        [$where, $whereParams] = self::where($data, $by);

        $sql = 'UPDATE ' . self::table($table) . ' SET ' . implode(', ', $set) . ' WHERE ' . $where;

        return [$sql, [...$params, ...$whereParams]];
    }

    /**
     * @param array<string, mixed> $data The values to match on
     * @param list<string> $by_fields The columns to match on
     *
     * @return array{0: string, 1: list<int|float|string|null>}
     *
     * @throws InvalidArgumentException When there is nothing to match on, so everything would be deleted
     */
    public static function delete(string $table, array $data, array $by_fields): array
    {
        $by = self::matchingFields($data, $by_fields);

        [$where, $params] = self::where($data, $by);

        return ['DELETE FROM ' . self::table($table) . ' WHERE ' . $where, $params];
    }

    /**
     * The extra SQL is added as it is, it must never contain user input. Use placeholders for that.
     */
    public static function select(string $table, ?int $limit = null, ?int $offset = null, string $extra_sql = ''): string
    {
        $sql = 'SELECT * FROM ' . self::table($table) . ' WHERE 1 = 1 ' . $extra_sql;

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);

            if ($offset !== null) {
                $sql .= ' OFFSET ' . max(0, $offset);
            }
        }

        return $sql;
    }

    public static function count(string $table, string $extra_sql = '', string $field = 'id'): string
    {
        $counted = $field === '*' ? '*' : self::column($field);

        return 'SELECT COUNT(' . $counted . ') AS total FROM ' . self::table($table) . ' WHERE 1 = 1 ' . $extra_sql;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $by_fields
     *
     * @return list<string>
     */
    private static function matchingFields(array $data, array $by_fields): array
    {
        $by = array_map(strval(...), $by_fields);

        if ($by === []) {
            throw new InvalidArgumentException('At least one field is needed to match on');
        }

        foreach ($by as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException('The value of the matching field "' . $field . '" is missing');
            }
        }

        return $by;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $by
     *
     * @return array{0: string, 1: list<int|float|string|null>}
     */
    private static function where(array $data, array $by): array
    {
        $conditions = [];
        $params = [];

        foreach ($by as $field) {
            $value = $data[$field];
            $column = self::column($field);

            if (is_array($value)) {
                if ($value === []) {
                    $conditions[] = '1 = 0';

                    continue;
                }

                $conditions[] = $column . ' IN (' . implode(', ', array_fill(0, count($value), '?')) . ')';

                foreach ($value as $item) {
                    $params[] = self::normalize($item);
                }
            } elseif ($value === null) {
                $conditions[] = $column . ' IS NULL';
            } else {
                $conditions[] = $column . ' = ?';
                $params[] = self::normalize($value);
            }
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Finds the end of a quoted text or name that starts at the given position.
     */
    private static function endOfQuoted(string $sql, int $start): int
    {
        $quote = $sql[$start];
        $length = strlen($sql);
        $i = $start + 1;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === '\\' && $quote !== '`') {
                $i += 2;

                continue;
            }

            if ($char === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    // A doubled quote is an escaped quote
                    $i += 2;

                    continue;
                }

                return $i + 1;
            }

            $i++;
        }

        return $length;
    }

    /**
     * Two dashes only start a comment when whitespace, a control character or the end follows them.
     */
    private static function startsComment(string $sql, int $position): bool
    {
        $gap = $sql[$position + 2] ?? '';

        return $gap === '' || ctype_space($gap) || ctype_cntrl($gap);
    }
}
