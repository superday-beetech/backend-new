<?php

declare(strict_types=1);

namespace Modules\Return\Models;

use Modules\Master\Models\MasterModel;

class PurchaseReturnModel extends MasterModel
{
    protected $table = 'purchase_returns';

    protected $allowedFields = [
        'return_number',
        'supplier_id',
        'goods_receipt_id',
        'return_date',
        'status',
        'reason',
        'total_amount',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $useTimestamps = true;
}
