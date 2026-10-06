<?php

declare(strict_types=1);

namespace Marko\Session\Database\Tests\Fixtures;

use DateTimeImmutable;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Diff\DiffCalculator;
use Marko\Database\Diff\SchemaDiff;
use Marko\Database\Diff\SqlGeneratorInterface;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Entity\SchemaBuilder;
use Marko\Database\Exceptions\EntityException;
use Marko\Database\Introspection\IntrospectorInterface;
use Marko\Database\Schema\Table;
use Marko\Session\Config\SessionConfig;
use Marko\Session\Database\Entity\DatabaseSession;
use Marko\Session\Database\Handler\DatabaseSessionHandler;
use Marko\Testing\Fake\FakeClock;
use Marko\Testing\Fake\FakeConfigRepository;

/**
 * Builds the sessions table on a real server the way db:migrate does (from the DatabaseSession entity, through
 * the driver's generator), and a handler with a 120-minute lifetime on a controllable clock.
 */
class SessionTable
{
    public const int LIFETIME_MINUTES = 120;

    public static function drop(
        ConnectionInterface $connection,
    ): void {
        $connection->execute('DROP TABLE IF EXISTS ' . $connection->quoteIdentifier('sessions'));
    }

    /**
     * @throws EntityException
     */
    public static function create(
        ConnectionInterface $connection,
        SqlGeneratorInterface $generator,
    ): void {
        foreach ($generator->generateUp(new SchemaDiff(tablesToCreate: ['sessions' => self::entityTable()])) as $sql) {
            $connection->execute($sql);
        }
    }

    /**
     * Create the table with the SQL the docs gave before the entity existed.
     */
    public static function createFromDocumentedDdl(
        ConnectionInterface $connection,
    ): void {
        $connection->execute(
            'CREATE TABLE sessions (id VARCHAR(128) PRIMARY KEY, payload TEXT NOT NULL, last_activity INT NOT NULL)',
        );
    }

    /**
     * @throws EntityException
     */
    public static function diff(
        IntrospectorInterface $introspector,
    ): SchemaDiff {
        $databaseTable = $introspector->getTable('sessions');

        return new DiffCalculator()->calculate(
            ['sessions' => self::entityTable()],
            $databaseTable !== null ? ['sessions' => $databaseTable] : [],
        );
    }

    public static function handler(
        ConnectionInterface $connection,
        FakeClock $clock,
    ): DatabaseSessionHandler {
        return new DatabaseSessionHandler(
            $connection,
            new SessionConfig(new FakeConfigRepository(['session.lifetime' => self::LIFETIME_MINUTES])),
            $clock,
        );
    }

    public static function clock(): FakeClock
    {
        return new FakeClock(new DateTimeImmutable('2026-10-06 12:00:00'));
    }

    /**
     * @throws EntityException
     */
    private static function entityTable(): Table
    {
        return new SchemaBuilder()->build(new EntityMetadataFactory()->parse(DatabaseSession::class));
    }
}
