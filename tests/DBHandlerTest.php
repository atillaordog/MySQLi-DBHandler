<?php

declare(strict_types=1);

namespace DBHandler\Tests;

use DBHandler\DatabaseException;
use DBHandler\DBHandler;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Runs against a real MySQL or MariaDB database, see phpunit.xml.dist for the settings.
 * The tests create and drop one table, so use an empty database made for testing.
 * They are skipped when the database cannot be reached.
 */
final class DBHandlerTest extends TestCase
{
    private const string TABLE = 'dbhandler_test';

    private ?DBHandler $db = null;

    /**
     * @return array{server: string, user: string, pass: string, database: string}
     */
    private static function config(): array
    {
        return [
            'server' => self::env('DBHANDLER_TEST_HOST', '127.0.0.1'),
            'user' => self::env('DBHANDLER_TEST_USER', 'root'),
            'pass' => self::env('DBHANDLER_TEST_PASS', ''),
            'database' => self::env('DBHANDLER_TEST_DATABASE', 'dbhandler_test'),
        ];
    }

    private static function env(string $name, string $default): string
    {
        // A variable set in the shell wins over the defaults PHPUnit copies from phpunit.xml.dist
        $value = getenv($name);

        if ($value === false) {
            $value = $_ENV[$name] ?? null;
        }

        return is_string($value) ? $value : $default;
    }

    protected function setUp(): void
    {
        try {
            $this->db = new DBHandler(self::config());
        } catch (DatabaseException $e) {
            $this->markTestSkipped('No test database available: ' . $e->getMessage());
        }

        $this->db->custom_query('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        $this->db->custom_query(
            'CREATE TABLE `' . self::TABLE . '` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . '`name` VARCHAR(100) NULL, '
            . '`slug` VARCHAR(100) NULL, '
            . '`amount` INT NULL, '
            . 'PRIMARY KEY (`id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }

        // Close first, which also drops anything left open, then clean up on a fresh connection
        // because a test may have closed its own
        $this->db->close();

        $cleanup = new DBHandler(self::config());
        $cleanup->custom_query('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        $cleanup->close();
    }

    private function db(): DBHandler
    {
        return $this->db ?? throw new LogicException('There is no connection');
    }

    private function insertRow(?string $name, ?string $slug = null, ?int $amount = null): int|string
    {
        return $this->db()->insert_data(self::TABLE, ['name' => $name, 'slug' => $slug, 'amount' => $amount]);
    }

    /**
     * @return list<mixed>
     */
    private function column(string $column): array
    {
        return array_column($this->db()->get_table_data(self::TABLE, null, null, 'ORDER BY `id`'), $column);
    }

    // ---- reading and writing ----

    public function testInsertReturnsTheNewIdAndRowsComeBackWithNativeTypes(): void
    {
        $id = $this->insertRow('first', 'first-slug', 5);

        $this->assertSame(1, $id);
        $this->assertSame(
            [['id' => 1, 'name' => 'first', 'slug' => 'first-slug', 'amount' => 5]],
            $this->db()->get_table_data(self::TABLE)
        );
    }

    public function testUnicodeQuotesAndMarkupSurviveARoundTrip(): void
    {
        $name = "\u{00D6}rd\u{00F6}g \u{0151}\u{0171} John's \"cat\" back\\slash <b>bold</b>";

        $this->insertRow($name);

        $this->assertSame([$name], $this->column('name'));
    }

    public function testTextThatLooksLikeANumberIsNotChanged(): void
    {
        foreach (['007', '1e3', '1.50'] as $text) {
            $this->insertRow($text);
        }

        $this->assertSame(['007', '1e3', '1.50'], $this->column('name'));
    }

    public function testNullIsStoredAsNull(): void
    {
        $this->insertRow(null);

        $this->assertSame([null], $this->column('name'));
    }

    public function testUpdateWithSeveralMatchingFields(): void
    {
        $a = $this->insertRow('a', 's1', 1);
        $this->insertRow('b', 's2', 1);

        $this->assertTrue($this->db()->update_data(self::TABLE, ['id' => $a, 'slug' => 's1', 'amount' => 9], ['amount'], ['id', 'slug']));
        $this->assertSame(1, $this->db()->affected_rows());
        $this->assertSame([9, 1], $this->column('amount'));

        // Both fields have to match, the slug is wrong here
        $this->assertTrue($this->db()->update_data(self::TABLE, ['id' => $a, 'slug' => 'wrong', 'amount' => 7], ['amount'], ['id', 'slug']));
        $this->assertSame(0, $this->db()->affected_rows());
        $this->assertSame([9, 1], $this->column('amount'));
    }

    public function testUpdateAndDeleteWithLists(): void
    {
        $ids = [$this->insertRow('a'), $this->insertRow('b'), $this->insertRow('c')];

        $this->db()->update_data(self::TABLE, ['id' => [$ids[0], $ids[1]], 'amount' => 9], ['amount'], ['id']);
        $this->assertSame([9, 9, null], $this->column('amount'));

        $this->db()->delete_data(self::TABLE, ['id' => [$ids[0], $ids[2]]], ['id']);
        $this->assertSame(['b'], $this->column('name'));
    }

    public function testDeleteWithoutMatchingFieldsIsRefused(): void
    {
        $this->insertRow('a');

        $this->expectException(InvalidArgumentException::class);

        $this->db()->delete_data(self::TABLE, ['id' => 1], []);
    }

    public function testTotals(): void
    {
        $this->insertRow('a', null, 1);
        $this->insertRow('b', null, 2);
        $this->insertRow('c', null, 3);

        $this->assertSame(3, $this->db()->get_table_total(self::TABLE));
        $this->assertSame(3, $this->db()->get_table_total(self::TABLE, '', '*'));
        $this->assertSame(2, $this->db()->get_table_total(self::TABLE, 'AND `amount` > :min', 'id', ['min' => 1]));
    }

    public function testLimitAndOffset(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $this->insertRow($name);
        }

        $rows = $this->db()->get_table_data(self::TABLE, 2, 1, 'ORDER BY `id`');

        $this->assertSame(['b', 'c'], array_column($rows, 'name'));
    }

    // ---- custom queries ----

    public function testNamedPlaceholdersAndLists(): void
    {
        $a = $this->insertRow('a', null, 1);
        $b = $this->insertRow('b', null, 2);
        $c = $this->insertRow('c', null, 3);

        $rows = $this->db()->custom_query(
            'SELECT `name` FROM `' . self::TABLE . '` WHERE `id` IN :ids AND `amount` >= :min ORDER BY `id`',
            ['ids' => [$a, $b, $c], 'min' => 2]
        );

        $this->assertSame([['name' => 'b'], ['name' => 'c']], $rows);
    }

    public function testQuotedTextAndLookalikeValuesAreNotTouched(): void
    {
        $rows = $this->db()->custom_query(
            "SELECT ':x' AS literal, :a AS a, :b AS b",
            ['a' => 'x:b', 'b' => 'y']
        );

        $this->assertSame([['literal' => ':x', 'a' => 'x:b', 'b' => 'y']], $rows);
    }

    public function testBoundIntegersWorkInALimit(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->insertRow($name);
        }

        $rows = $this->db()->custom_query('SELECT `name` FROM `' . self::TABLE . '` ORDER BY `id` LIMIT :n', ['n' => 2]);

        $this->assertSame([['name' => 'a'], ['name' => 'b']], $rows);
    }

    public function testQuestionMarksTakeAPlainList(): void
    {
        $this->insertRow('a', null, 1);
        $this->insertRow('b', null, 2);

        $rows = $this->db()->custom_query('SELECT `name` FROM `' . self::TABLE . '` WHERE `amount` = ?', [2]);

        $this->assertSame([['name' => 'b']], $rows);
    }

    public function testAQueryWithoutRowsReturnsTrueAndReportsTheChangedRows(): void
    {
        $this->insertRow('a');
        $this->insertRow('b');

        $this->assertTrue($this->db()->custom_query('UPDATE `' . self::TABLE . '` SET `amount` = :amount', ['amount' => 4]));
        $this->assertSame(2, $this->db()->affected_rows());
    }

    public function testTheConnectionUsesUtf8mb4(): void
    {
        $rows = $this->db()->custom_query('SELECT @@character_set_client AS charset');

        $this->assertSame('utf8mb4', $rows[0]['charset']);
    }

    // ---- transactions ----

    public function testRollbackAndCommit(): void
    {
        $this->db()->begin_transaction();
        $this->insertRow('rolled back');
        $this->db()->rollback_transaction();

        $this->assertSame(0, $this->db()->get_table_total(self::TABLE));

        $this->db()->begin_transaction();
        $this->insertRow('kept');
        $this->db()->commit_transaction();

        $this->assertSame(['kept'], $this->column('name'));
    }

    public function testTheTransactionHelperCommitsAndReturnsTheResult(): void
    {
        $total = $this->db()->transaction(function (DBHandler $db): int {
            $db->insert_data(self::TABLE, ['name' => 'a']);

            return $db->get_table_total(self::TABLE);
        });

        $this->assertSame(1, $total);
        $this->assertSame(['a'], $this->column('name'));
    }

    public function testTheTransactionHelperRollsBackWhenTheCallbackThrows(): void
    {
        try {
            $this->db()->transaction(function (DBHandler $db): void {
                $db->insert_data(self::TABLE, ['name' => 'a']);

                throw new RuntimeException('boom');
            });

            $this->fail('The exception should have been thrown again');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, $this->db()->get_table_total(self::TABLE));
    }

    // ---- failures ----

    public function testAFailedQueryThrowsWithTheMysqlErrorNumber(): void
    {
        try {
            $this->db()->custom_query('SELECT * FROM `dbhandler_missing_table`');

            $this->fail('A DatabaseException should have been thrown');
        } catch (DatabaseException $e) {
            // 1146 is "table doesn't exist"
            $this->assertSame(1146, $e->getCode());
        }
    }

    public function testAFailedInsertThrows(): void
    {
        $this->expectException(DatabaseException::class);

        $this->db()->insert_data(self::TABLE, ['no_such_column' => 1]);
    }

    public function testAClosedConnectionThrows(): void
    {
        $this->db()->close();

        $this->expectException(DatabaseException::class);

        $this->db()->custom_query('SELECT 1');
    }

    public function testClosingTwiceIsHarmless(): void
    {
        $this->db()->close();
        $this->db()->close();

        $this->assertTrue(true);
    }

    public function testWrongCredentialsThrow(): void
    {
        $config = self::config();
        $config['user'] = 'no_such_user_xyz';
        $config['pass'] = 'wrong password';

        $this->expectException(DatabaseException::class);

        new DBHandler($config);
    }
}
