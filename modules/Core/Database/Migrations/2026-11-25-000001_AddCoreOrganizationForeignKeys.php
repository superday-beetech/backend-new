<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Add referential integrity for the Core organization domain.
 *
 * Existing organization tables and indexes are already present in the
 * cakra schema. This migration backfills an organization key on the
 * membership-unit pivot and adds organization-scoped foreign keys.
 */
final class AddCoreOrganizationForeignKeys extends Migration
{
    public function up(): void
    {
        $requiredTables = [
            'users',
            'core_organizations',
            'core_organization_units',
            'core_organization_memberships',
            'core_organization_membership_units',
        ];

        foreach ($requiredTables as $table) {
            if (! $this->tableExists($table)) {
                throw new RuntimeException(
                    "Cannot add Core organization constraints: table {$table} does not exist."
                );
            }
        }

        $this->assertNoOrphans(
            'SELECT COUNT(*) AS total
             FROM core_organization_units u
             LEFT JOIN core_organizations o ON o.id = u.organization_id
             WHERE o.id IS NULL',
            'core_organization_units.organization_id references a missing organization.'
        );

        $this->assertNoOrphans(
            'SELECT COUNT(*) AS total
             FROM core_organization_units u
             INNER JOIN core_organization_units p ON p.id = u.parent_id
             WHERE u.parent_id IS NOT NULL
               AND p.organization_id <> u.organization_id',
            'A parent unit belongs to a different organization.'
        );

        $this->assertNoOrphans(
            'SELECT COUNT(*) AS total
             FROM core_organization_units u
             LEFT JOIN core_organization_units p ON p.id = u.parent_id
             WHERE u.parent_id IS NOT NULL AND p.id IS NULL',
            'core_organization_units.parent_id references a missing unit.'
        );

        $this->assertNoOrphans(
            'SELECT COUNT(*) AS total
             FROM core_organization_memberships m
             LEFT JOIN core_organizations o ON o.id = m.organization_id
             WHERE o.id IS NULL',
            'core_organization_memberships.organization_id references a missing organization.'
        );

        $this->assertNoOrphans(
            'SELECT COUNT(*) AS total
             FROM core_organization_memberships m
             LEFT JOIN users u ON u.id = m.user_id
             WHERE u.id IS NULL',
            'core_organization_memberships.user_id references a missing user.'
        );

        $this->assertNoOrphans(
            'SELECT COUNT(*) AS total
             FROM core_organization_memberships m
             LEFT JOIN core_organization_units u ON u.id = m.unit_id
             WHERE m.unit_id IS NOT NULL AND u.id IS NULL',
            'core_organization_memberships.unit_id references a missing legacy unit.'
        );

        $this->assertNoOrphans(
            'SELECT COUNT(*) AS total
             FROM core_organization_memberships m
             INNER JOIN core_organization_units u ON u.id = m.unit_id
             WHERE m.unit_id IS NOT NULL
               AND u.organization_id <> m.organization_id',
            'A legacy membership unit belongs to a different organization.'
        );

        $this->assertNoOrphans(
            'SELECT COUNT(*) AS total
             FROM core_organization_membership_units mu
             LEFT JOIN core_organization_memberships m ON m.id = mu.membership_id
             WHERE m.id IS NULL',
            'core_organization_membership_units.membership_id references a missing membership.'
        );

        $this->assertNoOrphans(
            'SELECT COUNT(*) AS total
             FROM core_organization_membership_units mu
             LEFT JOIN core_organization_units u ON u.id = mu.organization_unit_id
             WHERE u.id IS NULL',
            'core_organization_membership_units.organization_unit_id references a missing unit.'
        );

        $this->assertNoOrphans(
            'SELECT COUNT(*) AS total
             FROM core_organization_membership_units mu
             INNER JOIN core_organization_memberships m ON m.id = mu.membership_id
             INNER JOIN core_organization_units u ON u.id = mu.organization_unit_id
             WHERE u.organization_id <> m.organization_id',
            'A membership-unit relation connects different organizations.'
        );

        // Tambahkan organization_id pada pivot lama dan isi dari membership.
        if (! $this->fieldExists(
            'core_organization_membership_units',
            'organization_id'
        )) {
            $this->db->query(
                'ALTER TABLE core_organization_membership_units
                 ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER id'
            );
        }

        $this->db->query(
            'UPDATE core_organization_membership_units mu
             INNER JOIN core_organization_memberships m
                ON m.id = mu.membership_id
             SET mu.organization_id = m.organization_id
             WHERE mu.organization_id IS NULL'
        );

        $this->assertNoOrphans(
            'SELECT COUNT(*) AS total
             FROM core_organization_membership_units
             WHERE organization_id IS NULL',
            'A membership-unit relation could not be assigned to an organization.'
        );

        $this->db->query(
            'ALTER TABLE core_organization_membership_units
             MODIFY organization_id BIGINT UNSIGNED NOT NULL'
        );

        $this->addIndex(
            'core_organization_units',
            'idx_core_unit_org_parent',
            'ALTER TABLE core_organization_units
             ADD KEY idx_core_unit_org_parent (organization_id, parent_id)'
        );
        $this->addIndex(
            'core_organization_memberships',
            'idx_core_membership_org_unit',
            'ALTER TABLE core_organization_memberships
             ADD KEY idx_core_membership_org_unit (organization_id, unit_id)'
        );
        $this->addIndex(
            'core_organization_membership_units',
            'idx_membership_units_org_membership',
            'ALTER TABLE core_organization_membership_units
             ADD KEY idx_membership_units_org_membership (organization_id, membership_id)'
        );
        $this->addIndex(
            'core_organization_membership_units',
            'idx_membership_units_org_unit',
            'ALTER TABLE core_organization_membership_units
             ADD KEY idx_membership_units_org_unit (organization_id, organization_unit_id)'
        );

        $this->addUniqueIndex(
            'core_organization_memberships',
            'uq_core_membership_org_id',
            'ALTER TABLE core_organization_memberships
             ADD UNIQUE KEY uq_core_membership_org_id (organization_id, id)'
        );

        $this->addConstraint(
            'fk_core_units_organization',
            'ALTER TABLE core_organization_units
             ADD CONSTRAINT fk_core_units_organization
             FOREIGN KEY (organization_id)
             REFERENCES core_organizations (id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        $this->addUniqueIndex(
            'core_organization_units',
            'uq_core_unit_org_id',
            'ALTER TABLE core_organization_units
             ADD UNIQUE KEY uq_core_unit_org_id (organization_id, id)'
        );

        $this->addConstraint(
            'fk_core_units_parent_org',
            'ALTER TABLE core_organization_units
             ADD CONSTRAINT fk_core_units_parent_org
             FOREIGN KEY (organization_id, parent_id)
             REFERENCES core_organization_units (organization_id, id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        $this->addConstraint(
            'fk_core_memberships_organization',
            'ALTER TABLE core_organization_memberships
             ADD CONSTRAINT fk_core_memberships_organization
             FOREIGN KEY (organization_id)
             REFERENCES core_organizations (id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        $this->addConstraint(
            'fk_core_memberships_user',
            'ALTER TABLE core_organization_memberships
             ADD CONSTRAINT fk_core_memberships_user
             FOREIGN KEY (user_id)
             REFERENCES users (id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        $this->addConstraint(
            'fk_core_memberships_legacy_unit_org',
            'ALTER TABLE core_organization_memberships
             ADD CONSTRAINT fk_core_memberships_legacy_unit_org
             FOREIGN KEY (organization_id, unit_id)
             REFERENCES core_organization_units (organization_id, id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        $this->addConstraint(
            'fk_core_membership_units_membership_org',
            'ALTER TABLE core_organization_membership_units
             ADD CONSTRAINT fk_core_membership_units_membership_org
             FOREIGN KEY (organization_id, membership_id)
             REFERENCES core_organization_memberships (organization_id, id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );

        $this->addConstraint(
            'fk_core_membership_units_unit_org',
            'ALTER TABLE core_organization_membership_units
             ADD CONSTRAINT fk_core_membership_units_unit_org
             FOREIGN KEY (organization_id, organization_unit_id)
             REFERENCES core_organization_units (organization_id, id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    private function tableExists(string $table): bool
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?',
            [$table]
        )->getRowArray();

        return (int) ($row['total'] ?? 0) > 0;
    }

    private function fieldExists(string $table, string $field): bool
    {
        if (! $this->tableExists($table)) {
            return false;
        }

        $row = $this->db->query(
            'SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?',
            [$table, $field]
        )->getRowArray();

        return (int) ($row['total'] ?? 0) > 0;
    }

    public function down(): void
    {
        $this->dropConstraint(
            'core_organization_membership_units',
            'fk_core_membership_units_unit_org'
        );
        $this->dropConstraint(
            'core_organization_membership_units',
            'fk_core_membership_units_membership_org'
        );
        $this->dropUniqueIndex(
            'core_organization_memberships',
            'uq_core_membership_org_id'
        );
        if ($this->fieldExists(
            'core_organization_membership_units',
            'organization_id'
        )) {
            // Keep the additive column on rollback, but make it nullable so
            // older application code can continue inserting pivot rows.
            $this->db->query(
                'ALTER TABLE core_organization_membership_units
                 MODIFY organization_id BIGINT UNSIGNED NULL'
            );
        }
        $this->dropConstraint(
            'core_organization_memberships',
            'fk_core_memberships_legacy_unit_org'
        );
        $this->dropConstraint(
            'core_organization_memberships',
            'fk_core_memberships_user'
        );
        $this->dropConstraint(
            'core_organization_memberships',
            'fk_core_memberships_organization'
        );
        $this->dropConstraint(
            'core_organization_units',
            'fk_core_units_parent_org'
        );
        $this->dropUniqueIndex(
            'core_organization_units',
            'uq_core_unit_org_id'
        );
        $this->dropConstraint(
            'core_organization_units',
            'fk_core_units_organization'
        );
    }



    private function addIndex(string $table, string $name, string $sql): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        $this->db->query($sql);
    }

    private function addUniqueIndex(string $table, string $name, string $sql): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        $this->db->query($sql);
    }

    private function dropUniqueIndex(string $table, string $name): void
    {
        if (! $this->indexExists($table, $name)) {
            return;
        }

        $this->db->query(
            "ALTER TABLE {$table} DROP INDEX {$name}"
        );
    }

    private function indexExists(string $table, string $name): bool
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS total
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            [$table, $name]
        )->getRowArray();

        return (int) ($row['total'] ?? 0) > 0;
    }

    private function assertNoOrphans(string $sql, string $message): void
    {
        $row = $this->db->query($sql)->getRowArray();

        if ((int) ($row['total'] ?? 0) > 0) {
            throw new RuntimeException($message);
        }
    }

    private function addConstraint(string $name, string $sql): void
    {
        if ($this->constraintExists($name)) {
            return;
        }

        $this->db->query($sql);
    }

    private function dropConstraint(string $table, string $name): void
    {
        if (! $this->constraintExists($name)) {
            return;
        }

        $this->db->query(
            "ALTER TABLE {$table} DROP FOREIGN KEY {$name}"
        );
    }

    private function constraintExists(string $name): bool
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS total
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = ?',
            [$name]
        )->getRowArray();

        return (int) ($row['total'] ?? 0) > 0;
    }
}
