<?php

declare(strict_types=1);

namespace Modules\Return\Models;

use Modules\Master\Models\MasterModel;

class SalesReturnModel extends MasterModel
{
    protected $table            = 'sales_returns';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useTimestamps    = true;

    protected $allowedFields = [
        'return_number',
        'customer_id',
        'sale_id',
        'delivery_id',
        'return_date',
        'status',
        'reason',
        'total_amount',
        'notes',
        'created_by',
        'updated_by',
    ];
}
