<?php

declare(strict_types=1);

namespace DBHandler;

use RuntimeException;

/**
 * Thrown when the connection or a query fails.
 * The code is the MySQL error number when there is one.
 */
class DatabaseException extends RuntimeException
{
}
