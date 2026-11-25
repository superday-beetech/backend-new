<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ensure the Core organization tables exist.
 *
 * This is an adoption-safe migration: the current cakra database already
 * contains these tables and their data, so CREATE TABLE IF NOT EXISTS keeps
 * existing records intact. Foreign keys are added by the next migration.
 */
final class CreateCoreOrganizationTables extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS core_organizations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(50) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                name VARCHAR(150) NOT NULL,
                legal_name VARCHAR(200) DEFAULT NULL,
                organization_type VARCHAR(50) DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Jakarta',
                currency CHAR(3) NOT NULL DEFAULT 'IDR',
                settings JSON DEFAULT NULL,
                created_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                deleted_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_core_org_code (code),
                UNIQUE KEY uq_core_org_slug (slug),
                KEY idx_core_org_status (status),
                KEY idx_core_org_deleted (deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS core_organization_units (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                organization_id BIGINT UNSIGNED NOT NULL,
                parent_id BIGINT UNSIGNED DEFAULT NULL,
                code VARCHAR(50) NOT NULL,
                name VARCHAR(150) NOT NULL,
                unit_type VARCHAR(50) NOT NULL DEFAULT 'department',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                metadata JSON DEFAULT NULL,
                created_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                deleted_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_core_unit_org_code (organization_id, code),
                UNIQUE KEY uq_core_unit_org_id (organization_id, id),
                KEY idx_core_unit_parent (parent_id),
                KEY idx_core_unit_org_parent (organization_id, parent_id),
                KEY idx_core_unit_org_status (organization_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS core_organization_memberships (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                organization_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                unit_id BIGINT UNSIGNED DEFAULT NULL,
                membership_role VARCHAR(50) NOT NULL DEFAULT 'member',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_core_org_user (organization_id, user_id),
                KEY idx_core_membership_user_status (user_id, status),
                KEY idx_core_membership_unit (unit_id),
                KEY idx_core_membership_org_unit (organization_id, unit_id),
                UNIQUE KEY uq_core_membership_org_id (organization_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS core_organization_membership_units (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                organization_id BIGINT UNSIGNED NOT NULL,
                membership_id BIGINT UNSIGNED NOT NULL,
                organization_unit_id BIGINT UNSIGNED NOT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_membership_unit (membership_id, organization_unit_id),
                KEY idx_membership_units_unit (organization_unit_id),
                KEY idx_membership_units_org_membership (organization_id, membership_id),
                KEY idx_membership_units_org_unit (organization_id, organization_unit_id),
                KEY idx_membership_units_default (membership_id, is_default)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Intentionally non-destructive.
     *
     * These tables may predate migration tracking and contain live data.
     * Removing them during rollback would risk deleting user data.
     */
    public function down(): void
    {
    }
}
