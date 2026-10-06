<?php

declare(strict_types=1);

use Marko\Database\Diff\SchemaDiff;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Entity\SchemaBuilder;
use Marko\Database\MySql\Sql\MySqlGenerator;
use Marko\Database\PgSql\Sql\PgSqlGenerator;
use Marko\Database\Schema\Column;
use Marko\Database\Schema\Table;
use Marko\Session\Database\Entity\DatabaseSession;

function sessionEntityTable(): Table
{
    return new SchemaBuilder()->build(new EntityMetadataFactory()->parse(DatabaseSession::class));
}

describe('DatabaseSession entity', function (): void {
    it('maps DatabaseSession to the sessions table with the documented columns', function (): void {
        $table = sessionEntityTable();
        $columns = array_map(
            fn (Column $column): array => [$column->name, $column->type, $column->length, $column->nullable],
            $table->columns,
        );

        expect($table->name)->toBe('sessions')
            ->and($columns)->toBe([
                ['id', 'varchar', 128, false],
                ['payload', 'text', null, false],
                ['last_activity', 'integer', null, false],
            ])
            ->and($table->columns[0]->primaryKey)->toBeTrue();
    });

    it('generates the documented sessions DDL on MySQL and PostgreSQL', function (): void {
        $diff = new SchemaDiff(tablesToCreate: ['sessions' => sessionEntityTable()]);

        expect(new MySqlGenerator()->generateUp($diff))->toBe([
            'CREATE TABLE `sessions` (`id` VARCHAR(128) NOT NULL, `payload` TEXT NOT NULL, '
            . '`last_activity` INT NOT NULL, PRIMARY KEY (`id`))',
        ])
            ->and(implode("\n", new PgSqlGenerator()->generateUp($diff)))
            ->toContain('CREATE TABLE "sessions"')
            ->toContain('"id" VARCHAR(128) PRIMARY KEY')
            ->toContain('"payload" TEXT NOT NULL')
            ->toContain('"last_activity" INTEGER NOT NULL');
    });

    it('lives in src/Entity so db:migrate discovers it', function (): void {
        expect(is_file(dirname(__DIR__, 3) . '/src/Entity/DatabaseSession.php'))->toBeTrue();
    });
});
