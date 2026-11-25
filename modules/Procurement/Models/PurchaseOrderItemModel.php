<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Modules\Master\Models\MasterModel;

class PurchaseOrderItemModel extends MasterModel
{
    protected $table = 'purchase_order_items';

    protected $allowedFields = [
    'purchase_order_id',
    'product_id',
    'unit_id',
    'conversion_factor',
    'quantity',
    'unit_cost',
    'discount_amount',
    'subtotal',
    'notes',
];

    protected $useTimestamps = true;
}
