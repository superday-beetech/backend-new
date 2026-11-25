<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Modules\Master\Models\MasterModel;

class GoodsReceiptModel extends MasterModel
{
    protected $table = 'goods_receipts';

    protected $allowedFields = [
        'receipt_number',
        'purchase_order_id',
        'received_date',
        'supplier_delivery_number',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $useTimestamps = true;
}
