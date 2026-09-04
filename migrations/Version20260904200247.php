<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904200247 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(180) NOT NULL, email VARCHAR(180) DEFAULT NULL, display_name VARCHAR(180) DEFAULT NULL, roles JSON NOT NULL, password VARCHAR(255) DEFAULT NULL, auth_source VARCHAR(10) NOT NULL, external_id VARCHAR(255) DEFAULT NULL, active TINYINT NOT NULL, last_login_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_username (username), UNIQUE INDEX uniq_user_source_external (auth_source, external_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_log (id BIGINT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, actor_username VARCHAR(180) NOT NULL, actor_id INT DEFAULT NULL, action VARCHAR(20) NOT NULL, entity_class VARCHAR(120) DEFAULT NULL, entity_id VARCHAR(64) DEFAULT NULL, label VARCHAR(255) DEFAULT NULL, changes JSON DEFAULT NULL, client_ip VARBINARY(16) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, INDEX idx_audit_occurred (occurred_at), INDEX idx_audit_target (entity_class, entity_id), INDEX idx_audit_actor (actor_username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE device (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, type VARCHAR(20) NOT NULL, vendor VARCHAR(80) DEFAULT NULL, model VARCHAR(80) DEFAULT NULL, serial_number VARCHAR(80) DEFAULT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, site_id INT DEFAULT NULL, INDEX IDX_92FB68EF6BD1646 (site_id), INDEX idx_device_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ip_address (id INT AUTO_INCREMENT NOT NULL, address VARBINARY(16) NOT NULL, status VARCHAR(20) NOT NULL, hostname VARCHAR(255) DEFAULT NULL, mac_address VARCHAR(17) DEFAULT NULL, description LONGTEXT DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, subnet_id INT NOT NULL, interface_id INT DEFAULT NULL, INDEX IDX_22FFD58CC9CF9478 (subnet_id), INDEX IDX_22FFD58CAB0BE982 (interface_id), INDEX idx_ip_address (address), UNIQUE INDEX uniq_ip_subnet_address (subnet_id, address), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE network_interface (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, mac_address VARCHAR(17) DEFAULT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, device_id INT NOT NULL, INDEX IDX_B3518C3494A4C7D4 (device_id), UNIQUE INDEX uniq_interface_device_name (device_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE organization (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(32) NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_organization_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(32) NOT NULL, name VARCHAR(120) NOT NULL, address LONGTEXT DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, country VARCHAR(2) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, organization_id INT NOT NULL, INDEX IDX_694309E432C8A3DE (organization_id), UNIQUE INDEX uniq_site_org_code (organization_id, code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE subnet (id INT AUTO_INCREMENT NOT NULL, version SMALLINT NOT NULL, network_address VARBINARY(16) NOT NULL, last_address VARBINARY(16) NOT NULL, prefix_length SMALLINT NOT NULL, name VARCHAR(120) DEFAULT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, gateway VARBINARY(16) DEFAULT NULL, dns_servers JSON DEFAULT NULL, dhcp_enabled TINYINT NOT NULL, dhcp_range_start VARBINARY(16) DEFAULT NULL, dhcp_range_end VARBINARY(16) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, organization_id INT NOT NULL, site_id INT DEFAULT NULL, parent_id INT DEFAULT NULL, vlan_id INT DEFAULT NULL, INDEX IDX_91C2421632C8A3DE (organization_id), INDEX IDX_91C24216F6BD1646 (site_id), INDEX IDX_91C242168B4937A1 (vlan_id), INDEX idx_subnet_range (network_address, last_address), INDEX idx_subnet_parent (parent_id), UNIQUE INDEX uniq_subnet_org_network (organization_id, network_address, prefix_length), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vlan (id INT AUTO_INCREMENT NOT NULL, number SMALLINT NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, site_id INT DEFAULT NULL, INDEX IDX_F83104A1F6BD1646 (site_id), UNIQUE INDEX uniq_vlan_site_number (site_id, number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE device ADD CONSTRAINT FK_92FB68EF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ip_address ADD CONSTRAINT FK_22FFD58CC9CF9478 FOREIGN KEY (subnet_id) REFERENCES subnet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ip_address ADD CONSTRAINT FK_22FFD58CAB0BE982 FOREIGN KEY (interface_id) REFERENCES network_interface (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE network_interface ADD CONSTRAINT FK_B3518C3494A4C7D4 FOREIGN KEY (device_id) REFERENCES device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE site ADD CONSTRAINT FK_694309E432C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE subnet ADD CONSTRAINT FK_91C2421632C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE subnet ADD CONSTRAINT FK_91C24216F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE subnet ADD CONSTRAINT FK_91C24216727ACA70 FOREIGN KEY (parent_id) REFERENCES subnet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subnet ADD CONSTRAINT FK_91C242168B4937A1 FOREIGN KEY (vlan_id) REFERENCES vlan (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vlan ADD CONSTRAINT FK_F83104A1F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE device DROP FOREIGN KEY FK_92FB68EF6BD1646');
        $this->addSql('ALTER TABLE ip_address DROP FOREIGN KEY FK_22FFD58CC9CF9478');
        $this->addSql('ALTER TABLE ip_address DROP FOREIGN KEY FK_22FFD58CAB0BE982');
        $this->addSql('ALTER TABLE network_interface DROP FOREIGN KEY FK_B3518C3494A4C7D4');
        $this->addSql('ALTER TABLE site DROP FOREIGN KEY FK_694309E432C8A3DE');
        $this->addSql('ALTER TABLE subnet DROP FOREIGN KEY FK_91C2421632C8A3DE');
        $this->addSql('ALTER TABLE subnet DROP FOREIGN KEY FK_91C24216F6BD1646');
        $this->addSql('ALTER TABLE subnet DROP FOREIGN KEY FK_91C24216727ACA70');
        $this->addSql('ALTER TABLE subnet DROP FOREIGN KEY FK_91C242168B4937A1');
        $this->addSql('ALTER TABLE vlan DROP FOREIGN KEY FK_F83104A1F6BD1646');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE device');
        $this->addSql('DROP TABLE ip_address');
        $this->addSql('DROP TABLE network_interface');
        $this->addSql('DROP TABLE organization');
        $this->addSql('DROP TABLE site');
        $this->addSql('DROP TABLE subnet');
        $this->addSql('DROP TABLE vlan');
    }
}
