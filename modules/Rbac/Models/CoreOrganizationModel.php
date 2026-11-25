<?php

declare(strict_types=1);

namespace Modules\Rbac\Models;

use Modules\Core\Models\OrganizationModel as BaseOrganizationModel;

/**
     * @deprecated Use the Core organization query/service APIs.
     */
class CoreOrganizationModel extends BaseOrganizationModel
{
    public function getActiveOrganizations(): array
    {
        return $this->where('status', 'active')
            ->where('deleted_at', null)
            ->orderBy('name', 'ASC')
            ->findAll();
    }
}
