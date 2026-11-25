<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Modules\Master\Models\MasterModel;

class DistributionItemModel extends MasterModel
{
    protected $table = 'distribution_items';

    protected $allowedFields = [
        'distribution_order_id',
        'product_id',
        'quantity',
        'unit_price',
        'discount_amount',
        'subtotal',
        'notes',
    ];

    protected $useTimestamps = true;
}
