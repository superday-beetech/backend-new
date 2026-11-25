<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Modules\Master\Models\MasterModel;

class ExpenseModel extends MasterModel
{
    protected $table = 'expenses';

    protected $allowedFields = [
        'expense_number',
        'expense_date',
        'category',
        'description',
        'amount',
        'payment_method',
        'reference_number',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $useTimestamps = true;
}
