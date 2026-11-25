<?php

declare(strict_types=1);

namespace Modules\Logistic\Models;

use Modules\Master\Models\MasterModel;

class DeliveryItemModel extends MasterModel
{
    protected $table = 'delivery_items';

    protected $allowedFields = [
        'delivery_id',
        'distribution_item_id',
        'batch_id',
        'quantity_dispatched',
        'unit_price',
    ];

    protected $useTimestamps = true;
}
