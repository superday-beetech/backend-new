<?php

declare(strict_types=1);

namespace Modules\Return\Models;

use Modules\Master\Models\MasterModel;

class PurchaseReturnItemModel extends MasterModel
{
    protected $table = 'purchase_return_items';

    protected $allowedFields = [
        'purchase_return_id',
        'goods_receipt_item_id',
        'batch_id',
        'quantity',
        'unit_cost',
        'subtotal',
        'notes',
    ];

    protected $useTimestamps = true;
}
