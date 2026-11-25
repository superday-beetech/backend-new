<?php

declare(strict_types=1);

namespace Modules\Master\Models;

use Modules\Master\Models\MasterModel;

class SupplierModel extends MasterModel
{
    protected $table = 'suppliers';

    protected $allowedFields = [
        'code',
        'name',
        'phone',
        'email',
        'address',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $useTimestamps = true;
}
