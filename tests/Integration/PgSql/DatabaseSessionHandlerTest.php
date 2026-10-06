<?php

declare(strict_types=1);

namespace Marko\Session\Database\Tests\Integration\PgSql;

use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Introspection\PgSqlIntrospector;
use Marko\Database\PgSql\Sql\PgSqlGenerator;
use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase;
use Marko\Session\Database\Tests\Fixtures\SessionTable;

/*
 * DatabaseSessionHandler against a real PostgreSQL server, through its INSERT ... ON CONFLICT (id) DO UPDATE
 * upsert. The sessions table is built from the DatabaseSession entity through PgSqlGenerator, as db:migrate builds
 * it. Settings come from the database-pgsql IntegrationDatabase fixture (MARKO_TEST_PGSQL_*); the tests skip
 * without MARKO_TEST_PGSQL_HOST and fail instead with MARKO_INTEGRATION_REQUIRED set. The tests create and drop the
 * sessions table.
 */

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new PgSqlConnection($config);
    SessionTable::drop($this->connection);
    SessionTable::create($this->connection, new PgSqlGenerator());
    $this->clock = SessionTable::clock();
    $this->handler = SessionTable::handler($this->connection, $this->clock);
});

afterEach(function (): void {
    if (isset($this->connection)) {
        SessionTable::drop($this->connection);
        $this->connection->disconnect();
    }
});

describe('DatabaseSessionHandler on PostgreSQL', function (): void {
    it('writes, reads and overwrites a session through the upsert', function (): void {
        $this->handler->write('session-one', 'first');
        $this->clock->travel('+1 minute');
        $this->handler->write('session-one', 'second');

        $rows = $this->connection->query('SELECT id, payload, last_activity FROM sessions');

        expect($this->handler->read('session-one'))->toBe('second')
            ->and($this->handler->read('missing'))->toBe('')
            ->and($rows)->toHaveCount(1)
            ->and((int) $rows[0]['last_activity'])->toBe($this->clock->now()->getTimestamp());
    });

    it('validates only a known session within the lifetime', function (): void {
        $this->handler->write('session-one', 'payload');
        $known = $this->handler->validateId('session-one');
        $unknown = $this->handler->validateId('missing');
        $this->clock->travel('+' . (SessionTable::LIFETIME_MINUTES + 1) . ' minutes');

        expect($known)->toBeTrue()
            ->and($unknown)->toBeFalse()
            ->and($this->handler->validateId('session-one'))->toBeFalse();
    });

    it('refreshes last activity without rewriting the payload or inserting', function (): void {
        $this->handler->write('session-one', 'payload');
        $this->clock->travel('+30 minutes');

        $this->handler->updateTimestamp('session-one', 'ignored');
        $this->handler->updateTimestamp('missing', 'ignored');

        $rows = $this->connection->query('SELECT id, payload, last_activity FROM sessions');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['payload'])->toBe('payload')
            ->and((int) $rows[0]['last_activity'])->toBe($this->clock->now()->getTimestamp());
    });

    it('destroys a session and garbage-collects expired rows', function (): void {
        $this->handler->write('stale', 'old');
        $this->clock->travel('+3 hours');
        $this->handler->write('fresh', 'new');
        $this->handler->write('doomed', 'gone');

        $this->handler->destroy('doomed');
        $deleted = $this->handler->gc(SessionTable::LIFETIME_MINUTES * 60);

        expect($deleted)->toBe(1)
            ->and($this->connection->query('SELECT id FROM sessions'))->toBe([['id' => 'fresh']]);
    });

    it('diffs a sessions table created from the documented DDL as empty', function (): void {
        SessionTable::drop($this->connection);
        SessionTable::createFromDocumentedDdl($this->connection);

        $diff = SessionTable::diff(new PgSqlIntrospector($this->connection));

        expect(new PgSqlGenerator()->generateUp($diff))->toBe([]);
    });
});
