<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260418190548 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE account_ledger (id INT AUTO_INCREMENT NOT NULL, amount BIGINT NOT NULL, type VARCHAR(10) NOT NULL, reference_type VARCHAR(20) NOT NULL, reference_id INT DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_ledger_user (user_id), INDEX idx_ledger_reference (reference_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account_ledger ADD CONSTRAINT FK_E74F6ACFA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE user DROP balance');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account_ledger DROP FOREIGN KEY FK_E74F6ACFA76ED395');
        $this->addSql('DROP TABLE account_ledger');
        $this->addSql('ALTER TABLE user ADD balance BIGINT NOT NULL');
    }
}
