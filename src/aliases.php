<?php

declare(strict_types=1);

// Keeps code written for version 0.x working, where DBHandler was a class in the global namespace
if (!class_exists('DBHandler', false)) {
    class_alias(\DBHandler\DBHandler::class, 'DBHandler');
}
