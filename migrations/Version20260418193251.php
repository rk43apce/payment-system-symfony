<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260418193251 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account_ledger ADD idempotency_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E74F6ACF7FD1C147 ON account_ledger (idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_E74F6ACF7FD1C147 ON account_ledger');
        $this->addSql('ALTER TABLE account_ledger DROP idempotency_key');
    }
}
