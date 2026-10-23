<?php

declare(strict_types=1);

namespace DBHandler\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use DBHandler\Sql;

final class SqlTest extends TestCase
{
    // ---- names ----

    public function testColumnsAreQuoted(): void
    {
        $this->assertSame('`id`', Sql::column('id'));
    }

    public function testBackticksInNamesAreEscaped(): void
    {
        $this->assertSame('`a``b`', Sql::column('a`b'));
        $this->assertSame('`x``; DROP TABLE users; --`', Sql::column('x`; DROP TABLE users; --'));
    }

    public function testATableWithADotIsReadAsDatabaseAndTable(): void
    {
        $this->assertSame('`shop`.`orders`', Sql::table('shop.orders'));
        $this->assertSame('`orders`', Sql::table('orders'));
    }

    public function testEmptyNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sql::table('');
    }

    public function testEmptyColumnNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sql::column('');
    }

    // ---- values ----

    public function testValuesKeepTheirTypes(): void
    {
        $this->assertSame(5, Sql::normalize(5));
        $this->assertSame(5.5, Sql::normalize(5.5));
        $this->assertSame('007', Sql::normalize('007'));
        $this->assertSame('1e3', Sql::normalize('1e3'));
        $this->assertSame('1.50', Sql::normalize('1.50'));
        $this->assertNull(Sql::normalize(null));
    }

    public function testBooleansBecomeIntegers(): void
    {
        $this->assertSame(1, Sql::normalize(true));
        $this->assertSame(0, Sql::normalize(false));
    }

    public function testStringableObjectsBecomeText(): void
    {
        $object = new class () {
            public function __toString(): string
            {
                return 'text';
            }
        };

        $this->assertSame('text', Sql::normalize($object));
    }

    public static function unsupportedValues(): array
    {
        return [
            'plain object' => [new stdClass()],
            'array' => [[1, 2]],
        ];
    }

    #[DataProvider('unsupportedValues')]
    public function testUnsupportedValuesAreRejected(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sql::normalize($value);
    }

    public function testTypeString(): void
    {
        $this->assertSame('idss', Sql::types([1, 2.5, 'x', null]));
        $this->assertSame('', Sql::types([]));
    }

    // ---- named placeholders ----

    public function testNamedPlaceholdersBecomeQuestionMarks(): void
    {
        [$sql, $params] = Sql::rewrite(
            'SELECT * FROM t WHERE id = :id AND name = :name',
            ['id' => 5, 'name' => 'x']
        );

        $this->assertSame('SELECT * FROM t WHERE id = ? AND name = ?', $sql);
        $this->assertSame([5, 'x'], $params);
    }

    public function testAPlaceholderUsedTwiceIsBoundTwice(): void
    {
        [$sql, $params] = Sql::rewrite('SELECT :a, :a', ['a' => 'x']);

        $this->assertSame('SELECT ?, ?', $sql);
        $this->assertSame(['x', 'x'], $params);
    }

    public function testParametersFollowTheOrderInTheQuery(): void
    {
        [, $params] = Sql::rewrite('SELECT :second, :first', ['first' => 1, 'second' => 2]);

        $this->assertSame([2, 1], $params);
    }

    public function testKeysMayStartWithAColon(): void
    {
        [$sql, $params] = Sql::rewrite('SELECT :a', [':a' => 'x']);

        $this->assertSame('SELECT ?', $sql);
        $this->assertSame(['x'], $params);
    }

    public function testPlaceholdersInsideQuotedTextAreLeftAlone(): void
    {
        [$sql, $params] = Sql::rewrite(
            'SELECT * FROM t WHERE a LIKE "%-:x-%" AND b = \':y\' AND c = :z',
            ['x' => 1, 'y' => 2, 'z' => 3]
        );

        $this->assertSame('SELECT * FROM t WHERE a LIKE "%-:x-%" AND b = \':y\' AND c = ?', $sql);
        $this->assertSame([3], $params);
    }

    public function testEscapedQuotesDoNotEndTheText(): void
    {
        [$sql, $params] = Sql::rewrite(
            "SELECT 'it''s :x', 'back\\' :y', :z",
            ['x' => 1, 'y' => 2, 'z' => 3]
        );

        $this->assertSame("SELECT 'it''s :x', 'back\\' :y', ?", $sql);
        $this->assertSame([3], $params);
    }

    public function testPlaceholdersInsideQuotedNamesAreLeftAlone(): void
    {
        [$sql, $params] = Sql::rewrite('SELECT `a:x` FROM t WHERE b = :y', ['x' => 1, 'y' => 2]);

        $this->assertSame('SELECT `a:x` FROM t WHERE b = ?', $sql);
        $this->assertSame([2], $params);
    }

    public function testPlaceholdersInsideCommentsAreLeftAlone(): void
    {
        $sql = "SELECT 1 -- :x\n, 2 # :y\n, /* :z */ :w";

        [$rewritten, $params] = Sql::rewrite($sql, ['x' => 1, 'y' => 2, 'z' => 3, 'w' => 4]);

        $this->assertSame("SELECT 1 -- :x\n, 2 # :y\n, /* :z */ ?", $rewritten);
        $this->assertSame([4], $params);
    }

    public function testDoubleColonAndAssignmentAreNotPlaceholders(): void
    {
        [$sql, $params] = Sql::rewrite('SELECT @a := 1, a::b, :x', ['x' => 'v']);

        $this->assertSame('SELECT @a := 1, a::b, ?', $sql);
        $this->assertSame(['v'], $params);
    }

    public function testAnArrayBecomesAList(): void
    {
        [$sql, $params] = Sql::rewrite('SELECT * FROM t WHERE id IN :ids', ['ids' => [1, 2, 3]]);

        $this->assertSame('SELECT * FROM t WHERE id IN (?,?,?)', $sql);
        $this->assertSame([1, 2, 3], $params);
    }

    public function testAnEmptyListMatchesNothing(): void
    {
        [$sql, $params] = Sql::rewrite('SELECT * FROM t WHERE id IN :ids', ['ids' => []]);

        $this->assertSame('SELECT * FROM t WHERE id IN (NULL)', $sql);
        $this->assertSame([], $params);
    }

    public function testAValueThatLooksLikeAnotherPlaceholderIsJustText(): void
    {
        [$sql, $params] = Sql::rewrite('SELECT :a, :b', ['a' => 'x:b', 'b' => 'y']);

        $this->assertSame('SELECT ?, ?', $sql);
        $this->assertSame(['x:b', 'y'], $params);
    }

    public function testAMissingValueIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(':b');

        Sql::rewrite('SELECT :a, :b', ['a' => 1]);
    }

    public function testNullIsBoundAsNull(): void
    {
        [, $params] = Sql::rewrite('SELECT :a', ['a' => null]);

        $this->assertSame([null], $params);
    }

    // ---- positional placeholders ----

    public function testQuestionMarksTakeAPlainList(): void
    {
        [$sql, $params] = Sql::rewrite('SELECT * FROM t WHERE a = ? AND b = ?', [1, 'x']);

        $this->assertSame('SELECT * FROM t WHERE a = ? AND b = ?', $sql);
        $this->assertSame([1, 'x'], $params);
    }

    public function testAQuestionMarkInsideQuotedTextIsNotAPlaceholder(): void
    {
        [$sql, $params] = Sql::rewrite("SELECT 'why?', ?", [7]);

        $this->assertSame("SELECT 'why?', ?", $sql);
        $this->assertSame([7], $params);
    }

    public function testTheNumberOfValuesMustMatchTheQuestionMarks(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sql::rewrite('SELECT ?, ?', [1]);
    }

    public function testNamedAndPositionalPlaceholdersCannotBeMixed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sql::rewrite('SELECT :a, ?', ['a' => 1]);
    }

    public function testValuesAreIgnoredWhenThereAreNoPlaceholders(): void
    {
        [$sql, $params] = Sql::rewrite('SELECT 1', ['unused' => 5]);

        $this->assertSame('SELECT 1', $sql);
        $this->assertSame([], $params);
    }

    // ---- insert ----

    public function testInsert(): void
    {
        [$sql, $params] = Sql::insert('t', ['a' => 1, 'b' => 'x', 'c' => null]);

        $this->assertSame('INSERT INTO `t` (`a`, `b`, `c`) VALUES (?, ?, ?)', $sql);
        $this->assertSame([1, 'x', null], $params);
    }

    public function testInsertEscapesColumnNames(): void
    {
        [$sql] = Sql::insert('t', ['a`b' => 1]);

        $this->assertSame('INSERT INTO `t` (`a``b`) VALUES (?)', $sql);
    }

    public function testInsertNeedsData(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sql::insert('t', []);
    }

    // ---- update ----

    public function testUpdate(): void
    {
        [$sql, $params] = Sql::update('t', ['id' => 5, 'name' => 'x', 'slug' => 'y'], ['name', 'slug'], ['id']);

        $this->assertSame('UPDATE `t` SET `name` = ?, `slug` = ? WHERE `id` = ?', $sql);
        $this->assertSame(['x', 'y', 5], $params);
    }

    public function testUpdateSetsEverythingButTheMatchingFieldsWhenNoFieldsAreGiven(): void
    {
        [$sql, $params] = Sql::update('t', ['id' => 5, 'name' => 'x', 'slug' => 'y'], [], ['id']);

        $this->assertSame('UPDATE `t` SET `name` = ?, `slug` = ? WHERE `id` = ?', $sql);
        $this->assertSame(['x', 'y', 5], $params);
    }

    public function testUpdateJoinsSeveralMatchingFieldsWithAnd(): void
    {
        [$sql, $params] = Sql::update('t', ['a' => 1, 'b' => 2, 'name' => 'x'], ['name'], ['a', 'b']);

        $this->assertSame('UPDATE `t` SET `name` = ? WHERE `a` = ? AND `b` = ?', $sql);
        $this->assertSame(['x', 1, 2], $params);
    }

    public function testUpdateMatchesAnyItemOfAnArray(): void
    {
        [$sql, $params] = Sql::update('t', ['id' => [1, 2], 'name' => 'x'], ['name'], ['id']);

        $this->assertSame('UPDATE `t` SET `name` = ? WHERE `id` IN (?, ?)', $sql);
        $this->assertSame(['x', 1, 2], $params);
    }

    public function testUpdateMatchesNullWithIsNull(): void
    {
        [$sql, $params] = Sql::update('t', ['parent' => null, 'name' => 'x'], ['name'], ['parent']);

        $this->assertSame('UPDATE `t` SET `name` = ? WHERE `parent` IS NULL', $sql);
        $this->assertSame(['x'], $params);
    }

    public function testUpdateCanSetNull(): void
    {
        [$sql, $params] = Sql::update('t', ['id' => 1, 'name' => null], ['name'], ['id']);

        $this->assertSame('UPDATE `t` SET `name` = ? WHERE `id` = ?', $sql);
        $this->assertSame([null, 1], $params);
    }

    public function testUpdateNeedsMatchingFields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sql::update('t', ['name' => 'x'], ['name'], []);
    }

    public function testUpdateNeedsTheValueOfEveryMatchingField(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sql::update('t', ['name' => 'x'], ['name'], ['id']);
    }

    public function testUpdateNeedsSomethingToSet(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sql::update('t', ['id' => 1], ['name'], ['id']);
    }

    // ---- delete ----

    public function testDelete(): void
    {
        [$sql, $params] = Sql::delete('t', ['id' => 5], ['id']);

        $this->assertSame('DELETE FROM `t` WHERE `id` = ?', $sql);
        $this->assertSame([5], $params);
    }

    public function testDeleteWithAList(): void
    {
        [$sql, $params] = Sql::delete('t', ['id' => [1, 2, 3]], ['id']);

        $this->assertSame('DELETE FROM `t` WHERE `id` IN (?, ?, ?)', $sql);
        $this->assertSame([1, 2, 3], $params);
    }

    public function testDeleteWithAnEmptyListMatchesNothing(): void
    {
        [$sql, $params] = Sql::delete('t', ['id' => []], ['id']);

        $this->assertSame('DELETE FROM `t` WHERE 1 = 0', $sql);
        $this->assertSame([], $params);
    }

    public function testDeleteNeedsMatchingFields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sql::delete('t', ['id' => 1], []);
    }

    // ---- select and count ----

    public function testSelect(): void
    {
        $this->assertSame('SELECT * FROM `t` WHERE 1 = 1 ', Sql::select('t'));
        $this->assertStringContainsString('AND a = :a ORDER BY id', Sql::select('t', null, null, 'AND a = :a ORDER BY id'));
    }

    public function testSelectWithLimitAndOffset(): void
    {
        $this->assertStringEndsWith(' LIMIT 10 OFFSET 20', Sql::select('t', 10, 20));
        $this->assertStringEndsWith(' LIMIT 10', Sql::select('t', 10));
    }

    public function testAnOffsetWithoutALimitIsIgnored(): void
    {
        $this->assertStringNotContainsString('OFFSET', Sql::select('t', null, 20));
    }

    public function testNegativeLimitsBecomeZero(): void
    {
        $this->assertStringEndsWith(' LIMIT 0 OFFSET 0', Sql::select('t', -5, -1));
    }

    public function testCount(): void
    {
        $this->assertSame('SELECT COUNT(`id`) AS total FROM `t` WHERE 1 = 1 ', Sql::count('t'));
        $this->assertStringContainsString('COUNT(*)', Sql::count('t', '', '*'));
        $this->assertStringContainsString('COUNT(`a`)', Sql::count('t', '', 'a'));
        $this->assertStringContainsString('AND a = 1', Sql::count('t', 'AND a = 1'));
    }
}
