<?php

declare(strict_types=1);

namespace Modules\Master\Models;

use Modules\Master\Models\MasterModel;

class UnitModel extends MasterModel
{
    protected $table = 'units';

    protected $allowedFields = [
        'name',
        'abbreviation',
    ];

    protected $useTimestamps = true;
}
