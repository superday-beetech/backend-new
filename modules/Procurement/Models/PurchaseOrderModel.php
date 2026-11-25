<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Modules\Master\Models\MasterModel;

class PurchaseOrderModel extends MasterModel
{
    protected $table = 'purchase_orders';

    protected $allowedFields = [
    'order_number',
    'supplier_id',
    'purchase_mode',
    'order_date',
    'expected_date',
    'status',
    'subtotal',
    'discount_amount',
    'tax_amount',
    'total_amount',
    'notes',
    'created_by',
    'updated_by',
];

    protected $useTimestamps = true;
}
