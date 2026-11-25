<?php

declare(strict_types=1);

namespace Modules\Pharmacy\Models;

use CodeIgniter\Model;

abstract class PharmacyModel extends Model
{
    protected $DBGroup = 'apps';

    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;

    protected $returnType = 'array';

    protected $protectFields = true;
}
