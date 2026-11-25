<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Config\Database;
use InvalidArgumentException;

class OrganizationContextService
{
    private const SESSION_KEY = 'organization_context';

    private $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * Daftar organisasi aktif dan seluruh unit yang dapat diakses user.
     */
    public function getAvailableOrganizations(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $rows = $this->db
            ->table('core_organization_memberships m')
            ->select([
                'm.id AS membership_id',
                'm.organization_id',
                'm.user_id',
                'm.membership_role',
                'm.is_default',
                'o.code AS organization_code',
                'o.name AS organization_name',
                'o.organization_type',
                'o.timezone',
                'o.currency',
                'mu.organization_unit_id AS unit_id',
                'mu.is_default AS unit_is_default',
                'ou.code AS unit_code',
                'ou.name AS unit_name',
                'ou.unit_type',
            ])
            ->join(
                'core_organizations o',
                'o.id = m.organization_id',
                'inner'
            )
            ->join(
                'core_organization_membership_units mu',
                'mu.membership_id = m.id'
                . ' AND mu.organization_id = m.organization_id',
                'left'
            )
            ->join(
                'core_organization_units ou',
                'ou.id = mu.organization_unit_id'
                    . ' AND ou.organization_id = m.organization_id'
                    . ' AND ou.status = "active"'
                    . ' AND ou.deleted_at IS NULL',
                'left'
            )
            ->where('m.user_id', $userId)
            ->where('m.status', 'active')
            ->where('o.status', 'active')
            ->where('o.deleted_at', null)
            ->orderBy('m.is_default', 'DESC')
            ->orderBy('o.name', 'ASC')
            ->orderBy('mu.is_default', 'DESC')
            ->orderBy('ou.name', 'ASC')
            ->get()
            ->getResultArray();

        $organizations = [];

        foreach ($rows as $row) {
            $membershipId = (int) $row['membership_id'];

            if (!isset($organizations[$membershipId])) {
                $organizations[$membershipId] = [
                    'membership_id' => $membershipId,
                    'organization_id' => (int) $row['organization_id'],
                    'user_id' => (int) $row['user_id'],
                    'membership_role' => $row['membership_role'],
                    'is_default' => (int) $row['is_default'],
                    'organization_code' => $row['organization_code'],
                    'organization_name' => $row['organization_name'],
                    'organization_type' => $row['organization_type'],
                    'timezone' => $row['timezone'],
                    'currency' => $row['currency'],
                    'units' => [],
                    // Kompatibilitas respons lama.
                    'unit_id' => null,
                    'unit_name' => null,
                ];
            }

            if ($row['unit_id'] !== null && $row['unit_code'] !== null) {
                $unit = [
                    'id' => (int) $row['unit_id'],
                    'code' => $row['unit_code'],
                    'name' => $row['unit_name'],
                    'unit_type' => $row['unit_type'],
                    'is_default' => (int) $row['unit_is_default'],
                ];

                $organizations[$membershipId]['units'][] = $unit;

                if (
                    (int) $row['unit_is_default'] === 1
                    || $organizations[$membershipId]['unit_id'] === null
                ) {
                    $organizations[$membershipId]['unit_id'] = $unit['id'];
                    $organizations[$membershipId]['unit_name'] = $unit['name'];
                }
            }
        }

        return array_values($organizations);
    }

    /**
     * Menentukan konteks awal pengguna.
     */
    public function resolveInitialContext(int $userId): ?array
    {
        $organizations = $this->getAvailableOrganizations($userId);

        if ($organizations === []) {
            $this->clearContext();

            return null;
        }

        if (count($organizations) === 1) {
            return $this->switchOrganization(
                $userId,
                (int) $organizations[0]['organization_id']
            );
        }

        $defaults = array_values(array_filter(
            $organizations,
            static fn (array $item): bool =>
                (int) $item['is_default'] === 1
        ));

        if (count($defaults) === 1) {
            return $this->switchOrganization(
                $userId,
                (int) $defaults[0]['organization_id']
            );
        }

        $this->clearContext();

        return null;
    }

    /**
     * Memilih organisasi dan unit aktif.
     *
     * Jika unit_id tidak dikirim:
     * - Pilih unit default jika ada.
     * - Jika hanya ada satu unit, pilih unit tersebut.
     * - Jika ada beberapa unit tanpa default, konteks unit tetap null.
     */
    public function switchOrganization(
        int $userId,
        int $organizationId,
        ?int $unitId = null
    ): array {
        if ($userId <= 0 || $organizationId <= 0) {
            throw new InvalidArgumentException(
                'User atau organisasi tidak valid.'
            );
        }

        if ($unitId !== null && $unitId <= 0) {
            throw new InvalidArgumentException('Unit ID tidak valid.');
        }

        $context = $this->loadOrganizationContext(
            $userId,
            $organizationId,
            $unitId
        );

        if ($context === null) {
            throw new InvalidArgumentException(
                'Organisasi tidak ditemukan atau user tidak memiliki membership aktif.'
            );
        }

        session()->set(self::SESSION_KEY, [
            'organization_id' => $context['organization']['id'],
            'membership_id' => $context['membership']['id'],
            'unit_id' => $context['unit']['id'] ?? null,
        ]);

        return $context;
    }

    /**
     * Membaca konteks aktif dan memvalidasi ulang membership serta unit.
     */
    public function getCurrentContext(int $userId): ?array
    {
        if ($userId <= 0) {
            $this->clearContext();
            return null;
        }

        $sessionContext = session()->get(self::SESSION_KEY);

        if (
            !is_array($sessionContext)
            || empty($sessionContext['organization_id'])
            || empty($sessionContext['membership_id'])
        ) {
            return null;
        }

        $organizationId = (int) $sessionContext['organization_id'];

        $unitId = isset($sessionContext['unit_id'])
            ? (int) $sessionContext['unit_id']
            : null;

        try {
            $context = $this->loadOrganizationContext(
                $userId,
                $organizationId,
                $unitId
            );
        } catch (InvalidArgumentException $e) {
            $this->clearContext();
            return null;
        }

        if (
            $context === null
            || (int) $context['membership']['id']
                !== (int) $sessionContext['membership_id']
        ) {
            $this->clearContext();
            return null;
        }

        return $context;
    }

    public function clearContext(): void
    {
        session()->remove(self::SESSION_KEY);
    }

    /**
     * Memuat organisasi, membership, dan unit yang terhubung.
     */
    private function loadOrganizationContext(
        int $userId,
        int $organizationId,
        ?int $unitId = null
    ): ?array {
        $membership = $this->db
            ->table('core_organization_memberships m')
            ->select([
                'm.id AS membership_id',
                'm.organization_id',
                'm.user_id',
                'm.membership_role',
                'm.status AS membership_status',
                'm.is_default',
                'o.code AS organization_code',
                'o.name AS organization_name',
                'o.organization_type',
                'o.timezone',
                'o.currency',
            ])
            ->join(
                'core_organizations o',
                'o.id = m.organization_id',
                'inner'
            )
            ->where('m.user_id', $userId)
            ->where('m.organization_id', $organizationId)
            ->where('m.status', 'active')
            ->where('o.status', 'active')
            ->where('o.deleted_at', null)
            ->get()
            ->getRowArray();

        if ($membership === null) {
            return null;
        }

        $units = $this->db
            ->table('core_organization_membership_units mu')
            ->select([
                'ou.id',
                'ou.code',
                'ou.name',
                'ou.unit_type',
                'mu.is_default',
            ])
            ->join(
                'core_organization_units ou',
                'ou.id = mu.organization_unit_id',
                'inner'
            )
            ->where('mu.organization_id', $organizationId)
->where('mu.membership_id', $membership['membership_id'])
->where('ou.organization_id', $organizationId)
            ->where('ou.status', 'active')
            ->where('ou.deleted_at', null)
            ->orderBy('mu.is_default', 'DESC')
            ->orderBy('ou.name', 'ASC')
            ->get()
            ->getResultArray();

        $selectedUnit = null;

        if ($unitId !== null) {
            foreach ($units as $unit) {
                if ((int) $unit['id'] === $unitId) {
                    $selectedUnit = $unit;
                    break;
                }
            }

            if ($selectedUnit === null) {
                throw new InvalidArgumentException(
                    'Unit tidak terhubung dengan membership pengguna atau sudah tidak aktif.'
                );
            }
        } else {
            foreach ($units as $unit) {
                if ((int) $unit['is_default'] === 1) {
                    $selectedUnit = $unit;
                    break;
                }
            }

            if ($selectedUnit === null && count($units) === 1) {
                $selectedUnit = $units[0];
            }
        }

        return [
            'organization' => [
                'id' => (int) $membership['organization_id'],
                'code' => $membership['organization_code'],
                'name' => $membership['organization_name'],
                'organization_type' => $membership['organization_type'],
                'timezone' => $membership['timezone'],
                'currency' => $membership['currency'],
            ],

            'unit' => $selectedUnit === null ? null : [
                'id' => (int) $selectedUnit['id'],
                'code' => $selectedUnit['code'],
                'name' => $selectedUnit['name'],
                'unit_type' => $selectedUnit['unit_type'],
            ],

            'available_units' => array_map(
                static fn (array $unit): array => [
                    'id' => (int) $unit['id'],
                    'code' => $unit['code'],
                    'name' => $unit['name'],
                    'unit_type' => $unit['unit_type'],
                    'is_default' => (bool) $unit['is_default'],
                ],
                $units
            ),

            'membership' => [
                'id' => (int) $membership['membership_id'],
                'user_id' => (int) $membership['user_id'],
                'role' => $membership['membership_role'],
                'status' => $membership['membership_status'],
                'is_default' => (bool) $membership['is_default'],
            ],
        ];
    }
}
