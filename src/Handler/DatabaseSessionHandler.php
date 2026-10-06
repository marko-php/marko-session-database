<?php

declare(strict_types=1);

namespace Marko\Session\Database\Handler;

use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\PrimaryReadInterface;
use Marko\Session\Config\SessionConfig;
use Marko\Session\Contracts\SessionHandlerInterface;
use Psr\Clock\ClockInterface;

readonly class DatabaseSessionHandler implements SessionHandlerInterface
{
    private const int SECONDS_PER_MINUTE = 60;

    private const string TABLE = 'sessions';

    public function __construct(
        private ConnectionInterface $connection,
        private SessionConfig $config,
        private ClockInterface $clock,
    ) {}

    /**
     * The sessions table name quoted for the connection's SQL dialect.
     */
    private function table(): string
    {
        return $this->connection->quoteIdentifier(self::TABLE);
    }

    /**
     * Run a session read against the primary database.
     *
     * Logout and ID regeneration delete the old row on the primary. Reading
     * it back from a lagging replica would let a replayed old cookie pass
     * validateId() and read() its authenticated payload, so a connection that
     * can route reads elsewhere is told to use the primary.
     *
     * @return array<array<string, mixed>>
     */
    private function queryPrimary(
        string $sql,
        array $bindings,
    ): array {
        if ($this->connection instanceof PrimaryReadInterface) {
            return $this->connection->onPrimary(
                fn (): array => $this->connection->query($sql, $bindings),
            );
        }

        return $this->connection->query($sql, $bindings);
    }

    public function open(
        string $path,
        string $name,
    ): bool {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(
        string $id,
    ): string|false {
        $results = $this->queryPrimary(
            "SELECT payload FROM {$this->table()} WHERE id = ?",
            [$id],
        );

        if ($results === []) {
            return '';
        }

        return $results[0]['payload'];
    }

    public function write(
        string $id,
        string $data,
    ): bool {
        $now = $this->clock->now()->getTimestamp();

        if ($this->connection->driverName() === 'mysql') {
            $this->connection->execute(
                "INSERT INTO {$this->table()} (id, payload, last_activity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), last_activity = VALUES(last_activity)",
                [$id, $data, $now],
            );
        } else {
            $this->connection->execute(
                "INSERT INTO {$this->table()} (id, payload, last_activity) VALUES (?, ?, ?) ON CONFLICT (id) DO UPDATE SET payload = excluded.payload, last_activity = excluded.last_activity",
                [$id, $data, $now],
            );
        }

        return true;
    }

    public function destroy(
        string $id,
    ): bool {
        $this->connection->execute(
            "DELETE FROM {$this->table()} WHERE id = ?",
            [$id],
        );

        return true;
    }

    public function gc(
        int $max_lifetime,
    ): int|false {
        $expireTime = $this->clock->now()->getTimestamp() - $max_lifetime;

        return $this->connection->execute(
            "DELETE FROM {$this->table()} WHERE last_activity < ?",
            [$expireTime],
        );
    }

    /**
     * A session is known when its row exists and its last activity falls
     * within the configured lifetime, measured with the injected clock.
     * Expired rows are left for gc() to delete.
     */
    public function validateId(
        string $id,
    ): bool {
        $activeSince = $this->clock->now()->getTimestamp() - $this->config->lifetime() * self::SECONDS_PER_MINUTE;

        return $this->queryPrimary(
            "SELECT 1 FROM {$this->table()} WHERE id = ? AND last_activity >= ?",
            [$id, $activeSince],
        ) !== [];
    }

    /**
     * Slide the expiry of an existing session forward without rewriting its
     * payload. Never inserts: an id with no row stays without one.
     */
    public function updateTimestamp(
        string $id,
        string $data,
    ): bool {
        $this->connection->execute(
            "UPDATE {$this->table()} SET last_activity = ? WHERE id = ?",
            [$this->clock->now()->getTimestamp(), $id],
        );

        return true;
    }
}
