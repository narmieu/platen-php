<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\IndexEditor;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Documents and the job queue. Written with the schema API, so it runs on SQLite,
 * PostgreSQL and MySQL alike.
 */
final class Version20260927000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the document and messenger_messages tables';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        foreach ([self::documentTable(), self::messengerTable()] as $table) {
            foreach ($platform->getCreateTableSQL($table) as $sql) {
                $this->addSql($sql);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->addSql($platform->getDropTableSQL('messenger_messages'));
        $this->addSql($platform->getDropTableSQL('document'));
    }

    private static function documentTable(): Table
    {
        return Table::editor()
            ->setUnquotedName('document')
            ->setColumns(
                self::column('id', Types::INTEGER)->setAutoincrement(true)->create(),
                self::column('status_id', Types::STRING)->setLength(32)->create(),
                self::column('download_id', Types::STRING)->setLength(43)->create(),
                self::column('owner', Types::STRING)->setLength(64)->create(),
                self::column('kind', Types::STRING)->setLength(16)->create(),
                self::column('name', Types::TEXT)->create(),
                self::column('tag', Types::TEXT, nullable: true)->create(),
                self::column('document_type', Types::STRING)->setLength(8)->create(),
                self::column('test', Types::BOOLEAN)->create(),
                self::column('base_url', Types::TEXT)->create(),
                self::column('download_limit', Types::INTEGER, nullable: true)->create(),
                self::column('created_at', Types::DATETIME_IMMUTABLE)->create(),
                self::column('status', Types::STRING)->setLength(16)->create(),
                self::column('engine', Types::STRING, nullable: true)->setLength(16)->create(),
                self::column('message', Types::TEXT, nullable: true)->create(),
                self::column('validation_errors', Types::TEXT, nullable: true)->create(),
                self::column('number_of_pages', Types::INTEGER, nullable: true)->create(),
                self::column('size', Types::INTEGER, nullable: true)->create(),
                self::column('request', Types::TEXT, nullable: true)->create(),
                self::column('download_count', Types::INTEGER)->create(),
                self::column('expires_at', Types::DATETIME_IMMUTABLE, nullable: true)->create(),
                self::column('completed_at', Types::DATETIME_IMMUTABLE, nullable: true)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->setIndexes(
                self::index('document_status_id', 'status_id')->setType(IndexType::UNIQUE),
                self::index('document_download_id', 'download_id')->setType(IndexType::UNIQUE),
                self::index('document_owner_created', 'owner', 'created_at'),
                self::index('document_status', 'status'),
            )
            ->create();
    }

    /**
     * The table of Messenger's Doctrine transport, as the transport itself defines it.
     */
    private static function messengerTable(): Table
    {
        return Table::editor()
            ->setUnquotedName('messenger_messages')
            ->setColumns(
                self::column('id', Types::BIGINT)->setAutoincrement(true)->create(),
                self::column('body', Types::TEXT)->create(),
                self::column('headers', Types::TEXT)->create(),
                self::column('queue_name', Types::STRING)->setLength(190)->create(),
                self::column('created_at', Types::DATETIME_IMMUTABLE)->create(),
                self::column('available_at', Types::DATETIME_IMMUTABLE)->create(),
                self::column('delivered_at', Types::DATETIME_IMMUTABLE, nullable: true)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->setIndexes(
                self::index('IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750', 'queue_name', 'available_at', 'delivered_at', 'id'),
            )
            ->create();
    }

    /**
     * @param non-empty-string $name
     */
    private static function column(string $name, string $type, bool $nullable = false): ColumnEditor
    {
        return Column::editor()->setUnquotedName($name)->setTypeName($type)->setNotNull(!$nullable);
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $column
     * @param non-empty-string ...$columns
     */
    private static function index(string $name, string $column, string ...$columns): IndexEditor
    {
        return Index::editor()->setUnquotedName($name)->setUnquotedColumnNames($column, ...$columns);
    }
}
