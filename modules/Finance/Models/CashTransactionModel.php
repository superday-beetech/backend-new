<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Modules\Master\Models\MasterModel;

class CashTransactionModel extends MasterModel
{
    protected $table = 'cash_transactions';

    protected $allowedFields = [
        'transaction_number',
        'transaction_date',
        'transaction_type',
        'reference_type',
        'reference_id',
        'description',
        'amount',
        'payment_method',
        'created_by',
    ];

    protected $useTimestamps = false;

    protected $createdField = 'created_at';

    protected $updatedField = '';
}
