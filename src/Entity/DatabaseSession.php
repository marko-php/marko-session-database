<?php

declare(strict_types=1);

namespace Marko\Session\Database\Entity;

use Marko\Database\Attributes\Column;
use Marko\Database\Attributes\Table;
use Marko\Database\Entity\Entity;

/**
 * Owns the schema of the sessions table, so db:migrate creates it and db:diff reports drift.
 * DatabaseSessionHandler reads and writes the table with its own SQL, not through this entity.
 */
#[Table('sessions')]
class DatabaseSession extends Entity
{
    #[Column(type: 'varchar', length: 128, primaryKey: true)]
    public string $id;

    #[Column(type: 'text')]
    public string $payload;

    #[Column(type: 'int')]
    public int $lastActivity;
}
