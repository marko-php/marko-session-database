<?php

declare(strict_types=1);

namespace Marko\Session\Database\Tests\Integration\MySql;

use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Introspection\MySqlIntrospector;
use Marko\Database\MySql\Sql\MySqlGenerator;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;
use Marko\Session\Database\Tests\Fixtures\SessionTable;

/*
 * DatabaseSessionHandler against a real MySQL server, through its INSERT ... ON DUPLICATE KEY UPDATE upsert; CI
 * also runs this directory against MariaDB. The sessions table is built from the DatabaseSession entity through
 * MySqlGenerator, as db:migrate builds it. Settings come from the database-mysql IntegrationDatabase fixture
 * (MARKO_TEST_MYSQL_*); the tests skip without MARKO_TEST_MYSQL_HOST and fail instead with
 * MARKO_INTEGRATION_REQUIRED set. The tests create and drop the sessions table.
 */

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->database = $config->database;
    $this->connection = new MySqlConnection($config);
    SessionTable::drop($this->connection);
    SessionTable::create($this->connection, new MySqlGenerator());
    $this->clock = SessionTable::clock();
    $this->handler = SessionTable::handler($this->connection, $this->clock);
});

afterEach(function (): void {
    if (isset($this->connection)) {
        SessionTable::drop($this->connection);
        $this->connection->disconnect();
    }
});

describe('DatabaseSessionHandler on MySQL', function (): void {
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

        $diff = SessionTable::diff(new MySqlIntrospector($this->connection, $this->database));

        expect(new MySqlGenerator()->generateUp($diff))->toBe([]);
    });
});
