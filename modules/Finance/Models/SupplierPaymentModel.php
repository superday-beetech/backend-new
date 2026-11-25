<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Modules\Master\Models\MasterModel;

class SupplierPaymentModel extends MasterModel
{
    protected $table = 'supplier_payments';

    protected $allowedFields = [
        'payment_number',
        'supplier_id',
        'purchase_order_id',
        'payment_date',
        'amount',
        'payment_method',
        'reference_number',
        'notes',
        'created_by',
    ];

    protected $useTimestamps = true;
}
