<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Modules\Master\Models\MasterModel;

class StockOpnameModel extends MasterModel
{
    protected $table = 'stock_opnames';

    protected $allowedFields = [
        'opname_number',
        'opname_date',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $useTimestamps = true;
}
