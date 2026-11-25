<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use CodeIgniter\Model;

class OrganizationMembershipModel extends Model
{
    protected $table            = 'core_organization_memberships';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = true;
    protected $dateFormat       = 'datetime';

    protected $allowedFields = [
        'organization_id',
        'user_id',
        'unit_id',
        'membership_role',
        'status',
        'is_default',
    ];

    protected $validationRules = [
        'organization_id' => 'required|is_natural_no_zero',
        'user_id'         => 'required|is_natural_no_zero',
        'unit_id'         => 'permit_empty|is_natural_no_zero',
        'membership_role' => 'required|max_length[50]',
        'status'          => 'required|in_list[active,inactive]',
        'is_default'      => 'permit_empty|in_list[0,1]',
    ];
}
