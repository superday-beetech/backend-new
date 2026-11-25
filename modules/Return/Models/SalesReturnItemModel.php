<?php

declare(strict_types=1);

namespace Modules\Return\Models;

use Modules\Master\Models\MasterModel;

class SalesReturnItemModel extends MasterModel
{
    protected $table            = 'sales_return_items';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useTimestamps    = true;

    protected $allowedFields = [
        'sales_return_id',
        'sale_item_id',
        'delivery_item_id',
        'batch_id',
        'quantity',
        'unit_price',
        'subtotal',
        'restockable',
        'condition_notes',
    ];
}
