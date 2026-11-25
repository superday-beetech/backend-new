<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Modules\Master\Models\MasterModel;

class SaleItemModel extends MasterModel
{
    protected $table = 'sale_items';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useTimestamps = true;

    protected $allowedFields = [
        'sale_id',
        'product_id',
        'batch_id',
        'quantity',
        'unit_id',
        'conversion_factor',
        'unit_price',
        'discount_amount',
        'subtotal',
        'unit_cost',
        'notes',
    ];
}
