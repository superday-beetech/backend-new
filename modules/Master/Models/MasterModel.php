<?php

declare(strict_types=1);

namespace Modules\Master\Models;

use CodeIgniter\Model;

abstract class MasterModel extends Model
{
    protected $DBGroup = 'apps';

    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;

    protected $returnType = 'array';

    protected $protectFields = true;
}
