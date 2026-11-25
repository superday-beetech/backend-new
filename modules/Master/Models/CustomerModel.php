<?php

declare(strict_types=1);

namespace Modules\Master\Models;

use Modules\Master\Models\MasterModel;

class CustomerModel extends MasterModel
{
    protected $table = 'customers';

    protected $allowedFields = [
        'code',
        'name',
        'customer_type',
        'phone',
        'email',
        'address',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $useTimestamps = true;
}
