<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Modules\Master\Models\MasterModel;

class CustomerPaymentModel extends MasterModel
{
    protected $table            = 'customer_payments';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useTimestamps    = true;

    protected $allowedFields = [
        'payment_number',
        'customer_id',
        'sale_id',
        'distribution_order_id',
        'payment_date',
        'amount',
        'payment_method',
        'reference_number',
        'notes',
        'created_by',
    ];
}
