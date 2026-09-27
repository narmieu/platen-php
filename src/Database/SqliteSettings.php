<?php

declare(strict_types=1);

namespace App\Database;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Lets the web server and the workers share an SQLite database: in WAL mode, readers
 * do not wait for writers, and a writer waits for another instead of failing.
 */
#[AsMiddleware]
final class SqliteSettings implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Driver\Connection
            {
                $connection = parent::connect($params);
                if (\in_array($params['driver'] ?? null, ['pdo_sqlite', 'sqlite3'], true)) {
                    $connection->exec('PRAGMA journal_mode = WAL');
                    $connection->exec('PRAGMA busy_timeout = 10000');
                }

                return $connection;
            }
        };
    }
}
