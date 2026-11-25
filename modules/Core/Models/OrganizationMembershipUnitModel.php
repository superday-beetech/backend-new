<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use CodeIgniter\Model;

class OrganizationMembershipUnitModel extends Model
{
    protected $table            = 'core_organization_membership_units';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = true;
    protected $dateFormat       = 'datetime';

    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'organization_id',
        'membership_id',
        'organization_unit_id',
        'is_default',
    ];

    protected $validationRules = [
        'organization_id'      => 'required|is_natural_no_zero',
        'membership_id'        => 'required|is_natural_no_zero',
        'organization_unit_id' => 'required|is_natural_no_zero',
        'is_default'            => 'permit_empty|in_list[0,1]',
    ];

    /**
     * Daftar unit milik satu membership.
     */
    public function getByMembership(int $membershipId): array
    {
        return $this->where('membership_id', $membershipId)
            ->orderBy('is_default', 'DESC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * Mendapatkan unit default membership.
     */
    public function getDefaultUnit(int $membershipId): ?array
    {
        $result = $this->where('membership_id', $membershipId)
            ->where('is_default', 1)
            ->first();

        return $result ?: null;
    }

    /**
     * Memastikan unit sudah terdaftar pada membership.
     */
    public function hasUnit(
        int $membershipId,
        int $organizationUnitId
    ): bool {
        return $this->where('membership_id', $membershipId)
            ->where('organization_unit_id', $organizationUnitId)
            ->countAllResults() > 0;
    }

    /**
     * Menghapus seluruh relasi unit membership.
     */
    public function deleteByMembership(int $membershipId): bool
    {
        return $this->where('membership_id', $membershipId)
            ->delete();
    }
}
