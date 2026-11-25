<?php

declare(strict_types=1);

namespace Modules\Logistic\Models;

use Modules\Master\Models\MasterModel;

class DeliveryModel extends MasterModel
{
    protected $table = 'deliveries';

    protected $allowedFields = [
        'delivery_number',
        'distribution_order_id',
        'delivery_date',
        'status',
        'recipient_name',
        'recipient_phone',
        'shipping_address',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $useTimestamps = true;
}
