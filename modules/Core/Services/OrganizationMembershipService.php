<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Modules\Core\Exceptions\DomainException;
use App\Libraries\Pagination\Pagination;
use App\Libraries\Pagination\PaginationRequest;
use Throwable;
use Modules\Core\Models\OrganizationModel;
use Modules\Core\Models\OrganizationMembershipModel;
use RuntimeException;

class OrganizationMembershipService
{
    private BaseConnection $db;
    private OrganizationModel $organizations;
    private OrganizationMembershipModel $memberships;

    public function __construct()
    {
        $this->db = Database::connect();
        $this->organizations = new OrganizationModel($this->db);
        $this->memberships = new OrganizationMembershipModel($this->db);
    }



    public function listUsers(
        PaginationRequest $pagination,
        ?string $search = null
    ): array {
        $builder = $this->db->table('users')
            ->select('id, username');

        if ($search !== null && trim($search) !== '') {
            $builder->like('username', trim($search));
        }

        // Hitung total sebelum limit dan offset.
        $total = $builder->countAllResults(false);

        $items = $pagination
            ->apply($builder)
            ->orderBy('username', 'ASC')
            ->get()
            ->getResultArray();

        return [
            'items' => $items,
            'pagination' => Pagination::make(
                $pagination,
                $total
            )->toArray(),
        ];
    }



    public function listForOrganization(
        int $organizationId,
        PaginationRequest $pagination,
        ?string $search = null,
        ?string $status = null
    ): array {
        $this->validateOrganization($organizationId);

        $builder = $this->db->table('core_organization_memberships m')
            ->select([
                'm.id',
                'm.organization_id',
                'm.user_id',
                'm.membership_role',
                'm.status',
                'm.is_default',
                'm.created_at',
                'm.updated_at',
                'u.username',
            ])
            ->join('users u', 'u.id = m.user_id', 'left')
            ->where('m.organization_id', $organizationId);

        if ($status !== null && $status !== '') {
            $builder->where('m.status', $status);
        }

        if ($search !== null && trim($search) !== '') {
            $keyword = trim($search);

            $builder->groupStart()
                ->like('u.username', $keyword)
                ->orLike('m.membership_role', $keyword)
                ->orLike('m.user_id', $keyword)
                ->groupEnd();
        }

        // Hitung total berdasarkan filter yang sudah diterapkan.
        $total = $builder->countAllResults(false);

        // Terapkan pagination dari Core Library.
        $items = $pagination
            ->apply($builder)
            ->orderBy('m.is_default', 'DESC')
            ->orderBy('m.id', 'DESC')
            ->get()
            ->getResultArray();

        foreach ($items as &$item) {
            $units = $this->getMembershipUnits(
                (int) $item['id'],
                $organizationId
            );

            $defaultUnit = $this->findDefaultUnit($units);

            $item['units'] = $units;

            $item['unit_ids'] = array_map(
                static fn (array $unit): int => (int) $unit['id'],
                $units
            );

            // Kompatibilitas API lama.
            $item['unit_id'] = $defaultUnit['id']
                ?? ($units[0]['id'] ?? null);

            $item['unit_name'] = $defaultUnit['name']
                ?? ($units[0]['name'] ?? null);
        }

        unset($item);

        return [
            'items' => $items,
            'pagination' => Pagination::make(
                $pagination,
                $total
            )->toArray(),
        ];
    }


    public function findForOrganization(
        int $organizationId,
        int $membershipId
    ): ?array {
        $this->validateOrganization($organizationId);

        $membership = $this->memberships
            ->where('organization_id', $organizationId)
            ->where('id', $membershipId)
            ->first();

        if (!$membership) {
            return null;
        }

        $units = $this->getMembershipUnits(
            $membershipId,
            $organizationId
        );

        $defaultUnit = $this->findDefaultUnit($units);

        $membership['units'] = $units;

        $membership['unit_ids'] = array_map(
            static fn (array $unit): int => (int) $unit['id'],
            $units
        );

        // unit_id lama selalu mengikuti unit default.
        $membership['unit_id'] = $defaultUnit['id'] ?? null;
        $membership['unit_name'] = $defaultUnit['name'] ?? null;

        return $membership;
    }

    public function create(int $organizationId, array $data): int
    {
        $this->validateOrganization($organizationId);

        $payload = $this->normalizeData($data, true);
        $unitIds = $this->extractUnitIds($data);

        $this->validateUnits($organizationId, $unitIds);

        $existing = $this->memberships
            ->where('organization_id', $organizationId)
            ->where('user_id', $payload['user_id'])
            ->first();

        if ($existing) {
            throw DomainException::conflict(
                'User sudah terdaftar dalam organisasi ini.'
            );
        }

        $defaultUnitId = $this->resolveDefaultUnitId(
            $data,
            $unitIds,
            null
        );

        $payload['organization_id'] = $organizationId;

        $this->db->transBegin();

        try {
            // Default organization bersifat global per user, bukan per organisasi.
            if ((int) $payload['is_default'] === 1) {
                $this->db->table('core_organization_memberships')
                    ->where('user_id', $payload['user_id'])
                    ->set(['is_default' => 0])
                    ->update();
            }

            $membershipId = $this->memberships->insert($payload);

            if ($membershipId === false) {
                throw new RuntimeException($this->getModelErrors());
            }

            $membershipId = (int) $membershipId;

            $this->syncUnits(
                $membershipId,
                $organizationId,
                $unitIds,
                $defaultUnitId
            );

            // Kolom legacy mengikuti unit default.
            $this->syncLegacyUnitId(
                $membershipId,
                $defaultUnitId
            );

            $this->commitOrFail(
                'Gagal menyimpan membership dan unit.'
            );

            return $membershipId;
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    public function update(
        int $organizationId,
        int $membershipId,
        array $data
    ): bool {
        $this->validateOrganization($organizationId);

        $membership = $this->findForOrganization(
            $organizationId,
            $membershipId
        );

        if (!$membership) {
            throw DomainException::notFound('Membership tidak ditemukan.');
        }

        $payload = $this->normalizeData($data, false);

        if (isset($payload['user_id'])) {
            $existing = $this->memberships
                ->where('organization_id', $organizationId)
                ->where('user_id', $payload['user_id'])
                ->where('id !=', $membershipId)
                ->first();

            if ($existing) {
                throw DomainException::conflict(
                    'User sudah terdaftar dalam organisasi ini.'
                );
            }
        }

        $hasUnitUpdate = array_key_exists('unit_ids', $data)
            || array_key_exists('unit_id', $data);

        $hasDefaultUpdate = array_key_exists(
            'default_unit_id',
            $data
        );

        $unitIds = $hasUnitUpdate
            ? $this->extractUnitIds($data)
            : array_map(
                static fn (array $unit): int => (int) $unit['id'],
                $membership['units'] ?? []
            );

        $this->validateUnits($organizationId, $unitIds);

        $defaultUnitId = $this->resolveDefaultUnitId(
            $data,
            $unitIds,
            $membership['units'] ?? []
        );

        if (
            $payload === []
            && !$hasUnitUpdate
            && !$hasDefaultUpdate
        ) {
            throw DomainException::notFound(
                'Tidak ada data yang diperbarui.'
            );
        }

        $oldUserId = (int) $membership['user_id'];
        $targetUserId = (int) ($payload['user_id'] ?? $oldUserId);

        // Perpindahan user tidak otomatis membawa status default user lama.
        if ($targetUserId !== $oldUserId && !array_key_exists('is_default', $payload)) {
            $payload['is_default'] = 0;
        }

        $willBeDefault = (int) ($payload['is_default'] ?? $membership['is_default']) === 1;

        $this->db->transBegin();

        try {
            if ($willBeDefault) {
                $this->db->table('core_organization_memberships')
                    ->where('user_id', $targetUserId)
                    ->where('id !=', $membershipId)
                    ->set(['is_default' => 0])
                    ->update();
            }

            if ($payload !== []) {
                $updated = $this->memberships->update(
                    $membershipId,
                    $payload
                );

                if (!$updated) {
                    throw new RuntimeException($this->getModelErrors());
                }
            }

            if ($hasUnitUpdate || $hasDefaultUpdate) {
                $this->syncUnits(
                    $membershipId,
                    $organizationId,
                    $unitIds,
                    $defaultUnitId
                );

                $this->syncLegacyUnitId(
                    $membershipId,
                    $defaultUnitId
                );
            }

            $this->commitOrFail(
                'Gagal memperbarui membership.'
            );

            return true;
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    public function deactivate(
        int $organizationId,
        int $membershipId
    ): bool {
        $this->validateOrganization($organizationId);

        $membership = $this->findForOrganization(
            $organizationId,
            $membershipId
        );

        if (!$membership) {
            throw new RuntimeException('Membership tidak ditemukan.');
        }

        if ($membership['status'] === 'inactive') {
            throw DomainException::notFound(
                'Membership sudah tidak aktif.'
            );
        }

        return $this->memberships->update(
            $membershipId,
            [
                'status' => 'inactive',
                'is_default' => 0,
            ]
        );
    }

    private function normalizeData(
        array $data,
        bool $creating
    ): array {
        $allowedFields = [
            'user_id',
            'membership_role',
            'status',
            'is_default',
        ];

        $payload = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if ($creating && empty($payload['user_id'])) {
            throw DomainException::conflict('User wajib dipilih.');
        }

        if (isset($payload['user_id'])) {
            if (
                !is_numeric($payload['user_id'])
                || (int) $payload['user_id'] <= 0
            ) {
                throw DomainException::conflict('User ID tidak valid.');
            }

            $payload['user_id'] = (int) $payload['user_id'];
        }

        if (isset($payload['membership_role'])) {
            $payload['membership_role'] = trim(
                (string) $payload['membership_role']
            );

            if ($payload['membership_role'] === '') {
                throw DomainException::conflict(
                    'Role membership tidak boleh kosong.'
                );
            }
        } elseif ($creating) {
            $payload['membership_role'] = 'member';
        }

        if (isset($payload['status'])) {
            if (
                !in_array(
                    $payload['status'],
                    ['active', 'inactive'],
                    true
                )
            ) {
                throw DomainException::validation(
                    'Status membership tidak valid.'
                );
            }
        } elseif ($creating) {
            $payload['status'] = 'active';
        }

        if (array_key_exists('is_default', $payload)) {
            $payload['is_default'] = filter_var(
                $payload['is_default'],
                FILTER_VALIDATE_BOOLEAN
            ) ? 1 : 0;
        } elseif ($creating) {
            $payload['is_default'] = 0;
        }

        return $payload;
    }

    private function extractUnitIds(array $data): array
    {
        if (array_key_exists('unit_ids', $data)) {
            if (!is_array($data['unit_ids'])) {
                throw DomainException::validation(
                    'unit_ids harus berupa array.'
                );
            }

            $values = $data['unit_ids'];
        } elseif (
            array_key_exists('unit_id', $data)
            && $data['unit_id'] !== null
            && $data['unit_id'] !== ''
        ) {
            $values = [$data['unit_id']];
        } else {
            $values = [];
        }

        $ids = [];

        foreach ($values as $value) {
            if (
                !is_numeric($value)
                || (int) $value <= 0
                || (string) (int) $value !== (string) $value
                    && !is_int($value)
            ) {
                throw DomainException::validation(
                    'Terdapat Unit ID yang tidak valid.'
                );
            }

            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }

    private function validateUnits(
        int $organizationId,
        array $unitIds
    ): void {
        if ($unitIds === []) {
            return;
        }

        $units = $this->db->table('core_organization_units')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where('deleted_at', null)
            ->whereIn('id', $unitIds)
            ->get()
            ->getResultArray();

        if (count($units) !== count($unitIds)) {
            throw DomainException::validation(
                'Satu atau lebih unit tidak valid, tidak aktif, atau bukan milik organisasi ini.'
            );
        }
    }

    private function resolveDefaultUnitId(
        array $data,
        array $unitIds,
        ?array $existingUnits
    ): ?int {
        if (array_key_exists('default_unit_id', $data)) {
            $value = $data['default_unit_id'];

            if ($value === null || $value === '') {
                return null;
            }

            if (!is_numeric($value) || (int) $value <= 0) {
                throw DomainException::validation(
                    'Unit default tidak valid.'
                );
            }

            $defaultId = (int) $value;

            if (!in_array($defaultId, $unitIds, true)) {
                throw DomainException::validation(
                    'Unit default harus termasuk dalam daftar unit membership.'
                );
            }

            return $defaultId;
        }

        if (
            array_key_exists('unit_id', $data)
            && !array_key_exists('unit_ids', $data)
        ) {
            return $unitIds[0] ?? null;
        }

        if (count($unitIds) === 1) {
            return $unitIds[0];
        }

        if ($existingUnits !== null) {
            foreach ($existingUnits as $unit) {
                if (
                    (int) ($unit['is_default'] ?? 0) === 1
                    && in_array((int) $unit['id'], $unitIds, true)
                ) {
                    return (int) $unit['id'];
                }
            }
        }

        return null;
    }


    private function syncUnits(
        int $membershipId,
        int $organizationId,
        array $unitIds,
        ?int $defaultUnitId
    ): void {
        // Pastikan semua unit aktif dan berasal dari organisasi yang sama.
        $this->validateUnits($organizationId, $unitIds);

        // Unit default wajib termasuk dalam daftar unit membership.
        if (
            $defaultUnitId !== null
            && !in_array($defaultUnitId, $unitIds, true)
        ) {
            throw DomainException::validation(
                'Unit default tidak termasuk dalam unit membership.'
            );
        }

        $table = $this->db->table(
            'core_organization_membership_units'
        );

        // Hapus relasi lama. Operasi ini harus berada dalam transaksi.
        $deleted = $table
            ->where('membership_id', $membershipId)
            ->delete();

        if ($deleted === false) {
            throw new RuntimeException(
                'Gagal menghapus relasi unit membership lama.'
            );
        }

        // Membership boleh tidak memiliki unit.
        if ($unitIds === []) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $rows = [];

        foreach ($unitIds as $unitId) {
            $rows[] = [
                'organization_id'     => $organizationId,
                'membership_id'       => $membershipId,
                'organization_unit_id' => $unitId,
                'is_default'          => $defaultUnitId === $unitId ? 1 : 0,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        $inserted = $table->insertBatch($rows);

        if ($inserted === false) {
            throw new RuntimeException(
                'Gagal menyimpan relasi unit membership.'
            );
        }
    }


    private function syncLegacyUnitId(
        int $membershipId,
        ?int $defaultUnitId
    ): void {
        $updated = $this->db
            ->table('core_organization_memberships')
            ->where('id', $membershipId)
            ->update([
                'unit_id' => $defaultUnitId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if ($updated === false) {
            throw new RuntimeException(
                'Gagal menyinkronkan unit_id legacy membership.'
            );
        }
    }

    private function getMembershipUnits(
        int $membershipId,
        int $organizationId
    ): array {
        return $this->db->table(
            'core_organization_memberships m'
        )
            ->select([
                'ou.id',
                'ou.code',
                'ou.name',
                'ou.unit_type',
                'COALESCE(mu.is_default, 1) AS is_default',
            ])
            ->join(
                'core_organization_membership_units mu',
                'mu.membership_id = m.id AND mu.organization_id = m.organization_id',
                'left'
            )
            ->join(
                'core_organization_units ou',
                'ou.id = COALESCE(mu.organization_unit_id, m.unit_id)',
                'inner'
            )
            ->where('m.id', $membershipId)
            ->where('m.organization_id', $organizationId)
            ->where('ou.organization_id', $organizationId)
            ->where('ou.status', 'active')
            ->where('ou.deleted_at', null)
            ->orderBy('is_default', 'DESC')
            ->orderBy('ou.name', 'ASC')
            ->get()
            ->getResultArray();
    }

    private function findDefaultUnit(array $units): ?array
    {
        foreach ($units as $unit) {
            if ((int) $unit['is_default'] === 1) {
                return $unit;
            }
        }

        return null;
    }

    private function validateOrganization(int $organizationId): array
    {
        if ($organizationId <= 0) {
            throw DomainException::validation(
                'Organization ID tidak valid.'
            );
        }

        $organization = $this->organizations->find($organizationId);

        if (
            !$organization
            || ($organization['status'] ?? null) !== 'active'
        ) {
            throw new RuntimeException(
                'Organisasi tidak ditemukan atau tidak aktif.'
            );
        }

        return $organization;
    }

    private function commitOrFail(string $message): void
    {
        if ($this->db->transStatus() === false) {
            throw new RuntimeException($message);
        }

        $this->db->transCommit();
    }

    private function getModelErrors(): string
    {
        $errors = $this->memberships->errors();

        return $errors !== []
            ? implode(' ', $errors)
            : 'Terjadi kesalahan saat menyimpan membership.';
    }
}
