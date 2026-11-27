<?php

declare(strict_types=1);

namespace Modules\Rbac\Models;

/**
 * Compatibility adapter for legacy callers.
 *
 * @deprecated Use Modules\Core\Models\OrganizationUnitModel.
 * **/

use Modules\Core\Models\OrganizationUnitModel;

class CoreOrganizationUnitModel extends OrganizationUnitModel
{
}
