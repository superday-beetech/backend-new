<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Modules\Master\Models\MasterModel;

class ProductBatchModel extends MasterModel
{
    protected $table = 'product_batches';

    protected $allowedFields = [
        'product_id',
        'goods_receipt_item_id',
        'batch_number',
        'expired_at',
        'received_quantity',
        'quantity_remaining',
        'unit_cost',
        'status',
        'status_before_recall',
    ];

    protected $useTimestamps = true;
}
