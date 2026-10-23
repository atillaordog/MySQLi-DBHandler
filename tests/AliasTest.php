<?php

declare(strict_types=1);

namespace DBHandler\Tests;

use DBHandler\DBHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AliasTest extends TestCase
{
    public function testTheOldGlobalClassNameStillExists(): void
    {
        $this->assertTrue(class_exists('DBHandler'));
        $this->assertSame(DBHandler::class, (new ReflectionClass('DBHandler'))->getName());
    }
}
